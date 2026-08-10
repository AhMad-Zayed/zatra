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
    public $totalSeats = 50;
    
    public $isCancelled = false;
    public $isExpired = false;

    public function mount($uuid)
    {
        $this->uuid = $uuid;
        $this->booking = Booking::where('uuid', $uuid)
            ->with(['passengers.tripPassengerCategory', 'tripInstance.tripTemplate.requirementPreset', 'tenant'])
            ->firstOrFail();
            
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
        
        $this->totalSeats = $this->booking->tripInstance->seats_count ?? 50;
        $takenSeats = Passenger::whereHas('booking', fn($q) => $q->where('trip_instance_id', $this->booking->trip_instance_id))
            ->where('booking_id', '!=', $this->booking->id)
            ->whereNotNull('seat_number')
            ->pluck('seat_number')
            ->toArray();
            
        for ($i = 1; $i <= $this->totalSeats; $i++) {
            $this->availableSeats[$i] = !in_array((string)$i, $takenSeats);
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
        if (!$this->availableSeats[$seatNumber]) return;
        
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
            
            if (in_array('date_of_birth', $this->requirements)) {
                $rules["passengersData.{$p->id}.date_of_birth"] = 'required|date';
                $messages["passengersData.{$p->id}.date_of_birth.required"] = 'تاريخ الميلاد مطلوب';
            }
            if (in_array('document_number', $this->requirements)) {
                $rules["passengersData.{$p->id}.document_number"] = 'required|string';
                $messages["passengersData.{$p->id}.document_number.required"] = 'رقم الوثيقة مطلوب';
            }
            if (in_array('passport_image', $this->requirements)) {
                // If not already has media, require upload
                if (!$p->hasMedia('identity_documents')) {
                    $rules["passengersData.{$p->id}.passport_file"] = 'required|image|max:5120';
                    $messages["passengersData.{$p->id}.passport_file.required"] = 'صورة الجواز مطلوبة';
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
                        if ($requestedSeat < 1 || $requestedSeat > ($tripInstance->seats_count ?? 50)) {
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
