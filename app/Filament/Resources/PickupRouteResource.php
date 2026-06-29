<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PickupRouteResource\Pages;
use App\Filament\Resources\PickupRouteResource\RelationManagers;
use App\Models\PickupRoute;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PickupRouteResource extends Resource
{
    protected static ?string $model = PickupRoute::class;

    protected static ?string $navigationGroup = 'اللوجستيات (Logistics)';

    public static function getNavigationLabel(): string
    {
        return 'مسارات التجمع';
    }

    public static function getModelLabel(): string
    {
        return 'مسار تجمع';
    }

    public static function getPluralModelLabel(): string
    {
        return 'مسارات التجمع';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('اسم المسار')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Toggle::make('is_active')
                    ->label('نشط')
                    ->default(true),
                Forms\Components\Textarea::make('description')
                    ->label('الوصف')
                    ->columnSpanFull(),
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
            'index' => Pages\ListPickupRoutes::route('/'),
            'create' => Pages\CreatePickupRoute::route('/create'),
            'edit' => Pages\EditPickupRoute::route('/{record}/edit'),
        ];
    }
}
