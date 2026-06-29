<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>كشف ركاب - {{ $tripInstance->tripTemplate->title }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; margin: 0; padding: 20px; color: #333; direction: rtl; }
        h1 { text-align: center; color: #1e3a8a; margin-bottom: 5px; }
        h3 { text-align: center; color: #64748b; margin-top: 0; margin-bottom: 30px; font-weight: normal; }
        .meta-info { margin-bottom: 20px; padding: 10px; background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 5px; }
        .meta-info p { margin: 5px 0; font-size: 14px; }
        .pickup-group { margin-bottom: 30px; }
        .pickup-title { background-color: #1e3a8a; color: #fff; padding: 10px; font-size: 16px; font-weight: bold; margin-bottom: 10px; border-radius: 3px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 14px; }
        th, td { border: 1px solid #cbd5e1; padding: 8px 10px; text-align: right; }
        th { background-color: #f1f5f9; color: #334155; font-weight: bold; }
        tr:nth-child(even) { background-color: #f8fafc; }
        .text-center { text-align: center; }
        .footer { margin-top: 50px; text-align: center; font-size: 12px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 10px; }
    </style>
</head>
<body>
    <h1>{{ $tripInstance->tripTemplate->title }}</h1>
    <h3>{{ \Carbon\Carbon::parse($tripInstance->start_date)->format('Y-m-d') }}</h3>

    <div class="meta-info">
        <p><strong>تاريخ الرحلة:</strong> {{ \Carbon\Carbon::parse($tripInstance->start_date)->format('Y-m-d') }} إلى {{ \Carbon\Carbon::parse($tripInstance->end_date)->format('Y-m-d') }}</p>
        <p><strong>إجمالي الركاب (المؤكدين):</strong> {{ $totalPassengers }} راكب</p>
    </div>

    @foreach($groupedPassengers as $pickupName => $passengers)
        <div class="pickup-group">
            <div class="pickup-title">
                نقطة التجمع: {{ $pickupName }} 
                @if($passengers->first()['pickup_time'] !== 'N/A')
                    (الساعة: {{ \Carbon\Carbon::parse($passengers->first()['pickup_time'])->format('H:i') }})
                @endif
                - العدد: {{ $passengers->count() }} راكب
            </div>
            <table>
                <thead>
                    <tr>
                        <th width="5%">#</th>
                        <th width="25%">اسم الراكب</th>
                        <th width="20%">رقم الحجز (PNR)</th>
                        <th width="25%">رقم الجوال</th>
                        <th width="25%">الفئة / الباقة</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($passengers as $index => $passenger)
                        <tr>
                            <td class="text-center">{{ $index + 1 }}</td>
                            <td>{{ $passenger['name'] }}</td>
                            <td>{{ $passenger['pnr'] }}</td>
                            <td dir="ltr" style="text-align: right;">{{ $passenger['phone'] }}</td>
                            <td>{{ $passenger['category'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endforeach

    <div class="footer">
        تم إنشاء هذا الكشف بواسطة نظام زتارا السياحي في {{ now()->format('Y-m-d H:i') }}
    </div>
</body>
</html>
