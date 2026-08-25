# Zatara — Future Features Backlog

**Status:** Not scoped, not approved for implementation. This is an idea log only.
**Source:** Surfaced from a Google Stitch admin-panel mockup (2026-08-25) — the mockup's sample/filler content pointed at real product gaps, independent of its visual design (the visual design itself was evaluated separately for the panel redesign ticket).
**How to use this file:** Before picking any item here for implementation, run it through the same process as every other major decision in this project — investigation first, explicit stakeholder approval on the open questions, then implementation. Nothing here is pre-approved.

---

## High value, builds on existing foundation

### 1. Customer-initiated refund requests
Today, `payment_status = RefundPending` is set automatically by `BookingService::cancelBooking()`, but only staff-initiated cancellation triggers it. There is no channel for a customer to request a refund themselves (e.g. via the customer portal or booking-success page).

- **Why it fits:** Extends an already-built mechanism rather than inventing a new one.
- **Open questions for later:** Does a customer-initiated request need staff approval before it's actioned, or does it just create a visible flag for staff to review? Does it interact with the existing `requirements_complete`/portal flow?
- **Rough sizing:** Medium — mostly UI + a new request/approval state, minimal new schema.

### 2. Unified activity feed
Today, individual records have their own activity logs (via `spatie/activitylog`, used throughout `BookingService`), but there's no single "what happened across the whole system today" view for staff.

- **Why it fits:** Data already exists (activity log entries per record) — this is primarily an aggregation/display problem, not a new data-capture problem.
- **Open questions for later:** Scope per tenant/role? Real-time or a refresh-based feed? Which event types are noisy enough to filter out by default?
- **Rough sizing:** Medium — a new Filament widget/page querying the existing activity log table across models.

### 3. System-wide unified search
Today, global search (via Filament's built-in `GlobalSearch`) is only wired up on `BookingResource`/`CustomerResource` (phone/passport/name). Trips, hotels, and other resources aren't included.

- **Why it fits:** Filament's global search is already a working mechanism — this is extending its configuration to more resources, not building new search infrastructure.
- **Rough sizing:** Small — likely a quick win once picked up.

---

## Real business-model decision — do not treat as a small feature

### 4. B2B / partner agency customers
The mockup's "تسجيل عميل جديد (B2B)" activity line implies a second customer type: partner travel agencies booking wholesale, distinct from individual retail customers.

- **Why this is different from the others:** This isn't a UI addition — it's a potential new pricing model (wholesale rates), commission/margin structure, invoicing relationship, and possibly a different booking/approval flow. It touches currency, financial reporting, and the cost/margin-tracking gap already identified in the earlier UX/architecture audit.
- **First question before anything else:** Is B2B/wholesale actually part of the real, near-term business plan, or was this just Stitch-generated filler content? This must be answered before any technical scoping begins.
- **Rough sizing:** Not sized — this is a "major redesign, needs its own full decision cycle" item, comparable in weight to the Hotel/Rooming redesign, not a quick addition.

---

## Real domain gap, likely part of a future major-decision cycle

### 5. Tour guide as a distinct entity
The mockup shows "المرشد: خالد عبدالله" attached to a trip — implying a tour guide role distinct from a bus driver, with their own assignment per trip.

- **Why it fits here:** Same shape as the already-identified Bus/Fleet modeling gap (from the original UX/architecture audit) — a real operational entity the data model doesn't currently represent (`available_seats` is the only transport-adjacent concept; no staff/guide assignment model exists).
- **First question before anything else:** Do trips actually need a specifically assigned guide tracked in-system (for scheduling, payroll, or customer-facing display), or is "guide" informally handled outside the system today?
- **Rough sizing:** Not sized — likely bundled with, or sequenced near, the deferred Bus/Fleet major-redesign decision.

---

## Deferred — not relevant yet

### 6. Live integration/automation status panel
Mockup showed API sync, payment gateway, and ticket-sending status indicators. Not meaningful today since Stripe/online payments are still hardcoded-disabled and the system is pre-production — there's little live integration state to actually monitor yet.

- **Revisit when:** Real third-party integrations (payment gateway, external APIs) are actually turned on in production.

### 7. Featured destination / marketing banner
Editorial/promotional content block ("وجهة الشهر المميزة"). This belongs on the customer-facing storefront, not the staff admin panel — and storefront design work hasn't been scoped yet (admin panel was explicitly prioritized first).

- **Revisit when:** Storefront/customer-facing design becomes the active focus, after the admin panel redesign is complete.

---

## Process reminder for whoever picks this file up later

This project's established pattern (proven across every ticket so far): investigate before implementing, get explicit sign-off on open business questions before writing code, keep changes isolated and regression-tested, and confirm before/after test suite numbers stay consistent with the known baseline. Apply the same discipline to any item picked from this backlog — none of these are pre-scoped or pre-approved for implementation.
