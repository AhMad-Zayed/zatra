<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RequirementPresetResource\Pages;
use App\Filament\Resources\RequirementPresetResource\RelationManagers;
use App\Models\RequirementPreset;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class RequirementPresetResource extends Resource
{
    protected static ?string $model = RequirementPreset::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-check';
    protected static ?string $navigationGroup = 'الإدارة والإعدادات';
    protected static ?string $navigationLabel = 'قوالب المتطلبات';
    protected static ?string $modelLabel = 'قالب متطلبات';
    protected static ?string $pluralModelLabel = 'قوالب المتطلبات';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', \Filament\Facades\Filament::getTenant()->id);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('title')
                    ->label('اسم القالب (مثال: متطلبات تأشيرة شنجن)')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                    
                Forms\Components\Repeater::make('items')
                    ->label('الحقول المطلوبة')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('اسم الحقل (مثال: صورة الجواز)')
                            ->required(),
                        Forms\Components\Select::make('type')
                            ->label('نوع الحقل')
                            ->options([
                                'text' => 'نص قصير',
                                'image' => 'صورة / ملف',
                            ])
                            ->required()
                            ->default('image'),
                        Forms\Components\Toggle::make('is_required')
                            ->label('حقل إلزامي')
                            ->default(true),
                    ])
                    ->columns(3)
                    ->columnSpanFull()
                    ->defaultItems(1)
                    ->addActionLabel('إضافة حقل جديد'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('اسم القالب')
                    ->searchable(),
                Tables\Columns\TextColumn::make('items')
                    ->label('عدد الحقول')
                    ->formatStateUsing(fn ($state) => is_array($state) ? count($state) : 0)
                    ->badge(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
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
            'index' => Pages\ListRequirementPresets::route('/'),
            'create' => Pages\CreateRequirementPreset::route('/create'),
            'view' => Pages\ViewRequirementPreset::route('/{record}'),
            'edit' => Pages\EditRequirementPreset::route('/{record}/edit'),
        ];
    }
}
