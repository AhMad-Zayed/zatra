<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Case-Insensitive Uniqueness Fix: SQLite's default TEXT comparison is case-sensitive, MySQL's
 * default collation isn't -- a plain Rule::unique() inherits that split (and, for
 * global_addons/passenger_categories specifically, neither form had ANY form-level uniqueness
 * validation before this -- both relied entirely on the DB constraint throwing at insert time).
 * This rule uses an explicit LOWER() comparison, which is engine-agnostic SQL and behaves
 * identically on SQLite and MySQL regardless of collation.
 *
 * Deliberately does NOT exclude soft-deleted rows: the existing DB-level unique(tenant_id, name)
 * constraint on global_addons/passenger_categories doesn't either (no partial/filtered index), so
 * a soft-deleted "VIP" addon already blocks a new "VIP" addon at the DB layer today -- this rule
 * matches that existing behavior rather than introducing a new inconsistency between "validation
 * passed" and "the save still fails."
 */
class CaseInsensitiveUnique implements ValidationRule
{
    public function __construct(
        private readonly string $table,
        private readonly ?string $column = null,
        private readonly int|string|null $tenantId = null,
        private readonly string $tenantColumn = 'tenant_id',
        private readonly int|string|null $ignoreId = null,
        private readonly string $idColumn = 'id',
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        $column = $this->column ?? $attribute;

        $query = DB::table($this->table)
            ->whereRaw('LOWER('.$column.') = ?', [Str::lower(trim($value))]);

        if ($this->tenantId !== null) {
            $query->where($this->tenantColumn, $this->tenantId);
        }

        if ($this->ignoreId !== null) {
            $query->where($this->idColumn, '!=', $this->ignoreId);
        }

        if ($query->exists()) {
            $fail('يوجد عنصر آخر بنفس هذا الاسم بالفعل (بغض النظر عن حالة الأحرف).');
        }
    }
}
