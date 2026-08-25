<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\TripInstance;
use App\Models\User;
use App\Models\Passenger;
use App\Models\Payment;
use App\Enums\BookingStatus;
use App\Enums\PaymentType;
use App\Enums\PaymentStatus;
use Illuminate\Support\Facades\DB;

class BookingService
{
    /**
     * Cancel a booking, optionally applying a cancellation fee (P0-7: single authority
     * for full-booking cancellation, subsuming the previously-duplicated Filament
     * "process_cancellation"/"cancel_booking" admin actions).
     *
     * $cancellationFee is nullable (not just 0.0) so callers that must NOT touch the
     * booking's financials at all (e.g. TripService::cancelTrip(), the pre-existing live
     * caller) can omit it entirely and get the exact prior behavior, while callers that
     * explicitly decided on a fee (including an explicit 0.0 "no fee, full refund") get
     * the grand_total/balance_due override that the migrated admin actions always applied.
     */
    public function cancelBooking(Booking $booking, ?string $reason = null, ?float $cancellationFee = null): void
    {
        DB::transaction(function () use ($booking, $reason, $cancellationFee) {
            $booking = Booking::where('id', $booking->id)->lockForUpdate()->firstOrFail();
            $oldStatus = $booking->booking_status;

            // P0-5 idempotency guard: a second cancelBooking() call against an
            // already-cancelled booking (concurrent double-submit, or an independent caller
            // racing cancelPassengers()'s internal delegation to this method) must be a no-op.
            // releaseForCancellation() below is already self-guarded, but the customer
            // notification and WaitlistAutoPromotion dispatch further down are not, and would
            // otherwise fire a second time. lockForUpdate() above is what makes this check
            // race-safe: an overlapping caller blocks until the first transaction commits,
            // then observes booking_status already Cancelled here and returns.
            if ($oldStatus === BookingStatus::Cancelled) {
                return;
            }

            $passengers = $booking->passengers()->get();
            if ($passengers->isNotEmpty()) {
                Passenger::withoutEvents(function () use ($passengers, $reason) {
                    foreach ($passengers as $p) {
                        $p->extra_preferences = array_merge($p->extra_preferences ?? [], [
                            'cancelled_at' => now()->toISOString(),
                            'cancelled_reason' => $reason,
                            'cancelled_by' => auth()->id(),
                        ]);
                        $p->save();
                        $p->delete();
                    }
                });
            }

            // Bypass observers for the status write itself: this method already owns and
            // performs the cancellation side effects explicitly (inventory release below,
            // waitlist dispatch at the end) — letting BookingObserver also fire on an
            // Eloquent status update here would dispatch WaitlistAutoPromotion a second
            // time for the same cancellation, which is exactly the double-mutation defect
            // this phase (P0-7) exists to remove.
            DB::table('bookings')
                ->where('id', $booking->id)
                ->update(['booking_status' => BookingStatus::Cancelled->value]);

            // Release inventory (seats)
            app(\App\Services\InventoryService::class)->releaseForCancellation($booking);

            // Hotel/Rooming redesign Ticket 2: release room inventory too, via the fully
            // separate RoomInventoryService (own table, own locks, never sharing state with the
            // seat ledger above). Idempotent and a no-op for any booking with zero room
            // selections — i.e. every booking in this app before this ticket, and every booking
            // that doesn't use the new room system.
            app(\App\Services\RoomInventoryService::class)->releaseForCancellation($booking);

            // P0-5: removed a dead/broken "release PackageOption inventory" loop that used to
            // sit here. It iterated `$booking->addons` — no such relation exists on Booking
            // (the real relation is bookingAddons()) — looking for `$addon->packageOption`,
            // which also doesn't exist (BookingAddon has no packageOption() relation; package
            // options are selected at the booking level via `$booking->packageOption`, not per
            // addon). The loop body therefore never ran. Even if it had, it could not have
            // worked as an increment: PackageOption::remaining_seats is a computed accessor
            // (getRemainingSeatsAttribute()), not a persisted column — it's derived live from
            // non-cancelled bookings against package_option_id, which already excludes this
            // booking as soon as booking_status is set to Cancelled above. No explicit release
            // step is needed or possible here.

            if ($cancellationFee !== null) {
                $refundableAmount = ($booking->total_paid ?? 0) - $cancellationFee;
                $noteLine = "\n[" . now() . "] تم إلغاء الحجز كلياً. رسوم الإلغاء: {$cancellationFee}. المبلغ المسترد الواجب إرجاعه: {$refundableAmount}.";

                DB::table('bookings')->where('id', $booking->id)->update([
                    'cancellation_requested_at' => null,
                    'notes' => trim(($booking->notes ?? '') . $noteLine),
                    'grand_total' => (int) round($cancellationFee * 100),
                    'balance_due' => 0,
                ]);
            }

            // Track refund liability via payment_status, not by touching any financial amount
            // field. grand_total/total_paid/balance_due are never modified by cancellation
            // itself (the $cancellationFee branch above is a separate, pre-existing, opt-in
            // admin decision, not part of this) — they stay exactly as they were at the moment
            // of cancellation. A booking that had received any payment (PartiallyPaid or Paid)
            // moves to RefundPending, signalling money is owed back; an Unpaid booking has
            // nothing to refund and is left untouched. Applies identically whether this is a
            // single-booking cancellation or one iteration of TripService::cancelTrip()'s loop
            // — no special-casing by caller. The eventual RefundPending -> Refunded transition
            // (a future, separate refund-execution ledger record) is out of scope here.
            if ($booking->payment_status !== \App\Enums\PaymentStatus::Unpaid) {
                DB::table('bookings')->where('id', $booking->id)->update([
                    'payment_status' => \App\Enums\PaymentStatus::RefundPending->value,
                ]);
            }

            activity()
                ->performedOn($booking)
                ->causedBy(auth()->user())
                ->withProperties([
                    'old_status' => $oldStatus?->value,
                    'new_status' => BookingStatus::Cancelled->value,
                    'reason' => $reason,
                    'cancellation_fee' => $cancellationFee,
                ])
                ->log('booking_cancelled');

            // Notify the customer
            if ($booking->customer) {
                $booking->customer->notify(new \App\Notifications\BookingCancelled($booking, $reason ?? ''));
            }

            // Dispatch waitlist auto-promotion
            \App\Jobs\WaitlistAutoPromotion::dispatch($booking->trip_instance_id);
        });
    }

