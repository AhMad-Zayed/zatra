<?php

namespace App\Filament\Superadmin\Resources;

use App\Filament\Superadmin\Resources\TenantResource\Pages;
use App\Filament\Superadmin\Resources\TenantResource\RelationManagers;
use App\Models\Tenant;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('slug')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255)
                    // Case-Insensitive Uniqueness Fix: unlike TripTemplate.slug (always
                    // machine-generated via Str::slug(), no manual entry point at all), this
                    // field is manually typed by a super-admin with no auto-slugify -- the
                    // existing ->unique() above inherits the SQLite/MySQL collation split like
                    // everywhere else in this audit. No tenant scoping here: Tenant is the
                    // top-level entity, slugs must be globally unique.
                    ->rules(fn (?\App\Models\Tenant $record) => [
                        new \App\Rules\CaseInsensitiveUnique(
                            table: 'tenants',
                            ignoreId: $record?->id,
                        ),
                    ]),
                Forms\Components\TextInput::make('domain')
                    ->nullable()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                Forms\Components\Toggle::make('is_active')
                    ->default(true),

                Forms\Components\Section::make('تكامل Atlahub (Chatwoot)')
                    ->description('إعدادات الربط مع منصة أطلس هب للواتساب')
                    ->schema([
                        Forms\Components\TextInput::make('settings.atlahub_api_url')
                            ->label('رابط API')
                            ->url()
                            ->default('https://chat.atlahub.com')
                            ->required(),
                        Forms\Components\TextInput::make('settings.atlahub_account_id')
                            ->label('معرف الحساب')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('settings.atlahub_inbox_id')
                            ->label('معرف صندوق الواتساب')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('settings.atlahub_api_token')
                            ->label('رمز API')
                            ->password()
                            ->required(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('slug')
                    ->searchable(),
                Tables\Columns\TextColumn::make('domain')
                    ->searchable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('toggleActive')
                    ->label(fn (Tenant $record): string => $record->is_active ? 'تعليق' : 'تفعيل')
                    ->color(fn (Tenant $record): string => $record->is_active ? 'danger' : 'success')
                    ->icon(fn (Tenant $record): string => $record->is_active ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
                    ->action(function (Tenant $record) {
                        $record->update(['is_active' => !$record->is_active]);
                    })
                    ->requiresConfirmation(),
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
            'index' => Pages\ListTenants::route('/'),
            'create' => Pages\CreateTenant::route('/create'),
            'edit' => Pages\EditTenant::route('/{record}/edit'),
        ];
    }
}
