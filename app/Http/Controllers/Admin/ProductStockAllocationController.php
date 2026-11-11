<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Model\Product;
use App\Services\Stock\ProductStockAllocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductStockAllocationController extends Controller
{
    public function __construct(
        private Product $product,
        private ProductStockAllocationService $allocations,
    ) {
    }

    public function show(int $id): JsonResponse
    {
        $product = $this->product->findOrFail($id);

        return response()->json($this->allocations->payloadForProduct($product));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $product = $this->product->findOrFail($id);
        $this->allocations->syncFromRequest((int) $product->id, $request);

        return response()->json([
            'success' => 1,
            'message' => translate('Stock allocations updated'),
            'payload' => $this->allocations->payloadForProduct($product->fresh()),
        ]);
    }
}
