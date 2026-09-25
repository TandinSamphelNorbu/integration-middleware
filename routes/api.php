<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\FwaPlanController;
use App\Http\Controllers\Api\FwaPlanSyncController;
use App\Http\Controllers\Api\IllCacheController;
use App\Http\Controllers\Api\PostpaidFwaPlanController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/v1/login', [AuthController::class, 'login']);

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {

    Route::get('/user', function (Request $request) {
        return response()->json([
            'success' => true,
            'user' => [
                'id' => $request->user()->id,
                'name' => $request->user()->name,
            ],
        ]);
    });

    Route::get('/catalog', [
        CatalogController::class,
        'index',
    ]);

    Route::post('/catalog/refresh', [
        CatalogController::class,
        'refresh',
    ]);

    Route::get('/fwa/plans', [FwaPlanController::class, 'index']);

    Route::post('/fwa/sync', [FwaPlanSyncController::class, 'sync']);

    Route::post('/ill/refresh', [IllCacheController::class, 'refresh']);

    Route::get('/fwa/postpaid/plans', [PostpaidFwaPlanController::class, 'index']);

});
