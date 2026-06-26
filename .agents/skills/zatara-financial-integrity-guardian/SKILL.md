---
name: zatara-financial-integrity-guardian
description: Enforces absolute immutability of financial records, correct ledger logic, and audit trail requirements for the Zatara Tourism payment system. Apply this skill for ANY task involving payments, balances, booking status, or financial calculations.
---

# Zatara Financial Integrity Guardian — Hard Constraints

## Prime Directive
**Payments are sacred. They are an append-only ledger. They can never be changed. Ever.**

This is not a style preference — it is a legal and financial requirement. Violating this rule can cause unrecoverable accounting disasters.

---

## IMMUTABILITY — ABSOLUTE LAW

### Rule F-1: NEVER update or delete a payment record
```php
// ✅ CORRECT — corrections via reversal
class PaymentService
{
    public function reversePayment(Payment $original, string $reason): Payment
    {
        return Payment::create([
            'booking_id'  => $original->booking_id,
            'tenant_id'   => $original->tenant_id,
            'amount'      => -$original->amount,  // negative = reversal
            'type'        => PaymentType::REVERSAL,
            'reference'   => 'REV-' . $original->reference,
            'notes'       => $reason,
            'reversed_payment_id' => $original->id,
            'created_by'  => auth()->id(),
        ]);
    }
}

// ❌ FORBIDDEN — these lines must NEVER appear near a Payment model
$payment->update(['amount' => 500]);
$payment->delete();
Payment::where('booking_id', $id)->update([...]);
DB::table('payments')->where('id', $id)->delete();
```

### Rule F-2: Payment model must enforce immutability at the model level
```php
// ✅ CORRECT — built-in protection
class Payment extends Model
{
    // Payments are immutable — NO mass assignment of sensitive fields after creation
    protected $guarded = ['id', 'created_at'];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \RuntimeException(
                'FINANCIAL INTEGRITY VIOLATION: Payment records are immutable. Use a reversal entry instead.'
            );
        });

        static::deleting(function () {
            throw new \RuntimeException(
                'FINANCIAL INTEGRITY VIOLATION: Payment records cannot be deleted.'
            );
        });
    }
}
```

---

## DERIVED STATUS — NEVER HARDCODED

### Rule F-3: Booking financial status MUST be calculated, never stored
```php
// ✅ CORRECT — derived dynamically
class Booking extends Model
{
    public function getPaidAmountAttribute(): float
    {
        return $this->payments()->sum('amount'); // includes negatives from reversals
    }

    public function getRemainingAmountAttribute(): float
    {
        return $this->total_amount - $this->paid_amount;
    }

    public function getPaymentStatusAttribute(): PaymentStatus
    {
        if ($this->paid_amount <= 0) {
            return PaymentStatus::PENDING;
        }

        if ($this->paid_amount >= $this->total_amount) {
            return PaymentStatus::PAID;
        }

        return PaymentStatus::PARTIAL;
    }
}

// ❌ FORBIDDEN — storing financial status manually
$booking->update(['payment_status' => 'paid']); // NEVER
$booking->payment_status = 'partial'; // NEVER
```

### Rule F-4: Use database-level precision for money
```php
// ✅ CORRECT — decimal(12,2) for all monetary columns
$table->decimal('amount', 12, 2);
$table->decimal('total_amount', 12, 2);
$table->decimal('deposit_amount', 12, 2);

// ❌ FORBIDDEN — float causes precision errors in accounting
$table->float('amount');
$table->double('amount');
```

---

## AUDIT LOGGING — MANDATORY

### Rule F-5: Every financial action MUST be logged via Spatie Activity Log
```php
// ✅ CORRECT — always log financial actions
class PaymentService
{
    public function recordPayment(Booking $booking, array $data): Payment
    {
        $payment = Payment::create([...$data, 'booking_id' => $booking->id]);

        activity()
            ->performedOn($booking)
            ->causedBy(auth()->user())
            ->withProperties([
                'payment_id'   => $payment->id,
                'amount'       => $payment->amount,
                'type'         => $payment->type,
                'new_balance'  => $booking->fresh()->paid_amount,
            ])
            ->log('payment_recorded');

        return $payment;
    }
}

// ❌ FORBIDDEN — financial action with no audit trail
$payment = Payment::create($data); // silent = unacceptable
```

### Rule F-6: Booking status changes MUST also be logged
```php
activity()
    ->performedOn($booking)
    ->causedBy(auth()->user())
    ->withProperties([
        'old_status' => $booking->getOriginal('booking_status'),
        'new_status' => $booking->booking_status,
    ])
    ->log('booking_status_changed');
```

---

## PAYMENT TYPES — USE THE ENUM

All payments must use a defined `PaymentType` enum:
```php
enum PaymentType: string
{
    case DEPOSIT    = 'deposit';
    case INSTALLMENT = 'installment';
    case FULL       = 'full';
    case REVERSAL   = 'reversal';
    case REFUND     = 'refund';
}
```

**NEVER use raw strings** like `'type' => 'payment'` — always reference the enum.

---

## CHECKLIST before submitting any financial code
- [ ] Is there any `update()` or `delete()` on a Payment? → **STOP. Use reversal.**
- [ ] Is payment_status being set manually? → **STOP. Derive from sum().**
- [ ] Are monetary columns `decimal(12,2)`? Not float?
- [ ] Is every financial action logged via `activity()`?
- [ ] Does the Payment model have immutability protection in `booted()`?