<?php

namespace App\Filament\Resources\BookingResource\Pages;

use App\Filament\Resources\BookingResource;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use App\Enums\BookingStatus;
use Illuminate\Support\Facades\DB;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewBooking extends ViewRecord
{
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // ── إضافة مقاعد لحجز موجود ───────────────────────────────────
            Actions\Action::make('add_seats')
                ->label('+ إضافة مقاعد')
                ->icon('heroicon-o-plus-circle')
                ->color('primary')
                ->visible(fn ($record) =>
                    !in_array($record->booking_status, [\App\Enums\BookingStatus::Cancelled])
                )
                ->modalHeading(fn ($record) =>
                    "إضافة مقاعد — {$record->pnr}"
                )
                ->modalDescription(fn ($record) => implode(' · ', array_filter([
                    $record->tripInstance?->tripTemplate?->title,
                    $record->tripInstance?->start_date?->format('d M Y'),
                    $record->passengers()->count() . ' راكب حالياً',
                    ($record->tripInstance?->remaining_seats ?? 0) . ' مقعد متاح',
                ])))
                ->modalWidth('xl')
                ->form(function ($record): array {
                    $categories = \App\Models\TripPassengerCategory::where(
                        'trip_instance_id', $record->trip_instance_id
                    )->get();

                    if ($categories->isEmpty()) {
                        return [
                            \Filament\Forms\Components\Placeholder::make('no_categories')
                                ->label('')->content('لا توجد فئات تسعير لهذه الرحلة.'),
                        ];
                    }

                    $remaining = $record->tripInstance?->remaining_seats ?? 0;
                    $fields = [
                        \Filament\Forms\Components\Placeholder::make('info')
                            ->label('')
                            ->content("المقاعد المتاحة: {$remaining} مقعد  |  الركاب الحاليون: " . $record->passengers()->count()),
                    ];

                    foreach ($categories as $cat) {
                        $fields[] = \Filament\Forms\Components\TextInput::make("cat_{$cat->id}")
                            ->label("{$cat->name} — " . number_format($cat->price / 100, 0) . ' $')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->maxValue($remaining)
                            ->suffix('شخص');
                    }

                    return $fields;
                })
                ->action(function (array $data, $record): void {
                    $categories = \App\Models\TripPassengerCategory::where(
                        'trip_instance_id', $record->trip_instance_id
                    )->get()->keyBy('id');

                    $totalNewSeats  = 0;
                    $additionalAmount = 0;
                    $newPassengers  = [];

                    foreach ($data as $key => $count) {
                        if (!str_starts_with($key, 'cat_')) continue;
                        $count = (int) $count;
                        if ($count <= 0) continue;

                        $catId = (int) str_replace('cat_', '', $key);
                        $cat   = $categories->get($catId);
                        if (!$cat) continue;

                        for ($i = 0; $i < $count; $i++) {
                            $newPassengers[] = [
                                'category'    => $cat,
                                'label_index' => $record->passengers()->count() + $totalNewSeats + $i + 1,
                            ];
                            $additionalAmount += $cat->price; // cents
                        }
                        $totalNewSeats += $count;
                    }

                    if ($totalNewSeats === 0) {
                        Notification::make()->warning()->title('لم تحدد أي مقاعد')->send();
                        return;
                    }

                    $remaining = $record->tripInstance?->remaining_seats ?? 0;
                    if ($totalNewSeats > $remaining) {
                        Notification::make()->danger()
                            ->title('لا توجد مقاعد كافية')
                            ->body("المتاح: {$remaining}، المطلوب: {$totalNewSeats}")
                            ->send();
                        return;
                    }

                    DB::transaction(function () use ($record, $newPassengers, $additionalAmount, $totalNewSeats) {
                        $tenantId      = $record->tenant_id;
                        $overrideAmount = 0;

                        foreach ($newPassengers as $pInfo) {
                            $cat = $pInfo['category'];
                            \App\Models\Passenger::create([
                                'tenant_id'                  => $tenantId,
                                'booking_id'                 => $record->id,
                                'trip_passenger_category_id' => $cat->id,
                                'price_at_booking'           => $cat->price + $overrideAmount,
                                'first_name'                 => null,
                                'last_name'                  => null,
                                'data_complete'              => false,
                                'passenger_label'            => "راكب {$pInfo['label_index']} ({$cat->name})",
                                'extra_preferences'          => [],
                            ]);
                        }

                        \App\Models\InventoryLedger::create([
                            'trip_instance_id' => $record->trip_instance_id,
                            'booking_id'       => $record->id,
                            'quantity'         => -$totalNewSeats,
                            'type'             => 'confirmed',
                            'expires_at'       => null,
                        ]);

                        $newGrandTotal = $record->grand_total + ($additionalAmount / 100);
                        $newBalanceDue = $record->balance_due + ($additionalAmount / 100);

                        DB::table('bookings')->where('id', $record->id)->update([
                            'grand_total' => (int) ($newGrandTotal * 100),
                            'balance_due' => (int) ($newBalanceDue * 100),
                        ]);
                    });

                    Notification::make()
                        ->success()
                        ->title("✅ تمت إضافة {$totalNewSeats} مقاعد بنجاح")
                        ->body("الحجز الآن يضم " . $record->fresh()->passengers()->count() . " راكب")
                        ->send();

                    $this->refreshFormData(['passengers', 'grand_total', 'balance_due']);
                }),

            Actions\EditAction::make(),
            
            Actions\Action::make('copy_magic_link')
                ->label('نسخ الرابط السحري')
                ->icon('heroicon-o-link')
                ->color('info')
                ->visible(fn ($record) => $record->booking_status !== BookingStatus::Cancelled)
                ->action(function ($record) {
                    // Logic handled in frontend, we just show a copy to clipboard modal or use js
                })
                // Using Alpine.js to copy to clipboard in Filament v3
                ->extraAttributes(function ($record) {
                    $url = url("/b/{$record->uuid}");
                    return [
                        'x-data' => '',
                        'x-on:click' => "\$clipboard('{$url}'); \$tooltip('تم النسخ!')",
                    ];
                })
                ->requiresConfirmation()
                ->modalHeading('الرابط السحري للعميل')
                ->modalDescription(function ($record) {
                    return new \Illuminate\Support\HtmlString(
                        'قم بنسخ هذا الرابط وإرساله للعميل عبر الواتساب لتعبئة بيانات الركاب واختيار المقاعد:<br><br>' .
                        '<strong><a href="' . url("/b/{$record->uuid}") . '" target="_blank">' . url("/b/{$record->uuid}") . '</a></strong>'
                    );
                })
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('إغلاق'),
            
            Actions\Action::make('record_payment')
                // LABEL-005: Pure Arabic label
                ->label('تسجيل دفعة مالية')
                ->icon('heroicon-o-currency-dollar')
                ->color('success')
                ->visible(fn ($record) => $record->balance_due > 0 && $record->booking_status !== BookingStatus::Cancelled)
                ->form([
                    TextInput::make('amount')
                        // LABEL-006: Pure Arabic label
                        ->label('المبلغ')
                        ->required()
                        ->numeric()
                        ->minValue(0.01)
                        ->maxValue(fn ($record) => $record->balance_due)
                        ->prefix('SAR'),

                    Select::make('payment_method')
                        // LABEL-007: Pure Arabic label and options
                        ->label('طريقة الدفع')
                        ->required()
                        ->options([
                            'cash'          => 'نقدي',
                            'bank_transfer' => 'حوالة بنكية',
                            'e_wallet'      => 'محفظة إلكترونية',
                        ]),

                    Textarea::make('reference_note')
                        // LABEL-008: Pure Arabic label
                        ->label('الملاحظات ورقم المرجع')
                        ->nullable(),
                ])
                ->action(function (array $data, $record) {
                    DB::transaction(function () use ($data, $record) {
                        // Pessimistic lock to prevent race conditions during payment
                        $record = \App\Models\Booking::lockForUpdate()->find($record->id);

                        // Create Payment
                        $record->payments()->create([
                            'tenant_id' => $record->tenant_id,
                            'amount' => $data['amount'],
                            'payment_method' => $data['payment_method'],
                            'reference_note' => $data['reference_note'] ?? null,
                        ]);

                        // Update Ledger
                        $newTotalPaid = $record->total_paid + $data['amount'];
                        $newBalanceDue = $record->balance_due - $data['amount'];

                        $record->update([
                            'total_paid' => $newTotalPaid,
                            'balance_due' => $newBalanceDue,
                        ]);

                        // Status Transition & Ticketing
                        if ($record->balance_due <= 0) {
                            $record->update(['booking_status' => BookingStatus::Confirmed]);
                            
                            // Trigger Ticket
                            if (class_exists(\App\Services\TicketGenerationService::class)) {
                                $ticketService = app(\App\Services\TicketGenerationService::class);
                                $ticketPath = $ticketService->generateAndStoreTicket($record);
                                
                                // Dispatch Job (Assuming it exists or will be generated)
                                if (class_exists(\App\Jobs\SendBookingNotificationJob::class)) {
                                    \App\Jobs\SendBookingNotificationJob::dispatch($record, $ticketPath);
                                }
                            }
                            
                            Notification::make()->title('تم تسجيل الدفعة واكتمال الحجز')->success()->send();
                        } else {
                            Notification::make()->title('تم تسجيل الدفعة بنجاح')->success()->send();
                        }
                    });
                }),

            // ── إلغاء ركاب محددين ────────────────────────────────────────────
            Actions\Action::make('cancel_passengers')
                ->label('إلغاء مقاعد')
                ->icon('heroicon-o-user-minus')
                ->color('danger')
                ->visible(fn ($record) =>
                    !in_array($record->booking_status, [\App\Enums\BookingStatus::Cancelled])
                    && $record->passengers()->count() > 0
                )
                ->modalHeading(fn ($record) =>
                    "إلغاء مقاعد — {$record->pnr} ({$record->customer?->name})"
                )
                ->modalDescription('اختر الركاب الذين تريد إلغاء مقاعدهم. سيتم تحرير مقاعدهم وتعديل المبلغ الكلي.')
                ->modalWidth('xl')
                ->form(function ($record): array {
                    $passengers = $record->passengers()->get();

                    if ($passengers->isEmpty()) {
                        return [
                            \Filament\Forms\Components\Placeholder::make('empty')
                                ->label('')->content('لا يوجد ركاب في هذا الحجز.'),
                        ];
                    }

                    // Build checkbox options: one per passenger
                    $options = $passengers->mapWithKeys(function ($p) {
                        $name = $p->display_name ?? ($p->first_name ? trim($p->first_name . ' ' . $p->last_name) : ($p->passenger_label ?? "راكب #{$p->id}"));
                        $category = $p->tripPassengerCategory?->name ?? '';
                        $price = number_format(($p->price_at_booking ?? 0) / 100, 0);
                        $incomplete = !$p->data_complete ? ' ⚠️' : '';
                        return [$p->id => "{$name}{$incomplete} — {$category} ({$price} $)"];
                    })->toArray();

                    return [
                        \Filament\Forms\Components\CheckboxList::make('passenger_ids')
                            ->label('اختر الركاب المراد إلغاء مقاعدهم')
                            ->options($options)
                            ->required()
                            ->minItems(1)
                            ->helperText("الحجز الحالي: {$passengers->count()} راكب — اختر من تريد إلغاء مقعده"),

                        \Filament\Forms\Components\Select::make('cancellation_reason')
                            ->label('سبب الإلغاء')
                            ->options([
                                'customer_request'   => 'طلب العميل',
                                'no_show'            => 'لم يحضر (No Show)',
                                'medical'            => 'أسباب طبية',
                                'travel_issue'       => 'مشكلة سفر / وثائق',
                                'other'              => 'أخرى',
                            ])
                            ->required(),

                        \Filament\Forms\Components\Textarea::make('cancellation_note')
                            ->label('ملاحظة إضافية (اختياري)')
                            ->rows(2)
                            ->nullable(),
                    ];
                })
                ->action(function (array $data, $record): void {
                    $passengerIds = $data['passenger_ids'] ?? [];

                    if (empty($passengerIds)) {
                        Notification::make()->warning()->title('لم تختر أي راكب')->send();
                        return;
                    }

                    $passengers = \App\Models\Passenger::whereIn('id', $passengerIds)
                        ->where('booking_id', $record->id)
                        ->get();

                    if ($passengers->isEmpty()) {
                        Notification::make()->danger()->title('لم يتم العثور على الركاب')->send();
                        return;
                    }

                    $seatsToRelease = $passengers->count();
                    $amountToDeduct = $passengers->sum('price_at_booking'); // already in cents

                    DB::transaction(function () use ($record, $passengers, $seatsToRelease, $amountToDeduct, $data) {
                        // 1. Soft-delete the selected passengers
                        foreach ($passengers as $passenger) {
                            $passenger->extra_preferences = array_merge(
                                $passenger->extra_preferences ?? [],
                                [
                                    'cancelled_at'       => now()->toISOString(),
                                    'cancelled_reason'   => $data['cancellation_reason'],
                                    'cancellation_note'  => $data['cancellation_note'] ?? null,
                                    'cancelled_by_user'  => auth()->id(),
                                ]
                            );
                            $passenger->save();
                            $passenger->delete(); // soft delete
                        }

                        // 2. Release seats back to inventory ledger (+positive = seats returned)
                        \App\Models\InventoryLedger::create([
                            'trip_instance_id' => $record->trip_instance_id,
                            'booking_id'       => $record->id,
                            'quantity'         => +$seatsToRelease,
                            'type'             => 'cancellation',
                            'expires_at'       => null,
                        ]);

                        // 3. Update financial totals (bypass immutable snapshot check via raw query)
                        $currentGrandTotal = (int) DB::table('bookings')->where('id', $record->id)->value('grand_total');
                        $currentBalanceDue = (int) DB::table('bookings')->where('id', $record->id)->value('balance_due');

                        $newGrandTotal = max(0, $currentGrandTotal - $amountToDeduct);
                        $newBalanceDue = max(0, $currentBalanceDue - $amountToDeduct);

                        DB::table('bookings')->where('id', $record->id)->update([
                            'grand_total' => $newGrandTotal,
                            'balance_due' => $newBalanceDue,
                        ]);

                        // 4. If 0 passengers remain, cancel the whole booking
                        $remainingPassengers = \App\Models\Passenger::where('booking_id', $record->id)->count();
                        if ($remainingPassengers === 0) {
                            DB::table('bookings')->where('id', $record->id)->update([
                                'booking_status' => \App\Enums\BookingStatus::Cancelled->value,
                            ]);
                        }
                    });

                    $remainingCount = \App\Models\Passenger::where('booking_id', $record->id)->count();
                    $refundAmount   = number_format($amountToDeduct / 100, 0);

                    Notification::make()
                        ->success()
                        ->title("✅ تم إلغاء {$seatsToRelease} " . ($seatsToRelease === 1 ? 'مقعد' : 'مقاعد'))
                        ->body(
                            ($remainingCount > 0
                                ? "الحجز لا يزال نشطاً بـ {$remainingCount} راكب. "
                                : "⚠️ لا يوجد ركاب متبقون — الحجز أُلغي تلقائياً. ")
                            . "المبلغ المُعاد: {$refundAmount} $"
                        )
                        ->send();

                    $this->refreshFormData(['grand_total', 'balance_due', 'booking_status']);
                }),

            // ── إلغاء الحجز بالكامل ──────────────────────────────────────────
            Actions\Action::make('cancel_booking')
                ->label('إلغاء الحجز بالكامل')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('تأكيد إلغاء الحجز كلياً')
                ->visible(fn ($record) =>
                    $record->booking_status !== \App\Enums\BookingStatus::Cancelled
                )
                ->form([
                    Forms\Components\Select::make('cancellation_reason')
                        ->label('سبب الإلغاء')
                        ->options([
                            'customer_request' => 'طلب العميل',
                            'no_show'          => 'لم يحضر',
                            'medical'          => 'ظروف صحية',
                            'other'            => 'أخرى',
                        ])
                        ->required(),
                    Forms\Components\TextInput::make('cancellation_fee')
                        ->label('رسوم الإلغاء (إن وجدت)')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(fn ($record) => $record->total_paid)
                        ->default(0),
                ])
                ->action(function (array $data, Booking $record): void {
                    $passengers = $record->passengers()->get();
                    $count = $passengers->count();

                    DB::transaction(function () use ($data, $record, $passengers, $count) {
                        // 1. Soft-delete all passengers
                        foreach ($passengers as $p) {
                            $p->extra_preferences = array_merge($p->extra_preferences ?? [], [
                                'cancelled_at'     => now()->toISOString(),
                                'cancelled_reason' => $data['cancellation_reason'],
                                'cancelled_by'     => auth()->id(),
                            ]);
                            $p->save();
                            $p->delete();
                        }

                        // 2. Return seats to inventory
                        if ($count > 0) {
                            \App\Models\InventoryLedger::create([
                                'trip_instance_id' => $record->trip_instance_id,
                                'booking_id'       => $record->id,
                                'quantity'         => $count, // positive = returning seats
                                'type'             => 'cancellation',
                                'expires_at'       => null,
                            ]);
                        }

                        // 3. Update booking financial & status
                        $refundableAmount = $record->total_paid - $data['cancellation_fee'];
                        $note = $record->notes . "\n[".now()."] تم إلغاء الحجز كلياً. رسوم الإلغاء: {$data['cancellation_fee']}. المبلغ المسترد الواجب إرجاعه: {$refundableAmount}.";
                        
                        DB::table('bookings')->where('id', $record->id)->update([
                            'booking_status' => \App\Enums\BookingStatus::Cancelled->value,
                            'cancellation_requested_at' => null,
                            'notes' => trim($note),
                            'grand_total' => $data['cancellation_fee'] * 100,
                            'balance_due' => 0,
                        ]);

                        // 4. Notify waitlist
                        if (class_exists(\App\Jobs\ProcessWaitingListJob::class)) {
                            \App\Jobs\ProcessWaitingListJob::dispatch($record->tripInstance);
                        }
                    });

                    Notification::make()
                        ->title('تم إلغاء الحجز كلياً واستعادة المقاعد')
                        ->success()
                        ->send();

                    $this->refreshFormData(['grand_total', 'balance_due', 'booking_status']);
                }),

            // ── تحويل الحجز لرحلة أخرى ─────────────────────────────────────
            Actions\Action::make('transfer_booking')
                ->label('تحويل لرحلة أخرى')
                ->icon('heroicon-o-arrow-right-on-rectangle')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('تحويل الحجز إلى رحلة أخرى')
                ->modalDescription('سيتم نقل جميع الركاب للرحلة الجديدة، وإعادة حساب المبلغ الكلي بناءً على الفئات المختارة.')
                ->visible(fn ($record) =>
                    !in_array($record->booking_status, [\App\Enums\BookingStatus::Cancelled])
                )
                ->form([
                    Forms\Components\Select::make('new_trip_instance_id')
                        ->label('الرحلة الجديدة')
                        ->options(function (Booking $record) {
                            return \App\Models\TripInstance::with('tripTemplate')
                                ->where('id', '!=', $record->trip_instance_id)
                                ->where('start_date', '>=', now())
                                ->get()
                                ->mapWithKeys(function ($t) {
                                    return [$t->id => $t->tripTemplate?->title . ' — ' . $t->start_date?->format('Y-m-d') . ' (' . $t->remaining_seats . ' مقعد متاح)'];
                                });
                        })
                        ->searchable()
                        ->required()
                        ->live(),

                    Forms\Components\Group::make()
                        ->schema(function (Forms\Get $get, Booking $record) {
                            $newTripId = $get('new_trip_instance_id');
                            if (!$newTripId) return [];

                            $categories = \App\Models\TripPassengerCategory::where('trip_instance_id', $newTripId)->get();
                            if ($categories->isEmpty()) {
                                return [
                                    Forms\Components\Placeholder::make('err')->content('الرحلة المختارة ليس لها فئات تسعير. لا يمكن التحويل.')->label(''),
                                ];
                            }

                            $options = $categories->mapWithKeys(fn ($c) => [$c->id => $c->name . ' (' . number_format($c->price / 100, 0) . ' $)'])->toArray();

                            $fields = [
                                Forms\Components\Placeholder::make('lbl')
                                    ->label('')
                                    ->content('يرجى تحديد الفئة الجديدة لكل راكب:'),
                            ];

                            foreach ($record->passengers as $p) {
                                $name = $p->display_name ?? ($p->first_name ? trim($p->first_name . ' ' . $p->last_name) : ($p->passenger_label ?? "راكب #{$p->id}"));
                                $fields[] = Forms\Components\Select::make("passenger_{$p->id}_category")
                                    ->label($name)
                                    ->options($options)
                                    ->required();
                            }

                            return $fields;
                        })
                ])
                ->action(function (array $data, Booking $record): void {
                    $newTripId = $data['new_trip_instance_id'];
                    $newTrip = \App\Models\TripInstance::find($newTripId);
                    $passengers = $record->passengers()->get();
                    $count = $passengers->count();

                    if ($newTrip->remaining_seats < $count) {
                        Notification::make()->danger()->title('المقاعد المتاحة لا تكفي في الرحلة الجديدة')->send();
                        return;
                    }

                    $newCategories = \App\Models\TripPassengerCategory::where('trip_instance_id', $newTripId)->get()->keyBy('id');
                    $newGrandTotalCents = 0;

                    foreach ($passengers as $p) {
                        $catId = $data["passenger_{$p->id}_category"] ?? null;
                        if (!$catId || !$newCategories->has($catId)) {
                            Notification::make()->danger()->title('يجب اختيار فئة لكل راكب')->send();
                            return;
                        }
                        $newGrandTotalCents += $newCategories->get($catId)->price;
                    }

                    DB::transaction(function () use ($record, $newTripId, $passengers, $newCategories, $count, $newGrandTotalCents, $data) {
                        $oldTripId = $record->trip_instance_id;

                        // 1. Release old seats
                        \App\Models\InventoryLedger::create([
                            'trip_instance_id' => $oldTripId,
                            'booking_id'       => $record->id,
                            'quantity'         => $count, // positive = return
                            'type'             => 'cancellation',
                            'expires_at'       => null,
                        ]);

                        // 2. Consume new seats
                        \App\Models\InventoryLedger::create([
                            'trip_instance_id' => $newTripId,
                            'booking_id'       => $record->id,
                            'quantity'         => -$count, // negative = consume
                            'type'             => 'confirmed',
                            'expires_at'       => null,
                        ]);

                        // 3. Update passengers
                        foreach ($passengers as $p) {
                            $catId = $data["passenger_{$p->id}_category"];
                            $cat = $newCategories->get($catId);
                            $p->update([
                                'trip_passenger_category_id' => $cat->id,
                                'price_at_booking' => $cat->price,
                            ]);
                        }

                        // 4. Update booking totals
                        $gt = $newGrandTotalCents;
                        $paid = (int) DB::table('bookings')->where('id', $record->id)->value('total_paid');
                        $paidCents = $paid * 100;
                        $bd = max(0, $gt - $paidCents);

                        $note = $record->notes . "\n[".now()."] تم تحويل الحجز من الرحلة #{$oldTripId} إلى #{$newTripId}.";

                        DB::table('bookings')->where('id', $record->id)->update([
                            'trip_instance_id' => $newTripId,
                            'grand_total' => $gt,
                            'balance_due' => $bd,
                            'notes' => trim($note),
                        ]);
                    });

                    Notification::make()
                        ->title('تم تحويل الحجز بنجاح')
                        ->success()
                        ->send();

                    $this->refreshFormData(['grand_total', 'balance_due', 'trip_instance_id']);
                }),

        ];
    }
}
