# Admin Panel UX/Workflow Audit

**Scope:** Live "mystery shopper" walkthrough of the real staff-facing admin panel (`/admin/{tenant}`) against the real dev environment, acting as a booking agent / operations manager doing genuine daily tasks: creating a trip from scratch, taking a phone booking, handling a walk-in booking, finding a customer by partial phone/name, running the full payment lifecycle, cancelling a booking, transferring a booking, building out hotel/room and bus/seat assignments via the drag-and-drop boards, generating the manifest/rooming-list/pre-departure-readiness outputs, checking "today's departures," and comparing parallel entry points for the same task.

**This is not a visuals/colors/fonts audit.** Azure Horizon (the admin panel's brand redesign) is settled and out of scope. Everything below is about workflow complexity, duplication, unnecessary steps, and unclear UX — the bar is "would a real staff member recognize this as a professional SaaS tool," and the goal is simplification.

**Method:** Real browser automation (Puppeteer + headless Chrome) driving the actual running app and real dev database, logged in as a real seeded admin user (`admin@zatara.com`, tenant `zatara`) through the real login form. Every task below was actually performed — a real trip template, trip instance, hotel, room type, vehicle, and eleven bookings were created, paid, cancelled, and transferred for real, and two real drag-and-drop assignments (a room and a bus seat) were physically dragged with simulated mouse events and confirmed against the database, not just visually. All test data was prefixed `AUDIT TEST -` and fully deleted afterward (verified via direct DB queries — zero rows remain). No application code, view, or config file was modified.

**Test data used:** Real tenant "Zatara Tourism" (`zatara`), a fresh trip template/instance built specifically for this audit ("AUDIT TEST - Petra Day Trip," departing the same day the audit was run, to genuinely exercise the "today's departures" and "pre-departure readiness" scenarios), plus the pre-existing "Maldives Luxury Package" trip used as a transfer target.

---

## Read this first: the one that matters most

**The Pre-Departure Readiness report — the one screen built specifically to catch a passenger who isn't ready to travel — silently hides any trip departing *today* behind its own default filter.** Confirmed live: a passenger with a missing required document (passport number) on a trip departing the same day did not appear on the report at all under its default view. Clearing the date filter made the exact same passenger appear instantly. See Friction Point #1 for the precise, confirmed root cause.

This is the single screen operations staff would check on the morning of a departure to catch exactly this kind of problem — and by default, it can't.

---

## Top friction points (workflow-level, prioritized)

### 1. Pre-Departure Readiness report's default filter hides trips departing today
**Severity: Critical — this is a safety/data-correctness gap, not a style nitpick.**

Live-reproduced: a test trip departing today, with one passenger missing a required document, produces **zero rows** on `مركز التقارير › جاهزية ما قبل السفر` under its default view ("لا يوجد ركاب بانتظار إكمال المتطلبات" — no passengers pending requirements). Manually clearing both date filters makes the same passenger appear immediately, correctly flagged with the missing item ("رقم جواز السفر").

Root cause, confirmed by direct testing (`app/Filament/Clusters/ReportsCenter/Concerns/HasReportFilters.php` + `PreDepartureReadinessReport.php`): the report's default date-range filter is built from `now()->toDateString()`, but the rendered filter indicator shows a **full timestamp** (`من: 2026-08-28 21:49:30`), not a plain date. The query applies `whereDate('trip_instances.start_date', '>=', $from)` — comparing `DATE(start_date)` (e.g. `2026-08-28`) against the string `2026-08-28 21:49:30`. For a trip departing at midnight today, `'2026-08-28' >= '2026-08-28 21:49:30'` evaluates false, so today's own trips are excluded from the "from" boundary every time the report is opened at any time other than midnight. A trip departing tomorrow isn't affected (tomorrow's date is unambiguously greater than today's timestamp), which is why this specific failure mode is easy to miss in casual testing — it only bites the single most time-sensitive case, "is anyone on today's departure not ready."

**Recommendation:** Have the default filter values stay pure dates all the way through (verify the `DatePicker::default()` value isn't being upgraded to a full timestamp somewhere in the pipeline), or switch the comparison to `>= Carbon::parse($from)->startOfDay()` so a same-day trip is never excluded regardless of what time the report happens to be opened.

📷 `m10_02_predeparture_readiness.png` (empty under default filter) → `m10_03_after_clear_filters` output (same passenger correctly appears once filters are cleared).

---

### 2. Trip creation has two completely separate paths — and the better one is invisible
**Severity: Major — this is the single biggest "how many screens/clicks" question in the whole audit.**

There are two totally different ways to create a bookable trip:

- **The visible path** (dashboard "رحلة جديدة" tile, sidebar "دليل البرامج السياحية"): create a `TripTemplate` on one long form (basic info, media, pricing categories, add-ons, passenger requirements, itinerary — six sections, one screen), **then separately** create a `TripInstance` on a second resource, manually re-selecting the template, re-entering dates and seat count, and only then does pricing/add-on data get copied over. Two top-level resources, two separate "create" flows, no way to schedule more than one date at a time.
- **The hidden path** (`app/Filament/Resources/TripBuilderResource.php`): a single unified form that creates the template **and** its pricing categories, add-ons, and pickup routes **and** generates the trip instance(s) in one submission — including a **recurring-schedule mode** (pick a date range + days of the week, and it dispatches a background job to bulk-generate every matching `TripInstance` automatically). This is a meaningfully better tool for the exact "create a new trip" task the audit asked to time.

The catch: `TripBuilderResource` has `protected static bool $shouldRegisterNavigation = false;` — it is **not in the sidebar, not on the dashboard, not linked from anywhere in the UI.** A real staff member has no way to discover it exists; only someone who already knows the direct URL (`/admin/{tenant}/trip-builders/create`) could ever use it. Every real user is stuck on the slower, more repetitive visible path.

**Recommendation:** This needs a human decision, not a silent fix — but the two realistic options are (a) finish and register `TripBuilderResource` in the navigation as the primary "create a trip" entry point, retiring or clearly relabeling the separate Template/Instance flow as an "advanced/edit" path, or (b) if `TripBuilderResource` was abandoned for a reason (data-integrity concern, incomplete testing, etc.), remove it outright rather than leave a half-built, unreachable-but-fully-functional alternate trip-creation system sitting in the codebase.

📷 `m8_...` n/a — see `10_tripbuilder_create.png` (the hidden form) vs `11_triptemplate_create.png` + `12_tripinstance_create.png` (the two-resource visible path).

---

### 3. The standard "Create Booking" wizard is a 7-step commitment for the same task the phone-booking screen does in 2
**Severity: Major — real duplication with a large complexity gap, and no guidance on which to use when.**

`الحجوزات › حجز جديد` opens a **7-step wizard**: العميل (Customer) → الرحلة (Trip) → المسافرون (Passengers, one full name/document/DOB/category card per person) → الإقامة (Accommodation) → الخدمات الإضافية (Add-ons) → الدفع (Payment) → التأكيد (Confirmation).

`حجز هاتفي` (Phone Booking) — a completely separate sidebar item — does the same fundamental thing (pick a trip, pick/create a customer, take payment, produce a real booking) in **two screens**: pick trip + customer on one, then a simple quantity-stepper per passenger category with a live running total and one "احجز X مقاعد الآن" button. It deliberately does **not** collect individual passenger names — it uses the app's existing placeholder-passenger mechanism instead.

Both are real, working, production paths to the same outcome (a confirmed `Booking` record), and for the exact two scenarios the audit asked to test side-by-side (a phone call with less information available, vs. a walk-in with everything on hand), the tool genuinely offers the right shape for each — this pairing is defensible. What's missing is **any signal to the user about which one to reach for and why**, or what the tradeoff is (a phone booking's passengers start nameless and must be completed later; nothing on either entry point says so). A new staff member has no way to know this distinction exists except by trying both.

**Recommendation:** Not a call to merge these — reasonable if this is a deliberate design decision the business has already made. Worth a one-line hint on the dashboard tiles or sidebar ("للحجز السريع بالهاتف بدون بيانات الركاب الكاملة" / "for a quick phone booking without full passenger details yet") so staff self-select correctly instead of learning it by trial and error.

📷 `60_standard_booking_create_empty.png` (7-step stepper) vs `40_phone_booking_initial.png` (2-screen flow).

---

### 4. Adding a bus to a trip forces committing a driver and a guide in the same breath
**Severity: Moderate — a real operational sequencing mismatch.**

`تخصيص الحافلات › إضافة حافلة` is one form: pick the vehicle, its ownership type, **and** a required driver (internal staff or external name+phone) **and** a required guide (same). All four are mandatory before the bus can be attached to the trip at all.

In practice, fleet/vehicle assignment and driver/guide staffing are often finalized at different times — an operations manager may want to lock in "this trip needs a 30-seat bus" weeks out, long before knowing which driver is actually rostered for that date. Today there's no way to do that; the vehicle can't be added to the trip without simultaneously answering two staffing questions that may not be decided yet.

**Recommendation:** Make driver/guide optional at bus-assignment time (nullable, editable later), or split "assign a vehicle to this trip" from "assign crew to this vehicle" into two smaller, independently-completable steps.

📷 `m9_06_after_bus_added.png`

---

### 5. English relation-model names leak into the Arabic UI everywhere a relation manager renders its default state
**Severity: Moderate — small individually, systemic in total (confirmed codebase-wide, not a one-off typo).**

Confirmed directly in the running app:
- The "add a payment" modal on a booking is titled **"payment إضافة"** — half-Arabic, half-English.
- An empty payments tab reads **"لا توجد payments"**.
- The trip-instance "stay legs" tab reads **"لا توجد trip stay legs"** / **"قم بإضافة trip stay leg للبدء"**.

Checked the cause directly: **all 9 relation managers in the entire admin panel** (`PaymentsRelationManager`, `PassengersRelationManager`, `TripInstancesRelationManager`, `WaitingListsRelationManager`, `TripPassengersRelationManager`, `TripStayLegsRelationManager`, `PackageOptionsRelationManager`, `BookingsRelationManager` ×2) have **zero** `getModelLabel()`/`getPluralModelLabel()`/`$title` overrides. Filament falls back to guessing an English label from the PHP class name whenever it needs one for a modal title, an empty-state heading, or a save notification — which is why this surfaces inconsistently (some screens happen to have an explicit label elsewhere and look fine; these don't).

**Recommendation:** Add `getModelLabel()`/`getPluralModelLabel()` (or `$title`) to all 9 relation managers. This is a small, mechanical, low-risk fix, but touches every one of them — worth doing as a single dedicated pass rather than one-off patches, since the same gap is almost certainly why more instances exist than the three caught live in this pass.

📷 `96_add_payment_modal.png`, `m8_08_add_stay_leg_modal_v3.png`

---

## Quick wins (small, low-risk clarity/wording fixes)

1. **Trip-instance create form doesn't preview the categories/add-ons it's about to copy from the template.** The form correctly states "تم نسخ هذه الفئات من القالب تلقائياً" (these categories were copied automatically), but the list underneath is empty until the form is actually saved — confirmed live, with a 5-second wait, still empty. The copy genuinely does happen (confirmed correct on the edit page immediately after saving), but a staff member watching an empty list right under a claim that says "already copied" would reasonably conclude something's broken and start manually re-adding categories, risking real duplicates. Worth previewing the template's categories/add-ons live in that section, or at minimum removing "تلقائياً" language until the form actually shows them.

2. **Two dashboard tiles ("العملاء") point to the same place.** The dashboard's quick-action grid renders the "Customers" tile twice (both link to `/customers`) — a trivial layout bug, likely a copy-paste leftover.

3. **Drag-and-drop drop zones are narrower than the whole card.** Both the room-assignment and bus-assignment boards only accept a drop onto the card's specific content region (the room number sub-card, or the lower passenger-list area of a bus card) — dropping onto the card's header/driver-info area silently does nothing. Functionally fine once you know it, but the whole card visually reads as one drop target; a slightly more generous hit area (or a visual highlight on hover showing exactly where a drop will register) would remove the one moment of "did that work?" hesitation a first-time user would hit.

4. **The dashboard is dramatically more useful once there's real data, and near-empty otherwise.** On a freshly seeded/empty dev database the dashboard's revenue chart, occupancy stat, and "today's trips" panel all render essentially blank with no guidance. Not a bug — the widgets are genuinely well-designed once populated (see "What's working well") — but a brand-new tenant's first login would show a fairly bare screen with no "get started" nudge (e.g., pointing at trip creation) until real bookings exist.

5. **"طلبات الإلغاء" (cancellation requests) count badge and "طلبات بانتظار توفر مقاعد" (waitlist) are both surfaced on the dashboard, but nowhere on the booking list itself flags which specific rows they refer to at a glance without opening each one.** Minor — the counts are useful, but a staff member scanning the bookings table has no visual cue (a badge/icon on the row) for "this one has a pending cancellation request" without cross-referencing.

---

## Duplication map

Every place this audit found more than one way to accomplish the same task, and whether it's justified.

| Task | Path A | Path B | Path C | Verdict |
|---|---|---|---|---|
| **Create a bookable trip** | Visible: `TripTemplate` create → `TripInstance` create (2 resources, no recurring scheduling) | Hidden: `TripBuilderResource` (1 form, includes recurring bulk-scheduling) — **not in navigation, unreachable without the direct URL** | — | **Accidental drift / unfinished work.** The better tool exists and works but is invisible. Needs a human decision (finish + register it, or delete it) — see Friction Point #2. |
| **Create a booking** | `حجز هاتفي` (Phone Booking): 2 screens, quantity-only passengers | `الحجوزات › حجز جديد`: 7-step wizard, named passengers, accommodation, add-ons, payment | — | **Likely intentional**, genuinely fits two different real scenarios (phone call vs. walk-in with full info) — but zero in-UI guidance on which to pick. See Friction Point #3. |
| **Find a customer/booking by phone or name** | Bookings list search | Customers list search | Global top-nav search (⌘K-style) | **All three work correctly** for both name and partial phone number — verified live with a fresh substring search on each surface. Genuinely consistent, no gap found here (an earlier pass of this same audit had flagged the Bookings-list search as failing on partial phone; re-verified carefully with a controlled, freshly-authenticated test and that did **not** reproduce — the original observation was a test-methodology false negative, not a real defect, and is retracted here). |
| **Record a payment against a booking** | Booking edit page → "Payments" tab → add-payment modal (booking pre-selected) | `المدفوعات › حجز جديد` (Payments resource, standalone): search any booking by PNR, same fields | — | **Intentional and consistent** — same fields, same behavior, genuinely useful as a shortcut when a cashier already has a receipt/PNR in hand rather than needing to look the booking up first. No issue. |
| **See a trip's bookings** | Trip-instance edit page's own "الحجوزات" tab (that one trip only) | `عرض الحجوزات حسب الرحلة` (Bookings by Trip): an aggregate rollup across **every** trip, one row per trip with totals | — | **Correctly complementary, not duplicative.** One is a detail view, the other is a cross-trip index. No issue. |

---

## What's already working well

Worth calling out so the friction points above are read in proportion — a real amount of this admin panel is genuinely solid, professional-SaaS-grade work:

- **Drag-and-drop room assignment** (`تخصيص الغرف`) is excellent: numbered room cards with live occupancy counters, a clean unassigned-passengers rail, real drag-and-drop that works correctly (verified with a real simulated mouse drag, confirmed against the database — not just visually), plus a one-click **"تخصيص تلقائي"** (auto-assign) button for when a manual drag isn't needed.
- **Drag-and-drop bus/seat assignment** is the same quality — same board pattern, same auto-assign shortcut, and correctly re-used across both hotel rooms and bus seats rather than two different UI paradigms for what's conceptually the same "assign a passenger to a slot" task.
- **The "today's departures" dashboard widget** is genuinely built for the exact 7:30am-ops-person scenario this audit asked about: today's trips, live occupancy bar, a **payment-pending warning banner** ("2 حجز قيد الدفع (اضغط للمراجعة)"), and a one-click "طباعة كشف الركاب" manifest button, all without leaving the dashboard.
- **Cancellation correctly flows into a distinct refund-liability state.** Confirmed live: cancelling a previously-paid booking transitions `payment_status` to `refund_pending` (not just "unpaid"), computes a clear negative balance reflecting money owed back to the customer, releases the held inventory, and fires a real customer notification (confirmed via the WhatsApp-send log entry). It's also correctly idempotent and requires a reason before it will proceed.
- **Booking transfer thoughtfully re-asks for passenger categories on the destination trip** rather than blindly copying prices across — the modal reactively reveals a "select the new category for each passenger" step once a destination trip is chosen, since the two trips' pricing tiers may not match up 1:1.
- **Manifest and rooming-list PDFs both generate correctly and quickly** (confirmed 200 / `application/pdf` responses for both) — the Browsershot dependency issue from earlier in this project's history is holding up fine under real use.
- **The quick phone-booking screen is a genuinely well-designed, purpose-built tool** for its scenario — two screens, a live running total as quantities change, and it gets a real booking created fast.
- **The passenger-category price auto-fill on the standard booking wizard works correctly** on a real user interaction — an earlier concern from automated testing (the price field staying empty after selecting a category) turned out to be a test-script quirk, not a real bug; confirmed by re-testing with a proper interaction event.

---

## Test scenarios run (for reference)

1. **Trip creation, both paths:** built "AUDIT TEST - Petra Day Trip" via the visible Template→Instance path (confirmed categories/add-ons correctly copy server-side despite not previewing on the create form); separately opened and inspected the hidden `TripBuilderResource` form directly by URL.
2. **Phone booking:** a family of 4 (2 adult, 2 child) booked through `حجز هاتفي` end-to-end, including creating a new customer inline, through to a confirmed booking and a recorded payment.
3. **Standard walk-in booking:** driven through all 7 wizard steps with named passengers, room selection, and payment.
4. **Customer/booking search:** verified partial-phone and name search on the Bookings list, the Customers list, and the global top-search — all three correctly find matches.
5. **Payment lifecycle:** recorded a partial payment, then the remaining balance, on a real booking; confirmed status transitions correctly at each step.
6. **Cancellation:** cancelled a fully-paid real booking with a proper reason selected; confirmed `booking_status → cancelled`, `payment_status → refund_pending`, inventory released, customer notified.
7. **Transfer:** opened the transfer modal on a real booking, selected a destination trip, and confirmed the reactive per-passenger category-remap step correctly appears once a destination is chosen.
8. **Hotel + room type + drag-and-drop room assignment:** created a real hotel, a stay leg, a hotel option, and a room type with 3 room instances on the test trip; dragged a real passenger onto a specific room via simulated mouse events; confirmed the `RoomAssignment` row in the database.
9. **Bus + drag-and-drop seat assignment:** created a real vehicle, attached it to the test trip with an external driver and guide, dragged a real passenger onto the bus; confirmed `passengers.trip_bus_assignment_id`/`seat_number` in the database.
10. **Manifest / rooming list / pre-departure readiness:** downloaded the passenger manifest PDF and the hotel-option rooming-list PDF (both confirmed `200`/`application/pdf`); ran the Pre-Departure Readiness report against a passenger with a missing document on a trip departing that same day — this is where Friction Point #1 was found and confirmed.
11. **Today's departures:** confirmed the dashboard's "رحلات اليوم" widget correctly surfaces the same-day test trip with occupancy, a payment-pending warning, and a manifest button.
12. **Entry-point comparison:** compared trip creation (2 paths), booking creation (2 paths), customer/booking lookup (3 paths), and payment recording (2 paths) — see the Duplication Map above.

---

*This report is investigation and documentation only — no fixes have been implemented, and no application code was modified. All test data created during this audit (one trip template/instance, one hotel, one room type, one vehicle, six customers, eleven bookings, and their passengers/payments/assignments) was deleted afterward and verified clean against the database. All findings above wait for stakeholder review before any code changes are made.*
