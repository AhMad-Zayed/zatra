# Hardcoded Storefront Content Audit

Systematic sweep of every customer-facing view (storefront Livewire components, the shared
storefront layout, transactional emails/notifications, PDFs) for business-owned content —
copy, branding, contact info, policy text — that is hardcoded in Blade/PHP instead of editable
from `ManageAgencySettings`. UI chrome (button labels, form labels, system/validation messages)
is out of scope by design and is not listed below.

Scope confirmed already-editable (not re-audited, not re-implemented):
- **Trip photo galleries** — `TripTemplateResource` (`cover` + `gallery` SpatieMediaLibraryFileUpload)
  are admin-editable and rendered correctly in `storefront-catalog.blade.php` / `trip-details.blade.php`. Done.
- **Legal pages** (terms, privacy, refund policy) — `Tenant::terms_conditions/privacy_policy/refund_policy`
  are `RichEditor` fields in `ManageAgencySettings`, served at `/{tenant}/legal/{document}` via
  `LegalDocumentController` + `storefront/legal-document.blade.php`. Done — this audit's
  named "legal pages, check if they even exist" concern is resolved; they exist and are fully editable.
- **Footer** — contact phone/email/address/hours, WhatsApp, social links, FAQs, tourism license
  number, and the three legal links are all sourced from `settings`/`Tenant` columns already. One
  gap found (tagline paragraph) — see below.

## Findings

### A. New settings fields (implemented this pass)

| # | What's hardcoded | Where | Fix |
|---|---|---|---|
| 1 | Hero headline "رحلتك القادمة تبدأ من هنا" | `storefront-catalog.blade.php:53` | New `settings['hero_headline']`, falls back to current copy |
| 2 | Hero subheading "اكتشف أروع الوجهات..." | `storefront-catalog.blade.php:56` | New `settings['hero_subheading']`, same fallback pattern |
| 3 | Trips section eyebrow "الوجهات الرائجة" + title "اختر مغامرتك القادمة" | `storefront-catalog.blade.php:202-203` | New `settings['trips_section_eyebrow']` / `settings['trips_section_title']` — confirmed with this audit: not a fixed UI label, it's tenant marketing copy for the section, so it's now editable like the hero |
| 4 | Footer "about us" paragraph "نقدم لك تجارب سفر مصممة بعناية..." | `components/layouts/storefront.blade.php:222-224` | New `settings['agency_tagline']` |
| 5 | Email header tagline "اكتشف العالم بالفخامة التي تستحقها" | `emails/booking-confirmed.blade.php:19` | Reuses the **same** `settings['agency_tagline']` as #4 rather than a second field — one place to edit the agency's one-line pitch, shown in both places it appears today |
| 6 | `meta_description` was already *read* by the layout (`components/layouts/storefront.blade.php:7,9`) but had **no admin field to set it** — always fell back to a generic hardcoded description | `ManageAgencySettings` | Added `settings['meta_description']` as a form field (view already wired) |
| 7 | Tenant logo (`Tenant::logo` media collection registered, `hasMedia('logo')`/`getFirstMediaUrl('logo')` never had an upload control anywhere in the admin) | `app/Models/Tenant.php:18`, no admin field existed | Added a `FileUpload` in `ManageAgencySettings` that writes to the `logo` media collection on save |
| 8 | Tenant hero image (`Tenant::hero_image` media collection + `hero` conversion registered and already *read* by the catalog hero — `storefront-catalog.blade.php:15` — but likewise had no upload control anywhere) | same as above | Added a `FileUpload` for `hero_image` |

All eight are quick, additive settings-field work, following the exact `ManageAgencySettings`
pattern (light data → `settings` JSON merge in `save()`; heavy/media data handled explicitly).
Every field is optional and every consuming view keeps its current copy as the `??` fallback, so
a tenant that never touches these fields sees **zero visual change**.

### B. Correctness bugs found during the sweep (fixed, not settings work)

These aren't "make it editable" items — they're places where the *wrong* hardcoded value was
already shown to every tenant, a category the task explicitly flagged (worth listing since they
were found while doing this sweep, and they're one-line Blade fixes with no risk to the guarded
services):

