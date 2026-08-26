<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * bookings.payment_type was originally added via Schema::table(...)->enum(...) in
     * add_payment_fields_to_bookings_table (an ALTER, not part of the original CREATE TABLE).
     * Laravel's SQLite grammar only embeds an enum's CHECK constraint when the column is part of
     * a fresh Schema::create() -- adding an enum column later via Schema::table() silently
     * produces a plain, unconstrained VARCHAR on SQLite (confirmed: inserted an arbitrary string
     * directly, no error). MySQL enforces it correctly either way (native ENUM type), which is
     * exactly why this was invisible to the SQLite-run test suite while being a real constraint
     * on production. The other two native enum columns in this app (inventory_ledgers.type,
     * room_inventory_ledgers.type) don't have this gap because both were defined inside their
     * own Schema::create().
     *
     * ->change() forces Laravel's SQLite grammar through its table-rebuild path (the only way
     * SQLite can alter a column's constraints), which does properly emit the CHECK constraint --
     * unlike the original ALTER TABLE ADD COLUMN. On MySQL, ->change() is a normal, no-op-shaped
     * ALTER (the column is already exactly this enum) with zero behavioral change to a constraint
     * that already worked correctly there.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->enum('payment_type', ['full', 'deposit'])->default('full')->change();
        });
    }

    public function down(): void
    {
        // No-op: reverting to a less-constrained column is not a meaningful rollback target,
        // and the original migration's own down() already covers dropping the column entirely.
    }
};
