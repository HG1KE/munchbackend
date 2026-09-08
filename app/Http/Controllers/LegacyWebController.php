<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class LegacyWebController extends Controller
{
    public function home()
    {
        return redirect()->route('admin.dashboard');
    }

    public function imageProxy(Request $request)
    {
        $url = $request->query('url');
        if (!$url) {
            abort(400, 'Missing url parameter');
        }

        $response = Http::withHeaders([
            'User-Agent' => 'Laravel-Image-Proxy',
        ])->get($url);

        return response($response->body(), $response->status())
            ->header('Content-Type', $response->header('Content-Type'))
            ->header('Access-Control-Allow-Origin', '*');
    }

    public function authenticationFailed()
    {
        return response()->json([
            'errors' => [
                ['code' => 'auth-001', 'message' => 'Unauthenticated.'],
            ],
        ], 401);
    }

    public function addCurrency()
    {
        $currencies = file_get_contents(base_path('installation/currency.json'));
        $decoded = json_decode($currencies, true);
        $keep = [];
        foreach ($decoded as $item) {
            $keep[] = [
                'country' => $item['name'],
                'currency_code' => $item['code'],
                'currency_symbol' => $item['symbol_native'],
                'exchange_rate' => 1,
            ];
        }
        DB::table('currencies')->insert($keep);

        return response()->json(['ok']);
    }

    public function test()
    {
        return response()->noContent();
    }
}
