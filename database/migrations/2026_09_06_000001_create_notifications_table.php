<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's standard notifications table (the same schema `php artisan notifications:table`
 * publishes) was never actually created in this project, even though App\Models\User already
 * uses the Notifiable trait and at least two call sites (BulkGenerateTripInstances,
 * Livewire\Storefront\MyBookings) already call ->sendToDatabase() / rely on database
 * notifications -- both would fail with "no such table: notifications" the moment either path
 * actually ran against a real database. Discovered while porting TripBuilderResource's
 * recurring-schedule feature into TripInstanceResource (item 4, admin panel UX audit
 * follow-up): BulkGenerateTripInstances::handle() sends a "N مواعيد تم إنشاؤها" success
 * notification on every real run, so the ported create flow can't be live-verified without this
 * table existing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
