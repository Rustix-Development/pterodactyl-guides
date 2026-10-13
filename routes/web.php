<?php

use Illuminate\Support\Facades\Route;
use RustixDev\Guides\Http\Controllers\ImageController;

// Images in guides, from the synced copy: /extensions/guides/<server uuid>/images/<repo>/<folder>/<file>
Route::get('/{server}/images/{repo}/{folder}/{file}', ImageController::class)
    ->where(['server' => '[0-9a-f-]{36}', 'repo' => '[0-9]+', 'folder' => '[a-z0-9-]+', 'file' => '[A-Za-z0-9._-]+']);
