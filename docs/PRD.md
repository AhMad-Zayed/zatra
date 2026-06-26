# PRD - Zatara SaaS Tourism System

## CORE CONCEPT
A multi-tenant SaaS booking system for tourism agencies with full financial tracking, optimized for the local market (WhatsApp-first communication, partial payments).

## CUSTOMER FLOW (STOREFRONT) & DOCUMENT ACCESS
1. Customer selects a trip and enters their phone number & passenger details in a One-Page Checkout (Tailwind/Blade).
2. System auto-creates/finds the user via Phone Number (Silent Auth).
3. Passengers + documents (Passports) are uploaded via Spatie Media Library.
4. For international trips, the agent can optionally add flight tickets, hotel reservations, and travel insurance details/documents based on the customer's request.
5. Booking is created in PENDING state.
6. Once the booking is confirmed/vouchers are ready, the customer can securely log into the system to download their tickets, hotel vouchers, and insurance documents directly (instead of receiving them manually via WhatsApp).

## PAYMENTS & FINANCIAL ENGINE
- **Bookings:** Contain a fixed `total_amount`.
- **Payments:** Immutable records (`amount`, `payment_method`, `received_by`). Reversal creates a new negative entry.
- **Financial Logic:** Booking status (PENDING, PARTIALLY_PAID, FULLY_PAID) is AUTO-DETERMINED based on the sum of related payments.

## NOTIFICATIONS (WHATSAPP-FIRST)
- System relies on Laravel Notifications using a Custom WhatsApp Channel.
- `Pending` Status: Triggers Acknowledgment message (asking for payment).
- `Confirmed` (Fully Paid) Status: Triggers Ticket Confirmation message.

## ADMIN PANEL (FILAMENT)
- Role-based access via `Filament Shield` (Super Admin, Tenant Admin, OpsMgr, BookingAgent, Accountant, Guide).
- Global search: Find bookings by phone, passport, or passenger name.
- Export passengers list to Excel (using Filament Excel actions).
- Full audit log visible to Admins via Spatie Activity Log.