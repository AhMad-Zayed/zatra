# AI EXECUTION ROADMAP (PROMPTS)

## STEP 1: Foundation & Database
Prompt: "Read `docs/SCHEMA.md` and `docs/PRD.md`. Generate Migrations, Models, and Factories. Apply `tenant_id` correctly. DO NOT add `tenant_id` to system tables. Set up relationships and add Spatie Media Library traits to the Passenger model."

## STEP 2: Multi-Tenancy & Auth
Prompt: "Configure Filament v3 Native Panel Tenancy. The `Tenant` model should implement `HasTenants`. Set up the User model to support Silent Registration via Phone Number. Install Spatie Permission and Filament Shield."

## STEP 3: Booking & Financial Engine (Service Layer)
Prompt: "Create `BookingService`. Implement methods for creating bookings and processing payments. Ensure payments are immutable. Add an Eloquent Accessor on the Booking model to calculate the paid amount dynamically. Add Observers to auto-update the booking status when payments sum equals total_amount."

## STEP 4: Admin Panel Setup
Prompt: "Generate Filament Resources for TripTemplate, TripInstance, Booking, and Payment. Make sure they are Tenant-aware. For the Booking Resource, use a `RelationManager` to display and create Payments directly inside the Booking view page."

## STEP 5: Notifications System
Prompt: "Generate a custom `WhatsAppChannel` for Laravel Notifications. Create Notification classes for `BookingPending` and `BookingConfirmed`. Trigger these notifications inside the Booking Observer based on status changes."