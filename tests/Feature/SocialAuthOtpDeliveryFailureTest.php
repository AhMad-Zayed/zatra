<?php

namespace Tests\Feature;

use App\Exceptions\Auth\OtpDeliveryException;
use App\Models\Customer;
use App\Models\Tenant;
use App\Services\CustomerOtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression for SocialAuthController::callback()'s account-merge branch (~line 94): unlike
 * every other sendOtp() caller in this app (CustomerLogin, CompleteProfile), this one call was
 * not wrapped in a try/catch, so a delivery failure (OtpDeliveryException -- see the OTP
 * delivery hotfix) would surface as a raw 500 instead of the graceful redirect+error every other
 * caller already produces.
 *
 * Same testing constraint as EmailNormalizationTest::test_social_auth_controller_matches_
 * existing_customer_regardless_of_provider_email_casing(): laravel/socialite is not a project
 * dependency (absent from composer.json/vendor), so Socialite::driver($provider)->user() cannot
 * be reached or mocked in this test harness -- the controller's real callback() method can't be
 * invoked end-to-end. This follows that same file's established pattern: a source-level proof the
 * fix is actually in place, plus a behavioral proof that exercises the exact real
 * CustomerOtpService/OtpDeliveryException path the catch block now handles.
 */
class SocialAuthOtpDeliveryFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_controller_source_wraps_the_merge_flow_send_otp_call_in_a_try_catch(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Auth/SocialAuthController.php'));

        $this->assertMatchesRegularExpression(
            '/try\s*\{\s*app\(CustomerOtpService::class\)->sendOtp\(\$tenant, \$customer->email\);\s*\}\s*catch \(\\\\Exception \$e\)/s',
            $source,
            'sendOtp() in the account-merge branch must be wrapped in the same try/catch every other caller uses.'
        );
        $this->assertStringContainsString(
            "session()->forget('pending_social_link');",
            $source,
            'A failed send must not leave a half-started merge session dangling for a retry that can never complete it.'
        );
        $this->assertStringContainsString(
            "->with('error', \$e->getMessage())",
            $source,
            'The caught exception message must reach the user via the same flash-error convention the Socialite-failure catch just above already uses.'
        );
    }

    public function test_send_otp_throwing_during_account_merge_is_a_catchable_exception_with_a_graceful_message(): void
    {
        // Exercises the exact real dependency the controller's catch block now handles: force
        // production (where CustomerOtpService actually attempts delivery) with no WhatsApp/mail
        // credentials configured, and confirm sendOtp() throws OtpDeliveryException -- a plain
        // \Exception subclass, caught by the controller's `catch (\Exception $e)` -- carrying a
        // graceful, user-facing Arabic message rather than a raw framework exception.
        //
        // Uses a phone identifier, not the email the real call site passes: MAIL_MAILER=array in
        // this test environment (phpunit.xml) never fails, so the email path can't be forced to
        // throw here -- the WhatsApp path is the only one that reliably reproduces a delivery
        // failure in this harness (same reason CustomerOtpDeliveryTest's "not configured" test
        // uses a phone identifier). OtpDeliveryException's shape is identical either way; this
        // still proves the exception the catch block must handle.
        config(['services.whatsapp.token' => null, 'services.whatsapp.phone_id' => null]);
        app()->detectEnvironment(fn () => 'production');

        $tenant = Tenant::create(['name' => 'Agency', 'slug' => 'social-otp-fail']);
        Customer::create([
            'tenant_id' => $tenant->id, 'name' => 'Existing', 'phone' => '0500000700',
            'email' => 'merge-target@example.com', 'provider_id' => null,
        ]);

        try {
            app(CustomerOtpService::class)->sendOtp($tenant, '0500000700');
            $this->fail('sendOtp() was expected to throw OtpDeliveryException.');
        } catch (OtpDeliveryException $e) {
            $this->assertInstanceOf(\Exception::class, $e, 'Must be catchable by the controller\'s catch (\Exception $e).');
            $this->assertNotEmpty($e->getMessage());
            $this->assertStringNotContainsString('SQLSTATE', $e->getMessage(), 'Must be the service\'s own graceful message, not a leaked driver/database error.');
        }
    }
}
