---
name: zatara-stack-purist
description: Enforces exclusive use of Filament v3, Spatie packages, and approved stack components for the Zatara Tourism admin system. Apply this skill for ANY task involving admin panels, permissions, file uploads, notifications, or UI components.
---

# Zatara Stack Purist — Hard Constraints

## Core Principle
**Never reinvent what the stack already provides.** Filament, Spatie, and Alpine.js were chosen deliberately. Every workaround is a bug waiting to happen.

---

## FILAMENT V3 — ADMIN UI IS FILAMENT-ONLY

### Rule P-1: NEVER build a custom admin panel
```php
// ✅ CORRECT — Filament Resource for everything admin
class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('customer_id')->relationship('customer', 'name')->searchable(),
            Select::make('trip_instance_id')->relationship('tripInstance', 'departure_date'),
            TextInput::make('total_amount')->numeric()->prefix('ILS'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('reference')->searchable()->sortable(),
            TextColumn::make('customer.name'),
            BadgeColumn::make('payment_status')->colors([...]),
        ]);
    }
}

// ❌ FORBIDDEN — rolling a custom admin UI
Route::get('/admin/bookings', [AdminBookingController::class, 'index']);
// Writing raw Blade views for admin = VIOLATION
```

### Rule P-2: Use RelationManagers for related data — never custom components
```php
// ✅ CORRECT — Filament RelationManager for payments
class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('amount')->money('ILS'),
                TextColumn::make('type')->badge(),
                TextColumn::make('created_at')->dateTime(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->using(fn (array $data, string $model) =>
                        app(PaymentService::class)->recordPayment($this->getOwnerRecord(), $data)
                    ),
            ]);
    }
}

// ❌ FORBIDDEN — building a custom Vue/React component for this
```

### Rule P-3: Filament Actions must delegate to Service classes
```php
// ✅ CORRECT
Action::make('cancel_booking')
    ->action(fn (Booking $record) => app(BookingService::class)->cancelBooking($record))
    ->requiresConfirmation()
    ->color('danger');

// ❌ FORBIDDEN — action logic inline
Action::make('cancel_booking')
    ->action(function (Booking $record) {
        $record->update(['status' => 'cancelled']); // VIOLATION
        $record->payments()->delete(); // DOUBLE VIOLATION
    });
```

---

## SPATIE PERMISSION — AUTHORIZATION IS SPATIE-ONLY

### Rule SP-1: NEVER write custom roles or gates from scratch
```php
// ✅ CORRECT — use Spatie roles
$user->assignRole('booking-agent');
$user->can('create_booking');

// In Filament — use FilamentShield
public static function canCreate(): bool
{
    return auth()->user()->can('create_booking');
}

// ❌ FORBIDDEN — custom role columns or manual gate definitions
$user->role === 'admin'
Gate::define('create-booking', fn ($user) => $user->is_admin); // VIOLATION
```

### Rule SP-2: Role definitions live in a Seeder, not scattered in code
```
Defined roles: super_admin, admin, accountant, booking_agent, ops_manager, guide
All permission names follow snake_case: create_booking, view_payments, manage_trips
```

---

## SPATIE MEDIA LIBRARY — FILE HANDLING IS SPATIE-ONLY

### Rule M-1: NEVER use `Storage::put()` or `$request->file()->store()` for documents
```php
// ✅ CORRECT — Spatie MediaLibrary for all passenger documents
class Passenger extends Model
{
    use HasMedia, InteractsWithMedia;

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('passport')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'application/pdf']);

        $this->addMediaCollection('national_id')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'application/pdf']);
    }
}

// In Filament
SpatieMediaLibraryFileUpload::make('passport')
    ->collection('passport')
    ->image()
    ->maxSize(5120);

// ❌ FORBIDDEN — manual file handling
$request->file('passport')->store('passports', 'public'); // VIOLATION
Storage::put('documents/' . $id, $file); // VIOLATION
```

---

## SPATIE ACTIVITY LOG — AUDIT IS SPATIE-ONLY

### Rule AL-1: NEVER build a custom audit/log table
```php
// ✅ CORRECT — use Spatie ActivityLog
activity('financial')
    ->performedOn($payment)
    ->causedBy(auth()->user())
    ->withProperties(['amount' => $payment->amount])
    ->log('payment_created');

// ❌ FORBIDDEN — custom audit table
DB::table('audit_logs')->insert([...]); // VIOLATION
AuditLog::create([...]); // VIOLATION if it's a custom model
```

---

## NOTIFICATIONS — ABSTRACT CHANNEL ONLY

### Rule N-1: NEVER hardcode WhatsApp/SMS API calls in business logic
```php
// ✅ CORRECT — abstract Laravel notification
class BookingConfirmed extends Notification
{
    public function via(object $notifiable): array
    {
        return [WhatsAppChannel::class, 'database'];
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        return WhatsAppMessage::create()
            ->to($notifiable->phone)
            ->template('booking_confirmed')
            ->params(['booking_ref' => $this->booking->reference]);
    }
}

// Dispatch via Observer — never inline
class BookingObserver
{
    public function created(Booking $booking): void
    {
        $booking->customer->notify(new BookingConfirmed($booking));
    }
}

// ❌ FORBIDDEN — hardcoded API calls
Http::post('https://api.whatsapp.com/send', ['phone' => $phone, 'message' => '...']); // VIOLATION
// Direct Twilio/Vonage calls inside BookingService = VIOLATION
```

---

## CHECKLIST before submitting any admin/permission/media code
- [ ] Is there any custom admin HTML/Blade? → **Use Filament Resource instead.**
- [ ] Is there a manual `Storage::put()` for documents? → **Use Spatie MediaLibrary.**
- [ ] Is there a `$user->role === 'admin'` check? → **Use `$user->can()` with Spatie.**
- [ ] Is a WhatsApp/SMS API being called directly? → **Use Notification + custom Channel.**
- [ ] Is there a custom audit table? → **Use Spatie ActivityLog.**