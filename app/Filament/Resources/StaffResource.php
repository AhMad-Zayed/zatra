<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StaffResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class StaffResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationGroup = 'الإدارة';
    protected static ?string $navigationLabel = 'طاقم العمل';
    protected static ?string $modelLabel = 'موظف';
    protected static ?string $pluralModelLabel = 'طاقم العمل';
    protected static ?string $tenantOwnershipRelationshipName = 'tenants';

    public static function canAccess(): bool
    {
        // Restrict access ONLY to the Agency Admin
        return auth()->user()?->hasRole('agency_admin') ?? false;
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $tenantId = Filament::getTenant()?->id;

        // Strictly scope queries so the Admin only sees users belonging to their current $tenant
        return parent::getEloquentQuery()
            ->whereHas('tenants', function ($query) use ($tenantId) {
                $query->where('tenants.id', $tenantId);
            });
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('بيانات الموظف')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('الاسم الكامل')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('email')
                            ->label('البريد الإلكتروني')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),

                        Forms\Components\TextInput::make('phone')
                            ->label('رقم الهاتف')
                            ->tel()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),

                        Forms\Components\TextInput::make('password')
                            ->label('كلمة المرور')
                            ->password()
                            ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                            ->dehydrated(fn ($state) => filled($state))
                            ->required(fn (string $context): bool => $context === 'create')
                            ->maxLength(255),

                        Forms\Components\CheckboxList::make('roles')
                            ->label('الصلاحيات (Roles)')
                            ->relationship('roles', 'name')
                            ->pivotData(fn () => ['tenant_id' => \Filament\Facades\Filament::getTenant()?->id])
                            ->options(function () {
                                return Role::whereIn('name', ['agency_admin', 'accountant', 'booking_agent'])->pluck('name', 'id');
                            })
                            ->required(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('الاسم')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->label('البريد الإلكتروني')
                    ->searchable(),
                Tables\Columns\TextColumn::make('roles.name')
                    ->label('الصلاحيات')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'agency_admin' => 'danger',
                        'accountant' => 'warning',
                        'booking_agent' => 'success',
                        default => 'gray',
                    }),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->hidden(fn (User $record) => auth()->id() === $record->id), // Prevent deleting self
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
            'index' => Pages\ListStaff::route('/'),
            'create' => Pages\CreateStaff::route('/create'),
            'edit' => Pages\EditStaff::route('/{record}/edit'),
        ];
    }
}
