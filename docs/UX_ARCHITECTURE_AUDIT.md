# Zatara — Product/UX/Architecture Discovery Audit

**Scope:** Investigation only. No files were modified as part of this audit.
**Method:** Direct code reading (schema, models, Filament resources, Livewire components, services, jobs) cross-referenced with two background research passes that independently enumerated every booking-creation code path and every duplicated/dead-code pattern in the app. Findings from the prior `zatara_audit_report.md` forensic audit were individually re-verified against current code rather than assumed still valid.

**Severity/effort key:** 🔴 Quick fix (hours) · 🟠 Medium refactor (days) · 🔵 Major redesign (a planned initiative, not a patch)

---

## Executive summary

**The "feels unstable" complaint is accurate, and it has one root cause repeated in different clothes: this codebase has gone through several rounds of "build the new right way" without ever finishing the migration off the old way.** Trip creation has a hidden-but-live wizard sitting next to the "real" split-resource flow. Booking creation has a dead legacy service sitting next to the canonical one — and the dead one is the one named `BookingService`, which is exactly the name a future maintainer would trust. The customer portal has three independent "my bookings" screens from three different build phases. None of this is because the underlying engineering is bad — the core transactional logic (`CreateBookingService`, `BookingService::cancelBooking/transferBooking/recordPayment`) is genuinely well-built: locked, idempotent, activity-logged. The instability comes from *how many roads lead to the same place*, each slightly different, none of them deleted.

Two things are **not** design or duplication problems — they're features that were never built, described using business language (domestic/international, hotel rooming, bus assignment) that doesn't exist anywhere in the data model. Those need a product decision before any code gets written, not a bug fix.

