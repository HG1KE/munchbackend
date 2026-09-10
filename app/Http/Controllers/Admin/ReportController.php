<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Model\Order;
use App\Model\OrderDetail;
use Barryvdh\DomPDF\Facade as PDF;
use Brian2694\Toastr\Facades\Toastr;
use Carbon\Carbon;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Contracts\Support\Renderable;

class ReportController extends Controller
{
    public function __construct(
        private Order       $order,
        private OrderDetail $orderDetail,
    )
    {
    }

    /**
     * @return Renderable
     */
    public function orderIndex(): Renderable
    {
        if (session()->has('from_date') == false) {
            session()->put('from_date', date('Y-m-01'));
            session()->put('to_date', date('Y-m-30'));
        }

        return view('admin-views.report.order-index');
    }

    /**
     * @param Request $request
     * @return Renderable
     */
    public function earningIndex(Request $request): Renderable
    {
        $from = Carbon::parse($request->from)->startOfDay();
        $to = Carbon::parse($request->to)->endOfDay();

        if ($request->from > $request->to) {
            Toastr::warning(translate('Invalid date range!'));
        }

        $startDate = $request->from;
        $endDate = $request->to;

        $deliveredOrders = $this->deliveredOrdersQuery($request, $from, $to);

        $orderTotals = (clone $deliveredOrders)
            ->selectRaw('COALESCE(SUM(total_tax_amount), 0) as product_tax, COALESCE(SUM(order_amount), 0) as total_sold')
            ->first();

        $addonTaxAmount = (float) (clone $deliveredOrders)
            ->join('order_details', 'order_details.order_id', '=', 'orders.id')
            ->sum('order_details.add_on_tax_amount');

        $productTax = (float) ($orderTotals->product_tax ?? 0);
        $total_sold = (float) ($orderTotals->total_sold ?? 0);
        $total_tax = $productTax + $addonTaxAmount;

        if ($startDate == null) {
            session()->put('from_date', date('Y-m-01'));
            session()->put('to_date', date('Y-m-30'));
        }

        return view('admin-views.report.earning-index', compact('total_tax', 'total_sold', 'from', 'to', 'startDate', 'endDate'));
    }

