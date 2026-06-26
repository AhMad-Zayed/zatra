---
name: zatara-booking-domain-expert
description: Enforces correct business logic for trip management (templates vs instances), booking lifecycle, passenger management, and booking status calculation for Zatara Tourism. Apply this skill for ANY task involving trips, bookings, passengers, or booking workflows.
---

# Zatara Booking Domain Expert — Hard Constraints

## Domain Model — Understand This Before Touching Trips or Bookings

```
TripTemplate (القالب)
    └── defines: name, description, destinations, base_price, duration
    └── has many: TripInstances

TripInstance (الرحلة الفعلية)
    └── inherits from: TripTemplate
    └── defines: departure_date, return_date, max_capacity, price_override
    └── has many: Bookings

Booking (الحجز)
    └── belongs to: Customer + TripInstance
    └── has many: Passengers (actual travellers)
    └── has many: Payments (immutable ledger)
    └── status is DERIVED from payments, never stored
```

**Never conflate TripTemplate with TripInstance.** They serve different purposes.

---

## TRIP MANAGEMENT

### Rule TR-1: Never create a Booking directly on a TripTemplate
```php
// ✅ CORRECT — bookings attach to instances only
class BookingService
{
    public function createBooking(TripInstance $instance, Customer $customer, array $data): Booking
    {
        $this->ensureCapacity($instance);
        // ...
    }
}

// ❌ FORBIDDEN
Booking::create(['trip_template_id' => $templateId]); // Wrong — must be instance
```

### Rule TR-2: Capacity is enforced at booking time
```php
// ✅ CORRECT
private function ensureCapacity(TripInstance $instance): void
{
    $confirmedCount = $instance->bookings()
        ->whereNotIn('booking_status', [BookingStatus::CANCELLED])
        ->sum('passenger_count'); // count from passengers, not bookings

    if ($confirmedCount >= $instance->max_capacity) {
        throw new TripFullException("Trip instance {$instance->id} is at capacity.");
    }
}
```

### Rule TR-3: Price comes from TripInstance, falling back to TripTemplate
```php
// ✅ CORRECT
public function getEffectivePriceAttribute(): float
{
    return $this->price_override ?? $this->tripTemplate->base_price;
}
```

---

## BOOKING LIFECYCLE

### Rule BK-1: Booking statuses are strictly defined
```php
enum BookingStatus: string
{
    case PENDING    = 'pending';      // Created, no payment yet
    case PARTIAL    = 'partial';      // Deposit paid, installments pending
    case PAID       = 'paid';         // Fully paid
    case CONFIRMED  = 'confirmed';    // Ops confirmed (seat assigned, docs checked)
    case CANCELLED  = 'cancelled';    // Cancelled (with reversal payments if refund)
    case COMPLETED  = 'completed';    // Trip completed
}
```

### Rule BK-2: Financial status auto-updates via Observer after every payment
```php
// ✅ CORRECT — Observer recalculates after payment created
class PaymentObserver
{
    public function created(Payment $payment): void
    {
        app(BookingService::class)->recalculateFinancialStatus($payment->booking);
    }
}

// BookingService
public function recalculateFinancialStatus(Booking $booking): void
{
    $paid = $booking->payments()->sum('amount');
    $total = $booking->total_amount;

    // Derive status — NEVER hardcode
    $newStatus = match(true) {
        $paid <= 0           => BookingStatus::PENDING,
        $paid >= $total      => BookingStatus::PAID,
        default              => BookingStatus::PARTIAL,
    };

    // Only update if changed — prevent unnecessary observer loops
    if ($booking->payment_status !== $newStatus) {
        // Use DB::table to bypass the model observer (prevent loop)
        DB::table('bookings')
            ->where('id', $booking->id)
            ->update(['payment_status' => $newStatus->value]);

        activity()->performedOn($booking)
            ->withProperties(['old' => $booking->payment_status, 'new' => $newStatus])
            ->log('payment_status_recalculated');
    }
}

// ❌ FORBIDDEN — manual status setting
$booking->update(['payment_status' => 'paid']); // VIOLATION
```

