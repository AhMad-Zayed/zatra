<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VehicleResource\Pages;
use App\Models\User;
use App\Models\Vehicle;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tenant-wide (not trip-scoped) — owned buses are reused across many trips, same posture as
 * HotelResource. Bus/Fleet redesign Ticket 1. Rented buses are never CRUD-managed here; they're
 * captured inline per trip on TripBusAssignment.
 */
class VehicleResource extends Resource
{
    protected static ?string $model = Vehicle::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';
    protected static ?string $navigationGroup = 'الرحلات والفنادق';
    protected static ?int $navigationSort = 4;

    public static function getNavigationLabel(): string
    {
        return 'الحافلات';
    }

    public static function getModelLabel(): string
    {
        return 'حافلة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الحافلات';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', Filament::getTenant()->id);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('plate_number')
                    ->label('رقم اللوحة')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('default_capacity')
                    ->label('السعة الافتراضية (عدد المقاعد)')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->required(),
                Forms\Components\Select::make('default_driver_id')
                    ->label('السائق الدائم (اختياري)')
                    ->options(fn () => User::whereHas(
                        'tenants',
                        fn ($q) => $q->where('tenants.id', Filament::getTenant()?->id)
                    )->pluck('name', 'id'))
                    ->searchable()
                    ->nullable(),
                Forms\Components\Toggle::make('is_active')
                    ->label('نشطة')
                    ->default(true),
                Forms\Components\Textarea::make('notes')
                    ->label('ملاحظات')
                    ->nullable()
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('plate_number')
                    ->label('رقم اللوحة')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('default_capacity')
                    ->label('السعة الافتراضية')
                    ->sortable(),
                Tables\Columns\TextColumn::make('defaultDriver.name')
                    ->label('السائق الدائم')
                    ->placeholder('—'),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('نشطة')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('نشطة؟'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                // Guard against destroying a Vehicle still referenced by a trip's bus
                // assignment — identical pattern to HotelResource's delete guard.
                Tables\Actions\DeleteAction::make()
                    ->before(function (Tables\Actions\DeleteAction $action, Vehicle $record) {
                        if ($record->tripBusAssignments()->count() > 0) {
                            \Filament\Notifications\Notification::make()
                                ->warning()
                                ->title('لا يمكن حذف الحافلة')
                                ->body('هذه الحافلة مستخدمة في تخصيصات رحلات موجودة. يرجى إلغاء تفعيلها (أرشفتها) بدلاً من حذفها.')
                                ->persistent()
                                ->send();

                            $action->cancel();
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVehicles::route('/'),
            'create' => Pages\CreateVehicle::route('/create'),
            'edit' => Pages\EditVehicle::route('/{record}/edit'),
        ];
    }
}
