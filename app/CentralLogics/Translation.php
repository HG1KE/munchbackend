<?php

use Illuminate\Support\Facades\App;

if (! function_exists('translate')) {
    function translate($key)
    {
        $local = session()->has('local') ? session('local') : 'en';
        App::setLocale($local);

        $line = 'messages.'.$key;
        $translated = __($line);

        return $translated === $line ? $key : $translated;
    }
}