    /**
     * Reopen a cancelled booking — the single authority for "un-cancelling" (P0-9), replacing
     * the prior Filament `reopen_cancelled` action's bare `$record->update(['booking_status' =>
     * Pending])`, which had two undiscovered defects: (1) cancelBooking() soft-deletes every
     * passenger on the booking, so a "reopened" booking actually had zero visible passengers —
     * this restores them; (2) it never re-consumed the inventory cancelBooking() released, so a
     * reopened booking's seats stayed permanently released even though the booking was active
     * again — this re-consumes them through InventoryService::consumeForBooking(), the same
     * locked, capacity-checked path CreateBookingService/transferBooking() use, and rolls back
     * the entire operation (no passengers restored, no status change) if capacity is no longer
     * available, surfacing the failure to the caller instead of silently reopening into an
     * oversold trip.
     *
     * payment_status: cancelBooking() moves a previously-paid booking to RefundPending purely as
     * a "money is owed back" liability flag — it never touches grand_total/total_paid/
     * balance_due, and there is no refund-execution record anywhere in this app confirming money
     * has actually been paid back out. Once the booking is un-cancelled, that liability is stale:
     * the original payment(s) are still on the booking and now just fund an active booking again,
     * not a refund. recalculateTotals() (called below, now unblocked since booking_status is no
     * longer Cancelled) naturally recomputes payment_status from real payments vs. the
     * recalculated grand_total — which also means a cancellation-fee override (grand_total set to
     * the fee, balance_due forced to 0) is superseded by the normal recalculation on reopen,
     * consistent with "reopen" meaning "return to a normal active booking," not "keep the
     * cancellation's fee bookkeeping." If an agency has already manually refunded a customer
     * outside this system while payment_status was RefundPending, reopening will make the
     * booking look fully/partially paid again from that original payment — this app has no
     * refund-execution ledger to detect that case, so it's out of scope here, same as the
     * pre-existing RefundPending -> Refunded gap noted in cancelBooking().
     */
    public function reopenBooking(Booking $booking): void
    {
        DB::transaction(function () use ($booking) {
            $booking = Booking::where('id', $booking->id)->lockForUpdate()->firstOrFail();

            // Idempotency guard, same shape as cancelBooking()'s already-cancelled early return.
            if ($booking->booking_status !== BookingStatus::Cancelled) {
                return;
            }

            $trashedPassengers = Passenger::onlyTrashed()->where('booking_id', $booking->id)->get();
            if ($trashedPassengers->isEmpty()) {
                throw new \RuntimeException('لا يوجد ركاب لإعادة فتح هذا الحجز.');
            }

            $seatsNeeded = $trashedPassengers->filter(
                fn ($p) => $p->tripPassengerCategory?->requires_seat ?? true
            )->count();

            // Capacity check + ledger write BEFORE restoring passengers/status: if this throws
            // (InsufficientSeatsException, a subclass of InventoryExhaustedException), the whole
            // transaction rolls back and nothing about the booking changes.
            if ($seatsNeeded > 0) {
                app(\App\Services\InventoryService::class)->consumeForBooking($booking, $seatsNeeded);
            }

            Passenger::withoutEvents(function () use ($trashedPassengers) {
                foreach ($trashedPassengers as $passenger) {
                    $prefs = $passenger->extra_preferences ?? [];
                    unset($prefs['cancelled_at'], $prefs['cancelled_reason'], $prefs['cancelled_by']);
                    $passenger->extra_preferences = $prefs;
                    $passenger->restore();
                    $passenger->save();
                }
            });

            DB::table('bookings')->where('id', $booking->id)->update([
                'booking_status' => BookingStatus::Pending->value,
            ]);

            $booking = $booking->fresh();
            $this->recalculateTotals($booking);

            activity()
                ->performedOn($booking)
                ->causedBy(auth()->user())
                ->withProperties([
                    'passengers_restored' => $trashedPassengers->count(),
                    'seats_reconsumed' => $seatsNeeded,
                ])
                ->log('booking_reopened');
        });
    }

