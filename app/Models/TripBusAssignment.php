<?php

namespace App\Models;

use App\Enums\AssignmentPersonTypeEnum;
use App\Enums\BusOwnershipTypeEnum;
use App\Exceptions\InvalidBusAssignmentException;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Get;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Bus/Fleet redesign Ticket 1 — one physical bus assigned to one trip instance. Multiple rows
 * per trip_instance_id support "open bus 2 when bus 1 fills" with no schema change: it's just
 * another row (see migration docblock for the owned/rented split reasoning).
 */
class TripBusAssignment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'trip_instance_id',
        'ownership_type',
        'vehicle_id',
        'capacity',
        'rented_supplier_name',
        'rented_plate_number',
        'driver_type',
        'driver_staff_id',
        'driver_name',
        'driver_phone',
        'guide_type',
        'guide_staff_id',
        'guide_name',
        'guide_phone',
        'sort_order',
    ];

    protected $casts = [
        'ownership_type' => BusOwnershipTypeEnum::class,
        'capacity' => 'integer',
        'driver_type' => AssignmentPersonTypeEnum::class,
        'guide_type' => AssignmentPersonTypeEnum::class,
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (auth()->check()) {
                $model->tenant_id ??= Filament::getTenant()?->id;
            }
        });

        // Defense in depth: the Filament form already makes exactly one half of each dual-mode
        // pair required/visible via ->live() conditionals, but this guarantees it at the model
        // layer too for any other write path, and auto-clears the inactive half so a type switch
        // (internal -> external or back) never leaves stale data from the previous mode behind.
        static::saving(function (self $model) {
            self::assertPersonValid($model, 'driver', 'السائق');
            self::assertPersonValid($model, 'guide', 'المرشد');

            if ($model->ownership_type === BusOwnershipTypeEnum::Owned) {
                if (!$model->vehicle_id) {
                    throw new InvalidBusAssignmentException('يجب اختيار المركبة عند اختيار "ملك الشركة".');
                }
                $model->rented_supplier_name = null;
                $model->rented_plate_number = null;
            } elseif ($model->ownership_type === BusOwnershipTypeEnum::Rented) {
                if (!$model->rented_supplier_name) {
                    throw new InvalidBusAssignmentException('يجب إدخال اسم شركة التأجير عند اختيار "مستأجرة".');
                }
                $model->vehicle_id = null;
            }
        });
    }

    private static function assertPersonValid(self $model, string $prefix, string $roleLabel): void
    {
        $type = $model->{"{$prefix}_type"};

        if ($type === AssignmentPersonTypeEnum::Internal) {
            if (!$model->{"{$prefix}_staff_id"}) {
                throw new InvalidBusAssignmentException("يجب اختيار موظف داخلي لدور {$roleLabel}.");
            }
            $model->{"{$prefix}_name"} = null;
            $model->{"{$prefix}_phone"} = null;
        } elseif ($type === AssignmentPersonTypeEnum::External) {
            if (!$model->{"{$prefix}_name"} || !$model->{"{$prefix}_phone"}) {
                throw new InvalidBusAssignmentException("يجب إدخال اسم ورقم هاتف {$roleLabel} الخارجي.");
            }
            $model->{"{$prefix}_staff_id"} = null;
        } else {
            throw new InvalidBusAssignmentException("نوع {$roleLabel} غير صالح.");
        }
    }

    /**
     * Driver and guide are structurally identical dual-mode fields (internal staff_id FK OR
     * external name+phone) — this is the single schema both roles share, parameterized by
     * column prefix ('driver'/'guide') and its display label.
     *
     * @return array<int, Forms\Components\Component>
     */
    public static function personFieldsSchema(string $prefix, string $roleLabel): array
    {
        return [
            Forms\Components\Radio::make("{$prefix}_type")
                ->label($roleLabel)
                ->options(AssignmentPersonTypeEnum::class)
                ->default(AssignmentPersonTypeEnum::Internal->value)
                ->inline()
                ->inlineLabel(false)
                ->live()
                ->required(),

            Forms\Components\Select::make("{$prefix}_staff_id")
                ->label('الموظف')
                ->options(fn () => \App\Models\User::whereHas(
                    'tenants',
                    fn ($q) => $q->where('tenants.id', Filament::getTenant()?->id)
                )->pluck('name', 'id'))
                ->searchable()
                ->visible(fn (Get $get) => $get("{$prefix}_type") === AssignmentPersonTypeEnum::Internal->value)
                ->required(fn (Get $get) => $get("{$prefix}_type") === AssignmentPersonTypeEnum::Internal->value),

            Forms\Components\TextInput::make("{$prefix}_name")
                ->label('الاسم')
                ->maxLength(255)
                ->visible(fn (Get $get) => $get("{$prefix}_type") === AssignmentPersonTypeEnum::External->value)
                ->required(fn (Get $get) => $get("{$prefix}_type") === AssignmentPersonTypeEnum::External->value),

            Forms\Components\TextInput::make("{$prefix}_phone")
                ->label('رقم الهاتف')
                ->tel()
                ->maxLength(255)
                ->visible(fn (Get $get) => $get("{$prefix}_type") === AssignmentPersonTypeEnum::External->value)
                ->required(fn (Get $get) => $get("{$prefix}_type") === AssignmentPersonTypeEnum::External->value),
        ];
    }

    public function getDriverDisplayNameAttribute(): string
    {
        return $this->driver_type === AssignmentPersonTypeEnum::Internal
            ? ($this->driverStaff?->name ?? '—')
            : ($this->driver_name ?? '—');
    }

    public function getGuideDisplayNameAttribute(): string
    {
        return $this->guide_type === AssignmentPersonTypeEnum::Internal
            ? ($this->guideStaff?->name ?? '—')
            : ($this->guide_name ?? '—');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function tripInstance(): BelongsTo
    {
        return $this->belongsTo(TripInstance::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driverStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_staff_id');
    }

    public function guideStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guide_staff_id');
    }

    // Bus/Fleet redesign Ticket 3 — passengers seated on this specific bus.
    public function passengers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Passenger::class);
    }
}
