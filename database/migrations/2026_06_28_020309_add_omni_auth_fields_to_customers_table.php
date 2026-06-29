<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('email')->nullable()->after('phone');
            $table->string('provider_id')->nullable()->after('email');
            $table->string('provider_name')->nullable()->after('provider_id'); // 'google', 'apple'
            
            // Critical: A customer email must be unique per tenant
            $table->unique(['tenant_id', 'email']); 

            // Allow phone to be nullable temporarily for Socialite creation
            $table->string('phone')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 
    }
};