    /**
     * Move a booking (and all its passengers) to a different TripInstance — the single
     * authority for cross-trip transfer, replacing the two independently hand-rolled
     * "transfer_booking" admin action bodies previously duplicated in BookingResource.php
     * and ViewBooking.php (one of which wrote the invalid ledger enum literal
     * 'cancellation' instead of 'cancelled', crashing under MySQL strict mode).
     *
     * $passengerCategoryMap: [passenger_id => new_trip_passenger_category_id], one entry
     * required for every passenger currently on the booking.
     *
     * Locking mirrors cancelBooking() (P0-5): the booking row is locked and re-read fresh
     * first, and an already-cancelled booking cannot be transferred. The destination
     * TripInstance is additionally locked, and remaining capacity is re-verified against
     * that lock inside InventoryService::transferSeats() — not against a pre-transaction
     * read — closing the race the two previous implementations left open (both read
     * remaining_seats before opening any transaction/lock).
     */
    public function transferBooking(Booking $booking, TripInstance $newTrip, array $passengerCategoryMap): void
    {
        DB::transaction(function () use ($booking, $newTrip, $passengerCategoryMap) {
            $booking = Booking::where('id', $booking->id)->lockForUpdate()->firstOrFail();

            if ($booking->booking_status === BookingStatus::Cancelled) {
                throw new \RuntimeException('لا يمكن تحويل حجز ملغى إلى رحلة أخرى.');
            }

            // Idempotency guard, same shape as cancelBooking()'s already-cancelled early
            // return: a retried/overlapping call against a booking already moved to this
            // exact trip must be a no-op, not a second transfer — re-running the ledger
            // writes below would double-consume the destination and double-release the
            // (now former) source trip's inventory.
            if ((int) $booking->trip_instance_id === (int) $newTrip->id) {
                return;
            }

            $newTrip = TripInstance::where('id', $newTrip->id)->lockForUpdate()->firstOrFail();

            $passengers = $booking->passengers()->get();
            $count = $passengers->count();

            if ($count === 0) {
                return;
            }

            $newCategories = \App\Models\TripPassengerCategory::where('trip_instance_id', $newTrip->id)
                ->get()
                ->keyBy('id');

            // Accumulate via getRawOriginal(), not the MoneyCast-cast ->price accessor (which
            // returns a major-unit float) - both prior implementations summed the cast
            // (dollar) value directly into a variable they named "...Cents" and wrote it
            // straight into the raw grand_total column via DB::table()->update() below, which
            // bypasses casts entirely. That silently stored the total 100x too small on every
            // transfer. Fixed here to match the raw-cents convention already used elsewhere in
            // this class (see recordPayment()'s $booking->getRawOriginal('balance_due')).
            $newGrandTotalCents = 0;
            foreach ($passengers as $p) {
                $catId = $passengerCategoryMap[$p->id] ?? null;
                if (!$catId || !$newCategories->has($catId)) {
                    throw new \InvalidArgumentException("يجب اختيار فئة صالحة لكل راكب (الراكب #{$p->id}).");
                }
                $newGrandTotalCents += (int) $newCategories->get($catId)->getRawOriginal('price');
            }

            $oldTripId = $booking->trip_instance_id;

            // Release old-trip seats + consume new-trip seats with the destination capacity
            // recheck, all under the locks acquired above.
            app(InventoryService::class)->transferSeats($booking, $oldTripId, $newTrip, $count);

            foreach ($passengers as $p) {
                $cat = $newCategories->get($passengerCategoryMap[$p->id]);
                $p->update([
                    'trip_passenger_category_id' => $cat->id,
                    'price_at_booking' => $cat->price,
                ]);
            }

            $paidCents = (int) DB::table('bookings')->where('id', $booking->id)->value('total_paid');
            $balanceDueCents = max(0, $newGrandTotalCents - $paidCents);

            $note = trim(($booking->notes ?? '') . "\n[" . now() . "] تم تحويل الحجز من الرحلة #{$oldTripId} إلى #{$newTrip->id}.");

            DB::table('bookings')->where('id', $booking->id)->update([
                'trip_instance_id' => $newTrip->id,
                'grand_total' => $newGrandTotalCents,
                'balance_due' => $balanceDueCents,
                'notes' => $note,
            ]);

            activity()
                ->performedOn($booking)
                ->causedBy(auth()->user())
                ->withProperties([
                    'old_trip_instance_id' => $oldTripId,
                    'new_trip_instance_id' => $newTrip->id,
                    'passenger_count' => $count,
                    'new_grand_total_cents' => $newGrandTotalCents,
                ])
                ->log('booking_transferred');
        });
    }

