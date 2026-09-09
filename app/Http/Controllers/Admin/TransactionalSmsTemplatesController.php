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
        $variables = SmsTemplateCatalog::variableChips();

        return view('admin-views.business-settings.sms-templates', compact('templates', 'variables'));
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

            $status = $row['status'] ?? 0;
            if (is_array($status)) {
                $status = end($status);
            }

            $saved[$key] = [
                'status' => (int) $status === 1 ? 1 : 0,
                'message' => (string) ($row['message'] ?? ''),
                'gateway' => $gateway,
            ];
        }

        try {
            SMS_module::saveSmsTemplates($saved);
            Toastr::success(translate('Successfully updated!'));
        } catch (\Throwable $e) {
            Toastr::error(translate('Something went wrong'));
        }

        return back();
    }
}
