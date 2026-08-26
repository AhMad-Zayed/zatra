<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\WithFileUploads;
use App\Models\Booking;
use App\Models\Passenger;
use Livewire\Attributes\Layout;

class CustomerBookingPortal extends Component
{
    use WithFileUploads;

    public $uuid;
    public Booking $booking;
    
    public $step = 1; 
    // 1: Introduction, 2: Passengers, 3: Seats, 4: Success

    public $currentTenant;
    public $passengersData = [];
    public $requirements = [];
    
    public $availableSeats = [];
    public $selectedSeats = [];
    // null = trip has no fixed seat map (TripInstance::available_seats is null, meaning
    // unlimited capacity per TripInstance::getRemainingSeatsAttribute()'s own convention).
    // Numbered seat selection is skipped entirely in that case rather than guessed at.
    public $totalSeats = null;
    
    public $isCancelled = false;
    public $isExpired = false;

    public function mount($uuid)
    {
        $this->uuid = $uuid;
        $customerId = \Illuminate\Support\Facades\Auth::guard('customer')->id();

        $query = Booking::where('uuid', $uuid)
            ->with(['passengers.tripPassengerCategory', 'tripInstance.tripTemplate.requirementPreset', 'tenant']);
            
        if ($customerId) {
            $query->where('customer_id', $customerId);
        }
        
        $this->booking = $query->firstOrFail();
            
        $this->currentTenant = $this->booking->tenant;
        \Illuminate\Support\Facades\View::share('currentTenant', $this->currentTenant);
            
        // SECURITY GATE: Is Booking Cancelled?
        if ($this->booking->booking_status === \App\Enums\BookingStatus::Cancelled) {
            $this->isCancelled = true;
            return;
        }
        
        // SECURITY GATE: Is Trip Expired?
        if ($this->booking->tripInstance->start_date->isPast()) {
            $this->isExpired = true;
            return;
        }
            
        $preset = $this->booking->tripInstance->tripTemplate->requirementPreset;
        $this->requirements = $preset ? ($preset->items ?? []) : [];
        
        foreach($this->booking->passengers as $p) {
            $this->passengersData[$p->id] = [
                'first_name' => $p->first_name,
                'last_name' => $p->last_name,
                'date_of_birth' => $p->date_of_birth?->format('Y-m-d'),
                'document_type' => $p->document_type ?? 'passport',
                'document_number' => $p->document_number,
                'passport_file' => null,
            ];
            if (!$p->tripPassengerCategory || $p->tripPassengerCategory->requires_seat) {
                $this->selectedSeats[$p->id] = $p->seat_number;
            }
        }
        
        // Bug fix: this used to read a `seats_count` column that does not exist anywhere in
        // the schema (always null), silently falling back to a hardcoded 50-seat grid
        // completely disconnected from the trip's real capacity. `available_seats` is the
        // actual capacity column — the same field InventoryService/
        // TripInstance::getRemainingSeatsAttribute() use as their base for every other seat
        // calculation in the app. Total capacity (not the live remaining count) is the correct
        // bound for a numbered seat grid: the grid must stay a stable size so a seat number
        // already assigned to a passenger doesn't fall outside the range as the trip fills up.
        // Which specific numbered seats are taken is tracked separately below, from other
        // passengers' actual seat_number assignments — that part was already correct.
        //
        // Bus/Fleet redesign Ticket 2: portal_seat_selection_available is false once a trip has
        // 2+ buses (seat numbers are only unique within one bus, and this picker has no concept
        // of "which bus") — routes into the exact same "no numbered seat system" path already
        // used for available_seats === null, so the degraded UI/messaging is shared, not new.
        $this->totalSeats = $this->booking->tripInstance->portal_seat_selection_available
            ? $this->booking->tripInstance->available_seats
            : null;

        if ($this->totalSeats !== null) {
            $takenSeats = Passenger::whereHas('booking', fn($q) => $q->where('trip_instance_id', $this->booking->trip_instance_id))
                ->where('booking_id', '!=', $this->booking->id)
                ->whereNotNull('seat_number')
                ->pluck('seat_number')
                ->toArray();

            for ($i = 1; $i <= $this->totalSeats; $i++) {
                $this->availableSeats[$i] = !in_array((string)$i, $takenSeats);
            }
        }
    }
    