| # | Bug | Where | Fix |
|---|---|---|---|
| 9 | Header `<img>` logo pointed at a **static asset that doesn't exist in the repo** (`public/images/logo.png`), completely ignoring the tenant's uploaded `logo` media — every tenant's logo silently 404'd and fell back to the plain-text name | `components/layouts/storefront.blade.php:54` | Now reads `$currentTenant->getFirstMediaUrl('logo')` (same `hasMedia()` graceful-fallback pattern the now-dead `resources/views/layouts/storefront.blade.php` already used correctly) |
| 10 | Checkout wizard greets every customer, on every tenant, with **"مرحباً بك في زتارة"** — a different tenant's own brand name hardcoded into the welcome step and into both versions of the booking-confirmation disclaimer | `checkout-wizard.blade.php:100, 588, 590` | Now interpolates the real `$tenant->name` (already a public property on `CheckoutWizard`) |
| 11 | Booking-success page's "pay via bank transfer" WhatsApp deep link reads `$booking->tenant->settings['whatsapp']` — **the wrong settings key** (every other WhatsApp link in the app correctly reads `whatsapp_number`, per `docs/STOREFRONT_UX_AUDIT.md`'s prior contact-channel fix) — so it always silently used the placeholder fallback number regardless of what the tenant configured; the pre-filled WhatsApp message text also hardcodes "مرحباً زتارة" | `booking-success.blade.php:130` | Fixed key to `whatsapp_number`, aligned the fallback number to the same `970599000000` placeholder used everywhere else, and swapped the hardcoded brand name for `$booking->tenant->name` |
| 12 | Booking-confirmation email's "View my portal" CTA button builds its URL with `route('storefront.catalog', ['tenant_slug' => ...])` — **the route's actual parameter is named `tenant`**, not `tenant_slug` — so this call throws a missing-route-parameter exception on every real send | `emails/booking-confirmed.blade.php:58` | Fixed the route parameter name |
| 13 | Same email also references `$booking->tripInstance->template->title` — **`TripInstance` has no `template()` relation**, only `tripTemplate()`; this resolved to `null->title`, a crash, discovered while writing this pass's regression test (rendering the real mailable throws immediately, before either bug above is even reached) | `emails/booking-confirmed.blade.php:38` | Fixed to `tripInstance->tripTemplate->title` — this specific email view was, as far as this audit can tell, unable to render at all before this fix |

### C. Flagged for stakeholder decision — not implemented unilaterally

- **"وجهاتنا" (Destinations) and "عن زتارة" (About Us) header/nav links point at `href="#"`.**
  Per `docs/STOREFRONT_UX_AUDIT.md`'s prior Quick Win #4, these were explicitly left dead because
  "inventing destination/about content is out of scope for a bugfix pass." This audit confirms
  there is still no content model behind either: no About-Us copy field anywhere, no destinations
  concept beyond the trip catalog itself. Two real options exist and they have different costs:
  1. **Small**: hide/remove the two dead nav links (and their mobile-drawer twins) until real
     pages exist — a few lines, no new admin surface.
  2. **Larger**: build a real "About Us" editable page (and/or a "Destinations" concept) — this
     needs a product decision (is "Destinations" just a curated subset of trips, or a separate
     content type?) and a new admin content-management surface, not just a settings field.

  **Not implemented in this pass** — this is exactly the kind of structural/product call this
  task asked to flag rather than decide unilaterally.

### D. Reviewed, judged low-value to make individually editable (left as-is)

- The three homepage "trust signal" cards' body copy (`storefront-catalog.blade.php:173-187`,
  e.g. "دعم فوري عبر واتساب" / "تواصل معنا مباشرة لأي استفسار") ties directly to real product
  behavior (WhatsApp support, the two actual payment methods, the real cancellation flow) rather
  than free-form marketing voice — per the file's own comment, this was deliberately kept factual
  rather than invented. Making each of the 6 strings independently editable adds real settings-UI
  surface for content that would drift out of sync with the product if edited carelessly (e.g. a
  tenant editing away the "cash or bank transfer" claim while those are still the only two
  payment methods checkout offers). Flagging as optional/low-priority rather than implementing.
- The generic per-addon fallback copy `'إضافة رائعة تمنحك المزيد من الراحة والرفاهية خلال رحلتك.'`
  (`checkout-wizard.blade.php:315`, shown only when an individual addon has no admin-entered
  description) is a placeholder-of-last-resort in the same family as the existing trip-description
  and trip-cover placeholder fallbacks already accepted elsewhere in this codebase, not a
  tenant-wide brand message. Lowest priority; not implemented.

### E. Dead code noticed in passing (not touched — no live customer-facing impact)

Three files are unreferenced by any route or Livewire `#[Layout(...)]` attribute and cannot be
reached by a real request: `resources/views/storefront/home.blade.php`,
`resources/views/layouts/storefront.blade.php` (non-`components` path), and
`resources/views/pdf/ticket.blade.php`. All three contain their own stale hardcoded "زاتارا
للسياحة" copy. Left alone — deleting dead files is outside this audit's scope (hardcoded
*live* content), but noted here since they'd otherwise look like unaudited findings to a future
reader grepping for the same brand strings.

## TripTemplate-level content

No new TripTemplate fields were needed. Every trip-specific piece of storefront content
(description, itinerary, includes/excludes, destination map coordinates, cover + gallery images)
already has a real, admin-editable DB column or media collection wired through
`TripTemplateResource`, confirmed while reading `trip-details.blade.php` end to end.

## Effort summary

- **Quick (settings field, implemented):** items 1–8.
- **Quick (bugfix, implemented):** items 9–13.
- **Needs a product decision before any implementation (flagged, not implemented):** item C.
- **Reviewed and intentionally not implemented:** item D.
