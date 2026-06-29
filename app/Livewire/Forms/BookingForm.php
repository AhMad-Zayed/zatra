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
            'phone' => ['required', 'string', 'min:9', 'max:20'],
            'otp' => ['nullable', 'string', 'size:6'],
            
            'passengers' => ['required', 'array', 'min:1'],
            'passengers.*.first_name' => ['required', 'string', 'max:255'],
            'passengers.*.last_name' => ['required', 'string', 'max:255'],
            'passengers.*.document_type' => ['required', 'string'],
            'passengers.*.document_number' => ['required', 'string'],
            'passengers.*.date_of_birth' => ['required', 'date'],
            'passengers.*.trip_passenger_category_id' => [
                'required',
                'integer',
                Rule::exists('trip_passenger_categories', 'id')->where(function ($query) {
                    return $query->where('trip_instance_id', $this->trip_instance_id);
                })
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