### Rule BK-3: Booking reference is auto-generated and unique per tenant
```php
// ✅ CORRECT
private function generateReference(Tenant $tenant): string
{
    $prefix = strtoupper(substr($tenant->slug, 0, 3));
    $year   = now()->format('y');
    $seq    = str_pad(
        Booking::where('tenant_id', $tenant->id)->count() + 1,
        5, '0', STR_PAD_LEFT
    );
    return "{$prefix}-{$year}-{$seq}"; // e.g., ZAT-25-00042
}
```

---

## PASSENGER MANAGEMENT

### Rule PX-1: Passengers are separate entities from Customers
- `Customer` = the person who made the booking (has phone, is authenticated)
- `Passenger` = an individual traveller on the booking (may be the customer + others)

```php
// ✅ CORRECT model structure
class Booking extends Model
{
    public function customer(): BelongsTo  // the booker
    {
        return $this->belongsTo(Customer::class);
    }

    public function passengers(): HasMany  // all travellers including potentially the customer
    {
        return $this->hasMany(Passenger::class);
    }
}
```

### Rule PX-2: Document upload for passengers goes via Spatie MediaLibrary ONLY
```php
// ✅ CORRECT
$passenger->addMediaFromRequest('passport')->toMediaCollection('passport');
$passenger->addMediaFromRequest('national_id')->toMediaCollection('national_id');

// ❌ FORBIDDEN
$path = $request->file('passport')->store('passports'); // VIOLATION
$passenger->update(['passport_path' => $path]); // VIOLATION
```

### Rule PX-3: Passenger count on the booking derives from the passengers table
```php
// ✅ CORRECT — derived, not stored
public function getPassengerCountAttribute(): int
{
    return $this->passengers()->count();
}

// ❌ FORBIDDEN — stored integer that can go out of sync
$booking->passenger_count = 3; // VIOLATION
```

---

## BOOKING CREATION FLOW — COMPLETE REFERENCE

```
1. Customer enters phone → CustomerAuthService::findOrCreateByPhone()
2. OTP verification → CustomerAuthService::verifyOtp()
3. Select TripInstance → TripService::getAvailableInstances()
4. Capacity check → BookingService::ensureCapacity()
5. Create Booking record (status: pending) → BookingService::createBooking()
6. Add Passengers → PassengerService::attachPassengers()
7. Upload documents → via Spatie MediaLibrary
8. Record initial payment (deposit or full) → PaymentService::recordPayment()
9. Observer fires → BookingService::recalculateFinancialStatus()
10. Notify customer → BookingConfirmed notification via WhatsAppChannel
```

Every step is a separate Service method. **Never combine steps in a Controller.**

---

## CANCELLATION FLOW

```
1. Cancellation request received (agent or customer)
2. BookingService::cancelBooking() called
3. If refund required → PaymentService::reversePayment() (negative entry)
4. Booking status set to CANCELLED (this is OK — it's not a financial status)
5. TripInstance capacity is freed
6. Audit log entry created
7. Customer notified
```

**The only booking status that can be set directly (not derived) is: CONFIRMED, CANCELLED, COMPLETED.**
Financial statuses (PENDING, PARTIAL, PAID) are always derived from payments.

---

## CHECKLIST before submitting any booking/trip code
- [ ] Is the booking attached to a TripInstance (not TripTemplate)?
- [ ] Is capacity being checked before booking creation?
- [ ] Is payment_status being derived from payments, not hardcoded?
- [ ] Are passengers separate from the customer?
- [ ] Are document uploads going through Spatie MediaLibrary?
- [ ] Is every step of the booking flow in a Service method?
- [ ] Is the booking reference auto-generated with tenant prefix?