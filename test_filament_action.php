<?php
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use App\Models\Tenant;

$tenant = Tenant::first();
if ($tenant) {
    echo "Tenant settings: " . json_encode($tenant->settings) . "\n";
}
