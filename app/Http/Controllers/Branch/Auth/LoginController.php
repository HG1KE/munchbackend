<?php

namespace App\Http\Controllers\Branch\Auth;

use App\CentralLogics\helpers;
use App\Http\Controllers\Controller;
use Gregwar\Captcha\CaptchaBuilder;
use Gregwar\Captcha\PhraseBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Http\RedirectResponse;
use Illuminate\Contracts\Support\Renderable;

class LoginController extends Controller
{
    public function __construct()
    {
        $this->middleware('guest:branch', ['except' => ['logout']]);
    }

    /**
     * @param $tmp
     * @return void
     */
    public function captcha($tmp)
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

        if (Session::has('default_captcha_code_branch')) {
            Session::forget('default_captcha_code_branch');
        }
        Session::put('default_captcha_code_branch', $phrase);
        header("Cache-Control: no-cache, must-revalidate");
        header("Content-Type:image/jpeg");
        $builder->output();
    }

    /**
     * @return Renderable
     */
    public function login(): Renderable
    {
        $logoName = Helpers::get_business_settings('logo');
        $logo = Helpers::onErrorImage($logoName, asset('storage/app/public/restaurant') . '/' . $logoName, asset('public/assets/admin/img/logo.png'), 'restaurant/');
        return view('branch-views.auth.login', compact('logo'));
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     */
    public function submit(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|min:6'
        ]);

        if (auth('branch')->attempt([
            'email' => $request->email,
            'password' => $request->password,
            'status' => 1
        ], true)) {
            return redirect()->route('branch.dashboard');
        }

        return redirect()->back()->withInput($request->only('email', 'login_panel'))
            ->withErrors([translate('Credentials does not match.')]);
    }

    /**
     * @return RedirectResponse
     */
    public function logout(): RedirectResponse
    {
        auth()->guard('branch')->logout();
        return redirect()->route('branch.auth.login');
    }
}
