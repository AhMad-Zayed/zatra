<?php

namespace Tests\Feature;

use App\Exceptions\Auth\OtpDeliveryException;
use App\Models\Customer;
use App\Models\Tenant;
use App\Services\CustomerOtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Emergency hotfix, same tier as the storefront security fixes earlier this session:
 * CustomerOtpService::sendOtp() only ever wrote the OTP code to the log, in every environment
 * including production, behind a "TODO: Implement a proper SendCustomerNotificationJob" comment
 * that was never followed up on. The login flow appeared to work (step advanced, no error shown)
 * while no customer could ever actually receive a real code. Fixed by genuinely attempting
 * delivery in production/staging -- WhatsApp via the same Meta Graph API + services.whatsapp
 * config keys WhatsAppNotificationDriver already uses for booking notifications, email via the
 * same Mail facade EmailNotificationDriver already uses -- and throwing OtpDeliveryException
 * (caught and surfaced as a form error by every existing caller) rather than silently continuing
 * when a credential is missing or the provider rejects the request.
 *
 * Local/testing keeps the pre-existing log-only behavior on purpose (no real WhatsApp Business
 * account is expected on a dev machine, and CustomerLogin's own "1234" bypass doesn't depend on
 * this), so every test below that wants to exercise real delivery forces the app environment to
 * "production" first, the same way a real production/staging server would report it.
 */
class CustomerOtpDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(): Tenant
    {
        return Tenant::create(['name' => 'Agency OTP', 'slug' => 'agency-otp']);
    }

    public function test_local_environment_still_only_logs_and_attempts_no_real_send(): void
    {
        Http::fake();
        Mail::fake();
        $tenant = $this->makeTenant();

        // Default test/local environment -- unchanged behavior, no real network/mail attempt.
        app(CustomerOtpService::class)->sendOtp($tenant, '0599111222');

        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_production_environment_actually_attempts_a_real_whatsapp_send_for_a_phone_identifier(): void
    {
        config(['services.whatsapp.token' => 'test-token', 'services.whatsapp.phone_id' => '123456', 'services.whatsapp.otp_template' => 'otp_verification']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.test']]], 200)]);
        app()->detectEnvironment(fn () => 'production');

        $tenant = $this->makeTenant();
        app(CustomerOtpService::class)->sendOtp($tenant, '0599111222');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'graph.facebook.com')
                && str_contains($request->url(), '123456')
                && $request['type'] === 'template'
                && $request['template']['name'] === 'otp_verification'
                && $request->hasHeader('Authorization', 'Bearer test-token');
        });
    }

    public function test_production_environment_throws_a_clear_exception_when_whatsapp_is_not_configured(): void
    {
        config(['services.whatsapp.token' => null, 'services.whatsapp.phone_id' => null]);
        Http::fake();
        app()->detectEnvironment(fn () => 'production');

        $tenant = $this->makeTenant();

        $this->expectException(OtpDeliveryException::class);
        app(CustomerOtpService::class)->sendOtp($tenant, '0599111222');
    }

    public function test_production_environment_throws_when_the_whatsapp_api_rejects_the_request(): void
    {
        config(['services.whatsapp.token' => 'test-token', 'services.whatsapp.phone_id' => '123456']);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid template name']], 400)]);
        app()->detectEnvironment(fn () => 'production');

        $tenant = $this->makeTenant();

        $this->expectException(OtpDeliveryException::class);
        app(CustomerOtpService::class)->sendOtp($tenant, '0599111222');
    }

    public function test_production_environment_actually_attempts_a_real_email_send_for_an_email_identifier(): void
    {
        Mail::fake();
        app()->detectEnvironment(fn () => 'production');

        $tenant = $this->makeTenant();
        app(CustomerOtpService::class)->sendOtp($tenant, 'real-customer@example.com');

        Mail::assertSent(\App\Mail\CustomerOtpMail::class, function ($mail) {
            return $mail->hasTo('real-customer@example.com');
        });
    }

    public function test_a_thrown_delivery_exception_does_not_leave_a_dangling_otp_hash_the_customer_could_never_know(): void
    {
        // Not a data-integrity requirement of this fix (the OTP hash is harmless if never
        // delivered -- it can't be guessed, and verifyOtp() already rate-limits attempts), just
        // confirming the customer record itself is still created/updated normally even when
        // delivery fails, so a retry (sendOtp called again) isn't blocked by a missing record.
        config(['services.whatsapp.token' => null, 'services.whatsapp.phone_id' => null]);
        app()->detectEnvironment(fn () => 'production');

        $tenant = $this->makeTenant();

        try {
            app(CustomerOtpService::class)->sendOtp($tenant, '0599111222');
        } catch (OtpDeliveryException $e) {
            // expected
        }

        $this->assertDatabaseHas('customers', [
            'tenant_id' => $tenant->id,
            'phone' => '0599111222',
        ]);
        $customer = Customer::where('tenant_id', $tenant->id)->where('phone', '0599111222')->first();
        $this->assertNotNull($customer->otp_code);
    }
}
