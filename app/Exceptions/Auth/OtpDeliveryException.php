<?php

namespace App\Exceptions\Auth;

use Exception;

/**
 * Thrown when an OTP genuinely could not be sent -- missing WhatsApp/mail credentials, or the
 * delivery provider itself rejected the request. Deliberately NOT caught/swallowed inside
 * CustomerOtpService::sendOtp() itself: the caller (CustomerLogin, CheckoutWizard) already
 * catches \Exception around sendOtp() and surfaces the message as a form error, so letting this
 * propagate is both safe and correct -- the customer sees a real "we couldn't send your code"
 * error instead of the previous silent no-op that looked like success.
 */
class OtpDeliveryException extends Exception
{
    //
}
