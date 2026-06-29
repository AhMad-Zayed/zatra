# Fixes Log

### FIX #1
- **Broken:** `ReleaseExpiredHolds` created a compensating positive entry for expired holds, which artificially inflated the seat count since the expired hold was already excluded from the `sum()` query.
- **File:** `app/Console/Commands/ReleaseExpiredHolds.php`
- **Fix:** Removed the `InventoryLedger::create()` logic and instead directly updated the expired hold to `type = 'expired'` with a single query.

### FIX #2
- **Broken:** Admin cancellation updated booking status to Cancelled but never returned the seats to the `InventoryLedger`, causing permanent seat loss.
- **File:** `app/Filament/Resources/BookingResource.php`
- **Fix:** Added `InventoryLedger::create()` with a positive quantity (cancellation_reversal) inside the cancellation transaction.

### FIX #3
- **Broken:** Waitlist VIP Link Overselling
- **File:** `app/Filament/Resources/TripInstanceResource/RelationManagers/WaitingListsRelationManager.php`
- **Fix:** Added a DB transaction with pessimistic locking, verified the instance has remaining seats, and created a 2-hour hold in the `InventoryLedger` before generating the temporary signed URL.

### FIX #4
- **Broken:** Magic Login Cross-Tenant IDOR
- **File:** `routes/web.php` & `app/Services/CreateBookingService.php`
- **Fix:** Included `tenant_id` in the signed magic link URL generation and validated it in the route lookup.

### FIX #5
- **Broken:** PDF Generator Hardcoded Paths
- **File:** `app/Livewire/BookingSuccess.php` & `config/services.php`
- **Fix:** Removed the hardcoded `.nvm` node and npm paths and replaced them with configurable environment variables via `config('services.browsershot')`.

### FIX #6
- **Broken:** Wrong Money Cast on Payment and Addon Models (used `decimal:2` instead of `MoneyCast`)
- **File:** `app/Models/*.php`
- **Fix:** Replaced `'decimal:2'` with `\App\Casts\MoneyCast::class` across all relevant Models to ensure correct cent-level precision and prevent rounding errors.

### FIX #7
- **Broken:** N+1 Query in CheckoutWizard
- **File:** `app/Livewire/CheckoutWizard.php`
- **Fix:** Extracted `TripPassengerCategory::find()` and `TripAddon::find()` out of the `foreach` loops. Used `whereIn` and `keyBy('id')` to fetch all necessary records in exactly two queries.

### FIX #8
- **Broken:** Pessimistic Lock Missing in Admin Payment Recording
- **File:** `app/Filament/Resources/BookingResource/Pages/ViewBooking.php`
- **Fix:** Added `Booking::lockForUpdate()->find($record->id)` inside the `DB::transaction()` to prevent race conditions during concurrent admin payment processing.

### FIX #9
- **Broken:** Social Auth Account Hijack Vector
- **File:** `app/Http/Controllers/Auth/SocialAuthController.php`
- **Fix:** Prevented automatic linking of social accounts to existing email addresses if they are already associated with a different provider.

### FIX #10
- **Broken:** Spatie Role Policy Stub Variables (`{{ ForceDelete }}`)
- **File:** `app/Policies/RolePolicy.php`
- **Fix:** Replaced generator stub variables with actual permission names like `force_delete_role` and `restore_role`.

### FIX #11
- **Broken:** Orphaned Checkout Component
- **File:** `app/Livewire/Checkout.php` & `resources/views/livewire/checkout.blade.php`
- **Fix:** Deleted these obsolete files as they were superseded by `CheckoutWizard` and posed a maintenance hazard.
