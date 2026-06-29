<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Forms\Form;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Grid;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Log;

class ManageAgencySettings extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationGroup = 'الإعدادات';
    protected static ?string $navigationLabel = 'إعدادات الوكالة';
    protected static ?string $title = 'إعدادات الوكالة';
    protected static string $view = 'filament.pages.manage-agency-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        // Only allow users with the 'agency_admin' role to access this settings page
        return auth()->user()?->hasRole('agency_admin') ?? false;
    }

    public function mount(): void
    {
        $tenant = Filament::getTenant();

        // Separate and Merge State:
        // Load direct columns and JSON settings into a flat data array for the form
        $this->form->fill([
            // Heavy data mapped to Model attributes
            'tourism_license_number' => $tenant->tourism_license_number,
            'terms_conditions' => $tenant->terms_conditions,
            'privacy_policy' => $tenant->privacy_policy,
            'refund_policy' => $tenant->refund_policy,

            // Light data mapped from JSON `settings` column
            'contact_phone' => $tenant->settings['contact_phone'] ?? '',
            'contact_email' => $tenant->settings['contact_email'] ?? '',
            'office_address' => $tenant->settings['office_address'] ?? '',
            'working_hours' => $tenant->settings['working_hours'] ?? '',
            'whatsapp_number' => $tenant->settings['whatsapp_number'] ?? '',
            'facebook_url' => $tenant->settings['facebook_url'] ?? '',
            'instagram_url' => $tenant->settings['instagram_url'] ?? '',
            'tiktok_url' => $tenant->settings['tiktok_url'] ?? '',
            'faqs' => $tenant->settings['faqs'] ?? [],
            'waiting_list_channel' => $tenant->settings['waiting_list_channel'] ?? 'both',
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('معلومات الاتصال وحسابات التواصل')
                    ->description('يتم حفظ هذه البيانات بتنسيق JSON (Light Data).')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('contact_phone')
                                ->label('رقم الهاتف الأساسي'),
                            TextInput::make('contact_email')
                                ->label('البريد الإلكتروني الأساسي')
                                ->email(),
                            TextInput::make('whatsapp_number')
                                ->label('رقم الواتساب')
                                ->helperText('مهم لرسائل التنبيهات وللتواصل المباشر.'),
                            Textarea::make('working_hours')
                                ->label('ساعات العمل')
                                ->rows(2),
                            Textarea::make('office_address')
                                ->label('عنوان الفرع الرئيسي')
                                ->columnSpanFull(),
                        ]),
                        Grid::make(3)->schema([
                            TextInput::make('facebook_url')
                                ->label('رابط فيسبوك')
                                ->url(),
                            TextInput::make('instagram_url')
                                ->label('رابط إنستجرام')
                                ->url(),
                            TextInput::make('tiktok_url')
                                ->label('رابط تيك توك')
                                ->url(),
                        ]),
                        Select::make('waiting_list_channel')
                            ->label('قناة إشعارات قائمة الانتظار')
                            ->options([
                                'whatsapp' => 'WhatsApp فقط (مكلف)',
                                'email' => 'البريد الإلكتروني فقط (مجاني)',
                                'both' => 'WhatsApp + Email (موصى به)',
                            ])
                            ->default('both')
                            ->required()
                            ->helperText('حدد كيف سيتم إبلاغ الزبائن عند توفر مقعد شاغر من قائمة الانتظار.')
                    ]),

                Section::make('الأسئلة الشائعة (FAQs)')
                    ->description('تُعرض في ذيل صفحة المتجر.')
                    ->schema([
                        Repeater::make('faqs')
                            ->label('قائمة الأسئلة')
                            ->schema([
                                TextInput::make('question')
                                    ->label('السؤال')
                                    ->required(),
                                Textarea::make('answer')
                                    ->label('الإجابة')
                                    ->required()
                                    ->rows(3),
                            ])
                            ->defaultItems(0)
                            ->reorderableWithButtons()
                    ]),

                Section::make('الشروط والسياسات القانونية')
                    ->description('بيانات نصية ضخمة تُحفظ مباشرة في أعمدة قاعدة البيانات (Heavy Data).')
                    ->schema([
                        TextInput::make('tourism_license_number')
                            ->label('رقم الترخيص السياحي')
                            ->helperText('يُعرض في تذييل الموقع للثقة.')
                            ->maxLength(255),
                        RichEditor::make('terms_conditions')
                            ->label('الشروط والأحكام')
                            ->toolbarButtons([
                                'bold', 'italic', 'underline', 'strike',
                                'h2', 'h3', 'bulletList', 'orderedList', 'link',
                            ]),
                        RichEditor::make('privacy_policy')
                            ->label('سياسة الخصوصية'),
                        RichEditor::make('refund_policy')
                            ->label('سياسة الاسترجاع والإلغاء'),
                    ]),
            ])
            ->statePath('data');
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('حفظ الإعدادات')
                ->submit('save')
                ->color('primary'),
        ];
    }

    public function save(): void
    {
        try {
            $data = $this->form->getState();
            $tenant = Filament::getTenant();

            // Separate the Light Data (JSON) from Heavy Data (Columns)
            $heavyData = [
                'tourism_license_number' => $data['tourism_license_number'],
                'terms_conditions' => $data['terms_conditions'],
                'privacy_policy' => $data['privacy_policy'],
                'refund_policy' => $data['refund_policy'],
            ];

            $lightDataKeys = [
                'contact_phone', 'contact_email', 'office_address', 'working_hours',
                'whatsapp_number', 'facebook_url', 'instagram_url', 'tiktok_url', 'faqs',
                'waiting_list_channel'
            ];

            // Merge Light Data into the existing JSON settings
            $currentSettings = $tenant->settings ?? [];
            foreach ($lightDataKeys as $key) {
                if (array_key_exists($key, $data)) {
                    $currentSettings[$key] = $data[$key];
                }
            }

            // Update the Tenant model
            $tenant->update(array_merge($heavyData, [
                'settings' => $currentSettings,
            ]));

            Notification::make()
                ->title('تم حفظ الإعدادات بنجاح')
                ->success()
                ->send();

        } catch (\Exception $e) {
            Log::error('Settings Save Failed: ' . $e->getMessage());
            Notification::make()
                ->title('حدث خطأ أثناء الحفظ')
                ->danger()
                ->send();
        }
    }
}
