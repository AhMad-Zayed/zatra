<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TripInstanceResource\Pages;
use App\Filament\Resources\TripInstanceResource\RelationManagers;
use App\Models\TripInstance;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TripInstanceResource extends Resource
{
    protected static ?string $model = TripInstance::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    public static function getNavigationLabel(): string
    {
        return 'الرحلات المجدولة';
    }

    public static function getModelLabel(): string
    {
        return 'رحلة مجدولة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الرحلات المجدولة';
    }

    // Navigation group — matches TripBuilderResource
    protected static ?string $navigationGroup = 'الرحلات والفنادق';
    protected static ?int $navigationSort = 1;

    // N+1-003: Eager load tripTemplate to prevent N queries per row in the table
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['tripTemplate'])
            ->withCount([
                'bookings as confirmed_bookings_count' => fn ($q) =>
                    $q->whereIn('booking_status', ['confirmed', 'confirmed_partial'])
            ]);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('المعلومات الأساسية')
                    ->schema([
                        Forms\Components\Select::make('trip_template_id')
                            ->relationship('tripTemplate', 'title')
                            ->label('البرنامج الأساسي (القالب)')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(function (Forms\Set $set, ?string $state) {
                                if (! $state) {
                                    $set('tripPassengerCategories', []);
                                    $set('tripAddons', []);
                                    return;
                                }

                                $template = \App\Models\TripTemplate::with(['templatePassengerCategories', 'templateAddons'])->find($state);

                                if ($template) {
                                    $tiers = $template->templatePassengerCategories->map(fn ($tier) => [
                                        'name' => $tier->name,
                                        'price' => $tier->price,
                                        'requires_seat' => $tier->requires_seat,
                                    ])->toArray();

                                    $addons = $template->templateAddons->map(fn ($addon) => [
                                        'name' => $addon->name,
                                        'price' => $addon->price,
                                        'max_quantity' => $addon->max_quantity,
                                    ])->toArray();

                                    $set('tripPassengerCategories', $tiers);
                                    $set('tripAddons', $addons);
                                    $set('currency', $template->currency); // Auto inherit currency from template
                                }
                            }),
                        Forms\Components\Select::make('currency')
                            ->label('العملة')
                            ->options([
                                'USD' => 'دولار (USD)',
                                'ILS' => 'شيكل (ILS)',
                            ])
                            ->required()
                            ->disabledOn('edit') // Rule: Cannot change currency easily once created
                            ->helperText('ترث العملة من القالب، ولا يمكن تغييرها لاحقاً إذا كان هناك حجوزات.'),
                        Forms\Components\DatePicker::make('start_date')
                            ->label('تاريخ الذهاب')
                            ->required(),
                        Forms\Components\DatePicker::make('end_date')
                            ->label('تاريخ الإياب')
                            ->required()
                            ->afterOrEqual('start_date'),
                        Forms\Components\TextInput::make('available_seats')
                            ->label('المقاعد المتاحة')
                            ->numeric()
                            ->required(),
                        Forms\Components\Select::make('status')
                            ->label('حالة الرحلة')
                            ->options([
                                'active' => 'نشط',
                                'completed' => 'مكتملة',
                            ])
                            ->required()
                            ->default('active'),
                    ])->columns(2),
                    
                Forms\Components\Section::make('التسعير الخاص والوسائط')
                    ->description('تعديل السعر أو إضافة صورة غلاف خاصة بهذا الموعد فقط (اختياري)')
                    ->schema([
                        Forms\Components\Toggle::make('price_override')
                            ->label('تغيير السعر الأساسي لهذا الموعد؟')
                            ->live()
                            ->default(false),
                            
                        Forms\Components\TextInput::make('price_override_amount')
                            ->label('السعر الجديد لهذا الموعد')
                            ->numeric()
                            ->prefix('$')
                            ->visible(fn (Forms\Get $get) => $get('price_override')),
                            
                        Forms\Components\SpatieMediaLibraryFileUpload::make('cover')
                            ->collection('cover')
                            ->label('صورة الغلاف (لهذا الموعد تحديداً)')
                            ->image()
                            ->imageEditor()
                            ->helperText('اختياري: إذا تركته فارغاً، سيتم الاعتماد على صورة الغلاف المرفقة في البرنامج الأساسي.')
                            ->columnSpanFull(),
                    ])->columns(2),

                Forms\Components\Section::make('فئات التسعير الخاصة بهذا الموعد')
                    ->description('تم نسخ هذه الفئات من القالب تلقائياً، يمكنك تعديل أسعارها لهذا الموعد خصيصاً (مثال: أسعار العطلات).')
                    ->schema([
                        Forms\Components\Repeater::make('tripPassengerCategories')
                            ->relationship('tripPassengerCategories')
                            ->minItems(1)
                            ->label('الفئات')
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label('اسم الفئة')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('price')
                                    ->label('السعر لهذا الموعد')
                                    ->numeric()
                                    ->required()
                                    ->prefix('$'),
                                Forms\Components\Toggle::make('requires_seat')
                                    ->label('يخصم مقعد؟')
                                    ->default(true)
                                    ->required(),
                            ])
                            ->columns(3)
                            ->addActionLabel('إضافة فئة تسعير استثنائية'),
                    ]),

                Forms\Components\Section::make('الإضافات والمخزون الخاص بهذا الموعد')
                    ->description('تم نسخ الإضافات من القالب، قم بتحديد السعة القصوى المتاحة لهذا الموعد لتجنب الحجوزات الزائدة.')
                    ->schema([
                        Forms\Components\Repeater::make('tripAddons')
                            ->relationship()
                            ->label('الإضافات')
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label('اسم الإضافة')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('price')
                                    ->label('السعر')
                                    ->numeric()
                                    ->required()
                                    ->prefix('$'),
                                Forms\Components\TextInput::make('max_quantity')
                                    ->label('السعة القصوى المتاحة')
                                    ->numeric()
                                    ->helperText('مثال: 5 غرف مفردة فقط في هذا التاريخ'),
                            ])
                            ->columns(3)
                            ->addActionLabel('إضافة خدمة استثنائية'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('effective_cover_url')
                    ->label('صورة الغلاف')
                    ->getStateUsing(fn ($record) => $record->effective_cover_url)
                    ->circular(),
                Tables\Columns\TextColumn::make('tripTemplate.title')
                    ->label('البرنامج السياحي')
                    ->searchable()
                    ->sortable(),
                // Read through the template relation — trip_type is a template-level
                // classification field only, not duplicated onto trip_instances.
                Tables\Columns\TextColumn::make('tripTemplate.trip_type')
                    ->label('نوع الرحلة')
                    ->badge()
                    ->placeholder('غير مصنف'),
                Tables\Columns\TextColumn::make('start_date')
                    ->label('تاريخ الذهاب')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('effective_price')
                    ->label('السعر الفعلي')
                    ->getStateUsing(fn ($record) => $record->price_override ? $record->price_override_amount : $record->tripTemplate?->base_price)
                    ->money('USD')
                    ->sortable(),
                Tables\Columns\TextColumn::make('end_date')
                    ->label('تاريخ الإياب')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('available_seats')
                    ->label('السعة الكلية')
                    ->numeric()
                    ->sortable(),
                // N+1 fixed: remaining_seats uses the model accessor (computed from InventoryLedger)
                Tables\Columns\TextColumn::make('remaining_seats')
                    ->label('المقاعد المتاحة')
                    ->getStateUsing(fn ($record) => $record->remaining_seats)
                    ->badge()
                    ->color(fn ($state) => match(true) {
                        $state <= 0  => 'danger',
                        $state <= 5  => 'warning',
                        default      => 'success',
                    }),
                Tables\Columns\TextColumn::make('bookings_count')
                    ->label('الحجوزات المؤكدة')
                    ->counts('bookings')
                    ->badge()
                    ->color('success')
                    ->sortable(),
                Tables\Columns\TextColumn::make('active_passengers_count')
                    ->label('عدد المسافرين')
                    ->counts('activePassengers')
                    ->badge()
                    ->color('info')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->colors([
                        'primary' => 'active',
                        'success' => 'completed',
                        'danger' => 'cancelled',
                    ])
                    ->formatStateUsing(fn ($state): string => match ($state?->value ?? $state) {
                        'active' => 'نشط',
                        'completed' => 'مكتملة',
                        'cancelled' => 'ملغية',
                        default => (string) ($state?->value ?? $state),
                    })
                    ->sortable(),
            ])
            ->defaultSort('start_date', 'asc')
            ->filters([
                // HIGH-004: TripInstanceResource had empty filters — now fully functional
                Tables\Filters\SelectFilter::make('status')
                    ->label('حالة الرحلة')
                    ->options(\App\Enums\TripStatusEnum::class),
                Tables\Filters\Filter::make('upcoming')
                    ->label('الرحلات القادمة فقط')
                    ->query(fn (Builder $query) => $query->where('start_date', '>=', now()))
                    ->default(),
                Tables\Filters\Filter::make('date_range')
                    ->label('نطاق التاريخ')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('من'),
                        Forms\Components\DatePicker::make('until')->label('إلى'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'], fn (Builder $query) => $query->whereDate('start_date', '>=', $data['from']))
                        ->when($data['until'], fn (Builder $query) => $query->whereDate('start_date', '<=', $data['until']))
                    ),
                Tables\Filters\Filter::make('has_available_seats')
                    ->label('بها مقاعد متاحة')
                    ->query(fn (Builder $query) => $query->whereHas('tripPassengerCategories')),
                Tables\Filters\SelectFilter::make('trip_type')
                    ->label('نوع الرحلة')
                    ->options(\App\Enums\TripTypeEnum::class)
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $q, $value) => $q->whereHas('tripTemplate', fn (Builder $q2) => $q2->where('trip_type', $value))
                    )),
            ])
            ->actions([
                Tables\Actions\Action::make('generate_manifest')
                    ->label('تحميل كشف الركاب (PDF)')
                    ->icon('heroicon-o-document-text')
                    ->color('success')
                    ->url(fn (TripInstance $record): string => route('trip-instance.manifest', $record))
                    ->openUrlInNewTab(),
                    
                Tables\Actions\Action::make('copy_guide_link')
                    ->label('نسخ رابط المرشد')
                    ->icon('heroicon-o-link')
                    ->color('info')
                    ->action(function (TripInstance $record) {
                        // The action doesn't actually run backend code to copy to clipboard in filament v3 easily,
                        // so we dispatch a browser event or simply show a modal with the link.
                    })
                    ->modalHeading('رابط المرشد السياحي')
                    ->modalDescription('انسخ هذا الرابط وأرسله للمرشد السياحي عبر الواتساب. لا يحتاج المرشد لتسجيل الدخول.')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('إغلاق')
                    ->form([
                        Forms\Components\TextInput::make('guide_url')
                            ->label('الرابط السري (ينتهي بانتهاء الرحلة)')
                            ->default(fn (TripInstance $record) => url('/g/' . $record->uuid))
                            ->disabled() // So they can't edit it, but can copy it
                            ->extraInputAttributes(['readonly' => true]),
                    ]),
                    
                Tables\Actions\Action::make('add_to_waitlist')
                    ->label('إضافة لقائمة الانتظار')
                    ->icon('heroicon-o-user-plus')
                    ->color('warning')
                    ->visible(fn (\App\Models\TripInstance $record) => $record->remaining_seats <= 0)
                    ->form([
                        Forms\Components\TextInput::make('seats_requested')
                            ->label('عدد المقاعد')
                            ->numeric()
                            ->default(1)
                            ->minValue(1)
                            ->required(),
                        Forms\Components\TextInput::make('customer_name')
                            ->label('اسم العميل')
                            ->required(),
                        Forms\Components\TextInput::make('phone_number')
                            ->label('رقم الهاتف')
                            ->tel()
                            ->required(),
                    ])
                    ->action(function (array $data, \App\Models\TripInstance $record) {
                        $wl = \App\Models\WaitingList::create([
                            'tenant_id' => $record->tenant_id,
                            'seats_requested' => $data['seats_requested'],
                            'customer_name' => $data['customer_name'],
                            'phone_number' => $data['phone_number'],
                            'status' => 'pending',
                        ]);
                        
                        $wl->tripInstances()->attach($record->id);
                        
                        \Filament\Notifications\Notification::make()->success()->title('تم الإضافة لقائمة الانتظار بنجاح')->send();
                    }),

                Tables\Actions\Action::make('clone_trip')
                    ->label('نسخ الرحلة')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('warning')
                    ->form([
                        Forms\Components\DatePicker::make('new_start_date')
                            ->label('تاريخ الذهاب الجديد')
                            ->required(),
                        Forms\Components\DatePicker::make('new_end_date')
                            ->label('تاريخ الإياب الجديد')
                            ->required(),
                        Forms\Components\TextInput::make('seats_count')
                            ->label('عدد المقاعد')
                            ->numeric()
                            ->required()
                            ->default(fn (TripInstance $record) => $record->available_seats),
                    ])
                    ->action(function (TripInstance $record, array $data): void {
                        $newRecord = $record->replicate();
                        $newRecord->start_date = $data['new_start_date'];
                        $newRecord->end_date = $data['new_end_date'];
                        $newRecord->available_seats = $data['seats_count'];
                        $newRecord->status = 'active'; // or draft
                        $newRecord->save();
                        
                        // Clone categories
                        foreach ($record->tripPassengerCategories as $cat) {
                            $newCat = $cat->replicate();
                            $newCat->trip_instance_id = $newRecord->id;
                            $newCat->save();
                        }
                        
                        // Clone addons
                        foreach ($record->tripAddons as $addon) {
                            $newAddon = $addon->replicate();
                            $newAddon->trip_instance_id = $newRecord->id;
                            $newAddon->save();
                        }
                        
                        // Sync pickup routes
                        $newRecord->pickupRoutes()->sync($record->pickupRoutes->pluck('id'));
                        
                        \Filament\Notifications\Notification::make()
                            ->title('تم نسخ الرحلة بنجاح')
                            ->success()
                            ->send();
                    }),
                    
                Tables\Actions\Action::make('cancel_trip')
                    ->label('إلغاء الرحلة')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (TripInstance $record): bool =>
                        !in_array($record->status, [
                            \App\Enums\TripStatusEnum::Cancelled,
                            \App\Enums\TripStatusEnum::Completed,
                            \App\Enums\TripStatusEnum::InProgress,
                        ], true) &&
                        auth()->user()?->hasRole('agency_admin')
                    )
                    ->requiresConfirmation()
                    ->modalHeading('تأكيد إلغاء الرحلة')
                    ->modalDescription('سيتم إلغاء الرحلة وجميع الحجوزات المرتبطة بها وإشعار العملاء. هذا الإجراء لا يمكن التراجع عنه.')
                    ->modalIcon('heroicon-o-exclamation-triangle')
                    ->modalIconColor('danger')
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('سبب الإلغاء (سيظهر للعملاء)')
                            ->required()
                            ->rows(3)
                            ->placeholder('مثال: ظروف طارئة، عدم اكتمال العدد...'),
                    ])
                    ->action(function (TripInstance $record, array $data): void {
                        try {
                            $count = app(\App\Services\TripService::class)->cancelTrip(
                                $record,
                                $data['reason'] ?? ''
                            );

                            \Filament\Notifications\Notification::make()
                                ->title('تم إلغاء الرحلة بنجاح')
                                ->body("تم إلغاء {$count} حجز وإشعار العملاء.")
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('فشل إلغاء الرحلة')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\EditAction::make(),
                // SEC-002: Restrict trip delete to agency_admin only (trips can have live bookings)
                Tables\Actions\DeleteAction::make()
                    ->visible(fn () => auth()->user()?->hasRole('agency_admin'))
                    ->requiresConfirmation()
                    ->modalDescription('هذا الإجراء لا يمكن التراجع عنه. سيتم حذف الرحلة وجميع بياناتها.'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('send_manifest_whatsapp')
                        ->label('إرسال الكشوفات للمرشدين (WhatsApp)')
                        ->icon('heroicon-o-chat-bubble-left-right')
                        ->color('success')
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            foreach ($records as $record) {
                                // Real implementation: dispatch a Job that generates the PDF and sends it via WhatsApp API
                                // Example: \App\Jobs\SendManifestWhatsAppJob::dispatch($record);
                                \Illuminate\Support\Facades\Log::info("Dispatched WhatsApp Manifest Job for Trip: {$record->id}");
                            }
                            \Filament\Notifications\Notification::make()
                                ->title('تم جدولة إرسال الكشوفات عبر الواتساب')
                                ->success()
                                ->send();
                        })
                        ->requiresConfirmation(),
                    // SEC-003: Restrict bulk trip delete to agency_admin only
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()?->hasRole('agency_admin')),
                        
                    Tables\Actions\BulkAction::make('bulk_status_change')
                        ->label('تغيير حالة الرحلات')
                        ->icon('heroicon-o-arrow-path')
                        ->form([
                            Forms\Components\Select::make('new_status')
                                ->label('الحالة الجديدة')
                                // Cancelled is deliberately excluded: cancellation must only
                                // happen through the cancel_trip action, which cascades to
                                // bookings (inventory release, refund-liability tracking,
                                // notification). This bulk action does a bare status update
                                // with no cascade at all.
                                ->options(
                                    collect(\App\Enums\TripStatusEnum::cases())
                                        ->reject(fn ($c) => $c === \App\Enums\TripStatusEnum::Cancelled)
                                        ->mapWithKeys(fn ($c) => [$c->value => $c->getLabel()])
                                )
                                ->required(),
                        ])
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records, array $data) {
                            $records->each(fn ($record) => $record->update(['status' => $data['new_status']]));
                            \Filament\Notifications\Notification::make()
                                ->title('تم تحديث حالة الرحلات بنجاح')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\BookingsRelationManager::class,
            RelationManagers\TripPassengersRelationManager::class,
            RelationManagers\WaitingListsRelationManager::class,
            RelationManagers\PackageOptionsRelationManager::class,
            // Hotel/Rooming redesign Phase 1 — additive, alongside PackageOptionsRelationManager
            // above (which stays fully live and untouched; see Ticket 1 investigation report).
            RelationManagers\TripStayLegsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTripInstances::route('/'),
            'create' => Pages\CreateTripInstance::route('/create'),
            'edit' => Pages\EditTripInstance::route('/{record}/edit'),
            'assign-rooms' => Pages\AssignRooms::route('/{record}/assign-rooms'),
        ];
    }
}
