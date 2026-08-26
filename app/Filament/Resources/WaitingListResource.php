<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WaitingListResource\Pages;
use App\Filament\Resources\WaitingListResource\RelationManagers;
use App\Models\WaitingList;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class WaitingListResource extends Resource
{
    protected static ?string $model = WaitingList::class;
    protected static ?string $navigationIcon = 'heroicon-o-clock';
    protected static ?string $navigationLabel = 'قوائم الانتظار';
    protected static ?string $pluralModelLabel = 'طلبات الانتظار';
    protected static ?string $modelLabel = 'طلب انتظار';
    protected static ?string $navigationGroup = 'الحجوزات';
    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', \Filament\Facades\Filament::getTenant()->id);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('tripInstances')
                    ->label('الرحلات المطلوبة')
                    ->relationship('tripInstances', 'id') // Required for belongsToMany sync
                    ->options(fn () => \App\Models\TripInstance::with('tripTemplate')
                        // CRITICAL FIX: TripInstance has no BelongsToTenant global scope, so this
                        // picker was showing (and letting staff pick) trip instances belonging to
                        // OTHER tenants -- a cross-tenant data leak. Explicit tenant filter,
                        // matching the isolation convention every guardrail-protected service
                        // already follows.
                        ->where('tenant_id', \Filament\Facades\Filament::getTenant()?->id)
                        ->where('start_date', '>=', now()) // Only upcoming trips
                        ->get()
                        ->mapWithKeys(fn ($t) => [$t->id => $t->tripTemplate?->title . ' — ' . $t->start_date?->format('Y-m-d')])
                    )
                    ->multiple()
                    ->searchable()
                    ->required(),
                Forms\Components\TextInput::make('seats_requested')
                    ->label('عدد المقاعد المطلوبة')
                    ->numeric()
                    ->default(1)
                    ->minValue(1)
                    ->required(),
                Forms\Components\TextInput::make('customer_name')
                    ->label('اسم العميل')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('phone_number')
                    ->label('رقم الهاتف')
                    ->tel()
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('customer_email')
                    ->label('البريد الإلكتروني')
                    ->email()
                    ->maxLength(255)
                    ->nullable(),
                Forms\Components\Select::make('status')
                    ->label('الحالة')
                    ->options([
                        'pending' => 'في الانتظار',
                        'notified' => 'تم التبليغ',
                        'converted_to_booking' => 'تحول لحجز',
                        'expired' => 'منتهي / ملغي',
                    ])
                    ->required()
                    ->default('pending'),
                Forms\Components\DateTimePicker::make('notified_at')
                    ->label('تاريخ التبليغ')
                    ->nullable(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('tripInstances')
                    ->label('الرحلات')
                    ->getStateUsing(fn (WaitingList $record) => $record->tripInstances->map(fn($t) => $t->tripTemplate?->title . ' (' . $t->start_date?->format('m/d') . ')')->join('، '))
                    ->badge()
                    ->color('primary')
                    ->wrap(),
                Tables\Columns\TextColumn::make('seats_requested')
                    ->label('المقاعد')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('customer_name')
                    ->label('العميل')
                    ->searchable(),
                Tables\Columns\TextColumn::make('phone_number')
                    ->label('رقم الهاتف')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->label('الحالة')
                    ->formatStateUsing(fn ($state) => match ($state?->value ?? $state) {
                        'pending' => 'في الانتظار',
                        'notified' => 'تم التبليغ',
                        'converted_to_booking' => 'تحول لحجز',
                        'expired' => 'منتهي / ملغي',
                        default => $state?->value ?? $state,
                    })
                    ->colors([
                        'warning' => 'pending',
                        'success' => fn ($state) => in_array($state?->value ?? $state, ['notified', 'converted_to_booking']),
                        'danger' => 'expired',
                    ]),
                Tables\Columns\TextColumn::make('notified_at')
                    ->label('تاريخ التبليغ')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإضافة')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWaitingLists::route('/'),
            'create' => Pages\CreateWaitingList::route('/create'),
            'edit' => Pages\EditWaitingList::route('/{record}/edit'),
        ];
    }
}
