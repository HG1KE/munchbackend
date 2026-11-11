<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Model\StockItem;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StockItemController extends Controller
{
    public function __construct(
        private StockItem $stockItem
    ) {}

    public function index(Request $request): Renderable
    {
        $queryParam = [];
        $search = $request['search'];
        $query = $this->stockItem->newQuery();
        if ($request->has('search') && $search !== null && $search !== '') {
            $key = explode(' ', $search);
            $query->where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('name', 'like', "%{$value}%");
                }
            });
            $queryParam = ['search' => $search];
        }

        $stockItems = $query->orderBy('name')->paginate(Helpers::getPagination())->appends($queryParam);

        return view('admin-views.stock-item.index', compact('stockItems', 'search'));
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:stock_items,name',
        ]);

        $this->stockItem->create([
            'name' => trim($request->name),
            'status' => $request->has('status'),
        ]);

        Toastr::success(translate('Stock item added successfully!'));
        return back();
    }

    public function edit($id): Renderable
    {
        $stockItem = $this->stockItem->findOrFail($id);
        return view('admin-views.stock-item.edit', compact('stockItem'));
    }

    public function update(Request $request, $id): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:stock_items,name,' . $id,
        ]);

        $stockItem = $this->stockItem->findOrFail($id);
        $stockItem->name = trim($request->name);
        $stockItem->status = $request->status == 'on' || $request->status == 1;
        $stockItem->save();

        Toastr::success(translate('Stock item updated successfully!'));
        return redirect()->route('admin.stock-item.index');
    }

    public function status($id, $status): RedirectResponse
    {
        $stockItem = $this->stockItem->findOrFail($id);
        $stockItem->status = (bool) $status;
        $stockItem->save();

        Toastr::success(translate('Stock item status updated!'));
        return back();
    }

    public function delete($id): RedirectResponse
    {
        $stockItem = $this->stockItem->findOrFail($id);
        $stockItem->delete();

        Toastr::success(translate('Stock item removed!'));
        return back();
    }
}
