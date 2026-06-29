<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingPickup extends Model
{
    protected $fillable = ['booking_id', 'pickup_point_id', 'passenger_id'];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function pickupPoint()
    {
        return $this->belongsTo(PickupPoint::class);
    }

    public function passenger()
    {
        return $this->belongsTo(Passenger::class);
    }
}
