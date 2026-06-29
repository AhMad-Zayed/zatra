<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تأكيد الحجز</title>
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
            max-w-width: 600px;
            margin: 40px auto;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        .header {
            background-color: #0f172a;
            color: #ffffff;
            padding: 30px 20px;
            text-align: center;
            border-bottom: 4px solid #f59e0b;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            color: #f59e0b;
        }
        .content {
            padding: 30px;
        }
        .pnr-box {
            background-color: #f1f5f9;
            border: 1px dashed #cbd5e1;
            padding: 15px;
            text-align: center;
            border-radius: 6px;
            margin-bottom: 25px;
        }
        .pnr-box p {
            margin: 0;
            font-size: 14px;
            color: #64748b;
        }
        .pnr-box h2 {
            margin: 5px 0 0 0;
            font-family: monospace;
            font-size: 28px;
            color: #0f172a;
            letter-spacing: 2px;
        }
        .ledger-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        .ledger-table th, .ledger-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            text-align: right;
        }
        .ledger-table th {
            color: #64748b;
            font-weight: 600;
        }
        .ledger-table .total-row td {
            font-weight: bold;
            color: #0f172a;
            border-bottom: none;
        }
        .ledger-table .balance-row td {
            font-weight: bold;
            color: #16a34a; /* Green */
        }
        .cta-btn {
            display: block;
            width: 200px;
            margin: 0 auto;
            background-color: #f59e0b;
            color: #ffffff;
            text-align: center;
            padding: 12px 20px;
            text-decoration: none;
            border-radius: 6px;
            font-weight: bold;
        }
        .footer {
            background-color: #f8fafc;
            padding: 20px;
            text-align: center;
            font-size: 12px;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{{ $tenant->name ?? 'Zatara Tourism' }}</h1>
            <p style="margin-top: 10px; opacity: 0.8;">تم تأكيد حجزك بنجاح!</p>
        </div>
        
        <div class="content">
            <p>مرحباً،</p>
            <p>يسعدنا إبلاغك بأن حجزك قد تم تأكيده بنجاح. لقد قمنا بإرفاق تذكرة الصعود الإلكترونية الخاصة بك مع هذه الرسالة (أو يمكنك تحميلها عبر الرابط أدناه).</p>
            
            <div class="pnr-box">
                <p>رمز الحجز (PNR)</p>
                <h2>{{ $booking->pnr ?? $booking->reference }}</h2>
            </div>
            
            <h3>الملخص المالي</h3>
            <table class="ledger-table">
                <tr>
                    <th>الباقة</th>
                    <td>{{ $booking->tripInstance->template->title ?? 'N/A' }}</td>
                </tr>
                <tr>
                    <th>تاريخ المغادرة</th>
                    <td dir="ltr">{{ \Carbon\Carbon::parse($booking->tripInstance->start_date)->format('Y-m-d') }}</td>
                </tr>
                <tr class="total-row">
                    <th>الإجمالي</th>
                    <td>{{ number_format(($booking->grand_total ?? 0) / 100, 2) }} {{ $tenant->currency ?? 'SAR' }}</td>
                </tr>
                <tr>
                    <th>المدفوع</th>
                    <td>{{ number_format(($booking->total_paid ?? 0) / 100, 2) }} {{ $tenant->currency ?? 'SAR' }}</td>
                </tr>
                <tr class="balance-row">
                    <th>المتبقي</th>
                    <td>{{ number_format(($booking->balance_due ?? 0) / 100, 2) }} {{ $tenant->currency ?? 'SAR' }}</td>
                </tr>
            </table>
            
            <a href="{{ url('/zatara-tourism/my-bookings') }}" class="cta-btn">عرض التذكرة والرحلة</a>
        </div>
        
        <div class="footer">
            <p>نشكركم لاختياركم {{ $tenant->name ?? 'Zatara' }}. نتمنى لكم رحلة سعيدة وآمنة.</p>
        </div>
    </div>
</body>
</html>