    /**
     * Add one or more passengers to an existing booking (P0-7: single authority for
     * inventory consumption + financial recalculation on passenger addition, subsuming
     * the previously-duplicated Filament "add_seats" admin actions).
     *
     * $passengerSpecs: array of ['trip_passenger_category_id' => int, 'first_name' => ?string,
     * 'last_name' => ?string, 'document_type' => ?string, 'document_number' => ?string,
     * 'date_of_birth' => ?string, 'extra_preferences' => ?array, 'passenger_label' => ?string]
     * — name fields are optional; admin add-seats flows typically supply none, matching
     * CreateBookingService's existing phone-booking convention (data_complete reflects
     * whether identity data was supplied, independent of financial inclusion).
     */
    public function addPassengers(Booking $booking, array $passengerSpecs): \Illuminate\Database\Eloquent\Collection
    {
        if (empty($passengerSpecs)) {
            throw new \InvalidArgumentException('At least one passenger is required.');
        }

        return DB::transaction(function () use ($booking, $passengerSpecs) {
            $booking = Booking::where('id', $booking->id)->lockForUpdate()->firstOrFail();
            $tripInstance = TripInstance::where('id', $booking->trip_instance_id)->lockForUpdate()->firstOrFail();

            $categoryIds = collect($passengerSpecs)->pluck('trip_passenger_category_id')->filter()->unique();
            $categories = \App\Models\TripPassengerCategory::where('trip_instance_id', $tripInstance->id)
                ->whereIn('id', $categoryIds)
                ->get()
                ->keyBy('id');

            $overrideAmount = $tripInstance->price_override ? ($tripInstance->price_override_amount ?? 0) : 0;
            $existingCount = $booking->passengers()->count();

            $seatsNeeded = 0;
            $created = new \Illuminate\Database\Eloquent\Collection();

            Passenger::withoutEvents(function () use ($passengerSpecs, $categories, $overrideAmount, $booking, $existingCount, &$seatsNeeded, &$created) {
                foreach (array_values($passengerSpecs) as $index => $spec) {
                    $catId = $spec['trip_passenger_category_id'] ?? null;
                    $cat = $categories->get($catId);
                    if (!$cat) {
                        throw new \InvalidArgumentException("Invalid trip passenger category: {$catId}");
                    }

                    $isIncomplete = empty($spec['first_name']);
                    $labelIndex = $existingCount + $index + 1;

                    $passenger = Passenger::create([
                        'tenant_id' => $booking->tenant_id,
                        'booking_id' => $booking->id,
                        'trip_passenger_category_id' => $cat->id,
                        'price_at_booking' => $cat->price + $overrideAmount,
                        'first_name' => $spec['first_name'] ?? null,
                        'last_name' => $spec['last_name'] ?? null,
                        'document_type' => $spec['document_type'] ?? null,
                        'document_number' => $spec['document_number'] ?? null,
                        'date_of_birth' => $spec['date_of_birth'] ?? null,
                        'extra_preferences' => $spec['extra_preferences'] ?? [],
                        'data_complete' => !$isIncomplete,
                        'passenger_label' => $isIncomplete ? ($spec['passenger_label'] ?? "راكب {$labelIndex} ({$cat->name})") : null,
                    ]);

                    if ($cat->requires_seat) {
                        $seatsNeeded++;
                    }

                    $created->push($passenger);
                }
            });

            if ($seatsNeeded > 0) {
                app(\App\Services\InventoryService::class)->adjustForPassengerChange($booking, $seatsNeeded);
            }

            $this->recalculateTotals($booking);

            activity()
                ->performedOn($booking)
                ->causedBy(auth()->user())
                ->withProperties([
                    'passenger_count' => $created->count(),
                    'seats_consumed' => $seatsNeeded,
                ])
                ->log('passengers_added');

            return $created;
        });
    }

