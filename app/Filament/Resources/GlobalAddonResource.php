<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GlobalAddonResource\Pages;
use App\Filament\Resources\GlobalAddonResource\RelationManagers;
use App\Models\GlobalAddon;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class GlobalAddonResource extends Resource
{
    protected static ?string $model = GlobalAddon::class;

    protected static ?string $navigationIcon = 'heroicon-o-puzzle-piece';
    
    protected static ?string $navigationGroup = 'الإعدادات';
    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('agency_admin') ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return 'مكتبة الإضافات';
    }

    public static function getModelLabel(): string
    {
        return 'إضافة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'مكتبة الإضافات';
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
                    ->label('الاسم (مثال: غرفة مفردة)')
                    ->required()
                    ->maxLength(255)
                    // Case-Insensitive Uniqueness Fix: neither this field nor the DB constraint
                    // it backs had ANY form-level uniqueness check before this -- the DB
                    // constraint alone used to be the only thing catching a duplicate, with a
                    // raw QueryException instead of a friendly validation message.
                    ->rules(fn (?GlobalAddon $record) => [
                        new \App\Rules\CaseInsensitiveUnique(
                            table: 'global_addons',
                            tenantId: \Filament\Facades\Filament::getTenant()?->id,
                            ignoreId: $record?->id,
                        ),
                    ]),
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
            'index' => Pages\ListGlobalAddons::route('/'),
        ];
    }
}
