<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Model\Branch;
use App\Model\Order;
use App\Model\OrderDetail;
use App\Support\AdminDashboardSalesKpis;
use App\Support\AdminSaleReportExport;
use App\Support\AdminSaleReportSummary;
use App\Support\PosOrderTypes;
use Barryvdh\DomPDF\Facade\Pdf;
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
use Illuminate\Support\Facades\DB;

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

        $pdf = Pdf::loadView('admin-views.report.partials._report', compact('data'));
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

        $orderQuery = $this->saleReportOrderQuery($request, $fromDate, $toDate);
        $cancelledQuery = $this->saleReportCancelledQuery($request, $fromDate, $toDate);

        $validOrders = (clone $orderQuery)->orderBy('created_at')->get();
        $orders = $validOrders->pluck('id')->all();
        $cancelledOrders = (clone $cancelledQuery)->orderBy('created_at')->get();
        $paymentTotals = [
            'cash' => 0.0,
            'card' => 0.0,
            'mpesa' => 0.0,
            'paystack' => 0.0,
            'glovo' => 0.0,
            'uber' => 0.0,
            'bolt_food' => 0.0,
        ];
        foreach ($validOrders as $order) {
            $method = (string) $order->payment_method;
            $amount = (float) $order->order_amount;
            if (array_key_exists($method, $paymentTotals)) {
                $paymentTotals[$method] += $amount;
            }
        }

        $totalSold = 0;
        $totalQuantity = 0;
        $quantities = [];

        foreach ($this->orderDetail->whereIn('order_id', $orders)->latest()->get() as $detail) {
            $price = $detail['price'] - $detail['discount_on_product'];
            $orderTotal = $price * $detail['quantity'];
            $totalSold += $orderTotal;
            $totalQuantity += $detail['quantity'];
            $orderId = (string) $detail['order_id'];
            $quantities[$orderId] = ($quantities[$orderId] ?? 0) + (int) $detail['quantity'];
        }

        $cancelledQuantities = AdminSaleReportExport::quantitiesByOrderId($cancelledOrders->pluck('id')->all());
        $quantities = $quantities + $cancelledQuantities;
        $data = AdminSaleReportExport::listingRows($validOrders, $quantities);

        $summary = $this->saleReportSummary($orders, (float) $totalSold);
        $summaryDisplay = $this->formatSaleReportSummary($summary);
        $paymentGroups = AdminSaleReportSummary::fromPaymentTotals($paymentTotals);
        $summaryDisplay['munch_sales'] = Helpers::set_symbol($paymentGroups['munch_sales']);
        $summaryDisplay['marketplace_sales'] = Helpers::set_symbol($paymentGroups['marketplace_sales']);

        $exportReport = AdminSaleReportExport::build(
            $validOrders,
            [
                'branch_name' => $this->saleReportBranchName($request['branch_id'] ?? 'all'),
                'from' => $fromDate,
                'to' => $toDate,
                'payment_totals' => $paymentTotals,
                'cancelled_orders' => $cancelledOrders,
                'quantities' => $quantities,
            ]
        );
        $cancelledSummary = $exportReport['cancelled'] ?? ['total' => 0.0, 'order_count' => 0];

        session()->put('export_sale_data', $data);
        session()->put('export_sale_summary', $summaryDisplay);
        session()->put('export_sale_report', $exportReport);

        return response()->json([
            'order_count' => count($data),
            'item_qty' => $totalQuantity,
            'order_sum' => Helpers::set_symbol($totalSold),
            'summary' => $summaryDisplay,
            'payment_totals' => [
                'cash' => Helpers::set_symbol($paymentTotals['cash']),
                'card' => Helpers::set_symbol($paymentTotals['card']),
                'mpesa' => Helpers::set_symbol($paymentTotals['mpesa']),
                'paystack' => Helpers::set_symbol($paymentTotals['paystack']),
                'glovo' => Helpers::set_symbol($paymentTotals['glovo']),
                'uber' => Helpers::set_symbol($paymentTotals['uber']),
                'bolt_food' => Helpers::set_symbol($paymentTotals['bolt_food']),
            ],
            'cancelled' => [
                'order_count' => (int) ($cancelledSummary['order_count'] ?? 0),
                'total' => Helpers::set_symbol($cancelledSummary['total'] ?? 0),
            ],
            'view' => view('admin-views.report.partials._table', ['data' => $data, 'summary' => $summaryDisplay, 'isSaleReport' => true])->render(),
            'cancelled_view' => view('admin-views.report.partials._sale-report-cancelled', [
                'report' => $exportReport,
            ])->render(),
        ]);
    }

    /**
     * @return mixed
     */
    public function exportSaleReport(Request $request): mixed
    {
        $report = session('export_sale_report');
        if (! is_array($report)) {
            Toastr::warning(translate('No Data Found'));

            return back();
        }

        $format = AdminSaleReportExport::normalizeFormat($request->query('format', AdminSaleReportExport::FORMAT_PDF));
        $filename = AdminSaleReportExport::filename(
            $report,
            $format === AdminSaleReportExport::FORMAT_PRINT ? AdminSaleReportExport::FORMAT_PDF : $format
        );

        if ($format === AdminSaleReportExport::FORMAT_CSV) {
            return AdminSaleReportExport::downloadCsv($report, $filename);
        }
        if ($format === AdminSaleReportExport::FORMAT_XLSX) {
            return AdminSaleReportExport::downloadXlsx($report, $filename);
        }
        if ($format === AdminSaleReportExport::FORMAT_PRINT) {
            return view('admin-views.report.partials._sale-report-export', compact('report'));
        }

        return Pdf::loadView('admin-views.report.partials._sale-report-export', compact('report'))
            ->download($filename);
    }

    private function saleReportBranchName(mixed $branchId): string
    {
        if ($branchId === 'all' || $branchId === null || $branchId === '') {
            return 'All Branches';
        }

        $name = Branch::query()->where('id', $branchId)->value('name');

        return is_string($name) && trim($name) !== '' ? $name : 'Branch';
    }

    private function saleReportScopedQuery(Request $request, Carbon $fromDate, Carbon $toDate): Builder
    {
        $channel = (string) $request->input('sales_channel', 'all');
        $paymentMethod = (string) $request->input('payment_method', 'all');

        return $this->order->whereBetween('created_at', [$fromDate, $toDate])
            ->when($request['branch_id'] !== 'all', function ($query) use ($request) {
                $query->where('branch_id', $request['branch_id']);
            })
            ->when($channel !== '' && $channel !== 'all', function ($query) use ($channel) {
                PosOrderTypes::constrainSaleReportChannel($query, $channel);
            })
            ->when($paymentMethod !== '' && $paymentMethod !== 'all', function ($query) use ($paymentMethod) {
                $query->where('payment_method', $paymentMethod);
            });
    }

    private function saleReportOrderQuery(Request $request, Carbon $fromDate, Carbon $toDate): Builder
    {
        return AdminDashboardSalesKpis::constrainNotVoided(
            $this->saleReportScopedQuery($request, $fromDate, $toDate)
        );
    }

    private function saleReportCancelledQuery(Request $request, Carbon $fromDate, Carbon $toDate): Builder
    {
        return AdminDashboardSalesKpis::constrainVoided(
            $this->saleReportScopedQuery($request, $fromDate, $toDate)
        );
    }

    /**
     * @param  list<int|string>  $orderIds
     * @return array{gross_sales: float, total_discounts: float, net_sales: float, tax: float, delivery_fees: float, total_sales: float}
     */
    private function saleReportSummary(array $orderIds, float $totalSold): array
    {
        if ($orderIds === []) {
            return AdminSaleReportSummary::fromParts(['total_sales' => $totalSold]);
        }

        $orderSums = DB::table('orders')
            ->whereIn('id', $orderIds)
            ->selectRaw('COALESCE(SUM(extra_discount), 0) as extra_discount')
            ->selectRaw('COALESCE(SUM(coupon_discount_amount), 0) as coupon_discount')
            ->selectRaw('COALESCE(SUM(referral_discount), 0) as referral_discount')
            ->selectRaw('COALESCE(SUM(total_tax_amount), 0) as tax')
            ->selectRaw('COALESCE(SUM(delivery_charge), 0) as delivery_fees')
            ->first();

        $detailSums = $this->orderDetail->whereIn('order_id', $orderIds)
            ->selectRaw('COALESCE(SUM(price * quantity), 0) as gross')
            ->selectRaw('COALESCE(SUM(discount_on_product * quantity), 0) as item_discount')
            ->selectRaw('COALESCE(SUM(add_on_tax_amount), 0) as addon_tax')
            ->first();

        return AdminSaleReportSummary::fromParts([
            'gross' => $detailSums->gross ?? 0,
            'item_discount' => $detailSums->item_discount ?? 0,
            'extra_discount' => $orderSums->extra_discount ?? 0,
            'coupon_discount' => $orderSums->coupon_discount ?? 0,
            'referral_discount' => $orderSums->referral_discount ?? 0,
            'tax' => ((float) ($orderSums->tax ?? 0)) + (float) ($detailSums->addon_tax ?? 0),
            'delivery_fees' => $orderSums->delivery_fees ?? 0,
            'total_sales' => $totalSold,
        ]);
    }

    /**
     * @param  array{gross_sales: float, total_discounts: float, net_sales: float, tax: float, delivery_fees: float, total_sales: float}  $summary
     * @return array<string, string>
     */
    private function formatSaleReportSummary(array $summary): array
    {
        $formatted = [];
        foreach ($summary as $key => $value) {
            $formatted[$key] = Helpers::set_symbol($value);
        }

        return $formatted;
    }
}