    /**
     * Cancel (soft-delete) a subset of a booking's passengers, releasing their inventory
     * and recalculating totals (P0-7: single authority for partial passenger cancellation,
     * subsuming the previously-duplicated Filament "cancel_passengers" admin actions).
     * If zero passengers remain afterward, delegates to cancelBooking() rather than
     * leaving a zero-passenger booking in a non-Cancelled status.
     */
    public function cancelPassengers(Booking $booking, \Illuminate\Support\Collection $passengers, string $reason, ?string $note = null): void
    {
        if ($passengers->isEmpty()) {
            throw new \InvalidArgumentException('At least one passenger is required.');
        }

        DB::transaction(function () use ($booking, $passengers, $reason, $note) {
            $booking = Booking::where('id', $booking->id)->lockForUpdate()->firstOrFail();

            $ids = $passengers->pluck('id');
            $toCancel = Passenger::whereIn('id', $ids)->where('booking_id', $booking->id)->get();

            if ($toCancel->isEmpty()) {
                throw new \InvalidArgumentException('No matching passengers found on this booking.');
            }

            $seatCount = 0;

            Passenger::withoutEvents(function () use ($toCancel, $reason, $note, &$seatCount) {
                foreach ($toCancel as $passenger) {
                    if ($passenger->tripPassengerCategory?->requires_seat) {
                        $seatCount++;
                    }
                    $passenger->extra_preferences = array_merge($passenger->extra_preferences ?? [], [
                        'cancelled_at' => now()->toISOString(),
                        'cancelled_reason' => $reason,
                        'cancellation_note' => $note,
                        'cancelled_by' => auth()->id(),
                    ]);
                    $passenger->save();
                    $passenger->delete();
                }
            });

            if ($seatCount > 0) {
                app(\App\Services\InventoryService::class)->adjustForPassengerChange($booking, -$seatCount);
            }

            $this->recalculateTotals($booking);

            activity()
                ->performedOn($booking)
                ->causedBy(auth()->user())
                ->withProperties([
                    'passenger_ids' => $toCancel->pluck('id'),
                    'reason' => $reason,
                ])
                ->log('passengers_cancelled');

            $remaining = Passenger::where('booking_id', $booking->id)->count();
            if ($remaining === 0) {
                $this->cancelBooking($booking, $reason);
            }
        });
    }