    /**
     * Delivered orders for the earning report, with the same optional date filter as before.
     */
    private function deliveredOrdersQuery(Request $request, Carbon $from, Carbon $to): Builder
    {
        return $this->order->where(['order_status' => 'delivered'])
            ->when($request->from && $request->to, function ($q) use ($from, $to) {
                session()->put('from_date', $from);
                session()->put('to_date', $to);
                $q->whereBetween('created_at', [$from, $to]);
            });
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     */
    public function setDate(Request $request): RedirectResponse
    {
        $fromDate = Carbon::parse($request['from'])->startOfDay();
        $toDate = Carbon::parse($request['to'])->endOfDay();

        session()->put('from_date', $fromDate);
        session()->put('to_date', $toDate);

        return back();
    }

    /**
     * @return Renderable
     */
    public function deliverymanReport(): Renderable
    {
        $orders = $this->order->with(['customer', 'branch'])->paginate(25);
        return view('admin-views.report.driver-index', compact('orders'));
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function deliverymanFilter(Request $request): JsonResponse
    {
        $fromDate = Carbon::parse($request->formDate)->startOfDay();
        $toDate = Carbon::parse($request->toDate)->endOfDay();

        $orders = $this->order
            ->where(['delivery_man_id' => $request['delivery_man']])
            ->where(['order_status' => 'delivered'])
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->get();

        return response()->json([
            'view' => view('admin-views.order.partials._table', compact('orders'))->render(),
            'delivered_qty' => $orders->count()
        ]);
    }

    /**
     * @return Renderable
     */
    public function productReport(): Renderable
    {
        return view('admin-views.report.product-report');
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function productReportFilter(Request $request): JsonResponse
    {
        $fromDate = Carbon::parse($request->from)->startOfDay();
        $toDate = Carbon::parse($request->to)->endOfDay();

        $orders = $this->order->when($request['branch_id'] != 'all', function ($query) use ($request) {
            $query->where('branch_id', $request['branch_id']);
        })
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->latest()
            ->get();

        $data = [];
        $totalSold = 0;
        $totalQuantity = 0;
        foreach ($orders as $order) {
            foreach ($order->details as $detail) {
                if ($request['product_id'] != 'all') {
                    if ($detail['product_id'] == $request['product_id']) {
                        $price = Helpers::variation_price(json_decode($detail->product_details, true), $detail['variations']) - $detail['discount_on_product'];
                        $orderTotal = $price * $detail['quantity'];
                        $data[] = [
                            'order_id' => $order['id'],
                            'order_display_id' => Helpers::order_display_id($order),
                            'date' => $order['created_at'],
                            'customer' => $order->customer,
                            'price' => $orderTotal,
                            'quantity' => $detail['quantity'],
                        ];
                        $totalSold += $orderTotal;
                        $totalQuantity += $detail['quantity'];
                    }

                } else {
                    $price = Helpers::variation_price(json_decode($detail->product_details, true), $detail['variations']) - $detail['discount_on_product'];
                    $orderTotal = $price * $detail['quantity'];
                    $data[] = [
                        'order_id' => $order['id'],
                        'order_display_id' => Helpers::order_display_id($order),
                        'date' => $order['created_at'],
                        'customer' => $order->customer,
                        'price' => $orderTotal,
                        'quantity' => $detail['quantity'],
                    ];
                    $totalSold += $orderTotal;
                    $totalQuantity += $detail['quantity'];
                }
            }
        }

        session()->put('export_data', $data);

        return response()->json([
            'order_count' => count($data),
            'item_qty' => $totalQuantity,
            'order_sum' => Helpers::set_symbol($totalSold),
            'view' => view('admin-views.report.partials._table', compact('data'))->render(),
        ]);
    }

    /**
     * @return mixed
     */
    public function exportProductReport(): mixed
    {
        if (session()->has('export_data')) {
            $data = session('export_data');

        } else {
            $orders = $this->order->all();
            $data = [];
            $totalSold = 0;
            $totalQuantity = 0;
            foreach ($orders as $order) {
                foreach ($order->details as $detail) {
                    $price = Helpers::variation_price(json_decode($detail->product_details, true), $detail['variations']) - $detail['discount_on_product'];
                    $orderTotal = $price * $detail['quantity'];
                    $data[] = [
                        'order_id' => $order['id'],
                        'order_display_id' => Helpers::order_display_id($order),
                        'date' => $order['created_at'],
                        'customer' => $order->customer,
                        'price' => $orderTotal,
                        'quantity' => $detail['quantity'],
                    ];
                    $totalSold += $orderTotal;
                    $totalQuantity += $detail['quantity'];
                }
            }
        }

        $pdf = PDF::loadView('admin-views.report.partials._report', compact('data'));
        return $pdf->download('report_' . rand(00001, 99999) . '.pdf');
    }

    /**
     * @return Application|Factory|View
     */
    public function saleReport(): Factory|View|Application
    {
        return view('admin-views.report.sale-report');
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function saleFilter(Request $request): JsonResponse
    {
        $fromDate = Carbon::parse($request->from)->startOfDay();
        $toDate = Carbon::parse($request->to)->endOfDay();

        $channel = (string) $request->input('sales_channel', 'all');
        $orderQuery = $this->order->whereBetween('created_at', [$fromDate, $toDate])
            ->when($request['branch_id'] !== 'all', function ($query) use ($request) {
                $query->where('branch_id', $request['branch_id']);
            })
            ->when($channel !== '' && $channel !== 'all', function ($query) use ($channel) {
                $query->where('sales_channel', $channel);
            });

        $orders = (clone $orderQuery)->pluck('id')->toArray();
        $paymentTotals = [
            'cash' => 0.0,
            'card' => 0.0,
            'mpesa' => 0.0,
            'glovo' => 0.0,
            'uber' => 0.0,
            'bolt_food' => 0.0,
        ];
        foreach ((clone $orderQuery)->get(['payment_method', 'order_amount']) as $order) {
            $method = (string) $order->payment_method;
            $amount = (float) $order->order_amount;
            if (array_key_exists($method, $paymentTotals)) {
                $paymentTotals[$method] += $amount;
            }
        }

        $data = [];
        $totalSold = 0;
        $totalQuantity = 0;

        $orderDisplayIds = $this->order->whereIn('id', $orders)->get()
            ->mapWithKeys(fn ($order) => [$order->id => Helpers::order_display_id($order)]);

        foreach ($this->orderDetail->whereIn('order_id', $orders)->latest()->get() as $detail) {
            $price = $detail['price'] - $detail['discount_on_product'];
            $orderTotal = $price * $detail['quantity'];
            $data[] = [
                'order_id' => $detail['order_id'],
                'order_display_id' => $orderDisplayIds[$detail['order_id']] ?? $detail['order_id'],
                'date' => $detail['created_at'],
                'price' => $orderTotal,
                'quantity' => $detail['quantity'],
            ];
            $totalSold += $orderTotal;
            $totalQuantity += $detail['quantity'];
        }

        return response()->json([
            'order_count' => count($data),
            'item_qty' => $totalQuantity,
            'order_sum' => Helpers::set_symbol($totalSold),
            'payment_totals' => [
                'cash' => Helpers::set_symbol($paymentTotals['cash']),
                'card' => Helpers::set_symbol($paymentTotals['card']),
                'mpesa' => Helpers::set_symbol($paymentTotals['mpesa']),
                'glovo' => Helpers::set_symbol($paymentTotals['glovo']),
                'uber' => Helpers::set_symbol($paymentTotals['uber']),
                'bolt_food' => Helpers::set_symbol($paymentTotals['bolt_food']),
            ],
            'view' => view('admin-views.report.partials._table', compact('data'))->render(),
        ]);
    }

    /**
     * @return mixed
     */
    public function exportSaleReport(): mixed
    {
        $data = session('export_sale_data');
        $pdf = PDF::loadView('admin-views.report.partials._report', compact('data'));

        return $pdf->download('sale_report_' . rand(00001, 99999) . '.pdf');
    }
}
