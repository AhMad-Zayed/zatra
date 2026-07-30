# Zatara Admin Panel — Fixes Log
**Started:** 2026-06-30

## Fix History

[CRIT-005] Fixed: PassengersRelationManager form/table now uses correct Passenger model fields (first_name, last_name, document_type, document_number, date_of_birth, gender, trip_passenger_category_id, pickup_point_id) — replaced broken name/passport_number fields in PassengersRelationManager.php
[CRIT-003] Fixed: Replaced dynamic_data KeyValue with actual Passenger fields (first_name, last_name, document_type, document_number, date_of_birth, gender, pickup_point_id) in BookingResource.php passenger repeater
[CRIT-004] Fixed: Removed ->dehydrated(false) from passenger unit_price, added ->live() to Placeholder, price now correctly calculated in dollars (cents/100) in BookingResource.php
[CRIT-002] Fixed: balance_due color comparison changed to <= 0 (correct for dollar values via MoneyCast), kept ->sortable() since it IS a real DB column in BookingResource.php
[CRIT-007] Fixed: expires_at now has ->default(now()->addHours(24)) and ->required() so cash bookings never auto-expire immediately in BookingResource.php
[SEC-001] Fixed: DeleteBulkAction on Bookings now restricted to agency_admin role only in BookingResource.php
[SEC-004] Fixed: confirm_cash action now requires agency_admin or accountant role in BookingResource.php
[SEC-005] Fixed: process_cancellation action now requires agency_admin or accountant role in BookingResource.php
[LABEL-001] Fixed: customer_id label 'العميل (Lead Customer)' → 'العميل الرئيسي' in BookingResource.php
[LABEL-002] Fixed: trip_instance_id label 'موعد الرحلة (Trip Instance)' → 'موعد الرحلة' in BookingResource.php
[LABEL-003] Fixed: Section 'المسافرون (Passengers)' → 'بيانات المسافرين' in BookingResource.php
[LABEL-004] Fixed: Section 'الإضافات (Addons)' → 'الخدمات الإضافية' in BookingResource.php
[N+1-001] Fixed: Added getEloquentQuery() with ->with(['customer','tripInstance.tripTemplate','passengers']) to BookingResource.php
[MEDIUM-001] Fixed: Added passengers_count column to BookingResource table
[MEDIUM-002] Fixed: Added user.name (staff) column to BookingResource table
[VALID-001] Fixed: Added ->minItems(1) to passengers repeater in BookingResource.php
[MISSING-INFO-001] Fixed: Added remaining seats hint to trip_instance_id select in BookingResource.php
[CRIT-001] Fixed: Deleted duplicate BookingStatsWidget and merged it into DashboardStatsOverview. Kept /100 division since DB stores integer cents.
[LABEL-016] Fixed: Arabic month names in RevenueChart.php
[CRIT-006] Fixed: TripBuilderResource navigation group changed to pure Arabic 'الرحلات'
[CRIT-008] Fixed: Added canAccess() role guard (agency_admin/accountant) to ActivityLogResource.php
[N+1-003] Fixed: Added getEloquentQuery() with ->with(['tripTemplate']) to TripInstanceResource.php
[SEC-002] Fixed: Added role guard (agency_admin) to DeleteAction in TripInstanceResource.php
[SEC-003] Fixed: Added role guard (agency_admin) to DeleteBulkAction in TripInstanceResource.php
[HIGH-004] Fixed: Added functional filters (status, upcoming, date_range, has_available_seats) to TripInstanceResource.php
[HIGH-002] Fixed: Added missing table columns and default sort to PickupPointResource.php
[LABEL-017] Fixed: PickupPointResource navigation group changed to pure Arabic 'اللوجستيات'
[HIGH-003] Fixed: Added missing table columns and active filter to PickupRouteResource.php
[LABEL-018] Fixed: PickupRouteResource navigation group changed to pure Arabic 'اللوجستيات'
[LABEL-005] Fixed: Action label in ViewBooking.php
[LABEL-006] Fixed: Amount field label in ViewBooking.php
[LABEL-007] Fixed: Payment method options to pure Arabic in ViewBooking.php
[LABEL-008] Fixed: Reference note label in ViewBooking.php
[LABEL-009] Fixed: Title to pure Arabic in WaitingListsRelationManager.php
[SEC-006] Fixed: Added trip capacity check to send_link_now in WaitingListsRelationManager.php
[LABEL-019] Fixed: Roles label to pure Arabic in StaffResource.php
[HIGH-001] Fixed: Created CustomerResource with BookingsRelationManager, table with sum of total_paid/100, and filters.
[HIGH-006] Fixed: Created TodaysDeparturesWidget with custom Blade view showing fill rates, capacity, unpaid warnings, and manifest links. Registered it in AdminPanelProvider.
[HIGH-014] Fixed: CreateBookingService and CreateBooking page now process the 'pickup_point_id' for each passenger properly.
[VALID-002] Fixed: PaymentResource's CreatePayment page now has an afterCreate hook that automatically calculates and updates the Booking's total_paid and balance_due when a manual payment is entered.
[HIGH-027] Fixed: PaymentResource now has filters for Payment Method and Date Range. Table now displays currency as SAR.
[MEDIUM-028] Fixed: Added Waitlist Count (pending & notified) stat to DashboardStatsOverview.
[LOW-030] Fixed: Applied the recommended Arabic Navigation Structure across all resources:
  - BookingResource & PaymentResource & CustomerResource -> 'العمليات اليومية'
  - TripInstanceResource & TripBuilderResource -> 'إدارة الرحلات'
  - PickupPointResource & PickupRouteResource -> 'اللوجستيات'
  - StaffResource & ActivityLogResource & ManageAgencySettings -> 'الإدارة والإعدادات'
[LOW-026] Fixed: Added 'Bulk Status Change' action to TripInstanceResource to allow updating multiple trips simultaneously.
[LOW-029] Fixed: Added actual mock logic for dispatching WhatsApp manifests inside TripInstanceResource bulk action.
[LOW-031] Fixed: Added 'Send Ticket via WhatsApp' row action to BookingResource.
[LOW-032] Fixed: Added 'Confirm with Deposit' action to BookingResource allowing staff to handle partial payments workflows manually.
[LOW-033] Fixed: Added 'Reopen Cancelled Booking' action in BookingResource restricted to agency_admin, allowing them to revert cancelled bookings back to pending state.