    /**
     * Recalculate Booking financial status based on payments
     */
        /**
     * Recalculate Booking financial totals based on current valid passengers and addons.
     */
        /**
     * Recalculate Booking financial totals based on current valid passengers and addons.
     */
        public function recalculateTotals(\App\Models\Booking $booking): void
    {
        // P0-6 parity fix: the legacy per-payment recalculation method this superseded
        // explicitly skipped any write when the booking was already Cancelled, protecting
        // cancelBooking()'s fee-override values (grand_total=fee, balance_due=0) from being
        // silently recomputed/overwritten by a later payment-triggered recalculation (e.g. a
        // reversal recorded against an already-cancelled booking). recalculateTotals() had no
        // equivalent guard — replicate it here so consolidating onto this method doesn't lose
        // that protection.
        if ($booking->booking_status === BookingStatus::Cancelled) {
            return;
        }

        // P0-7c/d fix: data_complete=false means "seat reserved and priced, identity data
        // still pending" (phone-booking placeholder), NOT "not yet financially committed" —
        // price/seat are already assigned at passenger creation regardless of completeness.
        // Filtering this sum to data_complete=true silently undercounted grand_total for any
        // booking containing an incomplete passenger (confirmed live in the phone-booking
        // flow, not just the admin add-seats action). Soft-deleted (cancelled) passengers are
        // already correctly excluded by Eloquent's default global scope, so no other filter
        // is needed here.
        $passengers = $booking->passengers()->get();
        $grandTotalFloat = 0.0;
        foreach ($passengers as $passenger) { $grandTotalFloat += (float) $passenger->price_at_booking; }
        
        $addonsFloat = 0.0;
        foreach ($booking->addons ?? [] as $addon) { $addonsFloat += ((float) $addon->price_at_booking) * $addon->quantity; }
        
        $packageAdjustment = 0.0;
        if ($booking->package_option_id && $booking->packageOption) {
            $adj = (float) ($booking->packageOption->price_adjustment ?? 0);
            $packageAdjustment = $adj * $passengers->count();
        }

        // Hotel/Rooming redesign Ticket 2 — purely additive term, zero for every booking that
        // doesn't use the new room system (every booking before this ticket, and any booking on
        // a trip still using PackageOption above). price_at_booking is already the fully-priced
        // snapshot for that selection (BookingRoomSelection, set at creation time), so this is
        // a straight sum, not a live re-read of RoomType's current price.
        $roomChargesFloat = 0.0;
        foreach ($booking->roomSelections ?? [] as $selection) {
            $roomChargesFloat += (float) $selection->price_at_booking;
        }

        $discountFloat = (float) ($booking->discount_amount ?? 0);
        $totalFloat = max(0, $grandTotalFloat + $addonsFloat + $packageAdjustment + $roomChargesFloat - $discountFloat);
        
        $paidFloat = ((float) $booking->payments()->sum('amount')) / 100.0;
        // P0-6 decision: balance_due stays clamped to zero (matches the legacy recalculation
        // method's behavior and all 4 other original pre-remediation implementations, 5/5
        // historical precedent).
        // Negative balance_due / customer-credit semantics are an explicitly separate, future
        // business decision, not part of this remediation.
        $balanceDueFloat = max(0, $totalFloat - $paidFloat);
        
        $paymentStatus = match (true) {
            $paidFloat <= 0 => \App\Enums\PaymentStatus::Unpaid,
            $paidFloat >= $totalFloat => \App\Enums\PaymentStatus::Paid,
            default => \App\Enums\PaymentStatus::PartiallyPaid,
        };
        
        $booking->updateQuietly([
            'grand_total' => $totalFloat,
            'balance_due' => $balanceDueFloat,
            'total_paid' => $paidFloat,
            'payment_status' => $paymentStatus->value,
        ]);
        
        // P0-6: recordPayment()'s payment-driven booking-status transitions live here, since
        // this is the single place both recordPayment() and reversePayment() (and any
        // un-migrated PassengerObserver-triggered recalculation) funnel through. Mirrors what
        // the three former per-action implementations (confirm_deposit/confirm_cash/
        // collect_balance) each did manually and inconsistently: Pending -> ConfirmedPartial
        // on a partial payment, Pending|ConfirmedPartial -> Confirmed once fully paid. Never
        // touches a Cancelled booking (guarded above) or moves a booking backwards.
        if (in_array($booking->booking_status, [BookingStatus::Pending, BookingStatus::ConfirmedPartial], true)) {
            if ($paymentStatus === \App\Enums\PaymentStatus::Paid) {
                $booking->updateQuietly(['booking_status' => BookingStatus::Confirmed->value]);
            } elseif ($paymentStatus === \App\Enums\PaymentStatus::PartiallyPaid && $booking->booking_status === BookingStatus::Pending) {
                $booking->updateQuietly(['booking_status' => BookingStatus::ConfirmedPartial->value]);
            }
        }
    }

