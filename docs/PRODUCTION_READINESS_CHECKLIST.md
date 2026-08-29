# Production Readiness Checklist

Investigation-only pass, performed against the real dev repo and its local sqlite test database. Nothing was fixed, nothing in the 16 baseline tests was changed, no production system was touched (none is reachable from here). Every item below is marked ✅ Ready / ⚠️ Needs stakeholder action (non-code) / 🔴 Needs a code fix first.

---

## 🔴 PRIORITY FINDINGS — read these first

Two genuine, previously-undocumented problems surfaced during this pass. Neither was known before this checklist; both are real, not test artifacts.

### 1. `CustomerResource` has no policy — any tenant-attached user can see every customer's name and phone number

While diagnosing test #13 below (`test_roleless_customer_cannot_access_admin_panel`), I found the test's own premise is correct but incompletely enforced. A `User` record attached to a tenant, **with zero roles and zero permissions**, can:

- Load the full admin dashboard shell (200 OK) — sidebar, navigation, company name, all visible.
- Load `/admin/{tenant}/bookings` → correctly blocked, 403 (BookingResource has `app/Policies/BookingPolicy.php`, wired to `view_any_booking`).
- Load `/admin/{tenant}/customers` → **200 OK**, and the response contains the real customer's name and phone number.

Verified directly: created a customer named `Real Secret Customer Name` with phone `0799999999` for the tenant, hit the customers list as the roleless user, and both the name and the phone number were present in the rendered HTML.

**Root cause:** `app/Policies/` has policies for Booking, Payment, Hotel, RoomType, TripInstance, TripTemplate, Activity, Role, and two TripStayLeg-related models — but **no `CustomerPolicy.php`**. Without a policy, Filament's `can()` check has nothing to deny against, so it defaults to allow. Every other tenant-facing resource in the panel appears to have a policy; this one was missed.

**Fix needed (code):** add `app/Policies/CustomerPolicy.php` mirroring `BookingPolicy.php`'s shape, register it, and add the corresponding `view_any_customer`/`view_customer` (etc.) permissions to the role seeder. This is a real pre-launch blocker, not a nice-to-have — customer PII (name + phone) is exposed to anyone merely added to a tenant, regardless of their actual job.

### 2. OTP delivery is a no-op in production — customers can never actually receive a login code

`app/Services/CustomerOtpService::sendOtp()`:

```php
if (app()->environment('production', 'staging')) {
    // TODO: Implement a proper SendCustomerNotificationJob for OTPs
    // \App\Jobs\SendCustomerNotificationJob::dispatch($customer, $isEmail ? 'email' : 'whatsapp', "Your authentication code is {$otp}");
    \Illuminate\Support\Facades\Log::info("Production OTP for {$field} {$cleanIdentifier}: {$otp}");
} else {
    \Illuminate\Support\Facades\Log::info("Local/Testing OTP for {$field} {$cleanIdentifier}: {$otp}");
}
```

In every environment, including `production`, the OTP is written to the Laravel log and nothing else. The actual send call is commented out. `sendOtp()` throws no error and returns normally — the storefront login flow (phone/email OTP, the only self-service login path for customers) will appear to work perfectly (the "code sent" screen shows), but no SMS, WhatsApp message, or email ever reaches the customer. They cannot get the code. This is exactly the "fails silently, not loudly" pattern this checklist was asked to watch for, and it's more severe than the original Browsershot PDF issue found earlier this session: that one degraded a secondary feature; this one breaks the primary customer-facing login path entirely, without a single error anywhere.

**Fix needed (code):** wire `sendOtp()` to an actual delivery channel before launch. The `ATLAHUB_ACCOUNT_ID`/`ATLAHUB_INBOX_ID`/`ATLAHUB_API_TOKEN` env vars already exist (see the Environment Variable Audit below) and `app/Jobs/SendAtlahubWhatsAppJob.php` already exists in the codebase — the commented-out line suggests the intended integration point is already scaffolded, just never finished and wired up here.

---

## 1. The 16 baseline test failures

Every one of the 16 was re-run individually against the current codebase (not from memory). None were fixed or deleted. 406 tests total, 16 failing — confirmed unchanged from the session's running baseline.

