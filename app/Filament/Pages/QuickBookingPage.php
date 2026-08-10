<?php

namespace App\Filament\Pages;

use App\Filament\Resources\BookingResource;
use App\Models\Customer;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Services\CreateBookingService;
use App\Exceptions\InventoryExhaustedException;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class QuickBookingPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon    = 'heroicon-o-bolt';
    protected static ?string $navigationGroup   = 'العمليات اليومية';
    protected static ?int    $navigationSort     = 0; // Appears first in the group
    protected static ?string $navigationLabel   = '⚡ حجز سريع';
    protected static ?string $title             = 'حجز جديد — خطوة بخطوة';
    protected static string  $view              = 'filament.pages.quick-booking';

    // ─── Wizard State ────────────────────────────────────────────────
    public int $currentStep = 1;
    public const TOTAL_STEPS = 5;

    // Step 1 — Customer
    public ?string $customerSearch  = '';
    public ?int    $customer_id     = null;
    public ?string $customer_name   = '';
    public ?string $customer_phone  = '';
    public bool    $creatingCustomer = false;

    // Step 2 — Trip
    public ?int $trip_instance_id = null;

    // Step 3 — Passengers
    public array $passengers = [
        ['first_name' => '', 'last_name' => '', 'document_type' => 'national_id', 'document_number' => '', 'trip_passenger_category_id' => null],
    ];

    // Step 4 — Payment
    public string  $payment_method = 'cash';
    public ?string $notes          = '';

    // Step 5 — Confirmation
    public ?string $pnr            = null;
    public ?int    $booking_id     = null;

    // ─── Computed helpers ─────────────────────────────────────────────
    public function getTenant()
    {
        return Filament::getTenant();
    }

    public function getSelectedTrip(): ?TripInstance
    {
        if (!$this->trip_instance_id) return null;
        return TripInstance::with('tripTemplate', 'tripPassengerCategories')->find($this->trip_instance_id);
    }

    public function getAvailableTrips(): \Illuminate\Database\Eloquent\Collection
    {
        $tenantId = $this->getTenant()?->id;
        return TripInstance::with('tripTemplate')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where('start_date', '>=', now())
            ->orderBy('start_date')
            ->get();
    }

    public function getPassengerCategories(): array
    {
        if (!$this->trip_instance_id) return [];
        return TripPassengerCategory::where('trip_instance_id', $this->trip_instance_id)
            ->get()
            ->mapWithKeys(fn ($c) => [$c->id => $c->name . ' — ' . number_format($c->price / 100, 2) . ' $'])
            ->toArray();
    }

    public function getGrandTotal(): float
    {
        $total = 0;
        foreach ($this->passengers as $p) {
            if (!empty($p['trip_passenger_category_id'])) {
                $cat = TripPassengerCategory::find($p['trip_passenger_category_id']);
                if ($cat) $total += $cat->price / 100;
            }
        }
        return $total;
    }

    // ─── Step Navigation ─────────────────────────────────────────────
    public function nextStep(): void
    {
        $this->validateCurrentStep();
        if ($this->currentStep < self::TOTAL_STEPS) {
            $this->currentStep++;
        }
    }

    public function prevStep(): void
    {
        if ($this->currentStep > 1) {
            $this->currentStep--;
        }
    }

    protected function validateCurrentStep(): void
    {
        match ($this->currentStep) {
            1 => $this->validateStep1(),
            2 => $this->validateStep2(),
            3 => $this->validateStep3(),
            4 => fn () => null, // Payment step — no required validation
            default => fn () => null,
        };
    }

    protected function validateStep1(): void
    {
        if (!$this->customer_id && !$this->creatingCustomer) {
            Notification::make()->warning()->title('يرجى اختيار عميل أو إنشاء عميل جديد')->send();
            $this->halt();
        }

        if ($this->creatingCustomer) {
            if (empty($this->customer_name) || empty($this->customer_phone)) {
                Notification::make()->warning()->title('يرجى إدخال اسم العميل ورقم الهاتف')->send();
                $this->halt();
            }
            // Create or find customer
            $tenantId = $this->getTenant()?->id;
            $customer = Customer::firstOrCreate(
                ['phone' => $this->customer_phone, 'tenant_id' => $tenantId],
                ['name' => $this->customer_name, 'tenant_id' => $tenantId]
            );
            $this->customer_id = $customer->id;
            $this->customer_name = $customer->name;
        }
    }

    protected function validateStep2(): void
    {
        if (!$this->trip_instance_id) {
            Notification::make()->warning()->title('يرجى اختيار رحلة')->send();
            $this->halt();
        }
    }

    protected function validateStep3(): void
    {
        foreach ($this->passengers as $i => $p) {
            if (empty($p['first_name']) || empty($p['trip_passenger_category_id'])) {
                Notification::make()->warning()->title("يرجى إكمال بيانات الراكب رقم " . ($i + 1))->send();
                $this->halt();
            }
        }
    }

    // ─── Customer Search ──────────────────────────────────────────────
    public function searchCustomer(): void
    {
        $tenantId = $this->getTenant()?->id;
        $q = trim($this->customerSearch);
        if (strlen($q) < 3) return;

        $customer = Customer::where('tenant_id', $tenantId)
            ->where(fn ($query) => $query->where('phone', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%"))
            ->first();

        if ($customer) {
            $this->customer_id    = $customer->id;
            $this->customer_name  = $customer->name;
            $this->customer_phone = $customer->phone;
            $this->creatingCustomer = false;
            Notification::make()->success()->title("✅ تم العثور على العميل: {$customer->name}")->send();
        } else {
            // Suggest creating a new customer
            $this->customer_id = null;
            $this->customer_phone = $q;
            $this->creatingCustomer = true;
        }
    }

    public function clearCustomer(): void
    {
        $this->customer_id      = null;
        $this->customer_name    = '';
        $this->customer_phone   = '';
        $this->customerSearch   = '';
        $this->creatingCustomer = false;
    }

    // ─── Trip Selection ───────────────────────────────────────────────
    public function selectTrip(int $tripId): void
    {
        $this->trip_instance_id = $tripId;
        // Reset passengers categories when trip changes
        foreach ($this->passengers as &$p) {
            $p['trip_passenger_category_id'] = null;
        }
    }

    // ─── Passenger Management ─────────────────────────────────────────
    public function addPassenger(): void
    {
        $this->passengers[] = [
            'first_name' => '', 'last_name' => '', 
            'document_type' => 'national_id', 'document_number' => '',
            'trip_passenger_category_id' => null,
        ];
    }

    public function removePassenger(int $index): void
    {
        if (count($this->passengers) > 1) {
            array_splice($this->passengers, $index, 1);
        }
    }

    // ─── Final Booking Submission ─────────────────────────────────────
    public function submitBooking(): void
    {
        $tenantId = $this->getTenant()?->id;
        $tenantId = $tenantId ?? auth()->user()->tenants()->first()->id;

        $passengersData = array_map(fn ($p) => [
            'trip_passenger_category_id' => $p['trip_passenger_category_id'],
            'first_name'                 => $p['first_name'],
            'last_name'                  => $p['last_name'] ?? '',
            'document_type'              => $p['document_type'] ?? null,
            'document_number'            => $p['document_number'] ?? null,
            'date_of_birth'              => null,
            'extra_preferences'          => [],
        ], $this->passengers);

        try {
            $service = new CreateBookingService();
            $booking = $service->execute([
                'tenant_id'       => $tenantId,
                'trip_instance_id' => $this->trip_instance_id,
                'customer_id'     => $this->customer_id,
                'user_id'         => auth()->id(),
                'passengersData'  => $passengersData,
                'addonsData'      => [],
                'notes'           => $this->notes,
                'payment_type'    => 'full',
            ]);

            $this->pnr        = $booking->pnr;
            $this->booking_id = $booking->id;
            $this->currentStep = 5;

        } catch (InventoryExhaustedException $e) {
            Notification::make()->danger()->title('نفذت المقاعد')->body($e->getMessage())->send();
        } catch (\Exception $e) {
            Notification::make()->danger()->title('حدث خطأ')->body($e->getMessage())->send();
        }
    }

    // ─── Reset / Start Over ───────────────────────────────────────────
    public function startNewBooking(): void
    {
        $this->currentStep      = 1;
        $this->customer_id      = null;
        $this->customer_name    = '';
        $this->customer_phone   = '';
        $this->customerSearch   = '';
        $this->creatingCustomer = false;
        $this->trip_instance_id = null;
        $this->passengers       = [
            ['first_name' => '', 'last_name' => '', 'document_type' => 'national_id', 'document_number' => '', 'trip_passenger_category_id' => null],
        ];
        $this->payment_method   = 'cash';
        $this->notes            = '';
        $this->pnr              = null;
        $this->booking_id       = null;
    }

    public function viewBooking(): void
    {
        $this->redirect(BookingResource::getUrl('view', ['record' => $this->booking_id]));
    }
}
