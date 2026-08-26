<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Case-Insensitive Uniqueness Fix: backfills existing customers.email / users.email rows to
     * lowercase, matching the normalization Customer::booted()/User::booted() now apply on every
     * future write. Defensive by design -- the dev database being clean (checked directly, zero
     * case-duplicate emails on either table) does NOT guarantee production is, so this does not
     * assume it's safe to blindly lowercase everything.
     *
     * Detects collisions (two rows that would become identical once lowercased) FIRST, before
     * touching anything. Any row involved in a collision is left completely untouched and logged
     * in full detail (table, tenant, every colliding row's id and exact original casing) rather
     * than silently merged, deleted, or resolved by picking a "winner" -- which record is
     * correct, and whether their booking history should be merged, is a business decision that
     * needs human review, not something a migration should decide unilaterally.
     *
     * Chose "skip the colliding rows, normalize everything else" over "abort the whole
     * migration": migrations typically run unattended as part of a deploy, and aborting entirely
     * would block that whole deploy on what should be a rare edge case pending human review,
     * rather than just leaving that one small group temporarily un-normalized (harmless -- the
     * existing unique constraint isn't being newly added here, only backfilled casing).
     */
    public function up(): void
    {
        $this->backfillLowercaseEmails('customers', tenantScoped: true);
        $this->backfillLowercaseEmails('users', tenantScoped: false);
    }

    public function down(): void
    {
        // No-op: reverting to un-normalized casing is not a meaningful rollback target.
    }

    private function backfillLowercaseEmails(string $table, bool $tenantScoped): void
    {
        $groupColumns = $tenantScoped ? ['tenant_id'] : [];

        $collisionGroups = DB::table($table)
            ->select(array_merge($groupColumns, [
                DB::raw('LOWER(email) as lower_email'),
                DB::raw('COUNT(*) as cnt'),
            ]))
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->groupBy(array_merge($groupColumns, ['lower_email']))
            ->having('cnt', '>', 1)
            ->get();

        $skipIds = [];

        foreach ($collisionGroups as $group) {
            $rowsQuery = DB::table($table)->whereRaw('LOWER(email) = ?', [$group->lower_email]);
            if ($tenantScoped) {
                $rowsQuery->where('tenant_id', $group->tenant_id);
            }
            $rows = $rowsQuery->get(array_values(array_filter(['id', 'email', $tenantScoped ? 'tenant_id' : null])));

            foreach ($rows as $row) {
                $skipIds[] = $row->id;
            }

            Log::warning(
                "Case-Insensitive Uniqueness Fix backfill: skipped a colliding email group on `{$table}` -- left untouched, needs human review (which record is correct / whether to merge) before normalizing.",
                [
                    'table' => $table,
                    'tenant_id' => $tenantScoped ? $group->tenant_id : null,
                    'colliding_rows' => $rows->map(fn ($r) => (array) $r)->all(),
                ]
            );
        }

        $update = DB::table($table)->whereNotNull('email')->where('email', '!=', '');
        if (!empty($skipIds)) {
            $update->whereNotIn('id', $skipIds);
        }
        $update->update(['email' => DB::raw('LOWER(email)')]);
    }
};
