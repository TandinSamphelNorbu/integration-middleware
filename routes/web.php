<?php

use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\FwaPlanController;
use App\Http\Controllers\Api\FwaPlanSyncController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\WebAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', [WebAuthController::class, 'create']);
Route::get('/login', [WebAuthController::class, 'create'])->name('login');
Route::post('/login', [WebAuthController::class, 'store'])->middleware('guest')->middleware('throttle:5,1');

Route::middleware('auth')->group(function () {
    Route::view('/dashboard', 'dashboard')->name('dashboard');
    Route::get('/logs', [OperationsController::class, 'logs'])->name('logs');
    Route::get('/apis', [OperationsController::class, 'apis'])->name('apis');
    Route::post('/logout', [WebAuthController::class, 'destroy'])->name('logout');
    Route::get('/dashboard/catalog', [CatalogController::class, 'index'])->name('dashboard.catalog');
    Route::get('/dashboard/fwa', [FwaPlanController::class, 'index'])->name('dashboard.fwa');
    Route::post('/dashboard/catalog/refresh', [CatalogController::class, 'refresh'])->middleware('throttle:6,1')->name('dashboard.refresh');
    Route::post('/dashboard/fwa/sync', [FwaPlanSyncController::class, 'sync'])->middleware('throttle:6,1')->name('dashboard.sync');
});