    /**
     * Record a payment against a booking — the single canonical authority for payment
     * creation (P0-6). Absorbs PaymentService::recordPayment()'s prior validation and every
     * migrated Filament action's prior duplicate recalculation/status-transition logic.
     *
     * $receivedBy is nullable to support system/webhook-originated payments (no authenticated
     * user), which also pass $enforceBalanceGuard=false since the gateway path's existing
     * behavior never rejected an overpayment attempt — only the interactive/admin paths did.
     */
    public function recordPayment(
        Booking $booking,
        float $amount,
        string $method,
        ?User $receivedBy,
        PaymentType $type = PaymentType::DEPOSIT,
        ?string $referenceNumber = null,
        ?string $currency = null,
        bool $enforceBalanceGuard = true
    ): Payment {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('مبلغ الدفع يجب أن يكون أكبر من صفر.');
        }

        if ($enforceBalanceGuard && !app()->environment('testing')) {
            $key = 'record-payment:' . $booking->id;
            if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 3)) {
                $seconds = \Illuminate\Support\Facades\RateLimiter::availableIn($key);
                throw new \RuntimeException("لقد تجاوزت الحد الأقصى لتسجيل الدفعات. يرجى الانتظار {$seconds} ثانية.");
            }
            \Illuminate\Support\Facades\RateLimiter::hit($key, 60);
        }

        return DB::transaction(function () use ($booking, $amount, $method, $receivedBy, $type, $referenceNumber, $currency, $enforceBalanceGuard) {
            $booking = Booking::where('id', $booking->id)->lockForUpdate()->firstOrFail();

            if ($currency && $currency !== $booking->currency) {
                throw new \InvalidArgumentException("Invalid Currency: Payment currency ($currency) must match Booking currency ({$booking->currency})");
            }

            if ($enforceBalanceGuard) {
                // balance_due is stored as CENTS in DB (MoneyCast); $amount arrives as major
                // units — convert to cents for a unit-consistent comparison (matches
                // PaymentService::recordPayment()'s prior, correct logic exactly).
                $amountInCents = (int) round($amount * 100);
                $balanceDueCents = (int) $booking->getRawOriginal('balance_due');

                if ($balanceDueCents <= 0) {
                    throw new \InvalidArgumentException('تم سداد هذا الحجز بالكامل. لا يمكن إضافة دفعة جديدة.');
                }

                if ($amountInCents > $balanceDueCents) {
                    throw new \InvalidArgumentException(sprintf(
                        'المبلغ المدفوع (%s) يتجاوز الرصيد المستحق (%s).',
                        number_format($amount, 2),
                        number_format($balanceDueCents / 100, 2)
                    ));
                }
            }

            $payment = Payment::create([
                'tenant_id' => $booking->tenant_id,
                'booking_id' => $booking->id,
                'amount' => $amount,
                'currency' => $booking->currency,
                'payment_method' => $method,
                'reference_number' => $referenceNumber,
                'received_by' => $receivedBy?->id,
                'type' => $type,
            ]);

            // Single canonical recalculation call: owns total_paid/balance_due/payment_status
            // and the payment-driven booking-status transition (see recalculateTotals()).
            $this->recalculateTotals($booking);

            activity()
                ->performedOn($booking)
                ->causedBy(auth()->user() ?? $receivedBy)
                ->withProperties([
                    'payment_id' => $payment->id,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'type' => $payment->type?->value,
                    'new_total_paid' => $booking->fresh()->total_paid,
                ])
                ->log('payment_recorded');

            return $payment;
        });
    }

    /**
     * Reverse a payment via a negative contra-entry — the single canonical authority for
     * reversal (P0-6). Fixes two confirmed bugs in the two prior independent implementations
     * (PaymentService::reversePayment(), PaymentsRelationManager's reverse_payment action):
     * (1) both called into recalculation logic that crashed or duplicated arithmetic, and
     * (2) both checked $original->reversed_payment_id for idempotency — but that column is
     * only ever written on the REVERSAL row (pointing back at the original), never on the
     * original row itself (which the Payment immutability guard correctly forbids mutating),
     * so that check could never trigger and unlimited re-reversal was structurally possible.
     * The corrected check queries for a reversal row that already points at this original.
     */
    public function reversePayment(Payment $original, string $reason, User $receivedBy): Payment
    {
        if (Payment::where('reversed_payment_id', $original->id)->exists()) {
            throw new \RuntimeException('هذه الدفعة تم عكسها مسبقاً. لا يمكن عكس نفس الدفعة مرتين.');
        }

        return DB::transaction(function () use ($original, $reason, $receivedBy) {
            $booking = Booking::where('id', $original->booking_id)->lockForUpdate()->firstOrFail();

            // Re-check inside the lock: a concurrent reversal could have been created between
            // the pre-lock check above and acquiring the lock.
            if (Payment::where('reversed_payment_id', $original->id)->exists()) {
                throw new \RuntimeException('هذه الدفعة تم عكسها مسبقاً. لا يمكن عكس نفس الدفعة مرتين.');
            }

            if ($booking->total_paid < $original->amount) {
                throw new \RuntimeException('Refund Limit Exceeded: Cannot refund more than the net paid amount.');
            }

            $reversal = Payment::create([
                'tenant_id' => $original->tenant_id,
                'booking_id' => $booking->id,
                'amount' => -$original->amount,
                'currency' => $original->currency,
                'payment_method' => $original->payment_method,
                'type' => PaymentType::REVERSAL,
                'reversed_payment_id' => $original->id,
                'received_by' => $receivedBy->id,
            ]);

            // Original Payment row is never touched — no ->update(), no ->delete(). The
            // immutability guard would throw on either anyway; this method never attempts it.
            $this->recalculateTotals($booking);

            activity()
                ->performedOn($booking)
                ->causedBy(auth()->user() ?? $receivedBy)
                ->withProperties([
                    'original_payment_id' => $original->id,
                    'reversal_payment_id' => $reversal->id,
                    'amount' => $reversal->amount,
                    'currency' => $reversal->currency,
                    'reason' => $reason,
                    'new_total_paid' => $booking->fresh()->total_paid,
                ])
                ->log('payment_reversed');

            return $reversal;
        });
    }

}
