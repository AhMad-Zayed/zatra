<?php

namespace App\Http\Controllers;

use App\Models\WaitingList;
use App\Enums\WaitingListStatusEnum;
use Illuminate\Http\Request;

class WaitingListController extends Controller
{
    public function redeem(Request $request, WaitingList $waitingList)
    {
        // 1. Verify Status
        if ($waitingList->status !== WaitingListStatusEnum::Notified) {
            abort(403, 'This waiting list link is no longer valid or has already been used.');
        }

        // 2. Redirect to Checkout Wizard, passing the waiting list ID
        return redirect()->route('storefront.checkout', [
            'tenant' => $waitingList->tenant->slug,
            'tripInstance' => $waitingList->trip_instance_id,
            'wl' => $waitingList->id, // Passing WL to hook later
        ]);
    }
}
