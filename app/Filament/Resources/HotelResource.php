<?php

namespace App\Filament\Resources;

use App\Filament\Resources\HotelResource\Pages;
use App\Models\Hotel;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tenant-wide (not trip-scoped) — hotels are reused across many trips over time. Hotel/Rooming
 * redesign Phase 1. Deliberately independent of PackageOption/PackageOptionsRelationManager,
 * which remain fully untouched and live.
 */
class HotelResource extends Resource
{
    protected static ?string $model = Hotel::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';
    protected static ?string $navigationGroup = 'اللوجستيات';

    public static function getNavigationLabel(): string
    {
        return 'الفنادق';
    }

    public static function getModelLabel(): string
    {
        return 'فندق';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الفنادق';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', \Filament\Facades\Filament::getTenant()->id);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('اسم الفندق')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('city')
                    ->label('المدينة')
                    ->nullable()
                    ->maxLength(255),
                Forms\Components\Select::make('star_rating')
                    ->label('عدد النجوم')
                    ->options([1 => '★', 2 => '★★', 3 => '★★★', 4 => '★★★★', 5 => '★★★★★'])
                    ->nullable(),
                Forms\Components\Toggle::make('is_active')
                    ->label('نشط')
                    ->default(true),
                Forms\Components\TextInput::make('contact_phone')
                    ->label('هاتف التواصل')
                    ->tel()
                    ->nullable()
                    ->maxLength(255),
                Forms\Components\TextInput::make('contact_email')
                    ->label('بريد التواصل')
                    ->email()
                    ->nullable()
                    ->maxLength(255),
                Forms\Components\TextInput::make('address')
                    ->label('العنوان')
                    ->nullable()
                    ->maxLength(255)
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('اسم الفندق')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('city')
                    ->label('المدينة')
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('star_rating')
                    ->label('النجوم')
                    ->formatStateUsing(fn ($state) => $state ? str_repeat('★', $state) : '—'),
                Tables\Columns\TextColumn::make('contact_phone')
                    ->label('هاتف التواصل')
                    ->placeholder('—'),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('نشط؟'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                // Guard against destroying a Hotel still referenced by any trip's
                // accommodation — mirrors TripTemplateResource's identical
                // "can't delete, has dependents" pattern. The DB-level restrictOnDelete() FK
                // on trip_stay_leg_hotel_options.hotel_id is the hard backstop; this is the
                // friendly, explanatory front line.
                Tables\Actions\DeleteAction::make()
                    ->before(function (Tables\Actions\DeleteAction $action, Hotel $record) {
                        if ($record->tripStayLegHotelOptions()->count() > 0) {
                            \Filament\Notifications\Notification::make()
                                ->warning()
                                ->title('لا يمكن حذف الفندق')
                                ->body('هذا الفندق مستخدم في خيارات إقامة لرحلات موجودة. يرجى إلغاء تفعيله (أرشفته) بدلاً من حذفه.')
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
            'index' => Pages\ListHotels::route('/'),
            'create' => Pages\CreateHotel::route('/create'),
            'edit' => Pages\EditHotel::route('/{record}/edit'),
        ];
    }
}
