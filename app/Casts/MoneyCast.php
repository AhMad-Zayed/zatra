<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

class MoneyCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): float
    {
        return $value !== null ? round($value / 100, 2) : 0.00;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): int
    {
        return $value !== null ? (int) round($value * 100) : 0;
    }
}
