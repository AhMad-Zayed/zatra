<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Passenger;
use App\Models\Booking;
use App\Models\Tenant;
use App\Models\Payment;
use App\Models\InventoryLedger;
use App\Models\TripInstance;
use Illuminate\Support\Facades\DB;
use App\Services\CreateBookingService;
use App\Services\BookingService;
use App\Services\PaymentService;
use App\Models\User;
use App\Enums\PaymentType;

class RunQA extends Command
{
    protected $signature = 'qa:run';
    protected $description = 'Run QA tests';

    public function handle()
    {
        // A01
        $this->info("=== A01 ===");
        try {
            $service = new CreateBookingService();
            $data = [
                'tenant_id' => 1,
                'trip_instance_id' => 3,
                'customer_id' => 3,
                'passengersData' => [
                    ['trip_passenger_category_id' => 3, 'first_name' => 'AUTOQA_NoPP', 'last_name' => 'Test'],
                ],
                'notes' => 'AUTOQA_A01_NoPassport',
            ];
            $booking = $service->execute($data);
            $this->line('ACCEPTED: pnr=' . $booking->pnr . ' — Server did NOT enforce passport');
        } catch (\Exception $e) {
            $this->line('REJECTED: ' . $e->getMessage() . ' — Server enforces passport');
        }

        // A02
        $this->info("=== A02 ===");
        $p = Passenger::where('first_name','AUTOQA_NoPP')->first();
        if ($p) {
            $p->date_of_birth = '2050-01-01';
            $p->save();
            $this->line('SAVED future DOB: ' . $p->date_of_birth);
        } else $this->line("No passenger found");

        // T1
        $this->info("=== T1 ===");
        $booking = Booking::whereNotNull('uuid')->first();
        if ($booking) {
            $this->line('Test UUID: ' . $booking->uuid . ' owner_customer_id=' . $booking->customer_id);
        } else {
            $this->line("No booking with UUID found");
        }

        // T2
        $this->info("=== T2 ===");
        $b = Booking::where('customer_id', 4)->first();
        if (!$b) {
            try {
                $service = new CreateBookingService();
                $b = $service->execute([
                    'tenant_id' => 1,
                    'trip_instance_id' => 4, 'customer_id' => 4,
                    'passengersData' => [['trip_passenger_category_id' => 6, 'first_name' => 'AUTOQA_Sara', 'last_name' => 'Test']],
                    'notes' => 'AUTOQA_CustomerB_Booking'
                ]);
            } catch (\Exception $e) {}
        }
        if ($b) {
            $this->line('CustomerB booking UUID: ' . $b->uuid);
        }

        // M
        $this->info("=== M ===");
        $tenant = Tenant::first();
        $service = new CreateBookingService();
        try {
            $booking = $service->execute([
                'tenant_id' => 1,
                'trip_instance_id' => 4,
                'customer_id' => 3,
                'passengersData' => [
                    ['trip_passenger_category_id' => 6, 'first_name' => 'AUTOQA_PayTest', 'last_name' => 'Test'],
                ],
                'notes' => 'AUTOQA_M_PaymentTest',
            ]);
            $this->line('Created booking PNR=' . $booking->pnr . ' grand_total_raw=' . $booking->getRawOriginal('grand_total'));

            $bs = new PaymentService();
            $adminUser = User::first();

            // M1
            try {
                $bs->recordPayment($booking, 0, 'cash', $adminUser);
                $this->line('M1: ACCEPTED zero payment (BUG)');
            } catch (\Exception $e) {
                $this->line('M1: REJECTED: ' . $e->getMessage());
            }

            // M2
            try {
                $bs->recordPayment($booking, -5000, 'cash', $adminUser);
                $this->line('M2: ACCEPTED negative payment (BUG)');
            } catch (\Exception $e) {
                $this->line('M2: REJECTED: ' . $e->getMessage());
            }

            // M3
            $half = (int)($booking->getRawOriginal('grand_total') / 2);
            try {
                $bs->recordPayment($booking, $half, 'cash', $adminUser);
                $booking->refresh();
                $this->line('M3: Partial payment accepted. total_paid=' . $booking->getRawOriginal('total_paid') . ' balance_due=' . $booking->getRawOriginal('balance_due'));
            } catch (\Exception $e) {
                $this->line('M3: FAILED: ' . $e->getMessage());
            }

            // M4
            $overAmount = $booking->getRawOriginal('grand_total') * 2;
            try {
                $bs->recordPayment($booking, $overAmount, 'cash', $adminUser);
                $booking->refresh();
                $this->line('M4: OVERPAYMENT ACCEPTED (BUG). total_paid=' . $booking->getRawOriginal('total_paid'));
            } catch (\Exception $e) {
                $this->line('M4: Overpayment rejected: ' . $e->getMessage());
            }
        } catch (\Exception $e) {
            $this->line('M Failed to create booking: ' . $e->getMessage());
        }

        // N
        $this->info("=== N ===");
        $payment = Payment::where('type','!=','reversal')->first();
        if ($payment) {
            $booking = $payment->booking;
            $this->line('Payment: id=' . $payment->id . ' amount=' . $payment->getRawOriginal('amount') . ' booking=' . ($booking ? $booking->pnr : 'none'));
            
            try {
                $payment->amount = 999;
                $payment->save();
                $this->line('EDITED payment (BUG - immutability broken)');
            } catch (\Exception $e) {
                $this->line('IMMUTABILITY PASS: ' . $e->getMessage());
            }
            
            try {
                $payment->delete();
                $this->line('DELETED payment (BUG)');
            } catch (\Exception $e) {
                $this->line('DELETE PROTECTED: ' . $e->getMessage());
            }
        }

        // V
        $this->info("=== V ===");
        $service = new CreateBookingService();
        $bookingData = [
            'tenant_id' => 1,
            'trip_instance_id' => 4,
            'customer_id' => 3,
            'passengersData' => [
                ['trip_passenger_category_id' => 6, 'first_name' => 'AUTOQA_DupTest', 'last_name' => 'Test'],
            ],
            'notes' => 'AUTOQA_DuplicateTest',
        ];

        try {
            $b1 = $service->execute($bookingData);
            $b2 = $service->execute($bookingData);

            if ($b1 && $b2) {
                $this->line('DUPLICATE BOOKING CREATED: ' . $b1->pnr . ' AND ' . $b2->pnr . ' (BUG-005 CONFIRMED)');
            } else {
                $this->line('Second booking blocked (PASS)');
            }
        } catch (\Exception $e) {
            $this->line('V Test Failed: ' . $e->getMessage());
        }

        // U1
        $this->info("=== U1 ===");
        $b1 = Booking::first();
        if ($b1) {
            $this->line('Booking::first() - tenant_id=' . $b1->tenant_id . ' id=' . $b1->id);
            $raw = Booking::withoutGlobalScopes()->find($b1->id);
            $this->line('withoutGlobalScopes()->find() - same result? ' . ($raw->id === $b1->id ? 'YES (no isolation)' : 'NO'));
        }
        $allBookings = Booking::all();
        $tenants = $allBookings->pluck('tenant_id')->unique()->values()->toArray();
        $this->line('Booking::all() returns tenants: ' . implode(',', $tenants));
        $this->line(' (multiple tenants = isolation broken if >1)');

        // U2
        $this->info("=== U2 ===");
        $allLedgers = InventoryLedger::all();
        $hasTenantCol = $allLedgers->count() > 0 && isset($allLedgers->first()->tenant_id);
        $this->line('InventoryLedger tenant_id accessible: ' . ($hasTenantCol ? 'YES' : 'NO (BUG-003)'));

        // W
        $this->info("=== W ===");
        $orphans = DB::table('passengers')
            ->leftJoin('bookings','passengers.booking_id','=','bookings.id')
            ->whereNull('bookings.id')
            ->count();
        $this->line('Orphan passengers: ' . $orphans);

        TripInstance::all()->each(function($t) {
            if ($t->remaining_seats < 0) {
                $this->line('OVERBOOKED trip ' . $t->id . ': remaining=' . $t->remaining_seats);
            }
        });

        $cancelledLeaking = DB::table('bookings')
            ->join('inventory_ledgers','bookings.id','=','inventory_ledgers.booking_id')
            ->where('bookings.booking_status','cancelled')
            ->where('inventory_ledgers.quantity','<',0)
            ->whereNull('inventory_ledgers.expires_at')
            ->where('inventory_ledgers.type','confirmed')
            ->select('bookings.pnr', DB::raw('SUM(inventory_ledgers.quantity) as net'))
            ->groupBy('bookings.pnr')
            ->having('net','<',0)
            ->get();

        if ($cancelledLeaking->count() > 0) {
            $this->line('CANCELLED BOOKINGS LEAKING SEATS: ');
            foreach ($cancelledLeaking as $row) $this->line($row->pnr . ' net=' . $row->net);
        }

        $overpaid = DB::table('bookings')
            ->whereRaw('total_paid > grand_total AND grand_total > 0')
            ->get(['pnr','grand_total','total_paid']);
        if ($overpaid->count()) {
            foreach ($overpaid as $b) {
                $this->line('OVERPAID: ' . $b->pnr . ' grand=' . $b->grand_total . ' paid=' . $b->total_paid);
            }
        }

        // Additional Tests
        $this->info("=== ADD ===");
        $confirmedBooking = Booking::where('booking_status','confirmed')->first();
        if ($confirmedBooking) {
            $bsCode = file_get_contents(app_path('Services/BookingService.php'));
            $hasAddPassenger = str_contains($bsCode, 'addPassenger') || str_contains($bsCode, 'add_passenger');
            $hasAddSeats = str_contains($bsCode, 'addSeats');
            $this->line('BookingService has addPassenger: ' . ($hasAddPassenger ? 'YES' : 'NO'));
            $this->line('BookingService has addSeats: ' . ($hasAddSeats ? 'YES' : 'NO'));
            
            $brCode = file_get_contents(app_path('Filament/Resources/BookingResource.php'));
            $hasAddSeatsAction = str_contains($brCode, 'add_seats') || str_contains($brCode, 'addSeats');
            $this->line('BookingResource has add_seats action: ' . ($hasAddSeatsAction ? 'YES' : 'NO'));
        }

        $cancelCode = file_get_contents(app_path('Services/BookingService.php'));
        $cancelMethod = '';
        preg_match('/public function cancelBooking.*?\{.*?\n    \}/s', $cancelCode, $m);
        if (empty($m)) preg_match('/public function cancelBooking.*?\n    \}/s', $cancelCode, $m);
        if (empty($m)) preg_match('/function cancelBooking(.*?)\}/s', $cancelCode, $m);
        $this->line('cancelBooking method: ' . PHP_EOL . ($m[0] ?? 'not found'));
    }
}
