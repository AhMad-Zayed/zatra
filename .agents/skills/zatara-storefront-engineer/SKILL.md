---
name: zatara-storefront-engineer
description: Enforces frictionless customer-facing UI, phone-based silent authentication, and RTL-first Blade/Tailwind frontend standards for Zatara Tourism. Apply this skill for ANY task involving the customer checkout, booking form, or public-facing pages.
---

# Zatara Storefront Engineer — Hard Constraints

## Core Principle
The customer experience must be **invisible friction**. No registration walls, no passwords, no multi-page forms. The customer enters their phone, books a trip, and leaves. That's it.

---

## SILENT AUTHENTICATION — PHONE-ONLY

### Rule A-1: Customers NEVER create passwords
```php
// ✅ CORRECT — find or create customer by phone silently
class CustomerAuthService
{
    public function findOrCreateByPhone(string $phone): Customer
    {
        return Customer::firstOrCreate(
            ['phone' => $this->normalizePhone($phone)],
            [
                'name'       => null, // filled later in booking form
                'tenant_id'  => $this->resolveTenant()->id,
            ]
        );
    }

    private function normalizePhone(string $phone): string
    {
        // Strip spaces, dashes, ensure +970 / +972 prefix
        return preg_replace('/[^+\d]/', '', $phone);
    }
}

// ❌ FORBIDDEN — password-based registration
Auth::attempt(['email' => $email, 'password' => $password]); // VIOLATION for customers
User::create(['password' => Hash::make($password)]); // VIOLATION for customers
```

### Rule A-2: OTP is the ONLY allowed authentication method for customers
```php
// ✅ CORRECT flow
// 1. Customer enters phone on checkout
// 2. System sends OTP via WhatsApp/SMS
// 3. Customer enters OTP → silently logged in or session token set
// 4. Booking proceeds

// OTP stored temporarily — NEVER in payments or bookings table
Cache::put("otp:{$phone}", $otp, now()->addMinutes(10));

// ❌ FORBIDDEN
// Email/password login for customers
// Social OAuth for customers
// Magic links (not approved for this stack)
```

---

## ONE-PAGE CHECKOUT — NO WIZARDS

### Rule C-1: The booking form is a single Blade view
```blade
{{-- ✅ CORRECT — single page, Alpine.js for micro-interactions only --}}
<form wire:submit.prevent="submitBooking" x-data="{ step: 'contact' }">

    {{-- Contact Section --}}
    <div x-show="step === 'contact'">
        <input type="tel" name="phone" placeholder="+970 59 XXX XXXX" dir="ltr">
    </div>

    {{-- Passenger Details — shown after phone verified --}}
    <div x-show="step === 'passengers'">
        @foreach ($passengerSlots as $i => $slot)
            <x-passenger-form :index="$i" />
        @endforeach
    </div>

    {{-- Summary & Payment --}}
    <div x-show="step === 'summary'">
        <x-booking-summary :booking="$booking" />
        <x-payment-options :booking="$booking" />
    </div>

</form>

{{-- ❌ FORBIDDEN — separate routes for each step --}}
{{-- /checkout/step-1 → /checkout/step-2 → /checkout/step-3 = VIOLATION --}}
```

### Rule C-2: Use Livewire for reactivity, NOT full-page reloads
```php
// ✅ CORRECT — Livewire component manages checkout state
class CheckoutWizard extends Component
{
    public string $phone = '';
    public string $otp = '';
    public bool $phoneVerified = false;
    public array $passengers = [];

    public function verifyOtp(): void
    {
        // validate OTP → set $this->phoneVerified = true
    }

    public function submitBooking(): void
    {
        app(BookingService::class)->createFromCheckout($this->getBookingData());
    }
}

// ❌ FORBIDDEN — page redirect on each step
return redirect('/checkout/step-2'); // VIOLATION
```

---

## FRONTEND STACK — BLADE + TAILWIND + RTL

### Rule UI-1: NO custom CSS files
```blade
{{-- ✅ CORRECT — Tailwind utilities only --}}
<div class="flex flex-col gap-4 p-6 bg-white rounded-xl shadow-sm rtl:text-right">
    <h2 class="text-xl font-bold text-gray-900">تفاصيل الحجز</h2>
</div>

{{-- ❌ FORBIDDEN --}}
<style> .booking-card { padding: 24px; } </style>  {{-- VIOLATION --}}
<link href="/css/checkout.css" rel="stylesheet">    {{-- VIOLATION --}}
```

### Rule UI-2: RTL is not an afterthought — it's the default
```blade
{{-- ✅ CORRECT — RTL-first HTML --}}
<html lang="ar" dir="rtl">
<body class="font-arabic antialiased">

{{-- RTL-aware Tailwind classes --}}
<div class="ms-4">  {{-- margin-start: respects RTL --}}
<div class="ps-6">  {{-- padding-start: respects RTL --}}

{{-- ❌ FORBIDDEN — hardcoded directional classes --}}
<div class="ml-4">  {{-- breaks in RTL --}}
<div class="pl-6">  {{-- breaks in RTL --}}
<div class="text-left"> {{-- wrong for Arabic --}}
```

### Rule UI-3: Alpine.js for micro-interactions ONLY
```blade
{{-- ✅ CORRECT — Alpine for simple toggle --}}
<div x-data="{ open: false }">
    <button @click="open = !open">تفاصيل</button>
    <div x-show="open">...</div>
</div>

{{-- ❌ FORBIDDEN — Alpine doing business logic --}}
<div x-data="{
    calculateTotal() { /* complex pricing logic */ },  // VIOLATION — this belongs in PHP
    submitPayment() { /* payment processing */ }       // VIOLATION — this belongs in Livewire
}">
```

### Rule UI-4: NO React, NO Vue, NO custom JS frameworks
The approved JavaScript stack is:
- **Alpine.js** — micro-interactions only
- **Livewire** — reactive components
- **Tailwind CSS** — styling

Any suggestion to add React, Vue, Inertia, or a JS bundler (Vite for components, not assets) is a **stack violation**.

---

## UX PRINCIPLES FOR BOOKING FORM

### Rule UX-1: Phone number is always the first field
The phone number field is the anchor of the entire experience. It must appear at the top, formatted for Arabic users (`+970` default prefix).

### Rule UX-2: Disabled states must be clear
Fields not yet reachable (e.g., passenger details before phone verification) must be visually disabled with Arabic explanatory text.

### Rule UX-3: Price is always visible
The total price summary must be sticky/fixed and visible at all times during checkout. Never hide or collapse it.

### Rule UX-4: Success state is a full-page confirmation
After booking, redirect to a confirmation page with:
- Booking reference number (large, copyable)
- WhatsApp share button
- Trip details summary
- "What happens next" instructions in Arabic

---

## CHECKLIST before submitting any frontend/auth code
- [ ] Is a customer being asked for a password? → **VIOLATION. Phone + OTP only.**
- [ ] Is there a multi-route wizard? → **Collapse to single Livewire component.**
- [ ] Is there a custom CSS file? → **Convert to Tailwind utilities.**
- [ ] Are there `ml-` or `pl-` classes? → **Replace with `ms-` and `ps-` for RTL.**
- [ ] Is Alpine.js doing business calculations? → **Move to PHP/Livewire.**
- [ ] Is React or Vue being introduced? → **VIOLATION. Remove.**