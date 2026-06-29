<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PickupPoint extends Model
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory, \Illuminate\Database\Eloquent\SoftDeletes;

    protected $fillable = ['pickup_route_id', 'name', 'address', 'pickup_time', 'order'];

    public function pickupRoute()
    {
        return $this->belongsTo(PickupRoute::class);
    }
}
