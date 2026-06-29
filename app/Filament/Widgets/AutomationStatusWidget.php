<?php

namespace App\Filament\Widgets;

use Filament\Widgets\TableWidget as BaseWidget;
use Filament\Tables;
use Filament\Tables\Table;

class AutomationStatusWidget extends BaseWidget
{
    protected static ?string $heading = 'حالة النظام الآلي (Automation Engine)';
    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                \App\Models\AutomationRun::query()->latest('last_run_at')
            )
            ->columns([
                Tables\Columns\TextColumn::make('job_name')
                    ->label('العملية')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_run_at')
                    ->label('آخر تشغيل')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('records_processed')
                    ->label('السجلات المعالجة')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->colors([
                        'success' => 'success',
                        'danger' => 'error',
                    ]),
            ])
            ->defaultSort('last_run_at', 'desc')
            ->paginated([5, 10]);
    }
}
