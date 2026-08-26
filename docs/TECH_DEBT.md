# Technical Debt / Infrastructure Risk

Infrastructure-level risks discovered during other work — test/production environment parity
gaps, not product features. Logged here (separate from `docs/FUTURE_FEATURES_BACKLOG.md`, which
is for product-feature gaps) since these need someone with DB/infra context to pick up, and the
value is entirely in not having to re-derive the reproduction steps.

## SQLite/MySQL parity: case-insensitive `unique()` constraints on string columns — RESOLVED

**Found**: 2026-08-27/28, during the SQLite/MySQL Behavioral Parity Audit. **Fixed**:
2026-08-28ish, in the Case-Insensitive Uniqueness Fix ticket. Kept here (rather than moved to the
resolved list below) since the original reproduction detail is still useful context for anyone
touching these columns.

MySQL's default collation is case-insensitive; SQLite's default TEXT comparison is
case-sensitive. Original reproduction: `customers` has `unique(['tenant_id', 'email'])`;
inserting `user@example.com` then `User@Example.com` under the same tenant succeeded as two
distinct rows on SQLite, but the second insert was rejected on MySQL (real dev DB) as a duplicate
key.

**Fix applied, differentiated by column type** (not one blanket solution):
- **Identity/auth columns** (`customers.email`, `users.email`): normalized to lowercase at the
  application layer via a `static::saving()` hook on `Customer`/`User` (plus `GuestSession`, and
  an explicit fix in `SocialAuthController` for the one `firstOrCreate()`/`where()` call site
  whose search input needed lowercasing too — hooks don't cover query search arrays, only the
  eventual `create()`). A defensive backfill migration
  (`2026_09_03_000001_backfill_lowercase_customer_and_user_emails.php`) normalized existing rows,
  detecting and skipping (with full logging, never silently merging) any case-collision found.
- **Display-name columns** (`global_addons.name`, `passenger_categories.name` — the live table
  behind the `global_pricing_tiers.name` name used above, renamed in
  `2026_06_28_000000_rename_pricing_tiers_to_passenger_categories.php` — and `tenants.slug`,
  pulled into scope after all: turned out to be manually-typed with no auto-slugify, unlike
  `trip_templates.slug` which stayed correctly excluded): stored casing is preserved for display;
  a new reusable `App\Rules\CaseInsensitiveUnique` (engine-agnostic `LOWER()` comparison) makes
  the *uniqueness check itself* case-insensitive instead.

See `tests/Feature/EmailNormalizationTest.php`, `tests/Feature/CaseInsensitiveUniqueRuleTest.php`,
and `tests/Feature/EmailBackfillMigrationTest.php` for the full regression coverage.

## SQLite ignores VARCHAR length limits entirely

**Found**: same audit as above.

`$table->string('col', N)` enforces the `N`-character limit on MySQL (truncates or rejects,
depending on strict mode) but SQLite ignores the length argument completely — any length string
inserts without error or truncation. A value that's too long for its real MySQL column could
therefore write successfully in every test run and only fail (or silently truncate, corrupting
data) in production.

**Not exhaustively audited** — this needs runtime/data inspection (what values actually get
written where), not just reading the schema, to find real violations rather than theoretical
ones. Flagging as a category to keep in mind for anyone debugging a "field got cut off in
production but the test passed" report, not a list of specific broken columns.

## No audit-trail safety net for future price mutations on Booking

**Found**: 2026-08/09, during the Historical Snapshot / Price Integrity Audit. Not fixed (logged
per explicit instruction).

`Booking` does not use Spatie's `LogsActivity` trait (only `HasMedia`/`InteractsWithMedia`) — it
has no generic/automatic change log for its financial columns (`grand_total`, `discount_amount`,
`package_price_at_booking`, etc.). Checked concretely, not just in theory: the 3 methods that
actually mutate price-relevant state today (`BookingService::cancelBooking`, `::transferBooking`,
`::recordPayment`) already call `activity()->log(...)` correctly, and `discount_amount` is
`->visibleOn('create')` only in the admin form (not editable after creation), and the passenger
repeater in `BookingResource.php`'s edit form does not expose `price_at_booking` or category as
editable fields — so there is currently **no live, exploitable silent-price-edit path** in
practice. The gap is structural/preventive: if a future admin action or edit path is ever added
that mutates a price-relevant column outside those 3 already-audited methods, it would be
completely unlogged, with nothing in the model layer to catch it.

**Fix approach worth considering** (not evaluated in depth): add `LogsActivity` to `Booking`
scoped to just the financial columns (`grand_total`, `discount_amount`, `package_price_at_booking`,
`balance_due`, `total_paid`), so any future mutation path gets a log entry automatically rather
than relying on every future author remembering to call `activity()->log()` by hand.

## No snapshot mechanism for descriptive (non-price) booking content

