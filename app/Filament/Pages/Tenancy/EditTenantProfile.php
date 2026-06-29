<?php

namespace App\Filament\Pages\Tenancy;

use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Pages\Tenancy\EditTenantProfile as BaseEditTenantProfile;

class EditTenantProfile extends BaseEditTenantProfile
{
    public static function getLabel(): string
    {
        return 'إعدادات الشركة';
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('البيانات الأساسية')
                    ->schema([
                        TextInput::make('name')
                            ->label('اسم الشركة')
                            ->required(),
                        TextInput::make('domain')
                            ->label('النطاق (Domain)')
                            ->url(),
                    ]),
                
                Section::make('إعدادات الإشعارات والتنبيهات')
                    ->description('تحكم في قنوات الإشعارات الفعالة لشركتك')
                    ->schema([
                        Toggle::make('enable_email_alerts')
                            ->label('إشعارات البريد الإلكتروني')
                            ->helperText('إرسال تذكرة الحجز وتأكيد الدفع عبر البريد الإلكتروني')
                            ->default(true),
                        
                        Toggle::make('enable_whatsapp_alerts')
                            ->label('إشعارات الواتساب')
                            ->helperText('إرسال رسائل آلية سريعة للعميل عبر الواتساب')
                            ->default(true),
                        
                        Toggle::make('enable_sms_alerts')
                            ->label('الرسائل النصية SMS')
                            ->helperText('إرسال الرمز التعريفي ورابط التذكرة عبر رسالة نصية قصيرة')
                            ->default(true),
                    ])->columns(1),
            ]);
    }
}
