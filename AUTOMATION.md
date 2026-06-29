# Zatara Travel Automation Engine ⚙️

The system includes a suite of background jobs utilizing Laravel Queues and the Task Scheduler to automate trip operations.

## Jobs
1. **AbandonedCartRecovery** (Runs: every 30 mins)
   - **Trigger:** Guest Sessions older than 30 mins with an unconfirmed hold.
   - **Action:** Notifies the user via WhatsApp to resume checkout.
2. **WaitlistAutoPromotion** (Triggered: On Booking Cancellation)
   - **Trigger:** Dispatched by `BookingObserver` when a booking is cancelled.
   - **Action:** Checks if the next person on the waitlist can fit the freed up inventory. Applies a 2-hour hold, updates status, and dispatches a delayed release job.
3. **ReleaseWaitlistHold** (Delayed Job: 2 hours)
   - **Trigger:** Dispatched by `WaitlistAutoPromotion`.
   - **Action:** If the hold is still pending (unpaid), it expires it and triggers `WaitlistAutoPromotion` again to move to the next person.
4. **PreDepartureReminder** (Runs: Daily at 20:00)
   - **Trigger:** Trips departing tomorrow.
   - **Action:** WhatsApps confirmed passengers their outstanding balance and pickup locations.
5. **PostTripReviewRequest** (Runs: Daily at 10:00)
   - **Trigger:** Trips that ended yesterday.
   - **Action:** WhatsApps passengers asking for a Google Review and updates the booking flag.
6. **AutoManifestDistribution** (Runs: Daily at 20:00)
   - **Trigger:** Trips departing tomorrow.
   - **Action:** Emails/WhatsApps the generated passenger manifest to the guides.
7. **YieldPricingJob** (Runs: Hourly)
   - **Trigger:** Trips > 3 days away with ≤ 5 seats remaining.
   - **Action:** Applies a 15% override surcharge to the remaining seats (`price_override = true` on `TripInstance`).

## Monitoring
- **Admin Dashboard:** Access the "Automation Engine Status" widget on the Filament dashboard to monitor job health.
- **Log Table:** The `notification_logs` table prevents duplicate messages.
- **Run Table:** The `automation_runs` table tracks job metrics.

## Running Locally
To test scheduled commands locally:
```bash
php artisan schedule:run
php artisan queue:work
```
