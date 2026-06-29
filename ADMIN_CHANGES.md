# Admin Workflow Simplification

This document details the changes introduced to radically simplify the admin workflow for Trip Management and Logistics.

## 1. Unified Trip Builder Wizard
The previous 2-step flow (`TripTemplate` -> `TripInstance`) was consolidated into a single `TripBuilderResource` wizard.
- **Wizard Steps:**
  1. **Trip Info:** Basic details and Logistics assignment.
  2. **Pricing & Addons:** Tiers, Addons, and Deposit settings.
  3. **Schedule:** Options for Single Date or Recurring Schedules.
  4. **Publish:** Review and create.
- **Automation:** Submitting the wizard creates the base `TripTemplate` and dispatches the `BulkGenerateTripInstances` background job.
- **Admin Notification:** Once the job finishes generating the requested instances, a Filament database notification is sent to the creator.

## 2. Logistics System
- **Models & Migrations:** Created `PickupRoute`, `PickupPoint`, and `BookingPickup` models.
- **Navigation:** Grouped under a new "اللوجستيات (Logistics)" section in the sidebar.
- **Checkout Wizard Integration:** The Storefront Checkout now dynamically displays available Pickup Points based on the `PickupRoute`s attached to the `TripInstance`.
- **Database:** `BookingPickup` records the selected pickup point per passenger.

## 3. Bus Manifest PDF Generation
- **PDF Generation:** Replaced `spatie/laravel-pdf` with `barryvdh/laravel-dompdf` for environment-independent PDF rendering.
- **Manifest Action:** A new "تحميل كشف الركاب (PDF)" table action was added to `TripInstanceResource`. It generates an organized, printable PDF grouped by `PickupPoint` and ordered by time.
- **WhatsApp Action:** A bulk action was added to queue sending the generated manifests to guides via WhatsApp (placeholder logic ready for gateway integration).

## 4. Trip Cloning
- **Clone Trip Action:** Added to `TripInstanceResource`. It duplicates an existing trip instance (including categories, addons, and pickup routes) to a new date without copying the bookings.

## 5. UI Cleanup
- `TripTemplateResource` has been hidden from the main navigation to encourage the use of the new Wizard, though it remains accessible via URL for legacy data management.
