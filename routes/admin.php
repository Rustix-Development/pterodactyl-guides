<?php

use Illuminate\Support\Facades\Route;
use RustixDev\Guides\Http\Controllers\AdminController;

Route::get('/overview', [AdminController::class, 'index']);
Route::post('/repos', [AdminController::class, 'store']);
Route::put('/repos/order', [AdminController::class, 'order']);
Route::patch('/repos/{repo}', [AdminController::class, 'update'])->whereNumber('repo');
Route::delete('/repos/{repo}', [AdminController::class, 'destroy'])->whereNumber('repo');
Route::put('/interval', [AdminController::class, 'settings']);
Route::post('/sync', [AdminController::class, 'sync']);
Route::put('/eggs/{egg}', [AdminController::class, 'egg'])->whereNumber('egg');
