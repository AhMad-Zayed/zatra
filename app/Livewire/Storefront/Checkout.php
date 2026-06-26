<?php

namespace App\Livewire\Storefront;

use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\Booking;
use App\Enums\BookingStatus;
use App\Services\BookingService;
use App\Services\CustomerAuthService;
use Livewire\Component;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\Auth;

class Checkout extends Component
{
    use WithFileUploads;

    public TripInstance $tripInstance;
    public Tenant $tenant;

    // Contact details
    public string $phone = '';
    public string $name = '';
    public string $email = '';
    
    // OTP auth state
    public string $otp = '';
    public bool $showOtpInput = false;
    public bool $isVerified = false;
    public string $verificationError = '';
    public string $verificationSuccessMessage = '';

    // Passenger count and details
    public int $passengerCount = 1;
    public array $passengers = [];

    // Optional International Travel Extras
    public bool $needsFlight = false;
    public string $flightDetails = '';
    public bool $needsHotel = false;
    public string $hotelDetails = '';
    public bool $needsInsurance = false;
    public string $insuranceDetails = '';
    public bool $needsVisa = false;
    public string $visaDetails = '';

    // Success State
    public ?Booking $successBooking = null;

    public function mount(TripInstance $instance)
    {
        $this->tripInstance = $instance;
        $this->tenant = app(Tenant::class);

        // Pre-fill if customer is already logged in
        if (Auth::check()) {
            $user = Auth::user();
            $this->phone = $user->phone;
            $this->name = $user->name ?? '';
            $this->email = $user->email ?? '';
            $this->isVerified = true;
        }

        $this->initializePassengers();
    }

    public function initializePassengers()
    {
        $this->passengers = [];
        for ($i = 0; $i < $this->passengerCount; $i++) {
            $this->passengers[] = [
                'name' => '',
                'passport_number' => '',
                'special_requirements' => '',
                'passport_photo' => null,
                'national_id_photo' => null,
            ];
        }
    }

    public function updatedPassengerCount()
    {
        // Enforce boundary
        $this->passengerCount = max(1, min(10, $this->passengerCount));
        
        $currentCount = count($this->passengers);
        if ($this->passengerCount > $currentCount) {
            for ($i = $currentCount; $i < $this->passengerCount; $i++) {
                $this->passengers[] = [
                    'name' => '',
                    'passport_number' => '',
                    'special_requirements' => '',
                    'passport_photo' => null,
                    'national_id_photo' => null,
                ];
            }
        } else {
            $this->passengers = array_slice($this->passengers, 0, $this->passengerCount);
        }
    }

    public function sendVerificationCode()
    {
        $this->validate([
            'phone' => 'required|string|min:7',
            'name' => 'required|string|min:3',
        ]);

        if (!app()->environment('testing')) {
            $key = 'send-otp:' . $this->phone;
            if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 3)) {
                $seconds = \Illuminate\Support\Facades\RateLimiter::availableIn($key);
                $this->verificationError = "لقد تجاوزت الحد الأقصى للمحاولات. يرجى الانتظار {$seconds} ثانية.";
                return;
            }
            \Illuminate\Support\Facades\RateLimiter::hit($key, 60);
        }

        $authService = app(CustomerAuthService::class);
        
        try {
            $authService->sendOtp($this->phone);
            $this->showOtpInput = true;
            $this->verificationError = '';
            $this->verificationSuccessMessage = 'تم إرسال رمز التحقق بنجاح إلى هاتفك (تمت محاكاته في سجل النظام).';
        } catch (\Exception $e) {
            $this->verificationError = 'فشل إرسال رمز التحقق: ' . $e->getMessage();
        }
    }

    public function verifyCode()
    {
        $this->validate([
            'otp' => 'required|string|min:4',
        ]);

        if (!app()->environment('testing')) {
            $key = 'verify-otp:' . $this->phone;
            if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 3)) {
                $seconds = \Illuminate\Support\Facades\RateLimiter::availableIn($key);
                $this->verificationError = "لقد تجاوزت الحد الأقصى للمحاولات. يرجى الانتظار {$seconds} ثانية.";
                return;
            }
        }

        $authService = app(CustomerAuthService::class);

        if ($authService->verifyOtp($this->phone, $this->otp)) {
            if (!app()->environment('testing')) {
                \Illuminate\Support\Facades\RateLimiter::clear($key);
            }
            
            // Find or create customer
            $customer = $authService->findOrCreateByPhone($this->phone, $this->name, $this->tenant->id);
            
            // Login user
            Auth::login($customer);

            $this->isVerified = true;
            $this->showOtpInput = false;
            $this->verificationError = '';
            $this->verificationSuccessMessage = 'تم التحقق بنجاح وتوثيق هويتك.';
        } else {
            if (!app()->environment('testing')) {
                \Illuminate\Support\Facades\RateLimiter::hit($key, 60);
            }
            $this->verificationError = 'رمز التحقق غير صحيح. يرجى المحاولة مرة أخرى.';
        }
    }

    public function submitBooking()
    {
        // Enforce verified phone
        if (!$this->isVerified) {
            $this->verificationError = 'يرجى التحقق من رقم الهاتف أولاً.';
            return;
        }

        // Base validation rules
        $rules = [
            'passengers.*.name' => 'required|string|min:3',
            'passengers.*.passport_number' => 'required|string|min:5',
            'passengers.*.special_requirements' => 'nullable|string',
        ];

        // Image validation rules for uploads
        foreach ($this->passengers as $index => $px) {
            if (isset($px['passport_photo'])) {
                $rules["passengers.{$index}.passport_photo"] = 'image|max:5120';
            }
            if (isset($px['national_id_photo'])) {
                $rules["passengers.{$index}.national_id_photo"] = 'image|max:5120';
            }
        }

        $this->validate($rules);

        $bookingService = app(BookingService::class);

        try {
            // Check capacity
            $bookingService->ensureCapacity($this->tripInstance, $this->passengerCount);

            // Prepare passengers array
            $passengersData = [];
            foreach ($this->passengers as $px) {
                $item = [
                    'name' => $px['name'],
                    'passport_number' => $px['passport_number'],
                    'special_requirements' => $px['special_requirements'] ?? null,
                ];

                if (isset($px['passport_photo'])) {
                    // Pass the temporary file path from Livewire upload
                    $item['passport'] = $px['passport_photo']->getRealPath();
                }

                if (isset($px['national_id_photo'])) {
                    // Pass the temporary file path from Livewire upload
                    $item['national_id'] = $px['national_id_photo']->getRealPath();
                }

                $passengersData[] = $item;
            }

            // Calculate total price based on passengers and effective price
            $pricePerSeat = $this->tripInstance->price_override ?? $this->tripInstance->tripTemplate->base_price;
            $totalAmount = $pricePerSeat * $this->passengerCount;

            // Prepare additional international details
            $additionalData = [
                'flight_details' => $this->needsFlight ? $this->flightDetails : null,
                'hotel_details' => $this->needsHotel ? $this->hotelDetails : null,
                'insurance_details' => $this->needsInsurance ? $this->insuranceDetails : null,
                'visa_details' => ($this->tenant->is_visa_enabled && $this->needsVisa) ? $this->visaDetails : null,
            ];

            // Create the booking
            $this->successBooking = $bookingService->createBooking(
                $this->tripInstance,
                Auth::user(),
                $passengersData,
                $totalAmount,
                $additionalData
            );

        } catch (\Exception $e) {
            session()->flash('booking_error', 'حدث خطأ أثناء إتمام الحجز: ' . $e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.storefront.checkout')
            ->layout('layouts.storefront');
    }
}
