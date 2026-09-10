<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Model\Branch;
use App\Services\ReceiptTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReceiptTemplateController extends Controller
{
    public function __construct(
        private ReceiptTemplateService $templates,
        private Branch $branch,
    ) {
    }

    public function index(Request $request): View
    {
        $branchId = (int) $request->query('branch_id', 0);
        $branch = $branchId > 0 ? $this->branch->query()->find($branchId) : null;
        $canEditCompany = $this->isMasterAdmin();

        return view('admin-views.business-settings.receipt-templates', [
            'branches' => $this->branch->query()->orderBy('name')->get(['id', 'name']),
            'selectedBranch' => $branch,
            'payload' => $this->templates->editorPayload($branch, $canEditCompany),
            'canEditCompany' => $canEditCompany,
        ]);
    }

    public function payload(Request $request): JsonResponse
    {
        $branchId = (int) $request->query('branch_id', 0);
        $branch = $branchId > 0 ? $this->branch->query()->find($branchId) : null;

        return response()->json([
            'success' => 1,
            'data' => $this->templates->editorPayload($branch, $this->isMasterAdmin()),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $branchId = (int) $request->input('branch_id', 0);
        $payload = $request->only(['use_company', 'customer', 'kitchen', 'print']);

        if ($branchId < 1) {
            if (! $this->isMasterAdmin()) {
                return response()->json(['success' => 0, 'message' => 'Only Master Admin can edit the company template'], 403);
            }
            $saved = $this->templates->saveCompany($payload);

            return response()->json([
                'success' => 1,
                'message' => 'Company receipt templates saved',
                'data' => $this->templates->editorPayload(null, true),
                'saved' => $saved,
            ]);
        }

        $branch = $this->branch->query()->find($branchId);
        if (! $branch) {
            return response()->json(['success' => 0, 'message' => 'Branch not found'], 404);
        }

        $saved = $this->templates->saveBranch($branch, $payload);

        return response()->json([
            'success' => 1,
            'message' => 'Branch receipt templates saved',
            'data' => $this->templates->editorPayload($branch->fresh(), $this->isMasterAdmin()),
            'saved' => $saved,
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $action = (string) $request->input('action', '');
        $branchId = (int) $request->input('branch_id', 0);

        if ($action === 'company_default' && $branchId < 1) {
            if (! $this->isMasterAdmin()) {
                return response()->json(['success' => 0, 'message' => 'Only Master Admin can reset the company template'], 403);
            }
            $this->templates->restoreCompanyDefaults();

            return response()->json([
                'success' => 1,
                'message' => 'Company template restored',
                'data' => $this->templates->editorPayload(null, true),
            ]);
        }

        $branch = $branchId > 0 ? $this->branch->query()->find($branchId) : null;
        if (! $branch) {
            return response()->json(['success' => 0, 'message' => 'Branch not found'], 404);
        }

        if ($action === 'company_default') {
            $this->templates->restoreBranchToCompany($branch);
        } elseif ($action === 'branch_default') {
            $this->templates->restoreBranchCustomFromCompany($branch);
        } else {
            return response()->json(['success' => 0, 'message' => 'Unknown reset action'], 422);
        }

        return response()->json([
            'success' => 1,
            'message' => 'Template restored',
            'data' => $this->templates->editorPayload($branch->fresh(), $this->isMasterAdmin()),
        ]);
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => 'required|image|mimes:png,jpg,jpeg,webp|max:2048',
        ]);

        $branchId = (int) $request->input('branch_id', 0);
        if ($branchId < 1 && ! $this->isMasterAdmin()) {
            return response()->json(['success' => 0, 'message' => 'Only Master Admin can upload a company receipt logo'], 403);
        }

        $stored = $this->templates->storeLogo($request->file('logo'));

        return response()->json([
            'success' => 1,
            'path' => $stored['path'],
            'thermal_path' => $stored['thermal_path'],
            'url' => $this->templates->logoUrl($stored['path']),
            'thermal_url' => $this->templates->logoUrl($stored['thermal_path']),
        ]);
    }

    public function qr(Request $request): JsonResponse
    {
        $url = (string) $request->input('url', '');
        $size = (string) $request->input('size', 'medium');
        $px = match ($size) {
            'small' => 96,
            'large' => 200,
            default => 140,
        };

        return response()->json([
            'success' => 1,
            'url' => $url,
            'data_uri' => $this->templates->qrDataUri($url, $px),
        ]);
    }

    private function isMasterAdmin(): bool
    {
        $admin = auth('admin')->user();

        return $admin && (int) $admin->admin_role_id === 1;
    }
}