    public function nextStep()
    {
        if ($this->step === 2) {
            $this->validatePassengers();
        }
        if ($this->step === 3) {
            $this->saveAll();
        }
        $this->step++;
    }
    
    public function previousStep()
    {
        $this->step--;
    }
    
    public function selectSeat($passengerId, $seatNumber)
    {
        if (empty($this->availableSeats[$seatNumber])) return;
        
        // Remove seat from other passengers in this booking if they had it
        foreach ($this->selectedSeats as $pid => $seat) {
            if ($seat == $seatNumber && $pid != $passengerId) {
                $this->selectedSeats[$pid] = null;
            }
        }
        
        $this->selectedSeats[$passengerId] = $seatNumber;
    }

    public function validatePassengers()
    {
        $rules = [];
        $messages = [];

        foreach ($this->booking->passengers as $p) {
            $rules["passengersData.{$p->id}.first_name"] = 'required|string|max:255';
            $rules["passengersData.{$p->id}.last_name"] = 'required|string|max:255';
            $messages["passengersData.{$p->id}.first_name.required"] = 'الاسم الأول مطلوب للراكب ' . $p->passenger_label;
            $messages["passengersData.{$p->id}.last_name.required"] = 'اسم العائلة مطلوب للراكب ' . $p->passenger_label;

            // Bug fix: this used to compare literal strings ('date_of_birth', 'document_number',
            // 'passport_image') against $this->requirements, which is actually an array of
            // {name, type, is_required} objects (RequirementPreset::items) — a string can never
            // loosely-equal an array element that is itself an array, so in_array() was always
            // false and none of these rules were ever added. Now reads each item's real `type`.
            foreach ($this->requirements as $item) {
                if (!($item['is_required'] ?? false)) {
                    continue;
                }

                $type = $item['type'] ?? 'text';

                if ($type === 'date') {
                    $rules["passengersData.{$p->id}.date_of_birth"] = 'required|date|before:today';
                    $messages["passengersData.{$p->id}.date_of_birth.required"] = 'تاريخ الميلاد مطلوب';
                } elseif ($type === 'image') {
                    // If not already has media, require upload
                    if (!$p->hasMedia('identity_documents')) {
                        $rules["passengersData.{$p->id}.passport_file"] = 'required|image|max:5120';
                        $messages["passengersData.{$p->id}.passport_file.required"] = 'صورة الجواز مطلوبة';
                    }
                } else {
                    $rules["passengersData.{$p->id}.document_number"] = 'required|string';
                    $messages["passengersData.{$p->id}.document_number.required"] = 'رقم الوثيقة مطلوب';
                }
            }
        }

        $this->validate($rules, $messages);
    }

