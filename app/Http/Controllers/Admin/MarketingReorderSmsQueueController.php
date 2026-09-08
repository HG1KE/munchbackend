<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\ReorderReminderService;
use App\Http\Controllers\Controller;
use App\Model\ReorderReminderLog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class MarketingReorderSmsQueueController extends Controller
{
    public function index(Request $request): View
    {
        $rows = ReorderReminderLog::query()
            ->with(['customer', 'branch'])
            ->orderByDesc('sms_sent_at')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(40)
            ->appends($request->query());

        $rows->getCollection()->transform(function (ReorderReminderLog $row) {
            $row->sms_transport_status = $this->transportStatus($row);
            $row->sms_rendered_body = ReorderReminderService::renderPromotionalBody($row);

            return $row;
        });

        return view('admin-views.marketing.reorder-sms-queue-activity', compact('rows'));
    }

    private function transportStatus(ReorderReminderLog $row): string
    {
        if ($row->status === 'skipped') {
            return 'skipped';
        }
        if ($row->status === 'queued') {
            return 'queued';
        }
        if ($row->sms_sent_at !== null && $row->status === 'sent') {
            return 'sent';
        }

        return 'failed';
    }
}
