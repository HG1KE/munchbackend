<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\AbandonedCheckoutService;
use App\Http\Controllers\Controller;
use App\Model\AbandonedCheckout;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class MarketingSmsQueueController extends Controller
{
    public function index(Request $request): View
    {
        $rows = AbandonedCheckout::query()
            ->with(['branch', 'customer'])
            ->whereNull('converted_at')
            ->where(function ($q) {
                $q->whereNotNull('sms_sent_at')
                    ->orWhere('sms_attempts', '>', 0)
                    ->orWhereNotNull('sms_queued_at');
            })
            ->orderByDesc('sms_sent_at')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(40)
            ->appends($request->query());

        $rows->getCollection()->transform(function (AbandonedCheckout $row) {
            $row->sms_transport_status = $this->smsTransportStatus($row);
            $row->sms_rendered_body = AbandonedCheckoutService::renderAbandonedCheckoutPromotionalBody($row);
            $row->sms_delay_from_created = $this->minutesDelay($row->created_at, $row->sms_sent_at);
            $row->sms_delay_from_queued = $this->minutesDelay($row->sms_queued_at, $row->sms_sent_at ?? $row->sms_processed_at);

            return $row;
        });

        return view('admin-views.marketing.sms-queue-activity', compact('rows'));
    }

    /**
     * Promotional SMS transport semantics only (not checkout lifecycle).
     * Rows with no send attempt are excluded at query level.
     */
    private function smsTransportStatus(AbandonedCheckout $row): string
    {
        if ($row->sms_sent_at !== null) {
            return 'sent';
        }

        if ($row->sms_queued_at !== null && $row->sms_processed_at === null) {
            return 'queued';
        }

        return 'failed';
    }

    private function minutesDelay(?\Illuminate\Support\Carbon $from, ?\Illuminate\Support\Carbon $to): ?int
    {
        if ($from === null || $to === null) {
            return null;
        }

        return (int) $from->diffInMinutes($to);
    }
}
