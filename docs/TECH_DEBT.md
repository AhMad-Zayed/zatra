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

## Already resolved this session (listed here for context, not action items)

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
