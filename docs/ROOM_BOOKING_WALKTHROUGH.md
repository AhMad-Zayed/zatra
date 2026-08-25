# Room Booking Feature — Manual Walkthrough (Ticket 1 + Ticket 2)

This is a hands-on path to actually experience what was built: the Hotel/Rooming admin catalog (Ticket 1) and the room-type selection + inventory consumption at checkout (Ticket 2). No code changes are involved — this is a click-through guide.

**Before you start:**
- You need an admin login with the `agency_admin` role for one tenant you're happy to use as a test tenant.
- The room-booking feature is **off by default for every tenant** (the kill switch). Step 1 turns it on for your one test tenant only — every other tenant is completely unaffected.
- This whole walkthrough happens on your local/staging environment, never production data.

---

## Step 1 — Turn the feature on for your test tenant

There's no admin-UI toggle for this yet (that's intentionally out of scope for now — it's a safety switch, not a customer-facing setting). Turn it on via `tinker`:

```
php artisan tinker
```

First, find your tenant's slug if you don't already know it:

```php
\App\Models\Tenant::all(['id', 'name', 'slug']);
```

Then enable the switch for that one tenant:

```php
$tenant = \App\Models\Tenant::where('slug', 'YOUR-TENANT-SLUG')->firstOrFail();
$tenant->settings = array_merge($tenant->settings ?? [], ['room_booking_enabled' => true]);
$tenant->save();
$tenant->refresh()->settings; // should show ['room_booking_enabled' => true]
```

Keep this tinker session or the tenant slug handy — you'll use it again at the end to turn it back off if you want.

---

## Step 2 — Build one realistic test trip in the admin panel

This walks through Ticket 1's actual screens, not a seeder — worth doing manually since you haven't clicked through those screens yet.

**2a. Create a Hotel** (reusable master record, not tied to any trip):
- Admin panel → **اللوجستيات → الفنادق** (Hotels)
- New → Name (e.g. "فندق البحر الأحمر"), City ("العقبة"), Star rating (5), toggle نشط on → Create

**2b. Create (or reuse) a Trip Template:**
- **إدارة الرحلات → دليل البرامج السياحية**
- If creating new: title, base price, currency, and **at least one row under "فئات التسعير"** (a pricing tier — e.g. "بالغ", price 100). This matters: without it, the checkout step later has no passenger category to select.

**2c. Create a Trip Instance for that template:**
- **إدارة الرحلات → الرحلات المجدولة → New**
- Select the template from 2b, set **future** start/end dates, available seats (e.g. 10), status = **نشط (Active)**
- Confirm "فئات التسعير الخاصة بهذا الموعد" shows at least one row (it should auto-copy from the template) → Create

**2d. Add a Leg + Hotel Option + Room Types to that instance:**
- Open the Trip Instance you just created → tab **مراحل الإقامة (Legs)**
- New leg: sequence 1, label optional (e.g. "الإقامة"), start/end dates matching the trip
- Inside that leg's form, under **"خيارات الفنادق لهذه المرحلة"** → add one option: select the Hotel from 2a, label "الخيار القياسي", meal plan "إفطار وعشاء"
- Inside that hotel option, under **"أنواع الغرف لهذا الخيار"**, add two room types:

| Field | Room type 1 | Room type 2 |
|---|---|---|
| Name | غرفة مزدوجة (Double) | غرفة ثلاثية (Triple) |
| Capacity per room | 2 | 3 |
| Room count | 5 | 3 |
| Price/person (shared) | 40 | 30 |
| Single-supplement | 25 | 45 |

- Save the leg.

You now have a bookable trip with real room inventory (5 double rooms, 3 triple rooms).

---

## Step 3 — Book it as a customer would, on the storefront

1. Open a normal or private browser window (no admin login needed — this is the public site): `http://YOUR-APP-URL/{tenant-slug}`
2. Find your trip on the catalog and click into it.
3. Click **"بدء إجراءات الحجز"** (start booking).
4. **Step 1 (your details):** first/last name, email, phone → continue.
5. **Step 2 (passengers):** confirm the passenger category and any requested document fields → continue.
6. **Step 3 (addons):** scroll down — you should now see a new section, **"اختر الغرف (اختياري)"**, listing the room types you configured (only visible because the switch is on *and* the trip has room types — turn either off and this section disappears, see Step 5).
   - Set the quantity for "غرفة مزدوجة" to 1, leave occupancy on "مشاركة" (shared).
   - Watch the total at the top of the page update to include the room charge.
   - Try switching that room's occupancy dropdown to "فردي" (single) — the price should jump to reflect the single-supplement formula (per-person shared rate + flat supplement) instead.
   - Set it back to whichever you want to actually book, then continue.
7. **Step 4 (payment):** choose "نقداً" (cash) and "دفع كامل" (full) → submit.
8. You land on the Booking Success page — it now shows a **"الغرف المحجوزة"** (rooms booked) section alongside the trip details.

---

## Step 4 — Verify it in the admin panel

1. **العمليات اليومية → الحجوزات (Bookings)** → find your new booking by customer name/phone or PNR → open it. `الإجمالي` (grand total) should equal passenger price + the room charge you saw at checkout.

2. **Known gap, so you don't go looking for something that isn't there yet:** there's no dedicated admin screen listing a booking's room selection directly — that display is deferred, follow-up work. Right now, the two reliable ways to see it are:
   - Re-open the booking-success page from Step 3 (its URL is `/{tenant-slug}/booking/success/{booking-uuid}` — the uuid is visible on the booking's admin view page) — it shows the customer-facing summary.
   - Or check directly via tinker:
     ```php
     $booking = \App\Models\Booking::where('pnr', 'YOUR-PNR')->first();
     $booking->roomSelections; // room_type_id, quantity, occupancy_type, price_at_booking

     $roomType = \App\Models\RoomType::find($booking->roomSelections->first()->room_type_id);
     $remaining = $roomType->room_count + \App\Models\RoomInventoryLedger::where('room_type_id', $roomType->id)->sum('quantity');
     echo "Remaining {$roomType->name}: {$remaining}"; // should be room_count minus what you booked
     ```

---

## Optional — see the safety mechanisms with your own eyes

**Kill switch, live:** repeat Step 1 with `'room_booking_enabled' => false`, reload the checkout page from Step 3 — the room section disappears entirely, and the booking still completes normally without rooms.

**Oversell protection:** in tinker, set `room_count` on a RoomType down to match how many you've already booked (`$roomType->update(['room_count' => 1]);` if you've already consumed 1), then try to book one more of that type through checkout. It should fail cleanly with a clear "لا توجد غرف كافية" message and the booking should not be created at all — nothing partially saved.

**Cancellation release:** cancel the booking from the admin panel (البحث عن الحجز → إلغاء), then re-run the `$remaining` check above — the room count should be fully restored.

---

## Cleanup

Whenever you're done testing, turn the switch back off for the tenant (Step 1 with `false`) — this instantly returns that tenant to exactly the pre-Ticket-2 experience, with zero code changes.