    public function saveAll()
    {
        // Prevent saving if cancelled or expired
        if ($this->isCancelled || $this->isExpired) {
            abort(403, 'Action not allowed.');
        }

        try {
            \Illuminate\Support\Facades\DB::transaction(function () {
                // Pessimistic Lock on TripInstance to prevent concurrent seat selection
                $tripInstance = \App\Models\TripInstance::where('id', $this->booking->trip_instance_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $preset = $this->booking->tripInstance->tripTemplate->requirementPreset;
                $requirementService = app(\App\Services\RequirementValidationService::class);

                // Re-fetch taken seats from DB directly to ensure we have the absolute latest state
                $takenSeats = Passenger::whereHas('booking', fn($q) => $q->where('trip_instance_id', $tripInstance->id))
                    ->where('booking_id', '!=', $this->booking->id)
                    ->whereNotNull('seat_number')
                    ->pluck('seat_number')
                    ->toArray();

                foreach ($this->booking->passengers as $p) {
                    $data = $this->passengersData[$p->id];
                    $requestedSeat = $this->selectedSeats[$p->id] ?? null;

                    // Double Verification: Is seat valid?
                    if ($requestedSeat) {
                        // Bus/Fleet redesign Ticket 2: server-side twin of the mount() gate above
                        // — a stale/tampered request submitting a seat number for a now-multi-bus
                        // trip must be rejected here too, not just hidden from the UI, since
                        // available_seats is no longer null for such a trip (it's the summed bus
                        // capacity), so the old available_seats === null check alone would have
                        // silently let this through.
                        if (!$tripInstance->portal_seat_selection_available) {
                            throw new \Exception("لا يوجد نظام تخصيص مقاعد مرقمة لهذه الرحلة.");
                        }
                        if ($requestedSeat < 1 || $requestedSeat > $tripInstance->available_seats) {
                            throw new \Exception("رقم المقعد المحدد غير صحيح.");
                        }
                        if (in_array((string)$requestedSeat, $takenSeats)) {
                            throw new \Exception("عذراً، المقعد رقم {$requestedSeat} تم حجزه للتو من قبل شخص آخر. يرجى اختيار مقعد آخر.");
                        }
                    }

                    $p->update([
                        'first_name' => $data['first_name'],
                        'last_name' => $data['last_name'],
                        'date_of_birth' => $data['date_of_birth'] ?? null,
                        'document_type' => $data['document_type'] ?? null,
                        'document_number' => $data['document_number'] ?? null,
                        'seat_number' => $requestedSeat,
                        'data_complete' => true,
                    ]);
                    
                    if (isset($data['passport_file']) && $data['passport_file']) {
                        $p->clearMediaCollection('identity_documents');
                        $p->addMedia($data['passport_file']->getRealPath())
                          ->usingName($data['passport_file']->getClientOriginalName())
                          ->usingFileName($data['passport_file']->getClientOriginalName())
                          ->toMediaCollection('identity_documents', 'private');
                    }

                    // Requirement (E): recompute and persist requirements_complete now that this
                    // passenger's data/document has just been saved — this is the automatic
                    // clearing side effect once the actual document is uploaded via this
                    // post-booking flow. Recomputed against the shared validator (not just
                    // assumed true because validatePassengers() passed) so it stays accurate
                    // even if validation rules and this check ever drift.
                    $missing = $requirementService->findMissingRequirements($preset, [[
                        'document_number' => $data['document_number'] ?? null,
                        'date_of_birth' => $data['date_of_birth'] ?? null,
                        'has_identity_document' => $p->hasMedia('identity_documents'),
                    ]]);
                    $p->update(['requirements_complete' => $requirementService->isPassengerComplete($missing, 0)]);
                }
            });
        } catch (\Exception $e) {
            $this->addError('seats', $e->getMessage());
            $this->step = 3; // return to seats page
            
            // Recalculate available seats visually
            $this->mount($this->uuid);
            return;
        }

        // Generate / Re-generate the Ticket PDF now that names and seats are confirmed
        try {
            if (class_exists(\App\Services\TicketGenerationService::class)) {
                app(\App\Services\TicketGenerationService::class)->generateAndStoreTicket($this->booking);
            }
            
            // Dispatch WhatsApp Ticket Notification
            \App\Jobs\SendAtlahubWhatsAppJob::dispatch(
                $this->booking->tenant_id,
                'ticket',
                [
                    'phone_number' => $this->booking->customer->phone,
                    'customer_name' => $this->booking->customer->name,
                    'custom_attributes' => [
                        'booking_status' => $this->booking->booking_status->value,
                    ],
                    'template_variables' => [
                        $this->booking->customer->name,
                        $this->booking->tripInstance->tripTemplate->title,
                        route('customer.ticket.download', $this->booking->uuid)
                    ]
                ]
            );
            
        } catch (\Exception $e) {
            // Silently ignore PDF generation failure so customer still sees success page
            \Illuminate\Support\Facades\Log::error('Ticket generation failed via Magic Link: ' . $e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.customer-booking-portal')
            ->layout('components.layouts.storefront', ['currentTenant' => $this->currentTenant]);
    }
}