| # | Test | Class | Evidence |
|---|------|-------|----------|
| 1 | `CreateBookingServiceTest::test_it_successfully_creates_a_b2c_self_checkout_booking_with_accurate_financials` | (a) stale | `ValueError: "Published" is not a valid backing value for enum App\Enums\TripStatusEnum`. Fixture also references `App\Models\TripPricingTier`, which **does not exist anywhere in the codebase** (`class_exists()` returns false). Superseded by `tests/Feature/BookingAndFinancialEngineTest.php` (23 tests, all passing, explicitly rewritten "against the live booking path"). |
| 2 | `CreateBookingServiceTest::test_it_successfully_creates_a_b2b_admin_booking_with_audit_trail` | (a) stale | Same root cause as #1. |
| 3 | `CreateBookingServiceTest::test_it_throws_inventory_exhausted_exception_when_capacity_is_exceeded` | (a) stale | Same root cause as #1. |
| 4 | `AdminPanelTest::test_tenant_isolation_in_admin_panel` | (b) + deeper (a) | Immediate blocker: `NOT NULL constraint failed: model_has_roles.tenant_id` — `assignRole()` is called without first calling `setPermissionsTeamId($tenant->id)` (`config/permission.php` has `'teams' => true`; the working pattern, e.g. `tests/Feature/SecondBatchQuickFixesTest.php:98`, always calls this first). But fixing only that would not be enough: the test's `Booking::create([...'reference'=>..,'status'=>'pending','total_amount'=>..])` uses three columns that **do not exist** on `bookings` (real columns are `pnr`, `booking_status`, `grand_total`). Needs a genuine rewrite, not a patch. |
| 5 | `AdminPanelTest::test_payment_immutability_in_admin_panel` | (b) | Same team-id blocker as #4. Unlike #4/#6, this one's actual business assertion is still true today — confirmed `app/Filament/Resources/PaymentResource.php` registers no `EditAction`/`DeleteAction` at all. Cleanest of the three `AdminPanelTest` cases to actually fix. |
| 6 | `AdminPanelTest::test_booking_cancel_action_in_admin_panel` | (b) + deeper (a) | Same team-id blocker, same stale `Booking::create()` field names as #4. Booking-cancellation itself is already well covered by `TripCancellationTest.php`, `BookingAndFinancialEngineTest.php`, and `SecondBatchQuickFixesTest.php` — low priority to resurrect this specific test. |
| 7 | `BookingRedirectTest::test_booking_creation_redirects_to_view_page` | (a) stale | Uses `DatabaseTransactions` instead of `RefreshDatabase` (no migrations run — fails immediately with `no such table: users`), and assumes a hand-seeded `admin@zatara.com` user and an active trip instance that don't exist in the test environment. Even setting that aside: **the test body contains zero assertions** — it just computes a redirect URL and `echo`s it. Not a functioning test today. |
| 8 | `DatabaseSchemaTest::test_passenger_media_collections` | (a) stale | Same stale `Booking::create()` pattern as #4/#6 (`reference`/`status`/`total_amount`), plus `Passenger::create(['name'=>..,'passport_number'=>..])` — neither column exists (real columns are `first_name`/`last_name`/`document_number`), and it never sets the now-required `trip_passenger_category_id`, causing the actual fatal error. |
| 9 | `Filament\AdminBookingTest::test_admin_can_create_booking_and_identities_are_allocated_correctly` | (a) stale | Same `'Published'` invalid-enum + non-existent `TripPricingTier` pattern as #1-3. Same generation of pre-refactor tests. |
| 10 | `Livewire\CheckoutWizardTest::test_it_successfully_verifies_otp_and_advances_wizard` | (a) stale, shallow | `Livewire\Exceptions\MethodNotFoundException: Public method [submitPhone] not found`. `CheckoutWizard`'s actual Step-1 method is `submitLeadCapture()` — a straight rename the test never picked up. Cheapest fix in this whole list if resurrected (rename the call sites and re-verify the rest of the flow still matches). |
| 11 | `Livewire\CheckoutWizardTest::test_comprehensive_booking_flow_with_passenger_and_addons` | (a) stale, shallow | Same `submitPhone` issue as #10. The scenario itself (passengers + addons through checkout) is already covered by several current, passing tests (`CheckoutRoomPriceTransparencyTest`, `CheckoutLiveCategoryTotalTest`, `CheckoutPhaseDVisualPassTest`, etc.). |
| 12 | `StorefrontAndPortalTest::test_tenant_resolution_middleware` | (a) stale | `Route [storefront.home] not defined`. Tests a retired `/t/{slug}` URL scheme; the live route today is `/{tenant}` → `storefront.catalog`. Two sibling tests in this exact file were already deliberately removed for the identical reason, with inline comments explaining why — strong precedent this one should go the same way. |
| 13 | `AdminPanelTest::test_roleless_customer_cannot_access_admin_panel` | **(c) — see Priority Finding #1 above** | Not a stale test. It correctly predicted a real gap; the fix belongs in `CustomerResource`'s missing policy, not in the test. |
| 14 | `DatabaseSchemaTest::test_trip_relations` | (a) stale, shallow | `assertEquals('active', $instance->status)` — `status` is `TripStatusEnum`-cast now, so comparing to a raw string fails PHPUnit's strict comparator. The other two assertions in the same test (`$template->id`, `$template->base_price`) are still valid. Cheapest possible fix: compare `->status->value` or `TripStatusEnum::Active`. |
| 15 | `DatabaseSchemaTest::test_booking_with_international_extras_and_paid_amount_accessor` | (a) stale | `Booking::create()` sets `flight_details`/`hotel_details`/`insurance_details`/`visa_details`/`reference`/`status`/`total_amount` — **none of these seven columns exist** on `bookings` today. Eloquent silently drops them (not fillable), the booking is created essentially empty, and `assertDatabaseHas` correctly fails. Whatever these "international extras" fields were, they're gone from the schema. |
| 16 | `StorefrontAndPortalTest::test_customer_portal_login_and_dashboard` | (a) stale | Already self-documented in the same file: "Known-failing (pre-existing baseline failure, left in place per standing rule): exercises `/t/{tenant_slug}/portal/send-otp` and `/verify-otp`, which were already dead before the Customer Portal Consolidation... `portal.dashboard` is now a redirect to `storefront.my-bookings` rather than a page, so this test would need a full rewrite, not a fix." Nothing to add — a prior pass already correctly diagnosed this one. |

