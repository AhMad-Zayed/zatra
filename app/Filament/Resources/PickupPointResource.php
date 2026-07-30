<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PickupPointResource\Pages;
use App\Filament\Resources\PickupPointResource\RelationManagers;
use App\Models\PickupPoint;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PickupPointResource extends Resource
{
    protected static ?string $model = PickupPoint::class;

    protected static bool $isScopedToTenant = false;

    // LABEL-017: Pure Arabic navigation group (removed 'Logistics' English parenthetical)
    protected static ?string $navigationGroup = 'اللوجستيات';
    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string
    {
        return 'نقاط التجمع';
    }

    public static function getModelLabel(): string
    {
        return 'نقطة تجمع';
    }

    public static function getPluralModelLabel(): string
    {
        return 'نقاط التجمع';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('pickup_route_id')
                    ->label('المسار')
                    ->relationship('pickupRoute', 'name')
                    ->required(),
                Forms\Components\TextInput::make('name')
                    ->label('اسم النقطة (مثال: محطة وقود)')
                    ->required(),
                Forms\Components\TimePicker::make('pickup_time')
                    ->label('وقت التجمع')
                    ->required(),
                Forms\Components\TextInput::make('address')
                    ->label('العنوان التفصيلي أو رابط الخريطة'),
                Forms\Components\TextInput::make('order')
                    ->label('الترتيب في المسار')
                    ->numeric()
                    ->default(0),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // HIGH-002: Table was completely blank — added all relevant columns
                Tables\Columns\TextColumn::make('name')
                    ->label('اسم النقطة')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('pickupRoute.name')
                    ->label('المسار')
                    ->sortable()
                    ->badge()
                    ->color('info'),
                Tables\Columns\TextColumn::make('address')
                    ->label('العنوان')
                    ->limit(40)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('pickup_time')
                    ->label('وقت التجمع')
                    ->time('h:i A')
                    ->sortable(),
                Tables\Columns\TextColumn::make('order')
                    ->label('الترتيب')
                    ->sortable(),
            ])
            ->defaultSort('order', 'asc')
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('تعديل'),
                Tables\Actions\DeleteAction::make()->label('حذف'),
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('pickupRoute', function (Builder $query) {
            $query->where('tenant_id', \Filament\Facades\Filament::getTenant()?->id);
        });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPickupPoints::route('/'),
            'create' => Pages\CreatePickupPoint::route('/create'),
            'edit' => Pages\EditPickupPoint::route('/{record}/edit'),
        ];
    }
}
