<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('global_addon_id')->nullable()->constrained('global_addons')->nullOnDelete();
            $table->string('name');
            $table->decimal('price', 10, 2);
            $table->integer('max_quantity')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_addons');
    }
};
