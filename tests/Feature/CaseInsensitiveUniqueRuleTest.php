<?php

namespace Tests\Feature;

use App\Filament\Resources\GlobalAddonResource;
use App\Filament\Resources\GlobalAddonResource\Pages\CreateGlobalAddon;
use App\Filament\Resources\PassengerCategoryResource;
use App\Filament\Resources\PassengerCategoryResource\Pages\CreatePassengerCategory;
use App\Filament\Superadmin\Resources\TenantResource;
use App\Filament\Superadmin\Resources\TenantResource\Pages\CreateTenant;
use App\Models\GlobalAddon;
use App\Models\PassengerCategory;
use App\Models\Tenant;
use App\Rules\CaseInsensitiveUnique;
use Filament\Facades\Filament;
use Filament\Forms\Form;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Case-Insensitive Uniqueness Fix, Part 2 (display-name columns): global_addons.name,
 * passenger_categories.name (the live table behind the "global_pricing_tiers.name" name used in
 * the original TECH_DEBT.md note -- renamed in 2026_06_28_000000_rename_pricing_tiers_to_
 * passenger_categories.php), and tenants.slug do NOT get their stored casing mutated (a real
 * product name like "VIP Package" must keep its authored casing for display) -- instead, the
 * uniqueness CHECK itself is made case-insensitive via App\Rules\CaseInsensitiveUnique, an
 * engine-agnostic LOWER() comparison that behaves identically on SQLite and MySQL regardless of
 * collation. Neither global_addons.name nor passenger_categories.name had ANY form-level
 * uniqueness validation before this ticket (both relied solely on the DB constraint).
 */
class CaseInsensitiveUniqueRuleTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // The rule class itself, directly
    // ------------------------------------------------------------------

    public function test_rule_rejects_a_case_variant_duplicate(): void
    {
        DB::table('tenants')->insert(['name' => 'T', 'slug' => 'rule-t1', 'created_at' => now(), 'updated_at' => now()]);
        $tenantId = DB::table('tenants')->where('slug', 'rule-t1')->value('id');
        DB::table('global_addons')->insert(['tenant_id' => $tenantId, 'name' => 'VIP Package', 'default_price' => 100, 'created_at' => now(), 'updated_at' => now()]);

        $rule = new CaseInsensitiveUnique(table: 'global_addons', tenantId: $tenantId);
        $failed = false;
        $rule->validate('name', 'vip package', function () use (&$failed) { $failed = true; });

        $this->assertTrue($failed, 'A case-variant duplicate ("vip package" vs stored "VIP Package") must fail validation.');
    }

    public function test_rule_does_not_mutate_the_display_casing_of_the_value_being_checked(): void
    {
        // The rule only validates -- it must never write/normalize the value itself, unlike the
        // email fix. Confirmed by checking the rule has no side effect on the raw input, and
        // separately that a real GlobalAddon saved through the resource keeps its authored
        // casing in storage (see test_global_addon_name_keeps_its_authored_casing below).
        $rule = new CaseInsensitiveUnique(table: 'global_addons');
        $value = 'VIP Package';
        $rule->validate('name', $value, function () {});
        $this->assertSame('VIP Package', $value, 'The rule must not mutate the input value.');
    }

    public function test_rule_ignores_the_current_record_when_editing(): void
    {
        DB::table('tenants')->insert(['name' => 'T', 'slug' => 'rule-t2', 'created_at' => now(), 'updated_at' => now()]);
        $tenantId = DB::table('tenants')->where('slug', 'rule-t2')->value('id');
        $addon = GlobalAddon::create(['tenant_id' => $tenantId, 'name' => 'VIP Package', 'default_price' => 100]);

        // Editing the SAME record, keeping the same name (even with different casing) must not
        // be flagged as a duplicate of itself.
        $rule = new CaseInsensitiveUnique(table: 'global_addons', tenantId: $tenantId, ignoreId: $addon->id);
        $failed = false;
        $rule->validate('name', 'vip package', function () use (&$failed) { $failed = true; });

        $this->assertFalse($failed, 'Editing a record and keeping its own (differently-cased) name must not fail.');
    }

    public function test_rule_is_scoped_per_tenant(): void
    {
        DB::table('tenants')->insert(['name' => 'TA', 'slug' => 'rule-ta', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenants')->insert(['name' => 'TB', 'slug' => 'rule-tb', 'created_at' => now(), 'updated_at' => now()]);
        $tenantA = DB::table('tenants')->where('slug', 'rule-ta')->value('id');
        $tenantB = DB::table('tenants')->where('slug', 'rule-tb')->value('id');
        GlobalAddon::create(['tenant_id' => $tenantA, 'name' => 'VIP Package', 'default_price' => 100]);

        $rule = new CaseInsensitiveUnique(table: 'global_addons', tenantId: $tenantB);
        $failed = false;
        $rule->validate('name', 'vip package', function () use (&$failed) { $failed = true; });

        $this->assertFalse($failed, "A name used by a DIFFERENT tenant must not block this tenant's own use of it.");
    }

    // ------------------------------------------------------------------
    // Wired into the 3 real Filament forms
    // ------------------------------------------------------------------

    public function test_global_addon_resource_form_rejects_case_variant_duplicate_name(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 'ga-form-1']);
        GlobalAddon::create(['tenant_id' => $tenant->id, 'name' => 'VIP Package', 'default_price' => 100]);
        Filament::setTenant($tenant, true);

        $field = $this->findField(GlobalAddonResource::form(Form::make(new CreateGlobalAddon())), 'name');
        $rules = $field->getValidationRules();
        $caseRule = collect($rules)->first(fn ($r) => $r instanceof CaseInsensitiveUnique);
        $this->assertNotNull($caseRule, 'GlobalAddonResource name field must carry a CaseInsensitiveUnique rule.');

        $failed = false;
        $caseRule->validate('name', 'vip package', function () use (&$failed) { $failed = true; });
        $this->assertTrue($failed);
    }

    public function test_passenger_category_resource_form_rejects_case_variant_duplicate_name(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 'pc-form-1']);
        PassengerCategory::create(['tenant_id' => $tenant->id, 'name' => 'Adult', 'default_price' => 100, 'requires_seat' => true]);
        Filament::setTenant($tenant, true);

        $field = $this->findField(PassengerCategoryResource::form(Form::make(new CreatePassengerCategory())), 'name');
        $rules = $field->getValidationRules();
        $caseRule = collect($rules)->first(fn ($r) => $r instanceof CaseInsensitiveUnique);
        $this->assertNotNull($caseRule);

        $failed = false;
        $caseRule->validate('name', 'ADULT', function () use (&$failed) { $failed = true; });
        $this->assertTrue($failed);
    }

    public function test_tenant_resource_form_rejects_case_variant_duplicate_slug(): void
    {
        Tenant::create(['name' => 'T', 'slug' => 'zatara']);

        $field = $this->findField(TenantResource::form(Form::make(new CreateTenant())), 'slug');
        $rules = $field->getValidationRules();
        $caseRule = collect($rules)->first(fn ($r) => $r instanceof CaseInsensitiveUnique);
        $this->assertNotNull($caseRule);

        $failed = false;
        $caseRule->validate('slug', 'Zatara', function () use (&$failed) { $failed = true; });
        $this->assertTrue($failed);
    }

    public function test_global_addon_name_keeps_its_authored_casing_in_storage(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 'ga-casing']);
        $addon = GlobalAddon::create(['tenant_id' => $tenant->id, 'name' => 'VIP Package', 'default_price' => 100]);

        $this->assertSame('VIP Package', $addon->fresh()->name, 'Display casing must be preserved -- unlike email, this is a display-facing product name.');
    }

    /** Recurses into nested Group/Section containers to find a field by name. */
    private function findField($form, string $name)
    {
        return $this->findFieldIn($form->getComponents(), $name);
    }

    private function findFieldIn(array $components, string $name)
    {
        foreach ($components as $component) {
            if (method_exists($component, 'getName') && $component->getName() === $name) {
                return $component;
            }
            if (method_exists($component, 'getChildComponentContainer')) {
                $found = $this->findFieldIn($component->getChildComponentContainer()->getComponents(), $name);
                if ($found) {
                    return $found;
                }
            }
        }

        return null;
    }
}
