# DATABASE SCHEMA (STRICT)

ALL BUSINESS TABLES MUST INCLUDE: `id`, `tenant_id`, `created_at`, `updated_at`, `deleted_at` (SoftDeletes).

## CORE TABLES

### tenants
- name
- domain (nullable)
- is_visa_enabled (boolean, default false)

### users
- name
- phone (unique, primary identifier)
- email (nullable)
- password (nullable)

### trip_templates
- title
- description
- base_price

### trip_instances
- trip_template_id
- start_date
- end_date
- available_seats
- status

### bookings
- user_id
- trip_instance_id
- status (string: pending, confirmed, cancelled)
- total_amount (decimal)
- flight_details (text, nullable)
- hotel_details (text, nullable)
- insurance_details (text, nullable)
- visa_details (text, nullable)
- (Outgoing tickets/vouchers handled via Spatie Media Library under `vouchers_and_tickets` collection)
- **CRITICAL:** DO NOT create a physical column for `paid_amount`. Build it as an Eloquent Accessor (`getPaidAmountAttribute`) that sums related payments.

### passengers
- booking_id
- name
- passport_number
- special_requirements
- (Documents handled purely via Spatie Media Library)

### payments (IMMUTABLE)
- booking_id
- amount
- payment_method (string: cash, transfer, visa)
- received_by (user_id)
- type (string: payment, reversal)