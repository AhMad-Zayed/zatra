<?php

namespace App\Http\Controllers;

use App\Models\InventoryLedger;
use App\Models\WaitingList;
use App\Enums\WaitingListStatusEnum;
use Illuminate\Http\Request;

class WaitingListController extends Controller
{
    /**
     * EMERGENCY FIX: this used to read $waitingList->trip_instance_id, a column that no longer
     * exists (waiting_lists.trip_instance_id was dropped in favor of the
     * trip_instance_waiting_list pivot when a waitlist entry became able to reference multiple
     * candidate trips). Reading a nonexistent attribute silently returned null, so every real
     * redemption click was passing tripInstance => null into route(), which throws
     * UrlGenerationException — a hard 500 for every customer, confirmed live before this fix.
     */
    public function redeem(Request $request, WaitingList $waitingList)
    {
        // 1. Verify Status
        if ($waitingList->status !== WaitingListStatusEnum::Notified) {
            abort(403, 'This waiting list link is no longer valid or has already been used.');
        }

        // 2. Resolve which specific trip this notification was for. The hold created alongside
        // it (by WaitlistAutoPromotion or the admin "send link now" action — both always set
        // hold_id together with status=Notified) is the authoritative signal, matching exactly
        // what CheckoutWizard itself trusts when it later reuses this same hold. Falls back to
        // the pivot's highest-priority trip only if a hold is somehow missing, rather than
        // crashing.
        $tripInstanceId = $waitingList->hold_id
            ? InventoryLedger::find($waitingList->hold_id)?->trip_instance_id
            : null;

        $tripInstanceId ??= $waitingList->tripInstances()->first()?->id;

        if (!$tripInstanceId) {
            abort(404, 'Unable to determine which trip this waiting list entry belongs to.');
        }

        // 3. Redirect to Checkout Wizard, passing the waiting list ID
        return redirect()->route('storefront.checkout', [
            'tenant' => $waitingList->tenant->slug,
            'tripInstance' => $tripInstanceId,
            'wl' => $waitingList->id, // Passing WL to hook later
        ]);
    }
}
