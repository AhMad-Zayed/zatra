<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تأكيد الحجز - زتارة للسياحة</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8fafc;
            margin: 0;
            padding: 0;
            color: #334155;
            direction: rtl;
        }
        .container {
            max-width: 600px;
            margin: 40px auto;
            background-color: #ffffff;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border: 1px solid #f1f5f9;
        }
        .header {
            background-color: #0f172a;
            padding: 32px 24px;
            text-align: center;
        }
        .header h1 {
            color: #fbbf24;
            margin: 0;
            font-size: 24px;
        }
        .content {
            padding: 32px 24px;
        }
        .greeting {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 24px;
            color: #0f172a;
        }
        .booking-details {
            background-color: #f8fafc;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 32px;
            border: 1px solid #e2e8f0;
        }
        .detail-row {
            margin-bottom: 12px;
            font-size: 16px;
        }
        .detail-label {
            color: #64748b;
            font-weight: bold;
        }
        .detail-value {
            color: #0f172a;
            font-weight: bold;
            margin-right: 8px;
        }
        .pnr {
            display: inline-block;
            background-color: #e2e8f0;
            padding: 4px 8px;
            border-radius: 4px;
            letter-spacing: 2px;
            font-family: monospace;
        }
        .cta-container {
            text-align: center;
            margin-top: 32px;
        }
        .btn {
            display: inline-block;
            background-color: #2563eb;
            color: #ffffff;
            text-decoration: none;
            padding: 16px 32px;
            border-radius: 8px;
            font-weight: bold;
            font-size: 18px;
        }
        .footer {
            background-color: #f1f5f9;
            padding: 24px;
            text-align: center;
            color: #64748b;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>زتارة للسياحة والسفر</h1>
        </div>
        
        <div class="content">
            <div class="greeting">
                مرحباً {{ $booking->customer->name ?? ($booking->passengers->first()->dynamic_data['name'] ?? 'ضيفنا العزيز') }}،
            </div>
            
            <p style="font-size: 16px; line-height: 1.6; margin-bottom: 24px;">
                نشكرك على اختيارك زتارة للسياحة! طلب الحجز المبدئي الخاص بك قيد الانتظار حالياً.
            </p>
            
            <div class="booking-details">
                <div class="detail-row">
                    <span class="detail-label">الرحلة:</span>
                    <span class="detail-value">{{ $booking->tripInstance->tripTemplate->title ?? 'رحلة مميزة' }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">رقم المرجع (PNR):</span>
                    <span class="detail-value pnr" dir="ltr">{{ $booking->pnr }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">تاريخ المغادرة:</span>
                    <span class="detail-value" dir="ltr">{{ \Carbon\Carbon::parse($booking->tripInstance->start_date)->format('Y-m-d') }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">عدد المسافرين:</span>
                    <span class="detail-value">{{ $booking->passengers->count() }} مسافرين</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">المبلغ الإجمالي:</span>
                    <span class="detail-value" style="color: #fbbf24;">${{ number_format($booking->grand_total, 2) }}</span>
                </div>
            </div>
            
            <p style="font-size: 16px; line-height: 1.6; text-align: center;">
                للاطلاع على التذكرة الرقمية وتفاصيل الدفع وتحميل التذكرة (PDF)، يرجى الضغط على الزر أدناه:
            </p>
            
            <div class="cta-container">
                <a href="{{ route('booking.success', ['tenant' => $booking->tenant->slug ?? 'default', 'uuid' => $booking->uuid]) }}" class="btn">عرض التذكرة الرقمية</a>
            </div>
        </div>
        
        <div class="footer">
            هذه الرسالة تم إرسالها تلقائياً. إذا كان لديك أي استفسار، يرجى التواصل مع خدمة العملاء.
            <br><br>
            &copy; {{ date('Y') }} زتارة للسياحة. جميع الحقوق محفوظة.
        </div>
    </div>
</body>
</html>
