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

    // Array of passenger arrays: [['trip_pricing_tier_id' => X, 'dynamic_data' => [...]]]
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
            'passengers.*.trip_pricing_tier_id' => [
                'required',
                'integer',
                Rule::exists('trip_pricing_tiers', 'id')->where(function ($query) {
                    return $query->where('trip_instance_id', $this->trip_instance_id);
                })
            ],
            'passengers.*.dynamic_data' => ['nullable', 'array'],

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
    
    public function setTripInstanceId($id)
    {
        $this->trip_instance_id = $id;
    }
    
    public function addPassenger()
    {
        $this->passengers[] = [
            'trip_pricing_tier_id' => null,
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
