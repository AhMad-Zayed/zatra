<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إلغاء الحجز</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f8fafc; margin: 0; padding: 0; color: #334155; direction: rtl; }
        .container { max-width: 600px; margin: 40px auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        .header { background-color: #dc2626; color: #ffffff; padding: 30px 20px; text-align: center; }
        .header h1 { margin: 0; font-size: 24px; }
        .body { padding: 30px 40px; }
        .pnr-box { background: #fee2e2; border: 1px solid #fca5a5; border-radius: 6px; padding: 16px; text-align: center; margin: 20px 0; }
        .pnr-box .label { font-size: 13px; color: #6b7280; }
        .pnr-box .value { font-size: 28px; font-weight: 800; color: #dc2626; letter-spacing: 3px; }
        .reason-box { background: #fef3c7; border-right: 4px solid #f59e0b; padding: 14px 18px; border-radius: 4px; margin: 20px 0; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #9ca3af; background: #f9fafb; border-top: 1px solid #e5e7eb; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>❌ إلغاء الحجز</h1>
    </div>
    <div class="body">
        <p>عزيزنا {{ $booking->customer->name ?? 'العميل الكريم' }}،</p>
        <p>نأسف لإبلاغك بأنه تم <strong>إلغاء حجزك</strong> الخاص برحلة <strong>{{ $booking->tripInstance->tripTemplate->title ?? '' }}</strong>.</p>

        <div class="pnr-box">
            <div class="label">رقم الحجز</div>
            <div class="value">{{ $booking->pnr }}</div>
        </div>

        @if($reason)
        <div class="reason-box">
            <strong>سبب الإلغاء:</strong> {{ $reason }}
        </div>
        @endif

        <p>للاستفسار أو طلب استرداد المبلغ، يرجى التواصل مع فريقنا.</p>
        <p>نعتذر مجدداً عن أي إزعاج، ونتمنى أن نخدمك في رحلات قادمة.</p>

        <p>مع تحيات فريق <strong>{{ $tenant->name ?? 'زاتارا للسياحة' }}</strong></p>
    </div>
    <div class="footer">
        {{ $tenant->name ?? '' }} — {{ $tenant->email ?? '' }}
    </div>
</div>
</body>
</html>
