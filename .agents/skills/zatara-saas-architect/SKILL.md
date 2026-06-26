---
name: zatara-saas-architect
description: Enforces strict multi-tenancy, service layer purity, and Laravel architecture standards for the Zatara Tourism booking system. Apply this skill for ANY task involving models, migrations, controllers, services, or application structure.
---

# Zatara SaaS Architect — Hard Constraints

## Identity
You are building a **production-grade multi-tenant SaaS** for Zatara Tourism. Every architectural decision must reflect this. No shortcuts, no prototyping patterns.

---

## TENANT ISOLATION — NON-NEGOTIABLE

### Rule T-1: Every business table MUST have `tenant_id`
```php
// ✅ CORRECT — every business migration
Schema::create('bookings', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
    // ... other columns
    $table->softDeletes();
    $table->timestamps();
});

// ❌ FORBIDDEN — missing tenant_id
Schema::create('bookings', function (Blueprint $table) {
    $table->id();
    // NO tenant_id = data leakage disaster
});
```

### Rule T-2: Business models MUST use `BelongsToTenant` or Filament's native tenancy scope
```php
// ✅ CORRECT
class Booking extends Model
{
    use SoftDeletes, HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Booking $booking) {
            if (auth()->check()) {
                $booking->tenant_id ??= Filament::getTenant()->id;
            }
        });
    }
}

// ❌ FORBIDDEN — no tenancy scope whatsoever
class Booking extends Model
{
    use SoftDeletes;
    // Missing tenant isolation = cross-tenant data leak
}
```

### Rule T-3: NEVER apply `tenant_id` to Laravel system tables
The following tables are **permanently excluded** from tenancy:
- `users`, `migrations`, `jobs`, `failed_jobs`, `cache`, `sessions`, `password_reset_tokens`, `personal_access_tokens`

### Rule T-4: Every Filament Resource MUST declare the tenant relationship
```php
// ✅ CORRECT — in every Filament Resource
public static function getEloquentQuery(): Builder
{
    return parent::getEloquentQuery()->whereBelongsTo(Filament::getTenant());
}
```

---

## SERVICE LAYER PURITY — ABSOLUTE

### Rule S-1: Controllers are pass-through only
```php
// ✅ CORRECT — Controller is a thin router
class BookingController extends Controller
{
    public function store(StoreBookingRequest $request, BookingService $service): JsonResponse
    {
        $booking = $service->createBooking($request->validated());
        return response()->json($booking, 201);
    }
}

// ❌ FORBIDDEN — business logic in Controller
class BookingController extends Controller
{
    public function store(StoreBookingRequest $request): JsonResponse
    {
        $customer = Customer::firstOrCreate(['phone' => $request->phone]);
        $trip = Trip::find($request->trip_id);
        $booking = Booking::create([...]);
        // THIS IS A VIOLATION
    }
}
```

### Rule S-2: Service classes are the ONLY place for business logic
All the following logic **must live in Service classes**, never elsewhere:
- Booking creation/cancellation
- Payment processing and reversal
- Passenger assignment
- Status recalculation
- Notification dispatch

```
app/Services/
├── BookingService.php
├── PaymentService.php
├── PassengerService.php
├── TripService.php
└── NotificationService.php
```

### Rule S-3: Filament Resources contain ZERO business logic
```php
// ✅ CORRECT — Filament calls Service
public static function afterCreate(Booking $record): void
{
    app(BookingService::class)->onBookingCreated($record);
}

// ❌ FORBIDDEN — logic inside Filament Resource
public static function afterCreate(Booking $record): void
{
    $record->update(['status' => 'confirmed']); // VIOLATION
    Mail::to($record->customer->email)->send(new BookingConfirmed($record)); // VIOLATION
}
```

---

## ARCHITECTURE RULES

### Rule A-1: NO over-engineering
The following patterns are **BANNED unless explicitly requested**:
- Repository Pattern
- DTO classes
- CQRS / Event Sourcing
- Custom Service Providers for simple bindings
- Abstract Factories

### Rule A-2: Standard Laravel conventions only
- Models in `app/Models/`
- Services in `app/Services/`
- Filament resources in `app/Filament/Resources/`
- Observers in `app/Observers/`
- No custom folder structures unless specified

### Rule A-3: SoftDeletes is mandatory on all business models
```php
// ✅ Every business model
use SoftDeletes;

// ❌ Hard deletes are FORBIDDEN on business data
$booking->forceDelete(); // NEVER
```

### Rule A-4: Consult `docs/SCHEMA.md` before ANY migration
Before writing a single migration or modifying a model, you MUST verify the column names, types, and relationships in `docs/SCHEMA.md`. If a discrepancy exists, **stop and ask** — never assume.

---

## CHECKLIST before submitting any code
- [ ] Does every new table have `tenant_id`?
- [ ] Is all business logic in a Service class?
- [ ] Is the Controller empty except for delegation?
- [ ] Does every model use SoftDeletes?
- [ ] Is no system table being given a tenant_id?