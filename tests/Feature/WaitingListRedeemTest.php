<?php

namespace Tests\Feature;

use App\Enums\WaitingListStatusEnum;
use App\Models\InventoryLedger;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripTemplate;
use App\Models\WaitingList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * EMERGENCY FIX regression coverage: WaitingListController::redeem() used to read
 * $waitingList->trip_instance_id, a column dropped in favor of the trip_instance_waiting_list
 * pivot when a waiting list entry became able to reference multiple candidate trips — the exact
 * same root cause already fixed once for the "send_link_now" admin action (see
 * WaitingListSendLinkNowTest's docblock) but missed here. Reading a nonexistent attribute
 * silently returned null, so every real redemption click passed tripInstance => null into
 * route(), which throws UrlGenerationException — a hard 500 for every customer, reproduced live
 * before this fix (confirmed via a real signed URL against a real running server).
 */
class WaitingListRedeemTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{tenant: Tenant, instance: TripInstance}
     */
    private function makeFixture(string $suffix): array
    {
        $tenant = Tenant::create(['name' => "Agency {$suffix}", 'slug' => "agency-wlr-{$suffix}", 'domain' => "{$suffix}.zatara.com"]);
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Tour', 'base_price' => 100]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(10),
            'available_seats' => 10,
            'status' => 'active',
        ]);

        return compact('tenant', 'instance');
    }

    public function test_redeem_resolves_the_correct_trip_instance_via_the_hold_and_redirects_to_checkout(): void
    {
        $f = $this->makeFixture('001');

        $hold = InventoryLedger::create([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'quantity' => -1,
            'type' => 'hold',
            'expires_at' => now()->addHours(2),
        ]);

        $waitingList = WaitingList::create([
            'tenant_id' => $f['tenant']->id,
            'customer_name' => 'Jane',
            'phone_number' => '0790000001',
            'status' => WaitingListStatusEnum::Notified->value,
            'hold_id' => $hold->id,
        ]);
        $waitingList->tripInstances()->attach($f['instance']->id, ['priority' => 1]);

        $url = URL::signedRoute('waiting-list.redeem', ['waitingList' => $waitingList->id]);

        $response = $this->get($url);

        $response->assertRedirect(route('storefront.checkout', [
            'tenant' => $f['tenant']->slug,
            'tripInstance' => $f['instance']->id,
            'wl' => $waitingList->id,
        ]));
    }

    public function test_redeem_falls_back_to_the_pivot_relation_when_hold_id_is_missing(): void
    {
        // Defensive fallback path: in practice hold_id is always set together with
        // status=Notified (both write sites do it atomically), but the fix must not crash if
        // that ever isn't true.
        $f = $this->makeFixture('002');

        $waitingList = WaitingList::create([
            'tenant_id' => $f['tenant']->id,
            'customer_name' => 'Jane',
            'phone_number' => '0790000002',
            'status' => WaitingListStatusEnum::Notified->value,
            'hold_id' => null,
        ]);
        $waitingList->tripInstances()->attach($f['instance']->id, ['priority' => 1]);

        $url = URL::signedRoute('waiting-list.redeem', ['waitingList' => $waitingList->id]);

        $response = $this->get($url);

        $response->assertRedirect(route('storefront.checkout', [
            'tenant' => $f['tenant']->slug,
            'tripInstance' => $f['instance']->id,
            'wl' => $waitingList->id,
        ]));
    }

    public function test_redeem_rejects_a_link_that_is_no_longer_notified(): void
    {
        $f = $this->makeFixture('003');

        $waitingList = WaitingList::create([
            'tenant_id' => $f['tenant']->id,
            'customer_name' => 'Jane',
            'phone_number' => '0790000003',
            'status' => WaitingListStatusEnum::Expired->value,
        ]);
        $waitingList->tripInstances()->attach($f['instance']->id, ['priority' => 1]);

        $url = URL::signedRoute('waiting-list.redeem', ['waitingList' => $waitingList->id]);

        $this->get($url)->assertForbidden();
    }

    public function test_redeem_returns_404_instead_of_crashing_when_no_trip_can_be_resolved_at_all(): void
    {
        $f = $this->makeFixture('004');

        // No hold, no pivot rows attached at all -- truly unresolvable.
        $waitingList = WaitingList::create([
            'tenant_id' => $f['tenant']->id,
            'customer_name' => 'Jane',
            'phone_number' => '0790000004',
            'status' => WaitingListStatusEnum::Notified->value,
            'hold_id' => null,
        ]);

        $url = URL::signedRoute('waiting-list.redeem', ['waitingList' => $waitingList->id]);

        $this->get($url)->assertNotFound();
    }
}
