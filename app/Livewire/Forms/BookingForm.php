<?php

namespace App\Livewire\Forms;

use Livewire\Form;
use Livewire\Attributes\Validate;
use Livewire\Attributes\Locked;
use Illuminate\Validation\Rule;

class BookingForm extends Form
{
    #[Locked]
    public $trip_instance_id;
    
    public $email = '';
    public $phone = '';
    public $otp = '';

    // Array of passenger arrays: [['trip_passenger_category_id' => X, 'dynamic_data' => [...]]]
    public $passengers = [];
    
    // Array of addon arrays: [['trip_addon_id' => X, 'quantity' => Y]]
    public $addons = [];

    /**
     * Define strict validation rules ensuring the Tiers and Addons 
     * actually belong to the current TripInstance (IDOR / Cross-trip injection prevention).
     */
    public function rules()
    {
        return [
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9]{7,15}$/'],
            'otp' => ['nullable', 'string', 'size:6'],
            
            'passengers' => ['required', 'array', 'min:1'],
            'passengers.*.first_name' => ['required', 'string', 'max:255'],
            'passengers.*.last_name' => ['required', 'string', 'max:255'],
            'passengers.*.document_type' => ['nullable', 'string'],
            'passengers.*.document_number' => ['nullable', 'string'],
            'passengers.*.date_of_birth' => ['nullable', 'date', 'before:today'],
            'passengers.*.trip_passenger_category_id' => [
                // Was 'nullable' -- the passenger-category-required error message below
                // ('trip_passenger_category_id.required') already existed, confirming this was
                // always meant to be required and never actually was. A passenger with no
                // category selected passed this validation with a null value, which
                // CreateBookingService::execute() then fed straight into
                // TripPassengerCategory::where('id', null)->firstOrFail() -- ModelNotFoundException,
                // caught by CheckoutWizard's generic catch-all and shown as a raw English
                // "Something went wrong" error instead of a real, actionable validation message.
                // Live-reproduced: submitting with a passenger's category left unselected (the
                // dropdown's default "اختر النوع..." placeholder option has an empty value)
                // crashed at the final "تأكيد الحجز الآن" click with exactly this error.
                'required',
                'integer',
                Rule::exists('trip_passenger_categories', 'id')->where(function ($query) {
                    return $query->where('trip_instance_id', $this->trip_instance_id);
                })
            ],
            'passengers.*.pickup_point_id' => [
                'nullable',
                'integer',
                Rule::exists('pickup_points', 'id')
            ],
            'passengers.*.extra_preferences' => ['nullable', 'array'],

            'addons' => ['nullable', 'array'],
            'addons.*.trip_addon_id' => [
                'required',
                'integer',
                Rule::exists('trip_addons', 'id')->where(function ($query) {
                    return $query->where('trip_instance_id', $this->trip_instance_id);
                })
            ],
            'addons.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages()
    {
        return [
            'phone.required' => 'رقم الجوال مطلوب.',
            'phone.min' => 'رقم الجوال قصير جداً.',
            'phone.regex' => 'صيغة رقم الجوال غير صحيحة.',
            'otp.size' => 'رمز التحقق يجب أن يتكون من 6 أرقام.',
            'passengers.*.first_name.required' => 'الاسم الأول مطلوب.',
            'passengers.*.last_name.required' => 'اسم العائلة مطلوب.',
            'passengers.*.document_type.required' => 'نوع الوثيقة مطلوب.',
            'passengers.*.document_number.required' => 'رقم الوثيقة مطلوب.',
            'passengers.*.date_of_birth.required' => 'تاريخ الميلاد مطلوب.',
            'passengers.*.trip_passenger_category_id.required' => 'يرجى اختيار باقة المسافر.',
        ];
    }
    
    public function setTripInstanceId($id)
    {
        $this->trip_instance_id = $id;
    }
    
    public function addPassenger()
    {
        $this->passengers[] = [
            'trip_passenger_category_id' => null,
            'pickup_point_id' => null,
            'dynamic_data' => []
        ];
    }

    public function removePassenger($index)
    {
        unset($this->passengers[$index]);
        $this->passengers = array_values($this->passengers); // re-index
    }
    
    public function toggleAddon($addonId, $quantity = 1)
    {
        // Toggle logic: if exists, remove it, else add it
        $exists = false;
        foreach ($this->addons as $key => $addon) {
            if ($addon['trip_addon_id'] == $addonId) {
                unset($this->addons[$key]);
                $exists = true;
                break;
            }
        }
        
        if (!$exists) {
            $this->addons[] = [
                'trip_addon_id' => $addonId,
                'quantity' => $quantity
            ];
        }
        
        $this->addons = array_values($this->addons);
    }
}