**Top items worth fixing regardless of what gets redesigned later** (all 🔴 quick fixes, all independent of any bigger decision):
1. `CustomerBookingPortal`'s seat picker checks a database column (`seats_count`) that doesn't exist, and silently falls back to a fake hardcoded 50-seat grid — a live, customer-facing bug (5.1 #1).
2. `RevenueChart` sums payments across all currencies with no `GROUP BY currency` — actively wrong the moment a tenant runs more than one currency, not just incomplete (4.1).
3. `reopen_cancelled` flips a booking back to Pending with zero inventory re-consumption — a real, easy overbooking path (5.1 #3).
4. Delete `Livewire\Storefront\Checkout.php` and `BookingService::createBooking()` — both are confirmed dead, both are unsafe (no locking), both are exactly the kind of landmine that caused the historical audit's original oversell bugs (Section 2.1, 5.2).
5. `ProcessGatewayPaymentService` (the live online-payment webhook path) skips currency validation and `grand_total` recomputation that every other payment path enforces (5.1 #5–7).

Everything else in this document is either a **medium refactor** (consolidate 2-4 duplicate implementations of one behavior down to one) or a **major redesign decision** (trip type, rooming, bus assignment, cost/margin tracking) that's worth discussing before scoping work.

---

## SECTION 1 — Trip creation flow mapping

### 1.0 The core finding: "domestic vs. international" is not a system concept — it doesn't exist anywhere in the code

A repo-wide search for `international`, `domestic`, `خارجية`, `داخلية`, `trip_type`, and any "type" column on `trip_templates`/`trip_instances` found **zero hits** in the data model. `TripTemplate` and `TripInstance` have no type/category field at all (confirmed by reading both models' `$fillable` arrays in full — see `app/Models/TripTemplate.php`, `app/Models/TripInstance.php`).

The only place a domestic/international-like distinction can exist today is `RequirementPreset` (`app/Models/RequirementPreset.php`) — a tenant-defined, freely-named JSON list of document requirements (e.g. an admin could name one preset "دولية" and give it a passport-required item, and another "محلية" with just an ID number). This is **pure admin convention, not a system rule** — nothing in the code branches on trip type, validates that a preset matches a trip's real nature, or prevents mismatches (a "domestic" trip could have the "international" preset's requirements attached, or none at all).

**Why this is a problem:** the owner's complaint ("confusing domestic vs. international trip creation") isn't describing a bad implementation of that distinction — it's describing the *absence* of one. Every trip is created through the exact same generic form regardless of whether it will be marketed as a local day-trip or an international tour package. Any behavioral differences staff currently rely on (which documents to collect, whether flights/hotels/visas apply) exist only in the admin's head plus whichever `RequirementPreset` they remembered to attach.
**Severity/effort:** 🔵 Major redesign — this needs a real product decision (should trip type be a first-class field with validation rules?), not a patch.

### 1.1 Trip creation is implemented as (at least) four independent, only-partially-overlapping flows

| # | Flow | Where | Currently used? | Notably missing/different |
|---|------|-------|------------------|---------------------------|
| A | **`TripBuilderResource`** — single-page wizard (info → pricing/addons → schedule → publish) | `app/Filament/Resources/TripBuilderResource.php` (225 lines) + `Pages/CreateTripBuilder.php` | Hidden from nav (`$shouldRegisterNavigation = false`, comment: *"we are using the new split resources"*) but **still fully routed and reachable** at its direct URL — Filament auto-discovers every class in `Resources/` (`discoverResources()` in `AdminPanelProvider.php:42`) regardless of nav visibility. | **Has no `currency` field at all.** Includes pickup-route selection and single/recurring date scheduling that the "new" flow doesn't offer in one place. |
| B | **`TripTemplateResource`** — template-only CRUD (info, currency, media, pricing tiers, addons, requirements) | `app/Filament/Resources/TripTemplateResource.php` | Yes, primary nav entry ("دليل البرامج السياحية") | No scheduling/instance creation here at all — must go to the relation manager (C) or the separate resource (D) afterward. Currency dropdown hardcoded to `USD`/`ILS` only. |
| C | **`TripInstancesRelationManager`** on the template's edit page — manual single-instance `CreateAction` + a separate **`bulk_schedule`** action with its own inline recurring-date generator | `app/Filament/Resources/TripTemplateResource/RelationManagers/TripInstancesRelationManager.php` | Yes | Neither the manual create form nor `bulk_schedule` sets `currency` on the new instance — relies on the DB column default (`'USD'`), **regardless of the template's actual currency**. `bulk_schedule` re-implements template→instance tier/addon copying inline, a third copy of logic that also exists in (A)'s job and, effectively, in (D)'s create form. |
| D | **`TripInstanceResource`** — standalone instance CRUD, reachable independently of any template's page | `app/Filament/Resources/TripInstanceResource.php` | Yes, primary nav entry ("الرحلات المجدولة") | This is the only one of the four that both has a `currency` field **and** auto-inherits it from the selected template (`afterStateUpdated` sets `currency` from `$template->currency`, line 89) — i.e. the *correct* behavior exists, but only here, not in (A) or (C). |
| — | **`clone_trip` row action** on (D)'s table | `TripInstanceResource.php:351-397` | Yes | A fifth, quasi-creation path: replicates an instance + tiers + addons + pickup routes (currency is fine here since `replicate()` copies the whole row), but does **not** clone `packageOptions`. |

**Concretely duplicated logic, found in three separate places doing the same thing slightly differently:**
- Template→instance passenger-category/addon copying: `app/Jobs/BulkGenerateTripInstances.php:65-81`, `TripInstancesRelationManager.php:307-323` (`bulk_schedule` action), and `TripInstanceResource.php:72-91` (form `afterStateUpdated`, achieves the same end via form state rather than direct DB writes).
- `BulkGenerateTripInstances` (used by flow A) additionally **hardcodes `end_date = start_date`** for every generated instance (`"Assuming 1-day trip for bulk generation"`, line 60) — multi-day international trips scheduled through this flow would all get a wrong (1-day) end date, silently, unless someone remembers to edit each one afterward.

**Severity/effort:**
- 🔴 Quick fix: delete or fully unpublish `TripBuilderResource` (flow A) now that flow B+C+D is the intended path — it's a live, reachable landmine that will corrupt currency on any template it's used to create. Also set `currency` explicitly in `TripInstancesRelationManager`'s manual-create and `bulk_schedule` paths.
- 🟠 Medium refactor: consolidate the three tier/addon-copying implementations into one method on `TripTemplate` (e.g. `$template->instantiateOn($date, $seats)`), called by all three surviving entry points.
- 🔵 Major redesign: decide on ONE trip-creation flow (wizard vs. split-resource) rather than maintaining both indefinitely; the current "hidden but alive" state of flow A is exactly the kind of half-migrated state that produces "feels unstable" reports.

### 1.2 Hotel/Rooming: there is no rooming-list feature — only a flat "package tier" per trip instance

There is no `hotels`, `rooms`, or `rooming_list` table anywhere in `database/migrations/`. The entire "hotel" concept is `PackageOption` (`app/Models/PackageOption.php`, migration `2026_06_30_000326_create_package_options_table.php`): one flat record per trip instance with `hotel_name`, `stars`, `room_type` (a single free-text/tenant-defined string, not a room inventory), `meal_plan`, `price_adjustment`, and an optional `available_seats` cap.

This means:
- There is **no per-passenger room assignment** (who shares a room with whom), no room-type inventory (e.g. "3 double rooms left, 0 triples left" tracked separately from overall trip seats), and no concept of a trip using more than one hotel.
- "Partial availability" *is* implemented, but only at the whole-package level: `PackageOption::getRemainingSeatsAttribute()` (`app/Models/PackageOption.php:38-52`) counts booked passengers against the package's own `available_seats` cap, capped again by the trip's overall `remaining_seats`. So a single-hotel, single-package trip's availability logic works; anything resembling a real rooming list (multi-room, multi-hotel, per-passenger assignment) simply isn't there.
- **The exact same field set is implemented twice in the UI**: once as a nested `packageOptions` repeater embedded inside `TripInstancesRelationManager`'s form (`TripInstancesRelationManager.php:47-202`), and again as a full standalone `PackageOptionsRelationManager` on `TripInstanceResource` (237 lines, `app/Filament/Resources/TripInstanceResource/RelationManagers/PackageOptionsRelationManager.php`) — two separate screens editing the same underlying `PackageOption` records with near-identical forms (including two independent copies of the "manage room types"/"manage meal plans" tenant-settings hint actions).

**Severity/effort:** 🔵 Major redesign if the business actually needs real rooming-list functionality (per-passenger room assignment, multi-hotel trips, room-type-level inventory) — this is not a bug to patch, the data model doesn't support it. 🔴 Quick fix in the meantime: remove one of the two duplicate PackageOption-editing UIs (keep the standalone `PackageOptionsRelationManager`, drop the embedded repeater, or vice versa) to stop staff from wondering which screen is "the real one."

### 1.3 Bus/Transport: there is no bus/vehicle/fleet concept at all

`TripInstance` has exactly one capacity field: `available_seats` (a plain integer). There is no `buses`, `vehicles`, or `transport` table, no bus-type/capacity model, and no code anywhere that assigns a trip to one or more buses. The only transport-adjacent models are `PickupRoute`/`PickupPoint` (`app/Models/PickupRoute.php`, `app/Models/PickupPoint.php`) — a named route with an ordered list of local pickup addresses/times, clearly built for shuttle-to-meetup-point logistics on (presumably domestic) day trips, not for inter-city coach/fleet assignment.

Consequently "multi-bus overflow" (Section 3's example scenario) isn't partially implemented or broken — **it has no data model to be implemented on.** A trip with 100 people across 2 buses is, today, indistinguishable in the system from a trip with 100 people and one very large bus: it's just `available_seats = 100`.

**Severity/effort:** 🔵 Major redesign — needs a product decision on whether the business actually operates multi-bus trips often enough to warrant a `Vehicle`/`TripVehicle` model with its own capacity, or whether `available_seats` is genuinely sufficient and the "bus" language is just how staff talk about capacity informally. Not a code defect; a scoping question.

### 1.4 Positive findings (for balance)
- `TripInstanceResource`'s `cancel_trip` action correctly delegates to `app(\App\Services\TripService::class)->cancelTrip()` rather than reimplementing cancellation inline.
- The `bulk_status_change` bulk action on the same resource **deliberately excludes** the `Cancelled` status from its options, with a comment explaining exactly why (cancellation needs the cascade that a bare status bulk-update doesn't do) — a rare example in this codebase of the team explicitly guarding against the duplication-drift pattern instead of falling into it.
- Currency inheritance and immutability *is* correctly implemented in the `TripInstanceResource` form and at the model level (`TripTemplate`/`TripInstance` both throw on currency change once instances/bookings exist) — the problem is that this correct pattern isn't applied consistently across all trip-creation entry points (see 1.1).

---

## SECTION 2 — Booking flow mapping ("more than one way to book")

Confirmed via full-repo enumeration of every `Booking::create`, `InventoryLedger::create`, and service-call site across Filament, Livewire, Controllers, Jobs, and Console Commands.

### 2.1 Live booking-creation paths (all converge on one canonical service — this is the good news)

**Four live entry points**, all calling `App\Services\CreateBookingService::execute()`:
1. **Customer self-checkout** — `App\Livewire\CheckoutWizard::submitBooking()`, route `storefront.checkout`.
2. **Admin manual "Create Booking"** — `BookingResource\Pages\CreateBooking::handleRecordCreation()`.
3. **Admin "Quick Booking"** — `App\Filament\Pages\QuickBookingPage::submitBooking()`.
4. **Admin "Phone Booking"** — `App\Filament\Pages\PhoneBookingPage::submit()` (creates placeholder passengers, `phone_booking_mode: true`).

`CreateBookingService` is genuinely robust: `lockForUpdate()` on the `TripInstance` (and any `TripAddon`/`PackageOption` rows touched) inside a DB transaction, delegating all ledger writes to `InventoryService::consumeForBooking()`, which re-verifies live availability before writing. This is a **single, well-centralized canonical path** — a real architectural strength, not a weakness, and worth knowing before assuming everything here is broken.

**One dead, unsafe path still physically present:** `App\Services\BookingService::createBooking()` (legacy method, not to be confused with the very-much-alive `BookingService::cancelBooking()`/`recordPayment()`/etc. described in 2.2) is only called from `App\Livewire\Storefront\Checkout`, which is imported in `routes/web.php` but **never actually wired to a route** and not referenced by any Blade `<livewire:>` tag. Confirmed unreachable. It remains unsafe (no locking, whereHas-count capacity check) — a landmine if anyone ever re-wires a route to it or copies it as a template.
**Severity/effort:** 🔴 Quick fix — delete `App\Livewire\Storefront\Checkout.php` and `BookingService::createBooking()` outright; they cannot be reached and their continued presence risks someone reintroducing the exact oversell bug the rest of the system was hardened against.

### 2.2 Where the four live paths diverge — concrete, confirmed inconsistencies

- **Currency bug inside the canonical service itself:** `Booking.currency` is set from the *trip instance's* currency, but the optional initial `Payment.currency` (settable only from the admin "Create Booking" form's `initial_payment_amount`/`initial_payment_method` fields) is set from the *tenant's default* currency instead — and this specific write bypasses `BookingService::recordPayment()`'s currency-match guard (which throws everywhere else in the app when a payment's currency doesn't match its booking's). **Concrete failure case:** an admin books a trip priced in ILS for a tenant whose default currency is USD, enters an initial cash payment on the same form → the resulting `Payment` record is silently labeled USD on an ILS booking. This exact path is unavailable to customer self-checkout (CheckoutWizard never sends `initial_payment_amount`), so it's an admin-only, admin-panel-specific bug.
  **Severity/effort:** 🔴 Quick fix — set `Payment.currency` from `$tripInstance->currency`, matching `Booking.currency`, and route the initial payment through `recordPayment()` instead of a raw `Payment::create()`.
- **Admin "Create Booking" can set `booking_status` directly**, overriding whatever the service just derived from payment/deposit logic — an admin could mark a booking "Confirmed" with $0 collected. Not possible via self-checkout or the other two admin wizards.
  **Severity/effort:** 🔴 Quick fix — strip `booking_status` from the admin form's mutatable fields; let the service/observer derive it.
- **QuickBookingPage and PhoneBookingPage both have a "record payment" wizard step that does nothing**: both capture a payment-method selection in their UI but hardcode `payment_type: 'full'` and never pass `initial_payment_amount` to the service — no `Payment` row is ever created. An agent using either tool can reasonably believe they've recorded a cash payment when the booking is actually left fully unpaid.
  **Severity/effort:** 🟠 Medium refactor — either wire these steps to actually create a payment via `recordPayment()`, or remove the misleading UI.
- **Waitlist-to-booking conversion opens a second, redundant inventory hold.** Redeeming a waitlist offer routes the customer through the normal `CheckoutWizard`, which creates its own independent hold rather than reusing the waitlist's existing one. Worse: `ReleaseWaitlistHold` unconditionally flips the `WaitingList.status` to `Expired` two hours later — **even if the customer already converted and the status is already `Converted`** — so an admin can watch a successful conversion silently revert to "Expired" in the waitlist list hours after the booking exists.
  **Severity/effort:** 🟠 Medium refactor — pass the waitlist hold's id through the redemption link and have `CreateBookingService` reuse/release it instead of opening a second one; guard the status overwrite in `ReleaseWaitlistHold` to skip already-`Converted` entries.
- **`ProcessWaitingListJob`** (a third, independent waitlist-promotion implementation, dispatched only from the `waitinglist:sweep` console command) has no lock, creates no hold, and checks the *wrong* capacity field (raw `available_seats` instead of the ledger-derived `remaining_seats`) — it would reintroduce an oversell vector the other two waitlist paths were specifically hardened against. It is currently dead (the command is not registered in the scheduler), but it is exactly the kind of drifted duplicate this whole audit is meant to surface before someone schedules it out of convenience.
  **Severity/effort:** 🔴 Quick fix — delete `ProcessWaitingListJob` and `waitinglist:sweep`, or rewrite the job to call the same safe path `WaitlistAutoPromotion` uses.
- **A vestigial second cancellation trigger**: `BookingObserver::updated()` still fires an inventory-release + waitlist-promotion whenever `booking_status` changes to `Cancelled` via a normal Eloquent save — but the only live cancellation path (`BookingService::cancelBooking()`) deliberately uses a raw `DB::table()` update specifically to bypass this observer (to avoid double-dispatching). No other code path sets that status via Eloquent, so this observer branch is currently unreachable — harmless today, but misleading for a future maintainer who assumes "cancellation side-effects happen via the observer."
  **Severity/effort:** 🔴 Quick fix — remove the dead observer branch, or add a comment pointing to the real path.

### 2.3 Historical audit re-verification — what's actually fixed vs. still live

A prior forensic audit (`zatara_audit_report.md`) flagged nine specific issues. Re-checked against current code (not assumed):

| # | Finding | Status |
|---|---|---|
| 1 | `ReleaseExpiredHolds` double-counts by inserting a fresh positive ledger row on hold expiry | ✅ **Fixed** — now a pure `UPDATE ... type = 'expired'` on the existing row, no insert. |
| 2 | Admin cancellation leaks inventory (no reversal ledger entry) | ✅ **Fixed** — `BookingService::cancelBooking()` → `InventoryService::releaseForCancellation()`, idempotent, used by all live cancellation entry points. One accepted edge case remains: a partial-then-full cancellation sequence can under-release (documented in code as a known, deferred limitation). |
| 3 | Waitlist VIP-link / auto-promotion can oversell (no lock) | ⚠️ **Fixed for the two live paths** (`WaitlistAutoPromotion`, `send_link_now` admin action both lock + hold correctly) — **still present** in the third, currently-dead `ProcessWaitingListJob` (see 2.2). |
| 4 | Admin payment recording does unsafe unlocked PHP math | ✅ **Fixed** — `BookingService::recordPayment()`/`reversePayment()` both lock the booking row before any balance math; all admin entry points route through them. |
| 5 | Hardcoded local `.nvm` Node/NPM paths in PDF generation | ✅ **Fixed** — now conditional on `env()`-sourced config, no hardcoded default; repo-wide grep for absolute local paths returned nothing. |
| 6 | Magic-login route not tenant-scoped (cross-tenant IDOR) | ✅ **Fixed** — query is scoped by `tenant_id` from the start. |
| 7 | Social auth auto-links accounts by email without verifying provider attestation | ✅ **Fixed logically** — now requires the provider's own verified-email claim or forces OTP re-verification. ⚠️ **But the feature is currently non-functional**: `laravel/socialite` is not in `composer.json`/`composer.lock` at all (confirmed absent), so the whole social-login surface would fatal-error if hit today. Not a live risk, but also not a live feature. |
| 8 | Uncompiled `{{ ForceDelete }}`-style stub text in a policy's `->can()` call | ✅ **Fixed / not found** — all policy files use real, correctly-named permission strings. |
| 9 | `CheckoutWizard::submitBooking()` N+1 queries per passenger/addon | ✅ **Fixed** — batched via `whereIn()` into two queries, keyed collections used inside the loop. |

**Takeaway:** the prior audit's critical financial/security findings are almost entirely remediated — the team's fix cycle worked. The *new* problems surfaced by this pass are architectural/UX-shaped rather than security-shaped: multiple half-migrated flows, one dead-but-buggy job waiting to be reawakened, and a couple of admin-only currency/payment-recording gaps.

---

## SECTION 4 — Reporting gap analysis

### 4.1 What exists today
Four Filament widgets (all auto-discovered + two also explicitly registered, `app/Providers/Filament/AdminPanelProvider.php:55-59`):

1. **`DashboardStatsOverview`** — 5 KPI tiles: bookings today, total revenue collected, outstanding balance, seat occupancy %, active waitlist count. Correctly divides integer-cent columns by 100 (well-commented). Tenant/role-scoped to `agency_admin`/`accountant`.
2. **`RevenueChart`** — a 12-month line chart of `Payment.amount` summed by month for the current year. **Bug:** sums `amount` across ALL payments for the tenant with no `GROUP BY currency` and no currency filter — in a tenant that runs both USD- and ILS-priced trips (which the schema explicitly supports), this chart silently adds unlike currencies together into one number labeled with a single currency suffix. This is exactly the "multi-currency exposure" gap the owner flagged, and it isn't just *missing* — the one chart that touches money across time is *actively wrong* the moment a tenant has more than one active currency.
3. **`TodaysDeparturesWidget`** — operational, not financial: today's departures with fill rate and unpaid-booking counts, links to the manifest.
4. **`AutomationStatusWidget`** — a system-health table (job name / last run / status) for background automation, not a business report.

Plus: `BookingResource` and `PassengersRelationManager` both have working Excel export actions (`pxlrbt/filament-excel`, confirmed installed and wired), and global search on `BookingResource`/`CustomerResource` (phone/passport/name), matching the PRD's claims.

### 4.2 What's missing, against real tourism-agency reporting needs
- **No cost/margin reporting at all.** Repo-wide grep for `cost_price`, `supplier_price`, `margin`, `commission`, `agent_commission` across `app/` and `database/migrations/` returns **zero hits** (outside an unrelated PDF-layout `margins()` call). There is no field anywhere capturing what a trip/hotel/package actually costs the agency versus what it sells for — the owner's flagged "pricing/margin reports (cost vs. sale price)" gap is not a missing report, it's a missing *column*. Nothing to report on yet.
- **No time-based/pace analytics.** No widget or query anywhere computes booking velocity, days-before-departure sell-out patterns, or seasonal trends. `RevenueChart` is month-bucketed but only for the current calendar year and only for raw revenue, not booking pace.
- **No installment/deposit-schedule reporting.** Deposits exist as a percentage + enabled flag on `TripTemplate` and a `deposit_amount`/`payment_type` pair on `Booking`, but there's no report showing upcoming/overdue deposit balances, or an actual payment-schedule/due-date concept at all (see Section 3.1).
- **No agent/staff performance report** (bookings or revenue per staff `user_id`), despite `Booking.user_id` recording who created each booking.
- **No per-trip P&L or fill-rate-over-time view** beyond the single-day snapshot in `TodaysDeparturesWidget`.

**Severity/effort:**
- 🔴 Quick fix: fix `RevenueChart` to group by currency (or filter to the tenant's default currency and label it correctly) — this is actively producing a wrong number today, not just an absent one.
- 🔵 Major redesign: cost/margin tracking, pace analytics, and installment scheduling all require new schema (cost fields, a real payment-schedule model) before any report can be built on top — this is a data-model gap, not a dashboard gap.

---

## SECTION 3 — Missing/incomplete business logic inventory

*(Cross-referenced against the historical audit/QA material found in the repo — `zatara_audit_report.md`, `tests/qa-execution/run_qa_suite.php` — plus the specific example categories named in this audit's brief. Where a named example scenario has no corresponding feature at all, it's marked N/A rather than "broken," per Sections 1.2/1.3 above.)*

| Scenario category | Status | Evidence |
|---|---|---|
| Rooming list partial availability | **N/A — no real rooming list exists**; the adjacent feature (`PackageOption` seat cap) *is* implemented and correct (see 1.2) | `PackageOption::getRemainingSeatsAttribute()` |
| Child/infant seat logic | **Implemented, consistently** | `requires_seat` boolean checked identically across `CreateBookingService`, `CustomerBookingPortal`, `CheckoutWizard`, `PassengerObserver` — a category that doesn't require a seat is excluded from inventory consumption everywhere it's checked. |
| Multi-bus overflow | **N/A — no bus/vehicle data model exists at all** (see 1.3) | No `buses`/`vehicles` table; `available_seats` is a single undifferentiated integer. |
| Waitlist promotion order | **Partially implemented — inconsistent** | `WaitingList` has an explicit `priority` pivot column with an `orderByPivot('priority', 'asc')` relationship scope (`app/Models/WaitingList.php:34-35`) implying admins can prioritize certain customers — but `WaitlistAutoPromotion::handle()`, the job that actually promotes people, orders strictly by `created_at asc` (plain FIFO) and never references `priority` at all. The priority field can be set but has no effect on promotion order. |
| Currency immutability | **Implemented correctly at the model level**; **one live leak** in initial-payment currency (see 2.2) | `TripTemplate`/`TripInstance` both throw `RuntimeException` on currency change once dependent records exist. |

### 3.1 Additional business rules that appear entirely absent (flagging as "not found, possibly needed" — not assuming they must be built)
- **Installment/payment-schedule engine.** `deposit_percentage`/`deposit_enabled` (on `TripTemplate`) and `deposit_amount`/`payment_type` (on `Booking`) let a booking be split into "deposit now, rest later," and a `PaymentType::INSTALLMENT` enum case exists — but it's only ever used as a label on a single manually-entered payment, not a real recurring schedule with due dates, reminders, or an overdue report. If the business actually sells trips on multi-payment installment plans (common in this market), that's currently all manual tracking by staff.
- **Agent/staff commission tracking.** No field or table anywhere ties a percentage or flat commission to the `user_id` who created a booking.
- **Supplier cost vs. customer price / margin tracking.** Confirmed absent (Section 4.2) — no cost field on `TripTemplate`, `TripInstance`, or `PackageOption`.
- **Multi-currency exposure reporting.** No report aggregates "how much are we holding/owed, broken down by currency" — and the one chart that touches this (`RevenueChart`) currently mixes currencies incorrectly (Section 4.1).
- **Group/bulk booking discount rules.** `discount_amount` exists as a raw field on `Booking` (manually entered), but there's no rule engine (e.g. "5+ passengers get X% off automatically").

---

## SECTION 5 — General architecture/stability inventory

### 5.1 Systematic duplication audit — 10 categories of business logic

| # | Category | Verdict | Detail |
|---|---|---|---|
| 1 | Seat/capacity availability checking | 🔴 **Duplicated — 4 independent algorithms, one of them broken** | `InventoryService` (canonical, ledger-sum) and `TripInstance::getRemainingSeatsAttribute()` (the *same* ledger-sum math, re-implemented as a read accessor instead of calling the service) mostly agree. `BookingService::ensureCapacity()` uses a **completely different** algorithm (counts live `Passenger` rows, ignores holds/ledger entirely) — reachable only via the effectively-dead `BookingService::createBooking()`/`Storefront\Checkout`. `WaitlistAutoPromotion` reimplements the ledger-sum query inline a third time. Worst: **`CustomerBookingPortal`'s seat-map (`app/Livewire/CustomerBookingPortal.php:78,178`) references `$tripInstance->seats_count` — a column that does not exist anywhere in the schema** (only `available_seats` does). This always evaluates `null`, so the customer-facing seat picker silently falls back to a **hardcoded 50-seat grid**, completely disconnected from real inventory. This is a live, customer-facing bug, not just style drift. 🔴 Quick fix. |
| 2 | Inventory ledger writes/reversals | 🟠 **Partially duplicated** | Release/confirm/cancel/transfer all funnel through `InventoryService`. But *hold creation* is reimplemented independently in 3 places with inconsistent lifecycle pairing: `CheckoutWizard::submitLeadCapture()`, `WaitlistAutoPromotion` (correctly pairs its hold with a delayed `ReleaseWaitlistHold`), and the admin `send_link_now` VIP action (creates a hold **without** scheduling any release — it's only cleaned up incidentally by the generic expired-holds sweep, and the `WaitingList` row's own status never transitions when this happens). |
| 3 | Booking cancellation | 🟠 **Mostly single-source, one dangerous exception** | `BookingService::cancelBooking()` is the sole authority, called consistently by every live cancellation surface. **Exception:** `BookingResource.php`'s `reopen_cancelled` action does a bare `$record->update(['booking_status' => Pending])` with **no service call and no inventory re-consumption** — a "reopened" booking's seats stay permanently released even though the booking is active again. 🔴 Quick fix (real overbooking-undercounting risk). |
| 4 | Booking-to-booking transfer | ✅ **Single source of truth** | `BookingService::transferBooking()` replaced two prior hand-rolled versions (one of which wrote an invalid ledger enum value) and is called identically from both live UI surfaces. Only the Filament form scaffolding is copy-pasted, not the business logic. |
| 5–6 | Payment balance recalc / booking status auto-determination | 🔴 **Duplicated — 2 independent reimplementations** | Canonical: `BookingService::recalculateTotals()`. **Bypassed by:** `ProcessGatewayPaymentService::execute()` (the live online-payment-webhook path) — reimplements total/balance/status math inline, never recomputes `grand_total`, never sets `Payment.currency`, skips the Cancelled-booking guard, writes no activity log. **Also bypassed by** `CreateBookingService`'s initial-deposit write, which creates the `Payment` row via a raw `Payment::create()` without calling `recalculateTotals()` afterward — currently only saved from visibly wrong totals by `PaymentObserver`'s independent "defense in depth" recalculation, not by correctness of this code path itself. |
| 7 | Currency validation/enforcement | 🔴 **One enforcement point, bypassed by 2 payment paths** | `BookingService::recordPayment()` is the only place that checks a payment's currency against its booking's currency. `ProcessGatewayPaymentService` does no currency check at all and doesn't even set `Payment.currency`. `CreateBookingService`'s initial payment also bypasses the check (see Section 2.2's currency bug). |
| 8 | Passenger requirement/document validation | 🔴 **Duplicated — 3 implementations, materially inconsistent** | `BookingForm` (drives the live `CheckoutWizard` storefront checkout) makes `document_number`/`date_of_birth` **nullable — unenforced at initial checkout regardless of the trip's `RequirementPreset`.** `CustomerBookingPortal`'s post-booking "complete profile" step is the *only* place that actually reads `RequirementPreset->items` and enforces them dynamically. The dead `Storefront\Checkout` hardcodes `passport_number` as always-required, ignoring presets entirely. Net effect: the tenant-configurable requirement-preset system built in Section 1 is invisible at the point customers actually book — it's only enforced after the fact. |
| 9 | PNR/UUID generation | 🟠 **Duplicated — 2 different formats** | `BookingService::generateReference()` (tenant-prefixed, sequential — `{TENANT}-{YY}-{SEQ}`) is unused in production (only the dead `createBooking()` calls it). `CreateBookingService::execute()` (the live path every real booking uses) generates PNRs as `ZTR-` + 6 random chars with its own uniqueness loop — hardcoded prefix, ignores tenant branding. |
| 10 | Waitlist promotion/offer logic | 🔴 **Duplicated — 3 independent implementations** | `WaitlistAutoPromotion` (safe, ledger+lock+paired hold) and the admin `send_link_now` action (safe on capacity, but unpaired hold — see #2) are both live. `ProcessWaitingListJob`/`waitinglist:sweep` (unsafe: no lock, no hold, wrong capacity field) is currently unreachable since nothing schedules the command — see Section 2.2/2.3 and dead-code sweep below. |

### 5.2 Dead code sweep

Beyond the already-removed `HasTripState`/`InvalidStateException`:

- **`app/Livewire/Storefront/Checkout.php`** (260 lines) — **confirmed dead.** `routes/web.php`'s `storefront.checkout` route is bound to `CheckoutWizard`, not this class; the `use App\Livewire\Storefront\Checkout;` import at `routes/web.php:7` is unused. Only "alive" via a `Livewire::test(Checkout::class, ...)` call in `tests/Feature/StorefrontAndPortalTest.php:96` — the test is what makes it look live at a glance. Independently reimplements passenger validation, totals, and calls the dead `BookingService::createBooking()`/`ensureCapacity()`.
- **`App\Livewire\CustomerPortal`** — **confirmed dead.** No route, no `<livewire:>` tag anywhere, no test. Superseded by `CustomerBookingPortal` and `Storefront\MyBookings`.
- **`App\Services\Notifications\BookingNotificationService`** — **confirmed dead.** Zero references anywhere outside its own file. Superseded by `TicketGenerationService` + direct job dispatches scattered through Filament actions.
- **`BookingService::createBooking()`** — **confirmed dead in production**, kept "alive" only by tests (`DashboardAnalyticsTest`, `BookingAndFinancialEngineTest`, `NotificationSystemTest`). Every real booking goes through `CreateBookingService::execute()` instead — meaning the test suite is currently validating a code path production traffic never uses, while the actually-used path (`CreateBookingService`) has thinner direct test coverage by comparison.
- **`App\Console\Commands\WaitingListSweep`** (`waitinglist:sweep`) — **likely dead.** Not present in `routes/console.php`'s schedule (only `app:release-expired-holds` and job-dispatch schedules are registered there), not referenced by any deploy/CI config. Remains manually invocable, so not fully confirmed dead — but nothing triggers it automatically today.
- **`App\Http\Controllers\PortalController`** — **partially dead.** `showLogin()`, `sendOtp()`, `verifyOtp()` have zero routes anywhere (remnants of an older OTP-login flow superseded by `Livewire\Auth\CustomerLogin` + `CustomerOtpService`). Its other two methods (`dashboard`/`logout`) are routed and live — but constitute a *third* independent "my bookings" surface (see Customer Portal rating below).
- **`App\Filament\Resources\TripBuilderResource`** — **likely dead in day-to-day use, not fully confirmed.** Nav-hidden with an explicit "using the new split resources" comment, zero incoming links from anywhere else in the admin panel. Not fully dead, though: its `CreateTripBuilder` page is the *only* place that dispatches the queued, pickup-route-aware `BulkGenerateTripInstances` job — so it's simultaneously "abandoned" and "the only way to get one specific piece of working functionality."
- **Vestigial/inert enum cases:** `PaymentStatus::Refunded` (only referenced in a test asserting the case exists; no application code ever assigns it — matches a code comment noting the `RefundPending → Refunded` transition is explicitly out of scope for now); `PaymentType::REFUND` (selectable in the admin form/table but no service ever creates a `Payment` with this type — reversals use `PaymentType::REVERSAL` instead); `TripStatusEnum::Draft` (selectable in one dropdown, asserted in one test, but no code path defaults to it or treats it specially once selected — inert).

### 5.3 Module health ratings

**Trips (TripTemplate/TripInstance): Needs refactor.** Cancellation (`TripService::cancelTrip()`) is a clean, well-locked single authority. But bulk trip-instance generation from a template is implemented **independently twice** with different results: `TripBuilderResource`'s job-based generator (pickup routes attached, always 1-day trips) vs. `TripInstancesRelationManager::bulk_schedule`'s synchronous inline generator (configurable duration, no pickup routes). `TripBuilderResource` itself is half-abandoned mid-migration, by its own code comment.

**Bookings (creation/lifecycle): Needs refactor.** Lifecycle operations that got explicit remediation (cancel, transfer, add/cancel passengers, record/reverse payment — documented "P0-5/P0-6/P0-7" in code comments) are now genuinely solid: locked, idempotent, activity-logged. But *creation* still has two competing services with different PNR formats and different capacity algorithms, and the one named `BookingService::createBooking()` — sounding like the canonical one — is actually the dead one, a real trap for a future maintainer. `reopen_cancelled` bypasses the service layer with no inventory re-consumption (5.1 #3).

**Hotels/Rooming: Needs redesign.** No dedicated hotel/rooming table exists anywhere. `PackageOption` is a flat pricing-tier-with-a-hotel-name-attached, not an operational rooming system — no per-passenger room assignment, no room inventory, no multi-hotel trips. (Section 1.2.)

**Buses/Transport: Needs redesign.** No bus/vehicle/fleet model exists at all. `available_seats` is one undifferentiated integer per trip instance. `PickupRoute`/`PickupPoint` are local shuttle-logistics only, unrelated to fleet/coach assignment. (Section 1.3.)

**Payments: Needs refactor.** The admin/cash path (`recordPayment`/`reversePayment`) is solid: locked, idempotent, currency-checked, activity-logged. The **gateway/webhook path** (`ProcessGatewayPaymentService`, live via `PaymentWebhookController`) is a structurally separate, less-safe reimplementation — skips currency validation, skips `grand_total` recomputation, skips the Cancelled-booking guard, no activity log (5.1 #5–7). Also: the `reverse_payment` action in `PaymentsRelationManager` is only visible for `PaymentType::PAYMENT`, so payments recorded as `DEPOSIT`/`FULL` — i.e. most cash confirmations — cannot be reversed through that UI at all.

**Reports: Needs redesign** (per Section 4) — not a code-quality problem so much as a near-total absence of the cost/margin/pace reporting a real agency needs; the 4 widgets that exist are solid for what they cover.

**Customer Portal: Needs redesign.** **Three separate, overlapping "my bookings" surfaces**, evidently built in different phases and never consolidated: (1) `CustomerBookingPortal` (`/b/{uuid}` magic link — handles passenger-completion and seat selection, but its seat picker runs against the non-existent `seats_count` column and silently falls back to a fake hardcoded 50-seat grid, 5.1 #1); (2) `Storefront\MyBookings` (`/my-bookings`, customer-guard-authenticated listing + cancellation request); (3) `PortalController::dashboard`/`logout` (`/t/{tenant_slug}/portal/dashboard`, a third independent listing implementation, sitting next to dead OTP-login remnants in the same controller). A customer's "view my bookings" experience today depends entirely on which URL/link they happen to land on.

### 5.4 Positive findings worth preserving through any redesign
- `BookingService::transferBooking()`, `cancelBooking()`, `recordPayment()`, `reversePayment()` are all genuinely well-built: locked, idempotent, activity-logged, and consistently called from every live surface. Whatever gets redesigned, this pattern is the one to replicate elsewhere, not replace.
- `TripInstanceResource`'s `bulk_status_change` action explicitly excluding `Cancelled` from its options, with a comment explaining why, shows the team is capable of recognizing and design-guarding against exactly this audit's central failure mode — it just hasn't been applied everywhere yet.
