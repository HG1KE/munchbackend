<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Model\LoyaltyDeliverySmsLog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class MarketingLoyaltyDeliverySmsActivityController extends Controller
{
    public function index(Request $request): View
    {
        $rows = LoyaltyDeliverySmsLog::query()
            ->with(['customer', 'order'])
            ->orderByDesc('sms_sent_at')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(40)
            ->appends($request->query());

        return view('admin-views.marketing.loyalty-delivery-sms-activity', compact('rows'));
    }
}
