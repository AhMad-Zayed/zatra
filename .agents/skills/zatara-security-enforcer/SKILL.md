---
name: zatara-security-enforcer
description: Describe what this skill does and when to use it. Include keywords that help agents identify relevant tasks.
---

<!-- Tip: Use /create-skill in chat to generate content with agent assistance -->

Enforces production-grade security, data protection, and resilience against common vulnerabilities for the Zatara Tourism system. Apply this skill for EVERY task involving authentication, API development, form handling, or data processing.

Zatara Security Enforcer — Hard Constraints

Prime Directive:
"Never trust, always verify." Security is not an add-on; it is the foundation. Any code that compromises security is a critical failure.

Rule SEC-1: Secure Authentication & Rate Limiting
- Phone/OTP Flow: OTPs must be short-lived (max 10 mins) and stored in Cache with rate limiting.
- Rate Limiting: Implement `RateLimiter` on all authentication endpoints, booking creation, and payment recording. Max 3 attempts per minute per IP/Phone.
- No Information Leakage: Never expose internal database IDs, stack traces, or specific validation errors to the public API/Frontend. Use generic error messages for authentication failures.

Rule SEC-2: Input Validation & Sanitization
- Form Requests: ALL user input MUST be validated using Laravel FormRequests. Never use `validate()` inside controllers.
- Strict Typing: Always use typed inputs and cast values to their correct type (decimal, boolean, string).
- No Raw Input: Never pass `$request->all()` to a model or service. Whitelist inputs using `validated()` data.

Rule SEC-3: SQL Injection & Data Safety
- Query Builder: NEVER use `DB::raw()` or raw SQL strings with user-provided variables.
- Eager Loading: Always use `with()` to prevent N+1 queries.
- Mass Assignment: All models must have explicit `$fillable` arrays. `protected $guarded = []` is strictly forbidden.

Rule SEC-4: Data Protection (Multi-tenancy & PII)
- Data Isolation: Every query involving business data must be scoped to the authenticated tenant. If the tenant ID is missing from the query, it is a critical vulnerability.
- PII Handling: Personally Identifiable Information (Phone, Passport Number, Passport Images) must be handled with care. Ensure Spatie MediaLibrary collections are protected and not publicly accessible.
- Access Control: Use Spatie roles/permissions to enforce Least Privilege. Staff can only access data they are authorized to see.

Rule SEC-5: Resilience Against Attacks
- CSRF Protection: Ensure all forms include `@csrf` and API routes use appropriate middleware.
- Headers: Ensure the app uses secure headers (Content-Security-Policy, X-Frame-Options: SAMEORIGIN, etc.).
- Financial Integrity: Never allow the frontend to influence financial calculations (e.g., total_amount must be fetched from the database, not passed from the request).

CHECKLIST before submitting any code:
[ ] Is rate limiting applied to authentication/booking routes?
[ ] Is input validated via a dedicated FormRequest?
[ ] Are all SQL queries protected against injection?
[ ] Does every business query respect tenant isolation?
[ ] Is PII (Passports, Phones) properly shielded?
[ ] Does the frontend pass only necessary IDs, not calculated price/logic?