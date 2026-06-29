<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $financialColumns = [
        'trip_templates' => ['base_price'],
        'trip_addons' => ['price'],
        'trip_passenger_categories' => ['price'],
        'passenger_categories' => ['default_price'],
        'global_addons' => ['default_price'],
        'template_passenger_categories' => ['price'],
        'template_addons' => ['price'],
        'bookings' => ['grand_total', 'total_paid', 'balance_due'],
        'passengers' => ['price_at_booking'],
        'booking_addons' => ['price_at_booking'],
        'payments' => ['amount'],
    ];

    public function up(): void
    {
        foreach ($this->financialColumns as $table => $columns) {
            foreach ($columns as $column) {
                DB::table($table)->update([
                    $column => DB::raw("$column * 100")
                ]);
            }

            Schema::table($table, function (Blueprint $tableSchema) use ($columns) {
                foreach ($columns as $column) {
                    // Requires doctrine/dbal if using older Laravel, but L11 supports it natively
                    $tableSchema->integer($column)->default(0)->change();
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->financialColumns as $table => $columns) {
            Schema::table($table, function (Blueprint $tableSchema) use ($columns) {
                foreach ($columns as $column) {
                    $tableSchema->decimal($column, 10, 2)->default(0)->change();
                }
            });

            foreach ($columns as $column) {
                DB::table($table)->update([
                    $column => DB::raw("$column / 100")
                ]);
            }
        }
    }
};
