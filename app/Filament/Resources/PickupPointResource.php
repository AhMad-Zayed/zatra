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

    protected static ?string $navigationGroup = 'اللوجستيات (Logistics)';

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
                //
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
            'index' => Pages\ListPickupPoints::route('/'),
            'create' => Pages\CreatePickupPoint::route('/create'),
            'edit' => Pages\EditPickupPoint::route('/{record}/edit'),
        ];
    }
}
