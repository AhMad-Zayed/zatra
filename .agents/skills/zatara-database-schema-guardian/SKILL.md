---
name: zatara-database-schema-guardian
description: Enforces strict adherence to the Zatara Tourism database schema, migration conventions, and Eloquent model standards. Apply this skill for ANY task involving migrations, models, relationships, or database queries.
---

# Zatara Database Schema Guardian — Hard Constraints

## Prime Directive
**The schema is the source of truth. No column gets added, renamed, or removed without a migration. No model gets created without consulting the schema.**

---

## CORE SCHEMA — READ BEFORE ANY MIGRATION

Before writing any migration, verify against `docs/SCHEMA.md`. The core tables are:

```
tenants              — Travel agency accounts
users                — Staff users (admin, accountant, agent, ops, guide)
customers            — End travellers (phone-based, per-tenant)
trip_templates       — Reusable trip definitions
trip_instances       — Actual scheduled trips (derived from templates)
bookings             — Customer booking records
passengers           — Individuals on a booking
payments             — Immutable financial ledger entries
media                — Spatie MediaLibrary (passports, IDs)
activity_log         — Spatie ActivityLog (audit trail)
roles/permissions    — Spatie Permission tables
```

---

## MIGRATION STANDARDS

### Rule DB-1: Standard column order on every business table
```php
// ✅ CORRECT — always follow this order
Schema::create('bookings', function (Blueprint $table) {
    $table->id();                                                    // 1. PK
    $table->foreignId('tenant_id')->constrained('tenants');          // 2. Tenant
    $table->foreignId('customer_id')->constrained('customers');      // 3. Owner FK
    $table->foreignId('trip_instance_id')->constrained('trip_instances'); // 4. Related FKs
    $table->string('reference')->unique();                           // 5. Business fields
    $table->decimal('total_amount', 12, 2);
    $table->decimal('deposit_required', 12, 2)->default(0);
    $table->string('booking_status')->default('pending');
    $table->text('notes')->nullable();
    $table->foreignId('created_by')->nullable()->constrained('users'); // 6. Audit FKs
    $table->foreignId('updated_by')->nullable()->constrained('users');
    $table->softDeletes();                                           // 7. SoftDeletes
    $table->timestamps();                                            // 8. Timestamps last
});
```

### Rule DB-2: Every monetary column is `decimal(12,2)`
```php
// ✅ CORRECT
$table->decimal('amount', 12, 2);
$table->decimal('total_amount', 12, 2);

// ❌ FORBIDDEN
$table->float('amount');   // precision errors
$table->integer('amount'); // requires manual cents conversion — inconsistent
```

### Rule DB-3: Enums use PHP enums, stored as strings
```php
// ✅ CORRECT
$table->string('booking_status')->default(BookingStatus::PENDING->value);
$table->string('payment_type')->default(PaymentType::DEPOSIT->value);

// ❌ FORBIDDEN
$table->enum('booking_status', ['pending', 'confirmed', 'cancelled']); // MySQL enum = hard to alter
```

### Rule DB-4: Foreign keys always use `constrained()` with explicit table name
```php
// ✅ CORRECT
$table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
$table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();

// ❌ FORBIDDEN — implicit table inference is fragile
$table->foreignId('tenant_id')->constrained(); // Don't rely on convention
```

---

## ELOQUENT MODEL STANDARDS

### Rule M-1: All models must declare $fillable explicitly
```php
// ✅ CORRECT
class Booking extends Model
{
    protected $fillable = [
        'tenant_id', 'customer_id', 'trip_instance_id',
        'reference', 'total_amount', 'deposit_required',
        'booking_status', 'notes', 'created_by',
    ];
}

// ❌ FORBIDDEN
protected $guarded = []; // Mass assignment vulnerability
```

### Rule M-2: Casts must be declared for all special types
```php
// ✅ CORRECT
protected $casts = [
    'total_amount'      => 'decimal:2',
    'booking_status'    => BookingStatus::class,   // PHP Enum
    'payment_type'      => PaymentType::class,
    'departure_date'    => 'date',
    'created_at'        => 'datetime',
];
```

### Rule M-3: Relationships must be typed with return types
```php
// ✅ CORRECT
public function customer(): BelongsTo
{
    return $this->belongsTo(Customer::class);
}

public function payments(): HasMany
{
    return $this->hasMany(Payment::class);
}

public function passengers(): HasMany
{
    return $this->hasMany(Passenger::class);
}

// ❌ FORBIDDEN — no return type
public function customer() // Missing return type
{
    return $this->belongsTo(Customer::class);
}
```

### Rule M-4: Scopes for common filters — never raw where() in controllers
```php
// ✅ CORRECT — scopes on the model
public function scopeForTenant(Builder $query, int $tenantId): Builder
{
    return $query->where('tenant_id', $tenantId);
}

public function scopePending(Builder $query): Builder
{
    return $query->where('booking_status', BookingStatus::PENDING);
}

// Usage
Booking::forTenant($tenantId)->pending()->get();

// ❌ FORBIDDEN — raw filters in Service or Controller
Booking::where('tenant_id', $id)->where('booking_status', 'pending')->get();
```

---

## QUERY SAFETY

### Rule Q-1: NEVER use `DB::statement()` or raw SQL for business logic
```php
// ✅ CORRECT
$totalPaid = $booking->payments()->sum('amount');

// ❌ FORBIDDEN
DB::select("SELECT SUM(amount) FROM payments WHERE booking_id = {$id}"); // SQL injection risk + no tenant scope
```

### Rule Q-2: Always eager-load relationships to prevent N+1
```php
// ✅ CORRECT
$bookings = Booking::with(['customer', 'tripInstance', 'payments', 'passengers'])->get();

// ❌ FORBIDDEN — N+1 in disguise
foreach ($bookings as $booking) {
    echo $booking->customer->name; // N+1 query
}
```

### Rule Q-3: Paginate all list queries — never `->all()` or `->get()` on large tables
```php
// ✅ CORRECT
$bookings = Booking::with([...])->latest()->paginate(25);

// ❌ FORBIDDEN
$bookings = Booking::all(); // No pagination = memory explosion at scale
```

---

## NAMING CONVENTIONS

| Type | Convention | Example |
|------|-----------|---------|
| Tables | snake_case, plural | `trip_instances` |
| Columns | snake_case | `total_amount` |
| Foreign keys | `{table_singular}_id` | `trip_instance_id` |
| Pivot tables | alphabetical, both singular | `booking_passenger` |
| Indexes | `{table}_{column}_index` | `bookings_tenant_id_index` |
| Enums | PascalCase PHP enum | `BookingStatus::PENDING` |

---

## CHECKLIST before submitting any migration or model
- [ ] Does the table have `tenant_id` as the second column (after `id`)?
- [ ] Are monetary columns `decimal(12,2)`?
- [ ] Are status columns stored as strings (not MySQL enum)?
- [ ] Does the model declare `$fillable`?
- [ ] Are all casts declared including enums?
- [ ] Are relationships typed with return types?
- [ ] Does the migration match `docs/SCHEMA.md`?