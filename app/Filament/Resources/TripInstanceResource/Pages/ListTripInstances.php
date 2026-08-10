<?php

namespace App\Filament\Resources\TripInstanceResource\Pages;

use App\Filament\Resources\TripInstanceResource;
use App\Models\TripTemplate;
use App\Models\TripInstance;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Facades\Filament;

class ListTripInstances extends ListRecords
{
    protected static string $resource = TripInstanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // ⚡ Quick Trip from Template — create a trip instance in seconds without leaving the page
            Action::make('quick_trip')
                ->label('⚡ رحلة سريعة من قالب')
                ->color('warning')
                ->icon('heroicon-o-bolt')
                ->modalHeading('إنشاء رحلة جديدة بسرعة')
                ->modalDescription('اختر القالب وحدد التواريخ — سيتم إنشاء الرحلة فوراً مع نسخ فئات التسعير والإضافات.')
                ->modalWidth('lg')
                ->form([
                    Forms\Components\Select::make('trip_template_id')
                        ->label('قالب الرحلة')
                        ->options(function () {
                            $tenantId = Filament::getTenant()?->id;
                            return TripTemplate::where('tenant_id', $tenantId)
                                ->where('is_active', true)
                                ->orderBy('title')
                                ->pluck('title', 'id');
                        })
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live()
                        ->helperText('اختر من القوالب النشطة — ستنسخ أسعار الفئات تلقائياً'),

                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\DatePicker::make('start_date')
                            ->label('تاريخ الذهاب')
                            ->required()
                            ->native(false)
                            ->minDate(now()),

                        Forms\Components\DatePicker::make('end_date')
                            ->label('تاريخ الإياب')
                            ->required()
                            ->native(false)
                            ->minDate(now()),
                    ]),

                    Forms\Components\TextInput::make('available_seats')
                        ->label('عدد المقاعد المتاحة')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->default(20)
                        ->suffix('مقعد'),
                ])
                ->action(function (array $data): void {
                    $tenantId = Filament::getTenant()?->id;
                    $template = TripTemplate::with(['templatePassengerCategories', 'templateAddons'])
                        ->findOrFail($data['trip_template_id']);

                    $instance = TripInstance::create([
                        'tenant_id'          => $tenantId,
                        'trip_template_id'   => $template->id,
                        'start_date'         => $data['start_date'],
                        'end_date'           => $data['end_date'],
                        'available_seats'    => $data['available_seats'],
                        'status'             => 'active',
                    ]);

                    // Copy passenger categories from template
                    foreach ($template->templatePassengerCategories as $category) {
                        $instance->tripPassengerCategories()->create([
                            'tenant_id'     => $tenantId,
                            'name'          => $category->name,
                            'price'         => $category->price,
                            'requires_seat' => $category->requires_seat,
                        ]);
                    }

                    // Copy addons from template
                    foreach ($template->templateAddons as $addon) {
                        $instance->tripAddons()->create([
                            'tenant_id'    => $tenantId,
                            'name'         => $addon->name,
                            'price'        => $addon->price,
                            'max_quantity' => $addon->max_quantity,
                        ]);
                    }

                    Notification::make()
                        ->title('✅ تم إنشاء الرحلة بنجاح')
                        ->body("رحلة \"{$template->title}\" بتاريخ {$data['start_date']} — جاهزة للحجوزات.")
                        ->success()
                        ->send();
                })
                ->successRedirectUrl(fn () => TripInstanceResource::getUrl('index')),

            Actions\CreateAction::make()
                ->label('رحلة جديدة (تفصيلية)')
                ->color('gray'),
        ];
    }
}
