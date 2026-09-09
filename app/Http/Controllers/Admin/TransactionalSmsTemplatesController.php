<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\SMS_module;
use App\Http\Controllers\Controller;
use App\Support\SmsGatewayKeys;
use App\Support\SmsTemplateCatalog;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TransactionalSmsTemplatesController extends Controller
{
    public function index(): View
    {
        $templates = SMS_module::getSmsTemplates();

        return view('admin-views.business-settings.sms-templates', compact('templates'));
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'templates' => 'required|array',
        ]);

        $incoming = $request->input('templates', []);
        if (! is_array($incoming)) {
            $incoming = [];
        }

        $saved = [];
        foreach (SmsTemplateCatalog::keys() as $key) {
            $row = isset($incoming[$key]) && is_array($incoming[$key]) ? $incoming[$key] : [];
            $gateway = (string) ($row['gateway'] ?? SmsGatewayKeys::ASSIGNMENT_TRANSACTIONAL);
            if (! SmsGatewayKeys::isAssignment($gateway)) {
                $gateway = SmsGatewayKeys::ASSIGNMENT_TRANSACTIONAL;
            }

            $saved[$key] = [
                'status' => (int) ($row['status'] ?? 0) === 1 ? 1 : 0,
                'message' => (string) ($row['message'] ?? ''),
                'gateway' => $gateway,
            ];
        }

        SMS_module::saveSmsTemplates($saved);
        Toastr::success(translate('SMS templates updated successfully'));

        return back();
    }
}