**Pattern worth naming:** tests #1, #2, #3, #4, #6, #8, #9, #15 all share one specific fingerprint — `Booking::create()` (or a fixture) using an old field shape (`reference`/`status`/`total_amount`/`TripPricingTier`/`'Published'`) that predates the current schema (`pnr`/`booking_status`/`grand_total`/`TripPassengerCategory`). These look like one generation of tests written before a booking-model refactor that was never revisited. Worth deleting as a batch rather than one-by-one, once approved — a partial fix (e.g., just the Spatie team-id issue in #4/#6) would still leave them broken on the deeper schema mismatch.

---

## 2. Deployment infrastructure

| Item | Status | Detail |
|---|---|---|
| Dockerfile / docker-compose.yml | 🔴 Needs a code fix first (if containerized deploy is the plan) | Confirmed absent — nothing at any depth in the repo (only vendor packages' own unrelated Docker files under `vendor/`, not usable). |
| CI/CD (`.github/workflows` or any system) | 🔴 Needs a code fix first (if CI is wanted) | No `.github/workflows` directory exists for this project at all. Every `.yml`/`.yaml` CI file found lives inside `vendor/` (other packages' own CI, irrelevant to this app). Nothing runs the test suite automatically on push/PR today. |
| Documented/scripted deployment process | ⚠️ Needs stakeholder action (non-code) or 🔴 depending on approach | `README.md` is the **unmodified default Laravel skeleton** — no project name, no setup instructions, no deployment notes, nothing Zatara-specific at all. No deploy script, no Forge/Envoyer config, no Procfile. |
| **Frontend asset build step (`npm run build` + `npm run build:admin-theme`)** | 🔴 **Confirmed gap, not theoretical — demonstrated live this session** | **Nothing in this repo runs either build command automatically, anywhere.** Confirmed by checking every mechanism that could: no `.github/workflows` (none exists), `composer.json`'s `scripts` block (`post-autoload-dump`, `post-update-cmd`, `post-root-package-install`, `post-create-project-cmd`) only handles PHP/Laravel-side setup (package discovery, `filament:upgrade`, `vendor:publish`, `key:generate`, `migrate`) and never shells out to `npm`, no git hooks are configured (`.git/hooks/` has only the default `.sample` files), no Husky, no Procfile/Dockerfile/nixpacks/render config of any kind. `public/build/` (Vite's output for the storefront) is gitignored, so it is never in the deployed code either way — it only exists locally, on whatever machine last ran `npm run build`. **A deploy that pulls new code and restarts the app server will silently keep serving the old compiled CSS/JS forever, with no error, no warning, and no visual sign anything is wrong except the page just not reflecting the new code** — a customer-facing regression indistinguishable from "the deploy didn't happen" unless someone specifically thinks to rebuild. Live-reproduced during the storefront header-link fix earlier this session: a newly-added Tailwind utility class (`md:inline`, never used anywhere in the codebase before that change) was correctly present in the Blade source but silently rendered as `display: none` at every viewport width, because the class had never been generated into the compiled CSS bundle — confirmed via computed-style inspection in a real browser, fixed only by running `npm run build` and re-verifying. **Every deploy MUST run both `npm run build` (Vite — storefront CSS/JS) and `npm run build:admin-theme` (Filament admin panel CSS) after pulling code, or CSS/styling changes will silently not appear** — this needs to be a mandatory, scripted step in whatever deploy process gets built (CI/CD pipeline, a deploy script, or at minimum a documented manual checklist item with no ambiguity about when it runs), not something left to a deployer's memory. |
| Runtime versions the app assumes | ✅ Ready (documented, just not centrally) | `composer.json`: `"php": "^8.2"`, `laravel/framework: ^11.0`. `package.json`: no `"engines"` field pinning a Node version (a real gap for reproducible builds — dev environment happens to use Node 22, but nothing enforces or documents that). PHP extensions aren't declared in the app's own `composer.json` (`ext-*` requirements only appear transitively via `composer.lock` from dependencies) — a deployer has to infer gd, dom, mbstring, zip, json, zlib, intl, bcmath, iconv, curl are needed, rather than being told directly. |

**Bottom line:** there is currently no deployment automation and no deployment documentation of any kind. A real deployment today would be entirely manual and undocumented — someone would need to reconstruct the process from first principles (provision PHP 8.2+ with the right extensions, MySQL, Node+Chrome for PDFs, set up a queue worker and a cron entry, run migrations, build assets) with nothing in the repo to guide them. The frontend build step specifically (row above) is not a hypothetical risk on this list — it already caused a real, live-reproduced bug during this session's own work, and will keep recurring on every single deploy until it's made an explicit, non-optional, scripted part of the deploy process.

---

## 3. Environment variable audit

Every `env('...')` call across `app/`, `config/`, `routes/` was collected and cross-referenced against `.env.example`.

**Good news first:** `.env.example` is genuinely well-maintained for the project-specific variables — it already documents `BROWSERSHOT_NODE_PATH`/`BROWSERSHOT_NPM_PATH` with a clear explanatory comment (from this session's earlier PDF fix), Google/Apple OAuth, the Atlahub WhatsApp API credentials, and Stripe keys (explicitly marked "disabled currently").

**Missing from `.env.example` but read by the app or its config (real gaps):**

| Variable | Where used | Why it matters |
|---|---|---|
| `SESSION_SECURE_COOKIE` | `config/session.php:173`, no default (`null`) | Security-relevant — see Section 5. Not in `.env.example` at all, and has no safe fallback; needs to be explicitly set for production. |
| `SESSION_SAME_SITE` | `config/session.php:203`, defaults `'lax'` | Not in `.env.example`; default is reasonable, but worth the stakeholder explicitly confirming rather than relying on an implicit framework default. |
| `SESSION_HTTP_ONLY` | `config/session.php:186`, defaults `true` | Same — reasonable default, not explicit in `.env.example`. |
| `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | `config/database.php` | Present in `.env.example` but **commented out**, since the example defaults to `DB_CONNECTION=sqlite`. Production will be MySQL (per composer.lock's MySQL-specific packages and this project's whole architecture) — a deployer needs these five uncommented and filled in; nothing forces that. |

**Everything else** (`APP_KEY`, `APP_URL`, `MAIL_*`, `AWS_*`, `QUEUE_CONNECTION`, `CACHE_STORE`, `GOOGLE_*`, `APPLE_*`, `ATLAHUB_*`, `STRIPE_*`, `BROWSERSHOT_*`) is already present in `.env.example`. The framework-level driver-specific variables that showed up in the grep (Redis, Memcached, DynamoDB, SQS, Beanstalkd, Postmark, Slack logging, Papertrail) are Laravel's own stock `config/*.php` skeleton entries — they're only "used" if that specific driver is selected, which this app doesn't do by default, so they aren't real gaps.

**Stakeholder checklist to walk against the real production server:**

- [ ] `APP_ENV=production`, `APP_DEBUG=false` (see Section 5 — this is not optional)
- [ ] `APP_KEY` generated fresh for production (never reuse a dev key)
- [ ] `APP_URL` set to the real production HTTPS URL
- [ ] `DB_CONNECTION=mysql` + `DB_HOST`/`DB_PORT`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` filled in (all currently commented out in `.env.example`)
- [ ] `SESSION_SECURE_COOKIE=true` (currently has no default — see Section 5)
- [ ] `QUEUE_CONNECTION` — confirm `database` (the `.env.example` default) is actually intended, and that a worker will run against it (see Section 4)
- [ ] `MAIL_*` — real SMTP credentials, not the Mailgun placeholder
- [ ] `GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET`/`GOOGLE_REDIRECT_URI` — only if Google login is actually going live
- [ ] `APPLE_*` (6 vars) — only if Apple login is actually going live
- [ ] `ATLAHUB_ACCOUNT_ID`/`ATLAHUB_INBOX_ID`/`ATLAHUB_API_TOKEN` — **required** if Priority Finding #2 (OTP delivery) is fixed via this channel
- [ ] `STRIPE_SECRET_KEY`/`STRIPE_WEBHOOK_SECRET` — leave blank; Stripe is explicitly disabled in the checkout flow today (`CheckoutWizard::submitBooking()` aborts with 403 if `paymentMethod === 'stripe'`)
- [ ] `BROWSERSHOT_NODE_PATH`/`BROWSERSHOT_NPM_PATH` — set to `which node`/`which npm` on the actual production host (see Section 4)

---

## 4. Critical runtime dependencies

| Dependency | Required for | Status |
|---|---|---|
| Node.js + a working Chrome/Chromium binary | `spatie/browsershot` (`^5.4`) via `spatie/laravel-pdf` (`^2.12`) — every PDF: tickets, manifests, rooming lists | ⚠️ Needs stakeholder action. This was the subject of an earlier fix this session (the `BROWSERSHOT_NODE_PATH`/`NPM_PATH` env vars now exist specifically because PDF generation silently failed when `node`/`npm` weren't on the PHP process's inherited `$PATH`). The `.env.example` comment on this is excellent and already warns about exactly this. Production still needs Node + Chrome physically installed on the server, and those two env vars set to their absolute paths — nothing in the app verifies this at boot; if unset/wrong, PDF generation fails when a user requests one, not before. |
| A running queue worker (`php artisan queue:work`) | Many dispatched jobs: `SendBookingNotificationJob`, `AbandonedCartRecovery`, `PreDepartureReminder`, `AutoManifestDistribution`, `PostTripReviewRequest`, `YieldPricingJob`, `WaitlistAutoPromotion`, `ReleaseWaitlistHold`, `SendAtlahubWhatsAppJob`, `BulkGenerateTripInstances`, and others | 🔴 Needs stakeholder action — with `QUEUE_CONNECTION=database` (the `.env.example` default), dispatched jobs just accumulate in the `jobs` table forever if no worker process is running. **Silent failure**: no error is raised anywhere, booking confirmations/notifications simply never send. A persistent `queue:work` process (supervisor/systemd) is required. |
| A cron entry running `php artisan schedule:run` every minute | `routes/console.php`: `app:release-expired-holds` every 5 minutes, plus 5 daily/half-hourly/hourly jobs (abandoned-cart recovery, yield pricing, pre-departure reminders, auto-manifest distribution, post-trip review requests) | 🔴 Needs stakeholder action — same silent-failure class. Most importantly, `app:release-expired-holds` is what returns abandoned seat holds to inventory. Without the cron entry, holds never expire, and the trip will appear to sell out over time even with real available seats — a genuinely dangerous, quiet failure mode for a booking system. |
| Real SMS/WhatsApp/email delivery for OTP | Customer login | 🔴 See Priority Finding #2 — currently a no-op in every environment including production. |

**Failure-mode summary, as asked:** none of these four dependencies fail loudly. Missing Node/Chrome fails per-request when a PDF is requested (a user-visible error at that moment, at least — not silent, but also not caught at deploy time). Missing queue worker, missing cron, and the OTP no-op all fail **completely silently** — the app looks like it's working, returns success responses, and simply never does the thing it claimed to do. This is the same class of problem as the original Browsershot PDF bug found earlier this session, now confirmed in three more places.

---

## 5. Security basics check

| Item | Status | Detail |
|---|---|---|
| `APP_DEBUG=false` for production | ⚠️ Needs stakeholder action (non-code, but see caveat) | `.env.example` already sets `APP_DEBUG=false` — the *example* is correct. This is purely an environment-config concern: whatever `.env` actually gets deployed must have `APP_DEBUG=false`, and nothing in the code enforces it. This is exactly what was live-observed during the earlier storefront UX audit this session (a debug stack trace was shown to a simulated "customer") — that was a misconfigured environment, not a code defect, and the example file already gets this right. The residual risk is purely operational: whoever deploys must not accidentally leave debug mode on or copy a dev `.env`. |
| No hardcoded `http://` that should be `https://` | ✅ Ready | Searched `app/`, `resources/views/`, `config/`, `routes/` for `http://` — zero matches outside of `127.0.0.1`/`localhost` (dev-only) and unrelated XML namespace URLs (`schema.org`, `w3.org`). `APP_URL` in `.env.example` is already `https://zatara.com`. |
| Session/cookie security config | ⚠️ Needs stakeholder action | See Section 3 — `SESSION_SECURE_COOKIE` has no default value in `config/session.php` and is absent from `.env.example`. `SESSION_HTTP_ONLY` (default `true`) and `SESSION_SAME_SITE` (default `'lax'`) are both reasonable as-is, but neither is called out explicitly, so a deployer has to already know Laravel's defaults are fine rather than being told. |

---

## 6. Backup strategy

🔴 **No backup mechanism exists in the codebase at all.** Confirmed: `spatie/laravel-backup` is not in `composer.json` or `composer.lock`; no custom backup Artisan command exists anywhere under `app/Console/Commands`; nothing in `routes/console.php`'s schedule touches backups.

This is a real, plainly-stated gap — not a code problem to fix here, but something the stakeholder needs to solve at the hosting level before launch (most managed hosts, and managed MySQL in particular, offer automated backups/point-in-time recovery as a checkbox, not custom code). Worth calling out explicitly given the file-deletion incident earlier this session: that was recovered because the deleted files turned out to be disposable test screenshots the stakeholder could confirm by memory. A real production incident — a bad migration, an accidental bulk delete, a compromised admin account — would have no database-level safety net today. This should be resolved before go-live, not after.

---

## What's already working well

For balance, several things here are genuinely solid:

- **`.env.example` is well above average.** Most Laravel projects ship a bare-bones example file; this one documents the Browsershot PATH issue with a real explanatory comment, groups related OAuth/WhatsApp/Stripe vars clearly, and defaults `APP_DEBUG=false` and `APP_URL` to a real HTTPS domain rather than leaving placeholders.
- **No hardcoded insecure URLs anywhere** in the searched application code.
- **The permission/policy model is real and mostly consistently applied** — `BookingResource`, `PaymentResource`, and most other tenant-facing resources correctly enforce Shield-generated permissions via dedicated policies. The `CustomerResource` gap (Priority Finding #1) is the exception, not the pattern; it reads like a single resource that was added without going through the same checklist as the others, not a systemic design flaw.
- **The scheduler already has the right jobs registered** (`app:release-expired-holds` chief among them) — the gap is operational (is cron actually running it in production), not that anyone forgot to write the job.
- **Test coverage for the *current* schema is genuinely strong.** `BookingAndFinancialEngineTest.php` alone is 23 passing tests exercising the live `CreateBookingService` path in detail; the 16 baseline failures are essentially all attributable to one earlier generation of tests that predates a booking-model refactor and was never revisited, not to widespread fragility in the current suite (390 of 406 tests pass).
- **The `BROWSERSHOT_NODE_PATH`/`NPM_PATH` fix already shipped** with a genuinely excellent inline comment explaining exactly why it's needed — a real example of turning a silent-failure incident into documented, defensive configuration.
