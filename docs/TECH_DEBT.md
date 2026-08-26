# Technical Debt / Infrastructure Risk

Infrastructure-level risks discovered during other work — test/production environment parity
gaps, not product features. Logged here (separate from `docs/FUTURE_FEATURES_BACKLOG.md`, which
is for product-feature gaps) since these need someone with DB/infra context to pick up, and the
value is entirely in not having to re-derive the reproduction steps.

## SQLite/MySQL parity: case-insensitive `unique()` constraints on string columns

**Found**: 2026-08-27/28, during the SQLite/MySQL Behavioral Parity Audit (triggered by two prior
incidents this session: a missing `inventory_ledgers` enum CHECK constraint, and an ambiguous
`ORDER BY` column crash in `WaitingListsRelationManager`, both invisible to the SQLite-run test
suite despite being live-breaking or silently-wrong on MySQL).

MySQL's default collation is case-insensitive; SQLite's default TEXT comparison is
case-sensitive. Any `unique()` constraint on a string column can therefore accept case-variant
duplicates on SQLite that MySQL would reject as a duplicate key — meaning a real production bug
(two "duplicate" rows differing only by case slipping past a uniqueness check) could exist in the
app and never be caught by any test run against SQLite.

**Confirmed concretely** (test run, then rolled back / reverted, not left in the codebase):
`customers` has `unique(['tenant_id', 'email'])`. Inserting `user@example.com` then
`User@Example.com` under the same tenant:
- SQLite: **both inserts succeed** — two distinct rows exist.
- MySQL (real dev DB, same two inserts, transaction rolled back after): second insert
  **rejected** — `SQLSTATE[23000]: ... Duplicate entry '1-Parity-Check@Example.com' for key
  'customers_tenant_id_email_unique'`.

**Same category, structurally identical, not individually re-verified**:
- `users.email` (`unique()`)
- `global_addons` — `unique(['tenant_id', 'name'])`
- `global_pricing_tiers` — `unique(['tenant_id', 'name'])`

**Lower practical risk, same schema-level gap but unlikely to actually trigger**:
`tenants.slug` / `trip_templates.slug` — `Str::slug()` normalizes to lowercase before storage in
every write path checked, so a real case-collision is unlikely even though the underlying
constraint gap exists identically.

**Fix approaches worth considering** (not evaluated in depth — this is a triage note, not a
design): (a) add `COLLATE NOCASE` to the relevant SQLite columns so the test DB matches MySQL's
case-insensitive behavior, (b) normalize to lowercase at the application layer before every write
to an affected column (more invasive, touches every write path), or (c) accept the gap and add a
regression test per affected column using the same probe pattern shown above, run against a real
MySQL test connection specifically for this class of constraint (the general test suite would
need a documented way to opt a specific test into the mysql connection, which doesn't currently
exist).

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
