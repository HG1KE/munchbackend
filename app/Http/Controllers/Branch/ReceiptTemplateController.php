<?php

namespace App\Http\Controllers\Branch;

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
    ) {
    }

    public function index(): View
    {
        $branch = Branch::query()->find((int) auth('branch')->id());

        return view('branch-views.business-settings.receipt-templates', [
            'payload' => $this->templates->editorPayload($branch, false),
            'branch' => $branch,
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $branch = Branch::query()->find((int) auth('branch')->id());
        if (! $branch) {
            return response()->json(['success' => 0, 'message' => 'Branch not found'], 404);
        }

        $saved = $this->templates->saveBranch($branch, $request->only(['use_company', 'customer', 'kitchen', 'print']));

        return response()->json([
            'success' => 1,
            'message' => 'Receipt templates saved',
            'data' => $this->templates->editorPayload($branch->fresh(), false),
            'saved' => $saved,
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $branch = Branch::query()->find((int) auth('branch')->id());
        if (! $branch) {
            return response()->json(['success' => 0, 'message' => 'Branch not found'], 404);
        }

        $action = (string) $request->input('action', '');
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
            'data' => $this->templates->editorPayload($branch->fresh(), false),
        ]);
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => 'required|image|mimes:png,jpg,jpeg,webp|max:2048',
        ]);

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
}
