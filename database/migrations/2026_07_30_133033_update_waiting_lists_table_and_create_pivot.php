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
        Schema::table('waiting_lists', function (Blueprint $table) {
            $table->integer('seats_requested')->default(1)->after('customer_email');
            
            // Drop foreign key and column. Depending on the DB and exact name, it's safer to drop foreign then column.
            // Since we know the table name is `waiting_lists` and column is `trip_instance_id`, the FK name is usually `waiting_lists_trip_instance_id_foreign`.
            $table->dropForeign(['trip_instance_id']);
            $table->dropIndex(['trip_instance_id', 'status', 'created_at']);
            $table->dropColumn('trip_instance_id');
            
            // Re-add a new index for faster querying without trip_instance_id
            $table->index(['status', 'created_at']);
        });

        Schema::create('trip_instance_waiting_list', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('waiting_list_id')->constrained('waiting_lists')->cascadeOnDelete();
            $table->timestamps();
            
            $table->unique(['trip_instance_id', 'waiting_list_id'], 'trip_waitlist_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_instance_waiting_list');

        Schema::table('waiting_lists', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
            
            $table->foreignId('trip_instance_id')->nullable()->constrained()->cascadeOnDelete();
            $table->dropColumn('seats_requested');
            
            $table->index(['trip_instance_id', 'status', 'created_at']);
        });
    }
};
