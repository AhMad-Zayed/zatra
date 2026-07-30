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
        Schema::table('trip_templates', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('title');
        });
        
        // Generate slugs for existing templates
        \Illuminate\Support\Facades\DB::table('trip_templates')->get()->each(function ($template) {
            \Illuminate\Support\Facades\DB::table('trip_templates')
                ->where('id', $template->id)
                ->update(['slug' => \Illuminate\Support\Str::slug($template->title . '-' . $template->id)]);
        });
        
        Schema::table('trip_templates', function (Blueprint $table) {
            $table->string('slug')->nullable(false)->unique()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trip_templates', function (Blueprint $table) {
            $table->dropColumn('slug');
        });
    }
};
