<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Model\Product;
use App\Services\ProductBranchPricingCopyService;
use App\Services\ProductBulkPricingService;
use App\Services\ProductChannelPricingService;
use App\Support\ProductPricingChannels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductPricingController extends Controller
{
    public function __construct(
        private ProductChannelPricingService $pricing,
        private ProductBulkPricingService $bulk,
        private ProductBranchPricingCopyService $copy,
        private Product $product,
    ) {
    }

    public function meta(): JsonResponse
    {
        return response()->json([
            'branches' => $this->pricing->branchOptions(),
            'categories' => $this->pricing->categoryOptions(),
            'channels' => ProductPricingChannels::labels(),
            'override_channels' => ProductPricingChannels::overrideChannels(),
            'price_actions' => ProductBulkPricingService::ACTIONS,
            'availability_actions' => ProductBulkPricingService::AVAILABILITY_ACTIONS,
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $product = $this->product->findOrFail($id);

        return response()->json($this->pricing->drawerPayload(
            $product,
            $request->input('branch_search'),
            $request->input('channel')
        ));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $product = $this->product->findOrFail($id);
        $changes = $request->input('changes', []);
        if (! is_array($changes)) {
            $changes = [];
        }

        $result = $this->pricing->saveDrawer($product, null, $changes, 'drawer');

        return response()->json([
            'success' => 1,
            'message' => translate('Pricing updated'),
            'saved' => $result['saved'],
            'payload' => $this->pricing->drawerPayload($product),
        ]);
    }

    public function searchProducts(Request $request): JsonResponse
    {
        $query = $this->product->query()->orderBy('name');
        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $query->where(function ($inner) use ($search) {
                $inner->where('name', 'like', '%'.$search.'%')
                    ->orWhere('id', $search);
            });
        }
        $categoryId = (int) $request->input('category_id', 0);
        if ($categoryId > 0) {
            $query->where(function ($inner) use ($categoryId) {
                $inner->where('category_ids', 'like', '%"id":'.$categoryId.'%')
                    ->orWhere('category_ids', 'like', '%"id":"'.$categoryId.'"%');
            });
        }

        $products = $query->paginate(40);

        return response()->json([
            'data' => $products->getCollection()->map(function (Product $product) {
                $category = $product->category;

                return [
                    'id' => (int) $product->id,
                    'name' => (string) $product->name,
                    'price' => $this->pricing->defaultPrice($product),
                    'category' => is_array($category) ? (string) ($category['name'] ?? '') : '',
                ];
            })->values(),
            'next_page' => $products->hasMorePages() ? $products->currentPage() + 1 : null,
        ]);
    }

    public function previewBulkPrice(Request $request): JsonResponse
    {
        $result = $this->bulk->previewPrices(
            $request->input('product_ids', []),
            $request->input('branch_ids', []),
            (string) $request->input('channel', ProductPricingChannels::POS),
            (string) $request->input('action', 'increase_percent'),
            (float) $request->input('value', 0)
        );

        if (! empty($result['error'])) {
            return response()->json(['success' => 0, 'message' => $result['error']], 422);
        }

        return response()->json(['success' => 1] + $result);
    }

    public function applyBulkPrice(Request $request): JsonResponse
    {
        if (! $request->boolean('confirmed')) {
            return response()->json([
                'success' => 0,
                'message' => translate('Preview changes before applying'),
            ], 422);
        }

        $result = $this->bulk->applyPrices(
            $request->input('product_ids', []),
            $request->input('branch_ids', []),
            (string) $request->input('channel', ProductPricingChannels::POS),
            (string) $request->input('action', 'increase_percent'),
            (float) $request->input('value', 0)
        );

        if (! empty($result['error'])) {
            return response()->json(['success' => 0, 'message' => $result['error']], 422);
        }

        return response()->json([
            'success' => 1,
            'saved' => $result['saved'],
            'message' => translate('Pricing updated'),
        ]);
    }

    public function previewBulkAvailability(Request $request): JsonResponse
    {
        $result = $this->bulk->previewAvailability(
            $request->input('product_ids', []),
            $request->input('branch_ids', []),
            (string) $request->input('action', '')
        );

        if (! empty($result['error'])) {
            return response()->json(['success' => 0, 'message' => $result['error']], 422);
        }

        return response()->json(['success' => 1] + $result);
    }

    public function applyBulkAvailability(Request $request): JsonResponse
    {
        if (! $request->boolean('confirmed')) {
            return response()->json([
                'success' => 0,
                'message' => translate('Preview changes before applying'),
            ], 422);
        }

        $result = $this->bulk->applyAvailability(
            $request->input('product_ids', []),
            $request->input('branch_ids', []),
            (string) $request->input('action', '')
        );

        if (! empty($result['error'])) {
            return response()->json(['success' => 0, 'message' => $result['error']], 422);
        }

        return response()->json([
            'success' => 1,
            'saved' => $result['saved'],
            'message' => translate('Availability updated'),
        ]);
    }

    public function previewCopy(Request $request): JsonResponse
    {
        $result = $this->copy->preview(
            (int) $request->input('source_branch_id', 0),
            $request->input('destination_branch_ids', []),
            $request->input('price_channels', []),
            $request->input('availability_channels', []),
            (string) $request->input('mode', ProductBranchPricingCopyService::MODE_SKIP_EXISTING)
        );

        if (! empty($result['error'])) {
            return response()->json(['success' => 0, 'message' => $result['error']], 422);
        }

        return response()->json(['success' => 1] + $result);
    }

    public function applyCopy(Request $request): JsonResponse
    {
        if (! $request->boolean('confirmed')) {
            return response()->json([
                'success' => 0,
                'message' => translate('Preview changes before applying'),
            ], 422);
        }

        $result = $this->copy->apply(
            (int) $request->input('source_branch_id', 0),
            $request->input('destination_branch_ids', []),
            $request->input('price_channels', []),
            $request->input('availability_channels', []),
            (string) $request->input('mode', ProductBranchPricingCopyService::MODE_SKIP_EXISTING)
        );

        if (! empty($result['error'])) {
            return response()->json(['success' => 0, 'message' => $result['error']], 422);
        }

        return response()->json(['success' => 1] + $result);
    }
}
