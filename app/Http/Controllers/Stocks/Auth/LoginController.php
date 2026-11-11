<?php

namespace App\Http\Controllers\Stocks\Auth;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use Gregwar\Captcha\CaptchaBuilder;
use Gregwar\Captcha\PhraseBuilder;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;

class LoginController extends Controller
{
    public function __construct()
    {
        $this->middleware('guest:stocks', ['except' => ['logout']]);
    }

    public function captcha($tmp): void
    {
        $phrase = new PhraseBuilder;
        $code = $phrase->build(4);
        $builder = new CaptchaBuilder($code, $phrase);
        $builder->setBackgroundColor(220, 210, 230);
        $builder->setMaxAngle(25);
        $builder->setMaxBehindLines(0);
        $builder->setMaxFrontLines(0);
        $builder->build($width = 100, $height = 40, $font = null);
        $phrase = $builder->getPhrase();

        if (Session::has('default_captcha_code_stocks')) {
            Session::forget('default_captcha_code_stocks');
        }
        Session::put('default_captcha_code_stocks', $phrase);
        header('Cache-Control: no-cache, must-revalidate');
        header('Content-Type:image/jpeg');
        $builder->output();
    }

    public function login(): Renderable
    {
        $logoName = Helpers::get_business_settings('logo');
        $logo = Helpers::onErrorImage(
            $logoName,
            asset('storage/app/public/restaurant') . '/' . $logoName,
            asset('public/assets/admin/img/logo.png'),
            'restaurant/'
        );

        return view('stocks-views.auth.login', compact('logo'));
    }

    public function submit(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|min:6',
        ]);

        $recaptcha = Helpers::get_business_settings('recaptcha');
        if (isset($recaptcha) && $recaptcha['status'] == 1 && ! $request?->set_default_captcha) {
            $request->validate([
                'g-recaptcha-response' => [
                    function ($attribute, $value, $fail) {
                        $secret_key = Helpers::get_business_settings('recaptcha')['secret_key'];

                        $gResponse = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
                            'secret' => $secret_key,
                            'response' => $value,
                            'remoteip' => \request()->ip(),
                        ]);

                        if (! $gResponse->successful()) {
                            $fail(translate('ReCaptcha Failed'));
                        }
                    },
                ],
            ]);
        } else {
            if (strtolower((string) $request->default_captcha_value) != strtolower((string) Session('default_captcha_code_stocks'))) {
                return back()->withErrors(translate('Captcha Failed'));
            }
        }

        if (Session::has('default_captcha_code_stocks')) {
            Session::forget('default_captcha_code_stocks');
        }

        if (auth('stocks')->attempt([
            'email' => $request->email,
            'password' => $request->password,
            'status' => 1,
        ], true)) {
            return redirect()->route('stocks.dashboard');
        }

        return redirect()->back()->withInput($request->only('email'))
            ->withErrors([translate('Credentials does not match.')]);
    }

    public function logout(): RedirectResponse
    {
        auth()->guard('stocks')->logout();

        return redirect()->route('stocks.auth.login');
    }
}
