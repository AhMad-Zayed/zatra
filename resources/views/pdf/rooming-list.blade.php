<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>قائمة الغرف - {{ $hotelOption->hotel->name }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* IBM Plex Sans Arabic first (renders correctly under the Browsershot/headless-Chrome
           driver spatie/laravel-pdf defaults to); DejaVu Sans as the safe fallback if the PDF
           ever falls back to a dompdf-style renderer with no web-font access, matching the
           existing manifest.blade.php convention. */
        body { font-family: 'IBM Plex Sans Arabic', 'DejaVu Sans', sans-serif; margin: 0; padding: 24px; color: #0b1c30; direction: rtl; }
        h1 { text-align: center; color: #00355f; margin-bottom: 4px; font-size: 22px; }
        h3 { text-align: center; color: #64748b; margin-top: 0; margin-bottom: 24px; font-weight: 500; font-size: 14px; }
        .meta-info { margin-bottom: 20px; padding: 12px 16px; background-color: #f8f9ff; border: 1px solid #e2e8f0; border-radius: 8px; }
        .meta-info p { margin: 4px 0; font-size: 13px; }
        .room-card { margin-bottom: 16px; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; page-break-inside: avoid; }
        .room-header { background-color: #00355f; color: #fff; padding: 10px 16px; font-size: 14px; font-weight: 600; display: flex; justify-content: space-between; }
        .room-empty { background-color: #f8f9ff; color: #64748b; padding: 14px 16px; font-size: 13px; text-align: center; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { border-top: 1px solid #e2e8f0; padding: 8px 16px; text-align: right; }
        th { background-color: #f1f5f9; color: #334155; font-weight: 600; font-size: 12px; }
        .text-center { text-align: center; }
        .footer { margin-top: 32px; text-align: center; font-size: 11px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 10px; }
    </style>
</head>
<body>
    <h1>قائمة توزيع الغرف — {{ $hotelOption->hotel->name }}</h1>
    <h3>{{ $hotelOption->tripStayLeg->tripInstance->tripTemplate->title }}</h3>

    <div class="meta-info">
        <p><strong>الفندق:</strong> {{ $hotelOption->hotel->name }} @if($hotelOption->hotel->city) — {{ $hotelOption->hotel->city }} @endif</p>
        <p><strong>فترة الإقامة:</strong> {{ \Carbon\Carbon::parse($hotelOption->tripStayLeg->start_date)->format('Y-m-d') }} إلى {{ \Carbon\Carbon::parse($hotelOption->tripStayLeg->end_date)->format('Y-m-d') }}</p>
        @if($hotelOption->meal_plan)
            <p><strong>نظام الإطعام:</strong> {{ $hotelOption->meal_plan }}</p>
        @endif
        <p><strong>إجمالي عدد النزلاء المخصصين:</strong> {{ $totalOccupants }} نزيل — {{ $rooms->count() }} غرفة</p>
    </div>

    @foreach($rooms as $room)
        <div class="room-card">
            <div class="room-header">
                <span>{{ $room['room_type'] }} — غرفة رقم {{ $room['room_number'] }}</span>
                <span>{{ count($room['occupants']) }} / {{ $room['capacity'] }}</span>
            </div>
            @if(count($room['occupants']) > 0)
                <table>
                    <thead>
                        <tr>
                            <th width="8%">#</th>
                            <th width="42%">اسم النزيل</th>
                            <th width="25%">رقم الحجز (PNR)</th>
                            <th width="25%">رقم الجوال</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($room['occupants'] as $index => $occupant)
                            <tr>
                                <td class="text-center">{{ $index + 1 }}</td>
                                <td>{{ $occupant['name'] }}</td>
                                <td>{{ $occupant['pnr'] }}</td>
                                <td dir="ltr" style="text-align: right;">{{ $occupant['phone'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="room-empty">لا يوجد نزلاء مخصصون لهذه الغرفة</div>
            @endif
        </div>
    @endforeach

    <div class="footer">
        تم إنشاء هذه القائمة بواسطة نظام زتارا السياحي في {{ now()->format('Y-m-d H:i') }}
    </div>
</body>
</html>
