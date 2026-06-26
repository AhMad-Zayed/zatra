<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ActivityLogResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Spatie\Activitylog\Models\Activity;

class ActivityLogResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';
    
    protected static ?string $navigationLabel = 'سجلات التدقيق';
    
    protected static ?string $pluralLabel = 'سجلات التدقيق';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('log_name')
                    ->label('نوع السجل / النظام')
                    ->disabled(),
                Forms\Components\TextInput::make('description')
                    ->label('العملية المنجزة')
                    ->disabled(),
                Forms\Components\TextInput::make('causer.name')
                    ->label('الموظف المسؤول عن التعديل')
                    ->disabled(),
                Forms\Components\TextInput::make('subject_type')
                    ->label('نوع الكائن المعدل')
                    ->disabled(),
                Forms\Components\TextInput::make('subject_id')
                    ->label('رقم المعرف الفرعي')
                    ->disabled(),
                Forms\Components\KeyValue::make('properties')
                    ->label('تفاصيل التغييرات ومقارنة القيم')
                    ->disabled()
                    ->columnSpanFull(),
                Forms\Components\DateTimePicker::make('created_at')
                    ->label('توقيت العملية بدقة')
                    ->disabled(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('log_name')
                    ->label('نوع السجل')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('description')
                    ->label('العملية')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('causer.name')
                    ->label('الموظف')
                    ->placeholder('النظام التلقائي')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('subject_type')
                    ->label('السجل المعدل')
                    ->state(fn ($record) => $record->subject_type ? match($record->subject_type) {
                        \App\Models\Booking::class => 'حجز رقم ' . ($record->subject?->reference ?? "#{$record->subject_id}"),
                        \App\Models\Payment::class => 'دفعة مالية بقيمة ' . ($record->subject?->amount ?? '') . "$ (#{$record->subject_id})",
                        \App\Models\TripInstance::class => 'رحلة مجدولة #' . $record->subject_id,
                        \App\Models\TripTemplate::class => 'قالب رحلة #' . $record->subject_id,
                        \App\Models\Passenger::class => 'مسافر #' . $record->subject_id,
                        default => class_basename($record->subject_type) . " (#{$record->subject_id})",
                    } : '-')
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('توقيت وتاريخ العملية')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('log_name')
                    ->label('تصفية بنوع السجل')
                    ->options([
                        'default' => 'سجلات عامة',
                        'financial' => 'سجلات مالية',
                        'booking' => 'سجلات الحجوزات',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('عرض التفاصيل'),
            ])
            ->bulkActions([]);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $tenant = \Filament\Facades\Filament::getTenant();

        if (!$tenant) {
            return parent::getEloquentQuery();
        }

        return parent::getEloquentQuery()->where(function ($query) use ($tenant) {
            // Filter by subject matching our business tables with correct tenant_id
            $query->where(function ($q) use ($tenant) {
                $q->where(function ($sq) use ($tenant) {
                    $sq->where('subject_type', \App\Models\Booking::class)
                       ->whereRaw("exists (select 1 from bookings where bookings.id = activity_log.subject_id and bookings.tenant_id = ?)", [$tenant->id]);
                })
                ->orWhere(function ($sq) use ($tenant) {
                    $sq->where('subject_type', \App\Models\TripInstance::class)
                       ->whereRaw("exists (select 1 from trip_instances where trip_instances.id = activity_log.subject_id and trip_instances.tenant_id = ?)", [$tenant->id]);
                })
                ->orWhere(function ($sq) use ($tenant) {
                    $sq->where('subject_type', \App\Models\TripTemplate::class)
                       ->whereRaw("exists (select 1 from trip_templates where trip_templates.id = activity_log.subject_id and trip_templates.tenant_id = ?)", [$tenant->id]);
                })
                ->orWhere(function ($sq) use ($tenant) {
                    $sq->where('subject_type', \App\Models\Payment::class)
                       ->whereRaw("exists (select 1 from payments where payments.id = activity_log.subject_id and payments.tenant_id = ?)", [$tenant->id]);
                })
                ->orWhere(function ($sq) use ($tenant) {
                    $sq->where('subject_type', \App\Models\Passenger::class)
                       ->whereRaw("exists (select 1 from passengers where passengers.id = activity_log.subject_id and passengers.tenant_id = ?)", [$tenant->id]);
                });
            })
            // Or by causer who belongs to this tenant
            ->orWhere(function ($q) use ($tenant) {
                $q->where('causer_type', \App\Models\User::class)
                  ->whereRaw("exists (select 1 from tenant_user where tenant_user.user_id = activity_log.causer_id and tenant_user.tenant_id = ?)", [$tenant->id]);
            });
        });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivityLogs::route('/'),
            'view' => Pages\ViewActivityLog::route('/{record}'),
        ];
    }

    // Disable creation and editing
    public static function canCreate(): bool
    {
        return false;
    }
}
