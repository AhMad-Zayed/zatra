# CHANGES - Critical Bug Fixes & Features (Phase 17)

## 1. Race Condition on Seat Booking (Overbooking Bug)
- **Problem**: Simultaneous bookings relied on `available_seats` which caused race conditions.
- **Solution**: 
  - Created `InventoryLedger` model and migration (`add_inventory_ledger_table`).
  - Added pessimistic locking logic in `CreateBookingService.php` to calculate available seats dynamically by summing `quantity` in `InventoryLedger` where `expires_at` is null or in the future.
  - Implemented `InsufficientSeatsException` which is thrown if `available_seats` is less than `requestedSeats`.
  - Created an artisan command `ReleaseExpiredHolds` to release holds that expired.
  - Updated `routes/console.php` to run `app:release-expired-holds` every five minutes.

## 2. Decoupled Addons (Missing Passenger Link)
- **Problem**: `booking_addons` lacked a link to a specific `passenger_id`.
- **Solution**:
  - Created migration `add_passenger_id_to_booking_addons_table`.
  - Updated `BookingAddon` model to include `passenger_id` and relationship to `Passenger`.
  - Updated `BookingResource` (Filament Admin Panel) Step 3 (Addons) repeater to include `passenger_id` select dropdown.
  - Added `getPassengerWithAddons` method to `TripInstance` for easy querying.

## 3. Deposit / Partial Payment System
- **Problem**: No ability to collect partial payments/deposits.
- **Solution**:
  - Added `deposit_percentage` and `deposit_enabled` to `TripTemplate` model and table (`add_deposit_fields_to_trip_templates_table`).
  - Added `payment_type` and `deposit_amount` to `Booking` model and table (`add_payment_fields_to_bookings_table`).
  - Added `getBalanceDueAttribute` to `Booking.php`.
  - Updated `CheckoutWizard.blade.php` to show Deposit payment option to customers if `deposit_enabled` is true for the selected trip template.
  - Added 'Collect Remaining Balance' action in `BookingResource` table for bookings with `ConfirmedPartial` status.
  - Updated `BookingStatus` enum to include `ConfirmedPartial` ('مؤكد (عربون)').

## 4. Travel Requirement Presets
- **Problem**: Manual requirement typing per template.
- **Solution**:
  - Created `RequirementPreset` model and migration.
  - Seeded standard presets via `RequirementPresetSeeder`.
  - Added `requirement_preset_id` to `TripTemplate`.
  - Updated `TripTemplateResource` form to allow selection of presets, which automatically loads `passenger_requirements`.
