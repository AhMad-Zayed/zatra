<?php

namespace App\Services;

use App\Enums\WaitingListStatusEnum;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\TripInstance;
use App\Models\WaitingList;
use Illuminate\Support\Facades\DB;

/**
 * Waitlist-to-Different-Trip Manual Transfer: converts a WaitingList entry (lead-capture data
 * only -- no customer_id, no per-passenger records, no category breakdown) directly into a real
 * booking on ANY trip instance staff picks, not restricted to the trip(s) the entry was
 * originally waitlisted for. Entirely staff-initiated; no automatic matching.
 *
 * A NEW consumer of CreateBookingService, calling it exactly as every other entry point does --
 * zero modification to that service. Reuses CreateBookingService's existing phone_booking_mode
 * placeholder-passenger path (the same one PhoneBookingPage::submit() already uses), since a
 * WaitingList entry never has per-passenger identity data to begin with -- full passenger detail
 * stays deferred to the customer via CustomerBookingPortal, exactly like every phone booking
 * today.
 */
class WaitlistConversionService
{
    /**
     * @param  array<int, array{category_id: int, count: int}>  $categoryAllocation  Per-category
     *   seat counts for the destination trip -- WaitingList only stores a flat seats_requested
     *   total, never a category breakdown, since the destination trip's own category set may not
     *   even exist on the trip the entry originally waitlisted for.
     */
    public function convertToBooking(
        WaitingList $waitingList,
        TripInstance $destinationTrip,
        array $categoryAllocation,
        ?int $actingUserId = null
    ): Booking {
        if (!in_array($waitingList->status, [WaitingListStatusEnum::Pending, WaitingListStatusEnum::Notified], true)) {
            throw new \RuntimeException('لا يمكن تحويل طلب انتظار تم التعامل معه مسبقاً (محول أو منتهي الصلاحية).');
        }

        if ((int) $waitingList->tenant_id !== (int) $destinationTrip->tenant_id) {
            throw new \InvalidArgumentException('لا يمكن تحويل طلب الانتظار إلى رحلة تابعة لوكالة أخرى.');
        }

        return DB::transaction(function () use ($waitingList, $destinationTrip, $categoryAllocation, $actingUserId) {
            $waitingList = WaitingList::where('id', $waitingList->id)->lockForUpdate()->firstOrFail();

            if (!in_array($waitingList->status, [WaitingListStatusEnum::Pending, WaitingListStatusEnum::Notified], true)) {
                throw new \RuntimeException('لا يمكن تحويل طلب انتظار تم التعامل معه مسبقاً (محول أو منتهي الصلاحية).');
            }

            // Resolve/create the Customer -- WaitingList has no customer_id link, only free-text
            // lead data. Same firstOrCreate-by-phone pattern PhoneBookingPage::
            // createAndSelectCustomer() already uses for exactly this situation.
            $customer = Customer::firstOrCreate(
                ['phone' => $waitingList->phone_number, 'tenant_id' => $waitingList->tenant_id],
                ['name' => $waitingList->customer_name, 'email' => $waitingList->customer_email]
            );

            $passengersData = [];
            foreach ($categoryAllocation as $row) {
                for ($i = 0; $i < (int) $row['count']; $i++) {
                    $passengersData[] = [
                        'trip_passenger_category_id' => $row['category_id'],
                        'first_name' => null,
                        'last_name' => null,
                        'document_type' => null,
                        'document_number' => null,
                        'date_of_birth' => null,
                        'extra_preferences' => [],
                    ];
                }
            }

            $booking = app(CreateBookingService::class)->execute([
                'tenant_id' => $destinationTrip->tenant_id,
                'trip_instance_id' => $destinationTrip->id,
                'customer_id' => $customer->id,
                'user_id' => $actingUserId,
                'passengersData' => $passengersData,
                'addonsData' => [],
                'notes' => "تم التحويل من قائمة الانتظار #{$waitingList->id}.",
                'payment_type' => 'full',
                'phone_booking_mode' => true,
            ]);

            $sourceTripInstanceIds = $waitingList->tripInstances()->pluck('trip_instances.id')->all();

            // Release any stale hold this entry still holds on its ORIGINAL trip (from
            // send_link_now / WaitlistAutoPromotion) -- it's converting to a different trip now,
            // so that seat no longer needs to stay reserved for up to 2 more hours. Same
            // immutability-observer bypass ReleaseWaitlistHold itself already uses (an
            // intentional internal operation), not a call into InventoryService.
            if ($waitingList->hold_id) {
                DB::table('inventory_ledgers')
                    ->where('id', $waitingList->hold_id)
                    ->where('type', 'hold')
                    ->update(['type' => 'expired']);
            }

            // tripInstances() pivot is deliberately left untouched (NOT attaching the
            // destination trip) -- Report 4 (Waitlist & Cancellation Health) attributes
            // conversions to whichever TripTemplate(s) this entry's pivot rows already point to,
            // which is the semantically correct "of everyone who wanted THIS route, how many
            // got seated" reading. Attaching the destination trip here would also incorrectly
            // inflate the destination template's own waitlist_total for a route nobody actually
            // waitlisted for.
            $waitingList->update(['status' => WaitingListStatusEnum::Converted]);

            activity()
                ->performedOn($booking)
                ->causedBy($actingUserId ? \App\Models\User::find($actingUserId) : null)
                ->withProperties([
                    'waiting_list_id' => $waitingList->id,
                    'source_trip_instance_ids' => $sourceTripInstanceIds,
                    'destination_trip_instance_id' => $destinationTrip->id,
                ])
                ->log('booking_created_from_waitlist');

            activity()
                ->performedOn($waitingList)
                ->causedBy($actingUserId ? \App\Models\User::find($actingUserId) : null)
                ->withProperties([
                    'destination_trip_instance_id' => $destinationTrip->id,
                    'booking_id' => $booking->id,
                ])
                ->log('waitlist_converted');

            return $booking;
        });
    }
}
