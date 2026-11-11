<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Model\Branch;
use App\Model\StockManager;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class StockManagerController extends Controller
{
    public function __construct(
        private StockManager $stockManager,
        private Branch $branch
    ) {}

    public function index(Request $request): Renderable
    {
        $queryParam = [];
        $search = $request['search'];
        $query = $this->stockManager->newQuery()->with('branch');
        if ($request->has('search') && $search !== null && $search !== '') {
            $key = explode(' ', $search);
            $query->where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('name', 'like', "%{$value}%")
                        ->orWhere('email', 'like', "%{$value}%");
                }
            });
            $queryParam = ['search' => $search];
        }

        $managers = $query->orderBy('id', 'DESC')->paginate(Helpers::getPagination())->appends($queryParam);
        $branches = $this->branch->orderBy('name')->get(['id', 'name']);

        return view('admin-views.stock-manager.index', compact('managers', 'branches', 'search'));
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:stock_managers,email',
            'password' => 'required|min:6',
            'branch_id' => 'required|exists:branches,id',
        ]);

        $this->stockManager->create([
            'name' => trim($request->name),
            'email' => trim($request->email),
            'password' => Hash::make($request->password),
            'branch_id' => (int) $request->branch_id,
            'status' => true,
        ]);

        Toastr::success(translate('Stock manager added successfully!'));
        return back();
    }

    public function edit($id): Renderable
    {
        $manager = $this->stockManager->findOrFail($id);
        $branches = $this->branch->orderBy('name')->get(['id', 'name']);

        return view('admin-views.stock-manager.edit', compact('manager', 'branches'));
    }

    public function update(Request $request, $id): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:stock_managers,email,' . $id,
            'branch_id' => 'required|exists:branches,id',
            'password' => 'nullable|min:6',
        ]);

        $manager = $this->stockManager->findOrFail($id);
        $manager->name = trim($request->name);
        $manager->email = trim($request->email);
        $manager->branch_id = (int) $request->branch_id;
        $manager->status = $request->status == 'on' || $request->status == 1;
        if ($request->filled('password')) {
            $manager->password = Hash::make($request->password);
        }
        $manager->save();

        Toastr::success(translate('Stock manager updated successfully!'));
        return redirect()->route('admin.stock-manager.index');
    }

    public function status($id, $status): RedirectResponse
    {
        $manager = $this->stockManager->findOrFail($id);
        $manager->status = (bool) $status;
        $manager->save();

        Toastr::success(translate('Stock manager status updated!'));
        return back();
    }

    public function delete($id): RedirectResponse
    {
        $manager = $this->stockManager->findOrFail($id);
        $manager->delete();

        Toastr::success(translate('Stock manager removed!'));
        return back();
    }
}
