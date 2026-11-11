<?php

use App\Http\Controllers\Stocks\Auth\LoginController;
use App\Http\Controllers\Stocks\DashboardController;
use Illuminate\Support\Facades\Route;

Route::group(['namespace' => 'Stocks', 'as' => 'stocks.', 'middleware' => 'maintenance_mode'], function () {
    Route::group(['namespace' => 'Auth', 'prefix' => 'auth', 'as' => 'auth.'], function () {
        Route::get('/code/captcha/{tmp}', [LoginController::class, 'captcha'])->name('default-captcha');
        Route::get('login', [LoginController::class, 'login'])->name('login');
        Route::post('login', [LoginController::class, 'submit']);
        Route::get('logout', [LoginController::class, 'logout'])->name('logout');
    });

    Route::group(['middleware' => ['stocks']], function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::post('/counts', [DashboardController::class, 'saveCounts'])->name('counts.save');
        Route::match(['get', 'post'], '/export', [DashboardController::class, 'exportReport'])->name('export');
    });
});
