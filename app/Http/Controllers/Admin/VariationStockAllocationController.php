<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Model\Product;
use App\Services\Stock\VariationStockAllocationService;
use App\Support\ProductVariationPricing;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VariationStockAllocationController extends Controller
{
    public function __construct(
        private Product $product,
        private VariationStockAllocationService $allocations,
    ) {
    }

    /**
     * Dedicated bulk list: products with variations → allocate stock without product edit.
     */
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $products = $this->allocations->listProductsWithVariations($search !== '' ? $search : null);

        return view('admin-views.product.variation-stock', [
            'products' => $products,
            'search' => $search,
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $product = $this->product->findOrFail($id);
        $payload = $this->allocations->payloadForProduct($product);
        if ($payload['options'] === []) {
            return response()->json([
                'message' => translate('This product has no variations'),
            ], 422);
        }

        return response()->json($payload);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $product = $this->product->findOrFail($id);
        if (ProductVariationPricing::flatOptions($product->variations) === []) {
            return response()->json([
                'success' => 0,
                'message' => translate('This product has no variations'),
            ], 422);
        }

        $this->allocations->syncFromRequest((int) $product->id, $request, $product);

        return response()->json([
            'success' => 1,
            'message' => translate('Variation stock allocations updated'),
            'payload' => $this->allocations->payloadForProduct($product->fresh()),
        ]);
    }
}
