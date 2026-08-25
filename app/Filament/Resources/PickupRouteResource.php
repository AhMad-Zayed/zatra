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

    // LABEL-018: Pure Arabic navigation group (removed 'Logistics' English parenthetical)
    protected static ?string $navigationGroup = 'الرحلات والفنادق';
    protected static ?int $navigationSort = 1;

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
                // HIGH-003: Table was completely blank — added all relevant columns
                Tables\Columns\TextColumn::make('name')
                    ->label('اسم المسار')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('description')
                    ->label('الوصف')
                    ->limit(50)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('pickup_points_count')
                    ->label('عدد النقاط')
                    ->counts('pickupPoints')
                    ->badge()
                    ->color('primary'),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('حالة المسار'),
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPickupRoutes::route('/'),
            'create' => Pages\CreatePickupRoute::route('/create'),
            'edit' => Pages\EditPickupRoute::route('/{record}/edit'),
        ];
    }
}
