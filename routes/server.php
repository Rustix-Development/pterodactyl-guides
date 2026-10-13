<?php

use Illuminate\Support\Facades\Route;
use RustixDev\Guides\Http\Controllers\GuideController;

// Both need ext.guides.view.
Route::get('/guides', [GuideController::class, 'index']);
Route::get('/guides/{slug}', [GuideController::class, 'show'])->where('slug', '[a-z0-9][a-z0-9-]{0,99}');
