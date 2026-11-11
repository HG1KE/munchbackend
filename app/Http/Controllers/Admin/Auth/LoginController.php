<?php

namespace App\Http\Controllers\Admin\Auth;

use App\CentralLogics\helpers;
use App\Http\Controllers\Controller;
use App\Model\Admin;
use Gregwar\Captcha\CaptchaBuilder;
use Gregwar\Captcha\PhraseBuilder;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Http\RedirectResponse;

class LoginController extends Controller
{
    private Admin $admin;

    public function __construct(Admin $admin)
    {
        $this->middleware('guest:admin', ['except' => ['logout']]);

        $this->admin = $admin;
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

        if (Session::has('default_captcha_code')) {
            Session::forget('default_captcha_code');
        }
        Session::put('default_captcha_code', $phrase);
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
        return view('admin-views.auth.login', compact('logo'));
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

        $admin = $this->admin->where('email', $request->email)->first();
        if (isset($admin) && $admin->status == false) {
            return back()->withErrors(translate('You have been blocked'));
        }

        if (auth('admin')->attempt(['email' => $request->email, 'password' => $request->password], true)) {
            return redirect()->route('admin.dashboard');
        }

        return redirect()->back()->withInput($request->only('email', 'login_panel'))
            ->withErrors([translate('Credentials does not match.')]);
    }

    /**
     * @return RedirectResponse
     */
    public function logout(): RedirectResponse
    {
        auth()->guard('admin')->logout();
        return redirect()->route('admin.auth.login');
    }
}
