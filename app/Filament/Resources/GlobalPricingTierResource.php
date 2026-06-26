<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GlobalPricingTierResource\Pages;
use App\Filament\Resources\GlobalPricingTierResource\RelationManagers;
use App\Models\GlobalPricingTier;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class GlobalPricingTierResource extends Resource
{
    protected static ?string $model = GlobalPricingTier::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';
    
    protected static ?string $navigationGroup = 'القواميس والإعدادات';

    public static function getNavigationLabel(): string
    {
        return 'مكتبة فئات التسعير';
    }

    public static function getModelLabel(): string
    {
        return 'فئة تسعير';
    }

    public static function getPluralModelLabel(): string
    {
        return 'مكتبة فئات التسعير';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('الاسم (مثال: بالغ، طفل)')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('default_price')
                    ->label('السعر الافتراضي')
                    ->numeric()
                    ->required()
                    ->prefix('$'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('الاسم')
                    ->searchable(),
                Tables\Columns\TextColumn::make('default_price')
                    ->label('السعر الافتراضي')
                    ->money('USD')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
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
            'index' => Pages\ListGlobalPricingTiers::route('/'),
        ];
    }
}