**Found**: 2026-08/09, same audit. Not fixed (logged per explicit instruction; the price-affecting
title/date display bug from the same finding *was* fixed — see the Price Integrity Audit, Finding
B/C commit — this entry covers what's left).

`Booking` snapshots pricing (`price_at_booking` on `Passenger`/`BookingAddon`/
`BookingRoomSelection`, `package_price_at_booking`) and a few identifying fields
(`snapshot_trip_title`, `snapshot_start_date`, `snapshot_end_date`, etc.), but **nothing** captures
descriptive content: `TripTemplate.description` (the itinerary/inclusions text), hotel
name/details on a room selection (`BookingRoomSelection` only stores `room_type_id`, `quantity`,
`occupancy_type`, `price_at_booking` — no hotel/room descriptive snapshot at all), or anything
else a customer might point to later as "what I was told I'd get." If a `TripTemplate.description`
changes after a booking, or a `Hotel`/`RoomType`'s name or details change, every consumer that
reads through the live relation (admin views, customer views, any future document) shows the new
content, with no historical record of what was originally shown. Confirmed via reading
`TripTemplate`'s and `BookingRoomSelection`'s fillable lists directly — this isn't a display-layer
oversight like the title/date bug, there's genuinely no column to fall back to.

**Fix approach worth considering** (not evaluated in depth): a `snapshot_description` (and
similar hotel/room descriptive fields) captured the same way `snapshot_trip_title` already is, at
booking creation time in `CreateBookingService::execute()`.

## Dead code with latent bugs, found while tracing PDF generation paths

**Found**: 2026-08/09, same audit. Confirmed unreachable in both cases (grepped all of `routes/`
and every `view()`/`Pdf::view()` call site) — not live bugs, just landmines for whoever revives
either file without checking.

- **`app/Http/Controllers/Storefront/TicketDownloadController.php`**: zero routes point to it.
  Would crash immediately if ever wired up — `$booking->load(['tripInstance.template',
  'passengers.tripPricingTier'])` calls two relations that don't exist (`TripInstance` has
  `tripTemplate()`, not `template()`; `Passenger` has `tripPassengerCategory()`, not
  `tripPricingTier()` — renamed in `2026_06_28_000000_rename_pricing_tiers_to_passenger_
  categories.php`). Confirmed via `->load()` throwing `RelationNotFoundException` in tinker.
- **`resources/views/pdf/ticket.blade.php`**: not referenced by any controller/service/view
  anywhere (the real, live ticket path renders `pdf/ticket-template.blade.php` instead). Contains
  its own broken references if ever resurrected: `$tripInstance->tripTemplate->title` read live
  (would have been a real price/title-integrity leak, same class as the Finding B/C bug, had this
  file been live) and `$addon->total_price` — not a real accessor on `BookingAddon` (only
  `price_at_booking` and `quantity` exist), would silently render "$0.00" for every addon.
- **`resources/views/pdf/ticket-template.blade.php`** (the real, live one, used by
  `TicketGenerationService` and `BookingSuccess::downloadPdf()`): two unrelated, live but
  low-severity cosmetic bugs found while reading it for this audit. `$trip->template->origin` /
  `$trip->template->destination` (line 80, 93) — `template` isn't a real relation
  (`TripInstance::tripTemplate()` is), and `TripTemplate` has no `origin`/`destination` fields at
  all — silently falls back to generic Arabic placeholder text ("المحطة الرئيسية" / "الوجهة
  السياحية") on every generated ticket instead of showing anything real. `$addon->addon_name`
  (line 104) — not a real attribute on `BookingAddon` — silently falls back to the generic "إضافة"
  label instead of the addon's real name. Neither is a price-integrity issue (this document
  doesn't display price at all), just a display quirk affecting every real generated ticket.

## Already resolved this session (listed here for context, not action items)

- Price Integrity Audit, Finding A: `BookingService::recalculateTotals()` read
  `$booking->packageOption->price_adjustment` live instead of a stored snapshot, corrupting the
  stored `grand_total` column on nearly every subsequent booking mutation once a `PackageOption`'s
  price changed after booking (confirmed live: a real booking went from grand_total 150 to 600
  after a live price change + one new payment) — fixed via the new `package_price_at_booking`
  snapshot column, a `Booking::creating()` hook, and a one-line change to the guardrail-protected
  `recalculateTotals()`.
- Price Integrity Audit, Finding B/C: the customer's "My Bookings" page, the magic-link portal,
  and the admin bookings table all displayed a booking's trip title/dates via a live join through
  `tripInstance->tripTemplate`, bypassing the already-correctly-populated
  `snapshot_trip_title`/`snapshot_start_date`/`snapshot_end_date` fields — renaming a trip
  template silently rewrote what every past booking appeared to show on all 3 surfaces. Fixed to
  prefer the snapshot, live data only as a fallback.

- `inventory_ledgers.type` missing CHECK constraint — was actually fine; audit confirmed it
  (and `room_inventory_ledgers.type`) already had a working CHECK constraint on both engines,
  since both were defined inside their own `Schema::create()`.
- `bookings.payment_type` missing CHECK constraint on SQLite (added via `Schema::table()`, not
  `Schema::create()` — Laravel's SQLite grammar only embeds an enum's CHECK constraint on the
  `Schema::create()` path) — fixed via
  `2026_09_02_000001_reapply_payment_type_enum_constraint_on_bookings.php`.
- Ambiguous `ORDER BY created_at` across the `waiting_lists`/`trip_instance_waiting_list` pivot
  join in `WaitingListsRelationManager` — fixed (qualified to `waiting_lists.created_at`).
- Every other `defaultSort()`/`orderBy()` call across `app/Filament` was checked against its
  underlying query's actual JOINs (or lack thereof) and confirmed not at risk of the same
  ambiguous-column pattern.
