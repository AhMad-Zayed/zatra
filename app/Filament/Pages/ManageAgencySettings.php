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
use Filament\Forms\Components\Select;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;

class ManageAgencySettings extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationGroup = 'الإعدادات';
    protected static ?int $navigationSort = 0;
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
            'agency_tagline' => $tenant->settings['agency_tagline'] ?? '',
            'meta_description' => $tenant->settings['meta_description'] ?? '',
            'hero_headline' => $tenant->settings['hero_headline'] ?? '',
            'hero_subheading' => $tenant->settings['hero_subheading'] ?? '',
            'trips_section_eyebrow' => $tenant->settings['trips_section_eyebrow'] ?? '',
            'trips_section_title' => $tenant->settings['trips_section_title'] ?? '',
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('العلامة التجارية والمحتوى التسويقي')
                    ->description('يظهر هذا المحتوى في الصفحة الرئيسية لمتجر الوكالة ورسائل تأكيد الحجز.')
                    ->schema([
                        Placeholder::make('logo_preview')
                            ->label('الشعار الحالي')
                            ->content(fn () => Filament::getTenant()->hasMedia('logo')
                                ? new HtmlString('<img src="'.e(Filament::getTenant()->getFirstMediaUrl('logo')).'" style="height:48px" alt="Logo">')
                                : 'لم يتم رفع شعار بعد.'),
                        FileUpload::make('logo')
                            ->label('رفع شعار جديد')
                            ->image()
                            ->imageEditor()
                            ->disk('public')
                            ->directory('agency-uploads/logo')
                            ->helperText('يظهر في رأس صفحات المتجر. ارفع صورة جديدة لاستبدال الشعار الحالي.'),

                        Placeholder::make('hero_image_preview')
                            ->label('صورة الغلاف الحالية للصفحة الرئيسية')
                            ->content(fn () => Filament::getTenant()->hasMedia('hero_image')
                                ? new HtmlString('<img src="'.e(Filament::getTenant()->getFirstMediaUrl('hero_image')).'" style="height:120px;border-radius:12px" alt="Hero">')
                                : 'لم يتم رفع صورة غلاف بعد — سيتم عرض خلفية افتراضية.'),
                        FileUpload::make('hero_image')
                            ->label('رفع صورة غلاف جديدة')
                            ->image()
                            ->imageEditor()
                            ->disk('public')
                            ->directory('agency-uploads/hero')
                            ->helperText('صورة الخلفية الكبيرة أعلى الصفحة الرئيسية للمتجر.'),

                        TextInput::make('agency_tagline')
                            ->label('الشعار التعريفي (Tagline)')
                            ->helperText('جملة قصيرة تعرّف بالوكالة — تظهر في تذييل المتجر ورسائل تأكيد الحجز.')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Textarea::make('meta_description')
                            ->label('وصف SEO (Meta Description)')
                            ->helperText('يظهر في نتائج محركات البحث ومعاينات مشاركة الروابط.')
                            ->rows(2)
                            ->maxLength(500)
                            ->columnSpanFull(),
                        Grid::make(2)->schema([
                            TextInput::make('hero_headline')
                                ->label('عنوان الصفحة الرئيسية')
                                ->maxLength(255),
                            TextInput::make('hero_subheading')
                                ->label('العنوان الفرعي للصفحة الرئيسية')
                                ->maxLength(500),
                            TextInput::make('trips_section_eyebrow')
                                ->label('التسمية الصغيرة فوق قسم الرحلات')
                                ->maxLength(100),
                            TextInput::make('trips_section_title')
                                ->label('عنوان قسم الرحلات'),
                        ]),
                    ]),

                Section::make('معلومات الاتصال وحسابات التواصل')
                    ->description('يتم حفظ هذه البيانات بتنسيق JSON (بيانات خفيفة).')
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

                Section::make('الأسئلة الشائعة')
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
                    ->description('بيانات نصية ضخمة تُحفظ مباشرة في أعمدة قاعدة البيانات (بيانات ثقيلة).')
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
                'waiting_list_channel', 'agency_tagline', 'meta_description',
                'hero_headline', 'hero_subheading', 'trips_section_eyebrow', 'trips_section_title',
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

            // Logo/hero image: FileUpload (plain, not Spatie-media-bound -- this custom Page has
            // no Filament\Resources record-binding lifecycle for SpatieMediaLibraryFileUpload's
            // loadStateFromRelationshipsUsing/saveRelationshipsUsing to hook into) leaves the
            // uploaded file sitting on the 'public' disk as a plain path string; only actually
            // attach it to the tenant's media collection (replacing any existing singleFile media)
            // when a new file was uploaded this submission -- an empty field must never wipe out
            // an already-uploaded logo/hero image.
            if (!empty($data['logo'])) {
                $tenant->addMediaFromDisk($data['logo'], 'public')->toMediaCollection('logo');
            }
            if (!empty($data['hero_image'])) {
                $tenant->addMediaFromDisk($data['hero_image'], 'public')->toMediaCollection('hero_image');
            }

            // Clear the upload fields so the form doesn't show a stale "selected file" after a
            // successful save -- the Placeholder previews above re-fetch the tenant's fresh media
            // URL on every render, so the current image is still visible either way.
            $this->data['logo'] = null;
            $this->data['hero_image'] = null;

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
