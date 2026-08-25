<?php

namespace App\Filament\Pages;

use App\Models\Customer;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Services\CreateBookingService;
use App\Exceptions\InventoryExhaustedException;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * PhoneBookingPage — 90-second phone booking interface.
 *
 * Designed for: "Hi, I want to book the Yafa trip, 10 people, one child under 2"
 * → Agent completes the entire booking DURING the phone call, no documents needed.
 * → Placeholder passengers are created (data_complete=false), customer fills details later via link.
 */
class PhoneBookingPage extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-phone';
    protected static ?string $navigationGroup = 'العمليات اليومية';
    protected static ?int    $navigationSort   = 0; // First item in group
    protected static ?string $navigationLabel = '📞 حجز هاتفي';
    protected static ?string $title           = '📞 حجز هاتفي سريع';
    protected static string  $view            = 'filament.pages.phone-booking';

    // ─── Step control ─────────────────────────────────────────────────
    // 1 = customer+trip selection, 2 = seat allocation, 3 = confirmation
    public int $step = 1;

    // ─── Step 1: Customer ─────────────────────────────────────────────
    public string  $customerQuery   = '';
    public ?int    $customer_id     = null;
    public ?string $customerName    = '';
    public ?string $customerPhone   = '';
    public bool    $showNewCustomer = false;
    public string  $newCustomerName = '';
    public array   $customerBookings = [];

    public ?string $customerNotes = '';
    public int     $customerTotalBookings = 0;

    // ─── Step 1: Trip ─────────────────────────────────────────────────
    public string $tripQuery        = '';
    public array  $tripFilters      = []; // ['active', 'internal', 'external']
    public ?int   $trip_instance_id = null;
    public ?array $selectedTrip     = null;

    // ─── Step 2: Seat allocation ──────────────────────────────────────
    // [ ['category_id' => X, 'category_name' => '...', 'price' => 200, 'count' => 0], ... ]
    public array $allocation = [];

    // ─── Step 3: Result ───────────────────────────────────────────────
    public ?string $pnr        = null;
    public ?int    $booking_id = null;
    public ?string $notes      = '';

    // ─── Computed ─────────────────────────────────────────────────────
    public function getTenantId(): int
    {
        return Filament::getTenant()?->id ?? auth()->user()->tenants()->first()->id;
    }

    public function getCustomerResults(): array
    {
        if (mb_strlen($this->customerQuery) < 2) return [];
        return Customer::where('tenant_id', $this->getTenantId())
            ->where(fn ($q) => $q
                ->where('phone', 'like', "%{$this->customerQuery}%")
                ->orWhere('name', 'like', "%{$this->customerQuery}%")
            )
            ->limit(5)
            ->get()
            ->toArray();
    }

    public function getTripResults(): array
    {
        $query = TripInstance::with('tripTemplate')
            ->where('tenant_id', $this->getTenantId())
            ->where('status', 'active')
            ->where('start_date', '>=', now()->subDays(1));

        if (mb_strlen($this->tripQuery) >= 1) {
            $query->whereHas('tripTemplate', fn ($q) =>
                $q->where('title', 'like', "%{$this->tripQuery}%")
            );
        }

        // Bug fix: $tripFilters ('internal'/'external', already rendered as filter chips in the
        // Blade view) was never actually applied to this query — trip_type didn't exist yet to
        // filter by. 'active' is a display-only chip already covered by the base query above.
        // Both chips toggled together means "either type" (whereIn), not "neither" (which two
        // separate AND'd whereHas calls would have produced).
        $typeValues = [];
        if (in_array('internal', $this->tripFilters, true)) {
            $typeValues[] = \App\Enums\TripTypeEnum::Domestic->value;
        }
        if (in_array('external', $this->tripFilters, true)) {
            $typeValues[] = \App\Enums\TripTypeEnum::International->value;
        }
        if (!empty($typeValues)) {
            $query->whereHas('tripTemplate', fn ($q) => $q->whereIn('trip_type', $typeValues));
        }

        return $query->orderBy('start_date')
            ->limit(10)
            ->get()
            ->map(fn ($t) => [
                'id'             => $t->id,
                'title'          => $t->tripTemplate?->title ?? 'رحلة',
                'start_date'     => $t->start_date?->format('d M Y'),
                'end_date'       => $t->end_date?->format('d M Y'),
                'remaining'      => $t->remaining_seats,
                'available'      => $t->available_seats,
            ])
            ->toArray();
    }

    public function getTotalAmount(): float
    {
        return collect($this->allocation)->sum(fn ($row) => ($row['price'] * $row['count']) / 100);
    }

    public function getTotalPassengers(): int
    {
        return collect($this->allocation)->sum('count');
    }

    // ─── Customer actions ─────────────────────────────────────────────
    public function selectCustomer(int $id, string $name, string $phone): void
    {
        $this->customer_id     = $id;
        $this->customerName    = $name;
        $this->customerPhone   = $phone;
        $this->customerQuery   = '';
        $this->showNewCustomer = false;
        
        $this->loadCustomerBookings();
    }
    
    protected function loadCustomerBookings(): void
    {
        $customer = Customer::find($this->customer_id);
        $this->customerNotes = $customer?->notes ?? '';
        
        $this->customerTotalBookings = \App\Models\Booking::where('customer_id', $this->customer_id)
            ->where('booking_status', '!=', \App\Enums\BookingStatus::Cancelled)
            ->count();
            
        $this->customerBookings = \App\Models\Booking::with(['tripInstance.tripTemplate', 'passengers'])
            ->where('customer_id', $this->customer_id)
            ->where('booking_status', '!=', \App\Enums\BookingStatus::Cancelled)
            ->latest()
            ->get()
            ->map(function($b) {
                return [
                    'id' => $b->id,
                    'pnr' => $b->pnr,
                    'trip_title' => $b->tripInstance?->tripTemplate?->title ?? 'غير محدد',
                    'start_date' => $b->tripInstance?->start_date?->format('d M Y'),
                    'passengers_count' => $b->passengers->count(),
                    'total_paid' => $b->total_paid ?? 0,
                    'grand_total' => $b->grand_total ?? 0,
                ];
            })
            ->toArray();
    }

    public function promptNewCustomer(): void
    {
        $this->showNewCustomer  = true;
        $this->newCustomerName  = '';
        // Pre-fill phone if the query looks like a phone number
        $this->customerPhone = preg_match('/^[0-9+]+$/', $this->customerQuery) ? $this->customerQuery : '';
    }

    public function createAndSelectCustomer(): void
    {
        if (empty($this->newCustomerName) || empty($this->customerPhone)) {
            Notification::make()->warning()->title('أدخل الاسم ورقم الهاتف')->send();
            return;
        }
        $customer = Customer::firstOrCreate(
            ['phone' => $this->customerPhone, 'tenant_id' => $this->getTenantId()],
            ['name'  => $this->newCustomerName, 'tenant_id' => $this->getTenantId()]
        );
        $this->selectCustomer($customer->id, $customer->name, $customer->phone);
        $this->newCustomerName  = '';
        $this->showNewCustomer  = false;
    }

    public function clearCustomer(): void
    {
        $this->customer_id      = null;
        $this->customerName     = '';
        $this->customerPhone    = '';
        $this->customerQuery    = '';
        $this->showNewCustomer  = false;
        $this->customerBookings = [];
    }

    public array  $waitlistTrips = [];
    public int    $waitlistSeats = 1;
    public string $waitlistNotes = '';

    public function queueForWaitlist(int $tripId): void
    {
        if (!in_array($tripId, $this->waitlistTrips)) {
            $this->waitlistTrips[] = $tripId;
            // Default seats if not set
            if ($this->waitlistSeats <= 1 && $this->getTotalPassengers() > 1) {
                $this->waitlistSeats = $this->getTotalPassengers();
            }
        }
        $this->dispatch('open-modal', id: 'waitlist-cart');
    }

    public function removeFromWaitlistQueue(int $tripId): void
    {
        $this->waitlistTrips = array_values(array_diff($this->waitlistTrips, [$tripId]));
        if (empty($this->waitlistTrips)) {
            $this->dispatch('close-modal', id: 'waitlist-cart');
        }
    }

    public function submitWaitlist(): void
    {
        if (!$this->customer_id) {
            \Filament\Notifications\Notification::make()->warning()->title('الرجاء اختيار العميل أولاً')->send();
            return;
        }
        if (empty($this->waitlistTrips)) {
            return;
        }

        $customer = \App\Models\Customer::find($this->customer_id);
        
        $waitlist = \App\Models\WaitingList::create([
            'tenant_id' => $this->getTenantId(),
            'customer_name' => $customer->name,
            'phone_number' => $customer->phone,
            'customer_email' => $customer->email,
            'seats_requested' => $this->waitlistSeats,
            'status' => \App\Enums\WaitingListStatusEnum::Pending,
            'notes' => $this->waitlistNotes ?: 'إضافة من شاشة الحجز الهاتفي',
        ]);
        
        $syncData = [];
        foreach ($this->waitlistTrips as $index => $id) {
            $syncData[$id] = ['priority' => $index + 1];
        }
        
        $waitlist->tripInstances()->sync($syncData);
        
        $this->waitlistTrips = [];
        $this->waitlistNotes = '';
        
        $this->dispatch('close-modal', id: 'waitlist-cart');
        \Filament\Notifications\Notification::make()->success()->title('تم الحفظ في قائمة الانتظار بنجاح!')->send();
    }

    // ─── Trip actions ─────────────────────────────────────────────────
    public function toggleTripFilter(string $filter): void
    {
        if ($filter === 'all') {
            $this->tripFilters = [];
            return;
        }
        
        if (in_array($filter, $this->tripFilters)) {
            $this->tripFilters = array_values(array_diff($this->tripFilters, [$filter]));
        } else {
            $this->tripFilters[] = $filter;
        }
    }

    public function selectTrip(int $id): void
    {
        $instance = TripInstance::with('tripTemplate', 'tripPassengerCategories')->find($id);
        if (!$instance) return;

        $this->trip_instance_id = $id;
        $this->tripQuery = '';
        $this->selectedTrip = [
            'id'        => $instance->id,
            'title'     => $instance->tripTemplate?->title ?? 'رحلة',
            'start'     => $instance->start_date?->format('d M Y'),
            'end'       => $instance->end_date?->format('d M Y'),
            'remaining' => $instance->remaining_seats,
        ];

        // Build allocation table from the trip's passenger categories
        $this->allocation = $instance->tripPassengerCategories
            ->map(fn ($cat) => [
                'category_id'   => $cat->id,
                'category_name' => $cat->name,
                'price'         => $cat->price, // in cents
                'count'         => 0,
            ])
            ->toArray();
    }

    public function clearTrip(): void
    {
        $this->trip_instance_id = null;
        $this->selectedTrip     = null;
        $this->tripQuery        = '';
        $this->allocation       = [];
    }

    // ─── Seat counters ────────────────────────────────────────────────
    public function increment(int $index): void
    {
        $remaining = $this->selectedTrip['remaining'] ?? 0;
        if ($this->getTotalPassengers() >= $remaining) {
            Notification::make()->warning()->title('لا يوجد مقاعد كافية')->send();
            return;
        }
        $this->allocation[$index]['count']++;
    }

    public function decrement(int $index): void
    {
        if ($this->allocation[$index]['count'] > 0) {
            $this->allocation[$index]['count']--;
        }
    }

    // ─── Navigation ───────────────────────────────────────────────────
    public function goToStep2(): void
    {
        if (!$this->customer_id) {
            Notification::make()->warning()->title('يرجى اختيار العميل أولاً')->send();
            return;
        }
        if (!$this->trip_instance_id) {
            Notification::make()->warning()->title('يرجى اختيار الرحلة أولاً')->send();
            return;
        }
        $this->step = 2;
    }

    public function backToStep1(): void
    {
        $this->step = 1;
    }

    // ─── Submit booking ───────────────────────────────────────────────
    public function submit(): void
    {
        $totalPassengers = $this->getTotalPassengers();
        if ($totalPassengers === 0) {
            Notification::make()->warning()->title('يرجى تحديد عدد المقاعد')->send();
            return;
        }

        // Build passengersData: one placeholder per seat per category
        $passengersData = [];
        foreach ($this->allocation as $row) {
            for ($i = 0; $i < $row['count']; $i++) {
                $passengersData[] = [
                    'trip_passenger_category_id' => $row['category_id'],
                    'first_name'                 => null, // filled later
                    'last_name'                  => null,
                    'document_type'              => null,
                    'document_number'            => null,
                    'date_of_birth'              => null,
                    'extra_preferences'          => [],
                ];
            }
        }

        try {
            $tenantId = $this->getTenantId();
            $service  = new CreateBookingService();
            $booking  = $service->execute([
                'tenant_id'          => $tenantId,
                'trip_instance_id'   => $this->trip_instance_id,
                'customer_id'        => $this->customer_id,
                'user_id'            => auth()->id(),
                'passengersData'     => $passengersData,
                'addonsData'         => [],
                'notes'              => $this->notes,
                'payment_type'       => 'full',
                'phone_booking_mode' => true, // ← tells service to create placeholder passengers
            ]);

            // Permissive requirement-preset check: never blocks — phone bookings deliberately
            // collect nothing per-passenger, so this will fire on nearly every phone booking
            // against a trip with a preset attached, by design. CreateBookingService::execute()
            // already computed and persisted each passenger's requirements_complete flag.
            if ($summary = app(\App\Services\RequirementValidationService::class)->summarizeIncompletePassengers($booking)) {
                Notification::make()
                    ->warning()
                    ->title('تنبيه: بيانات ناقصة')
                    ->body($summary)
                    ->persistent()
                    ->send();
            }

            $this->pnr        = $booking->pnr;
            $this->booking_id = $booking->id;
            $this->step       = 3;

        } catch (InventoryExhaustedException $e) {
            Notification::make()->danger()->title('نفذت المقاعد')->body($e->getMessage())->send();
        } catch (\Exception $e) {
            Notification::make()->danger()->title('خطأ في الحجز')->body($e->getMessage())->send();
        }
    }

    // ─── Reset ────────────────────────────────────────────────────────
    public function resetBooking(): void
    {
        $this->step            = 1;
        $this->customerQuery   = '';
        $this->customer_id     = null;
        $this->customerName    = '';
        $this->customerPhone   = '';
        $this->showNewCustomer = false;
        $this->newCustomerName = '';
        $this->tripQuery       = '';
        $this->trip_instance_id = null;
        $this->selectedTrip    = null;
        $this->allocation      = [];
        $this->notes           = '';
        $this->pnr             = null;
        $this->booking_id      = null;
    }

    public function goToBooking(): void
    {
        $this->redirect(\App\Filament\Resources\BookingResource::getUrl('view', ['record' => $this->booking_id]));
    }
}
