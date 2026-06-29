<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تأكيد حجزك</title>
</head>
<body style="background-color: #f8fafc; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; margin: 0; padding: 0; direction: rtl; text-align: right;">

    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f8fafc; padding: 40px 0;">
        <tr>
            <td align="center">
                
                <table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);">
                    
                    <tr>
                        <td style="background-color: #0f172a; padding: 30px; text-align: center; border-bottom: 3px solid #f59e0b;">
                            <h1 style="color: #f59e0b; margin: 0; font-size: 28px; font-weight: bold;">{{ $booking->tenant->name }}</h1>
                            <p style="color: #94a3b8; margin: 10px 0 0 0; font-size: 14px;">اكتشف العالم بالفخامة التي تستحقها</p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 40px 30px;">
                            <h2 style="color: #1e293b; margin-top: 0; font-size: 22px;">مرحباً {{ $booking->customer->name }}،</h2>
                            <p style="color: #475569; line-height: 1.6; font-size: 16px;">
                                {{ $messageText }}
                            </p>

                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f1f5f9; border-radius: 8px; margin: 25px 0; padding: 20px;">
                                <tr>
                                    <td style="padding-bottom: 10px;">
                                        <span style="color: #64748b; font-size: 13px;">رقم الحجز:</span><br>
                                        <strong style="color: #0f172a; font-size: 18px; font-family: monospace;">#{{ $booking->pnr }}</strong>
                                    </td>
                                    <td style="padding-bottom: 10px;">
                                        <span style="color: #64748b; font-size: 13px;">الرحلة:</span><br>
                                        <strong style="color: #0f172a; font-size: 16px;">{{ $booking->tripInstance->template->title }}</strong>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding-top: 10px; border-top: 1px solid #e2e8f0;">
                                        <span style="color: #64748b; font-size: 13px;">المبلغ الإجمالي:</span><br>
                                        <strong style="color: #0f172a; font-size: 16px;">{{ number_format($booking->grand_total, 2) }} دولار</strong>
                                    </td>
                                    <td style="padding-top: 10px; border-top: 1px solid #e2e8f0;">
                                        <span style="color: #64748b; font-size: 13px;">المبلغ المتبقي:</span><br>
                                        <strong style="color: #ef4444; font-size: 16px;">{{ number_format($booking->balance_due, 2) }} دولار</strong>
                                    </td>
                                </tr>
                            </table>

                            <p style="color: #475569; line-height: 1.6; font-size: 14px;">
                                تجد مرفقاً بهذه الرسالة تذكرتك الإلكترونية (PDF) التي تحتوي على كافة التفاصيل ورمز الـ QR. يرجى الاحتفاظ بها.
                            </p>

                            <div style="text-align: center; margin-top: 35px;">
                                <a href="{{ route('storefront.catalog', ['tenant_slug' => $booking->tenant->slug]) }}" style="background-color: #f59e0b; color: #0f172a; padding: 14px 28px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px; display: inline-block;">
                                    الذهاب لبوابتي
                                </a>
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <td style="background-color: #f8fafc; padding: 20px; text-align: center; border-top: 1px solid #e2e8f0;">
                            <p style="color: #94a3b8; font-size: 12px; margin: 0;">
                                هذه الرسالة تم إرسالها آلياً. يرجى عدم الرد عليها.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>

</body>
</html>
