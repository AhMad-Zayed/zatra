# Customer Storefront UX/UI Audit

**Scope:** Live "mystery shopper" walkthrough of the real customer-facing storefront (zatara.test/zatara equivalent) against the real dev environment — catalog browsing, trip details, full checkout (two scenarios), booking confirmation, returning-customer login, "My Bookings," and deliberate customer-mistake testing (back button, refresh, invalid input, abandon/return).

**Method:** Real browser automation (Puppeteer + headless Chrome) driving the actual running app and real dev database, as a first-time visitor would experience it. Nothing below is inferred from code alone — everything is either a live-reproduced observation (screenshotted) or a source-cited root cause for something observed live. No app code, config, or data was modified as part of this audit; two real bookings were created and left in place as the live evidence trail; temporary inventory holds created by abandoned test checkouts were expired via the same mechanism the app already uses for that purpose (no schema/model/logic changes).

**Test data used:** Real tenant "Zatara Tourism" (`zatara`), real trip "Maldives Luxury Package" (the only trip currently live and bookable on the storefront — see note under Quick Wins about the second trip, "العقبة," which has no pricing configured and doesn't appear in the catalog at all).

---

## Read this first: the one that matters most

**Typing anything into the homepage search bar crashes the page.** This is not a style nitpick — it's the first interactive element a first-time visitor sees, directly under the hero headline, and it is broken for every visitor, every time. See Friction Point #1.

---

## Top friction points (most likely to lose a real customer)

### 1. The homepage search bar crashes the page the moment you use it
**Severity: Critical.** A first-time visitor's very first instinct — search for a destination — throws a fatal PHP error.

Confirmed live: typing a single character into the "الوجهة" (Destination) field, or anything into the "تاريخ السفر" (Travel Date) field, sends a request that returns **HTTP 500**. With debug mode on (as in this dev environment) the visitor sees a raw developer stack trace instead of the page:

> `BadMethodCallException: Call to undefined method App\Models\TripTemplate::tripTemplate()`

Root cause (`app/Livewire/StorefrontCatalog.php:33-34`): the catalog query starts from `TripTemplate::where(...)`, but the destination filter calls `->whereHas('tripTemplate', ...)` — a relationship that would only make sense starting from `TripInstance` (which belongs to a trip template), not from `TripTemplate` itself. The very next line has the same problem for dates: `->whereDate('start_date', ...)` is called directly on the `TripTemplate` query, but `start_date` lives on `TripInstance`, not `TripTemplate` (confirmed: `trip_templates` has no `start_date` column). Both filters were written against the wrong base model — this reads like a leftover from when the catalog query was refactored from `TripInstance`-based to `TripTemplate`-based, without updating these two clauses.

In production (debug off) this wouldn't show a stack trace, but the search would still be completely non-functional — the visitor gets a spinner or a blank failure with no results and no explanation either way.

**Recommendation:** Fix the two filter closures to match the actual base model (`TripTemplate::where('title', 'like', ...)` directly for destination; `whereHas('tripInstances', fn($q) => $q->where('start_date', '>=', ...))` for date). This is a two-line fix, but it blocks the entire top-of-funnel search experience today.

📷 `docs/design-reference/ux-audit-screenshots/02-search-crash-error-page.png`

---

### 2. The trip price shown everywhere before checkout is "0 دولار" (0 dollars)
**Severity: Critical — trust and conversion.**

Both the catalog card and the trip details page (including the sticky "احجز مقعدك الآن" booking widget) show **"0 دولار"** as the price for "Maldives Luxury Package" — a trip that actually costs $5,000/passenger. The real price only appears for the first time on Checkout Step 2, after the visitor has already entered their name, email, and phone.

Root cause (`resources/views/livewire/storefront-catalog.blade.php:191` and `resources/views/livewire/trip-details.blade.php:55`): both read `$template->base_price ?? 0`. `base_price` is a separate field on `TripTemplate` that is `0` for this trip, because this trip's actual pricing lives entirely in `TripPassengerCategory.price` (5,000). The storefront's price display was written for a pricing model this trip doesn't use.

A real customer sees "starting from $0," which either looks like an obvious error (undermining trust in a "luxury" product) or like the trip might be free — both are bad. Nobody currently promoting or sharing this trip's page is showing the real price.

**Recommendation:** Have the price teaser fall back to (or derive from) the trip's actual minimum `TripPassengerCategory.price` when `base_price` is 0/unset, so a real number always shows before the customer commits to entering personal details.

📷 `docs/design-reference/ux-audit-screenshots/01-catalog-zero-price-dead-links.png`, `03-trip-details-zero-price.png`

---

### 3. A refresh mid-checkout silently wipes all typed passenger data
**Severity: Critical for multi-passenger bookings.**

Live-reproduced: at Step 2 (Passenger Details), after adding a second passenger and typing their name, a hard refresh (F5) leaves the visitor on the correct step with their 15-minute seat hold still ticking — but **the entire passenger list resets to one blank passenger**, including the customer's own name that had auto-filled from Step 1. No warning, no "your changes weren't saved" message, nothing recoverable.

This is worst exactly where it hurts most: a family booking with several passengers is the slowest, most effort-intensive step to fill in, and it's the one most likely to be interrupted (a phone auto-rotating, a browser gesture, force of habit hitting F5 to "check something"). The countdown timer is completely unaffected by the refresh, so the customer loses time on the clock *and* the work they already did.

**Recommendation:** At minimum, persist in-progress passenger entries against the guest session server-side (or in `localStorage`) so a refresh can restore them, matching what already happens for the countdown/hold itself. Short of that, a visible warning ("لا تقم بتحديث الصفحة أثناء التعبئة" / "don't refresh while filling this in") would at least prevent the silent loss.

📷 `docs/design-reference/ux-audit-screenshots/05a-before-refresh-data-typed.png` → `05b-after-refresh-data-lost.png`

---

### 4. Online payment is shown as an option but is permanently disabled — every real booking today requires an offline follow-up step
**Severity: Major — conversion.**

Step 4 offers three payment methods. "الدفع الإلكتروني" (credit card / mada / Apple Pay) is visibly the first, most familiar option — but it's marked "قريباً" (Coming Soon), its radio input carries a hard `disabled` attribute, and the code also hard-blocks it server-side (`app/Livewire/CheckoutWizard.php:404-406`: `abort(403, 'Stripe payments are currently disabled.')`). The only two working options are "الدفع نقداً" (pay in person at the office, within 24 hours) or "حوالة بنكية" (manual bank transfer).

This means **every single online booking today requires the customer to leave the digital flow and take a real-world action** — visit an office or manually arrange a bank transfer — before their seat is actually secured. For a customer who expected to "buy" a trip online in one sitting, this is a hard stop at the final step, not a minor inconvenience.

**Recommendation:** This is flagged for stakeholder awareness, not as a code fix — it's presumably a deliberate/temporary business decision (payment gateway not yet integrated). Worth confirming the timeline for enabling it, since it directly caps what fraction of interested customers can actually complete a purchase unattended.

📷 `docs/design-reference/ux-audit-screenshots/06-payment-methods-online-disabled.png`

---

### 5. Room prices are invisible at the moment of choice — the customer picks blind
**Severity: Major — transparency.**

At Step 3, room selection ("اختر الغرف") shows only a room name, a quantity stepper, and a "shared/single" occupancy toggle — **no price anywhere in this section**, for either option. Confirmed live: selecting 1 triple room (shared) added exactly $90 to the total, but that $90 is only visible after advancing to Step 4's payment summary. Worse, at Step 4 the $90 room charge is silently folded into a line literally labeled **"الركاب (3)" (Passengers (3))**, which is supposed to represent the three $5,000 passenger fees, not room costs too — so even after reaching Step 4, a customer can't see what the room actually cost them.

**Recommendation:** Show each room type's per-room price (shared vs. single) directly in the Step 3 selector, and break the Step 4 order summary into separate "Passengers" and "Rooms" lines rather than combining them under the passengers label.

📷 `docs/design-reference/ux-audit-screenshots/04-room-selection-no-pricing.png`

---

### 6. The currency label is wrong on the page customers will return to most: "My Bookings"
**Severity: Major — trust, and it's recurring, not one-time.**

Live-reproduced with a real booking: the booking confirmation page correctly shows **"15,090.00 USD"** (it reads the booking's real `currency` field). Minutes later, the same booking on "My Bookings" — the page a customer will check repeatedly to track what they owe — shows **"SAR 15,090.00"** for Total, Paid, and Remaining. Same amount, same booking, two different currencies shown in two different places.

Root cause (`resources/views/livewire/storefront/my-bookings.blade.php:109,113,118`): "SAR" is a hardcoded string, unlike the booking-success page's `{{ $booking->currency ?? 'USD' }}` at `resources/views/livewire/booking-success.blade.php:29`, which does this correctly. The checkout wizard has the same pattern in reverse (hardcoded "$"/"دولار" for passenger totals, hardcoded "SAR" for add-ons — see `resources/views/livewire/checkout-wizard.blade.php:235,301,398,402,421,426`); this wasn't independently reproducible live only because this specific trip has no add-ons configured to show the mismatch side-by-side, but the same hardcoding is confirmed in the code and will surface for any USD/SAR-mixed trip, or any trip not priced in USD (e.g., the "العقبة" trip is configured in ILS).

**Recommendation:** Replace every hardcoded currency label in the storefront (`my-bookings.blade.php` and `checkout-wizard.blade.php`) with the booking's/trip's actual `currency` field, the way `booking-success.blade.php` already does it correctly.

📷 `docs/design-reference/ux-audit-screenshots/07a-my-bookings-shows-SAR.png` vs `07b-booking-success-shows-USD.png`

---

### 7. The site tells the customer two different things about whether their seat is confirmed
**Severity: Moderate-Major — confusion at the exact moment of commitment.**

At Step 4, right before the "تأكيد الحجز الآن" button, the disclaimer reads: *"سيتم تأكيد المقاعد فور الدفع بنجاح"* — "Seats will be confirmed as soon as payment succeeds" (`checkout-wizard.blade.php:518`). For a cash-at-office booking, no payment happens at that moment at all. The very next screen — booking success — says the opposite: *"طلبك قيد الانتظار حالياً"* — "Your request is currently pending" (`booking-success.blade.php:11`).

A customer who reads the Step 4 disclaimer literally may believe clicking "Confirm Booking" with cash selected locks in their seat immediately; the success page then tells them it's actually just pending. This isn't a functional bug (the backend correctly treats it as pending), it's a wording contradiction between two screens shown seconds apart.

**Recommendation:** Make the Step 4 disclaimer conditional on payment method — e.g. for cash/transfer: "سيتم تأكيد المقاعد بعد استلام الدفع" (seats confirm once payment is *received*, not immediately).

---

## Quick wins (small, low-risk clarity/wording fixes)

1. **Validation error leaks a raw internal field name.** Submitting an invalid phone number shows: *"صيغة form.phone غير صحيحة"* — literally "the format of `form.phone` is invalid," not a human label. (`app/Livewire/CheckoutWizard.php:280` — no custom validation attribute name defined.) Add `'form.phone' => 'رقم الجوال'` to the validator's attribute names.

2. **The malformed-email error is in English, on an all-Arabic page.** Typing `not-an-email` and submitting shows the browser's native tooltip: *"Please include an '@' in the email address..."* — the one moment a customer needs help is the one message not localized. Consider a pre-emptive Arabic-localized check (or a `pattern`/custom message) so this doesn't fall through to the browser's native, English-only validation.

3. **The site logo is missing everywhere** (`/images/logo.png` 404s on every page — `resources/views/components/layouts/storefront.blade.php:45`). A text fallback (`$currentTenant->name`) is coded to appear on image error, but in practice the header renders as a blank box, not the tenant name — worth a quick manual check of why the fallback isn't visibly kicking in, in addition to simply adding the logo file.

4. **Three header nav links go nowhere.** "وجهاتنا" (Our Destinations), "عن زتارة" (About Zatara), and "اتصل بنا" (Contact Us) are all literal `href="#"` (`storefront.blade.php:59-61` and `123-129`, both desktop and mobile nav). Either wire them to real pages/anchors or remove them — a customer clicking "Contact Us" and getting nothing is a small but real trust ding.

5. **The "guests" field in the search bar does nothing.** Unlike destination/date (which are wired but crash — see Friction Point #1), the "الضيوف (2 بالغين)" field has no `wire:model` at all (`storefront-catalog.blade.php:74`). Once the crash above is fixed, this field should either do something or be removed so it doesn't imply a capability that isn't there.

6. **The countdown timer briefly flashes "0:00" on arrival at Step 2**, before self-correcting to the real ~15:00 within about a second (Alpine's timer state initializes at 0 before its first tick). Momentarily alarming ("did I lose my hold?") but not a functional bug — initialize `minutes`/`seconds` synchronously from the real expiry on `x-init` instead of waiting for the first `setInterval` tick.

7. **"Ticket Locked" has no explanation.** On "My Bookings," an unpaid booking shows a disabled "تذكرة مقفلة" (Ticket Locked) button with a lock icon and no supporting text (`storefront/my-bookings.blade.php:145-149`). A one-line note ("ستتوفر التذكرة بعد إتمام الدفع" / ticket unlocks after payment) would remove the ambiguity.

8. **Content gaps visible to real customers, not code bugs, but worth a look:** the trip title and description are in English ("Maldives Luxury Package" / "A luxurious 5-night stay...") on an otherwise fully-Arabic page; the itinerary section says "لم يتم إضافة مسار تفصيلي لهذه الرحلة بعد" (no itinerary added yet); the FAQ section says "لا توجد أسئلة شائعة حالياً" (no FAQs yet); no trip has a real photo (every card/detail page shows a placeholder icon). *Caveat: trip-image 404s specifically traced back to this dev machine's `public/storage` symlink pointing at a stale, renamed project folder — that part may be a local environment artifact, not a production issue, and should be re-checked against staging/production before treating it as a real gap.* The missing English→Arabic content and empty itinerary/FAQ sections are unrelated to that symlink and are genuinely what a customer sees today.

9. **No child/infant fare tier exists for this trip** — the only passenger category is "بالغ (Adult)," so a family booking (as tested) pays full adult price for every passenger regardless of age. This surfaced directly from testing the "family booking" scenario; it's a pricing/product setup question for the business side, not a code defect — flagged for awareness, not as something to implement.

---

## What's already working well

Worth calling out so the friction points above are read in proportion:

- **Booking confirmation screen** is genuinely well done: clear reference number, QR code, itemized passenger list, room summary when applicable, a visible payment-expiry countdown, one-tap WhatsApp contact, and a downloadable PDF receipt. Both test bookings (simple 1-passenger and 3-passenger family) rendered this correctly.
- **Guest checkout** (no forced account creation) plus a fast **OTP-based login** for returning customers is a good, low-friction pattern already in place.
- **Refreshing mid-flow does not lose the seat hold or kick the customer back to Step 1** — the countdown and step position both survive correctly; only the in-progress form fields are lost (Friction Point #3).
- **"My Bookings"** is clean and functional: status badge, self-service cancellation request, and a locked/unlocked ticket-download state tied correctly to payment status.

---

## Test scenarios run (for reference)

1. **Simple booking:** 1 adult passenger, no room, cash payment → completed successfully (`ZTR-ZJS4MN`, $5,000).
2. **Family booking:** 3 passengers, 1 triple room (shared), bank transfer → completed successfully (`ZTR-VJCV7Q`, $15,090).
3. **Returning customer:** OTP login (phone-based, local dev bypass code) → landed correctly on "My Bookings" showing the family booking above.
4. **Mistake testing:** invalid email (native browser block, English tooltip), invalid phone (Arabic error, but leaks raw field name), realistic back-button navigation (browser correctly returns to trip details, no data corruption), hard refresh at Step 1 (correctly resumes at Step 2, hold intact) and at Step 2 (resumes at Step 2, but passenger data lost — Friction Point #3), homepage search interaction (crashes — Friction Point #1), and direct navigation to a trip with no pricing configured (loads without crashing, but would let a customer complete a $0 booking with no fare tier to select — low real-world likelihood since this trip isn't linked from anywhere in the catalog).

---

*This report is investigation and documentation only — no fixes have been implemented. All findings above wait for stakeholder review before any code changes are made.*
