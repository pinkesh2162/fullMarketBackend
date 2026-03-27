<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/run-command/{key}', function ($key) {

    if ($key !== 'lucifer') {
        abort(403, 'Unauthorized');
    }

    Artisan::call('optimize:clear');
    Artisan::call('route:clear');
    Artisan::call('cache:clear');

    return "Commands executed successfully!";
});