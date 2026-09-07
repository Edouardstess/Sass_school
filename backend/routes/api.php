<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
| Placeholder while the controllers are built; replaced in the next commit.
*/

Route::prefix('v1')->group(function (): void {
    Route::get('/ping', fn () => response()->json(['success' => true, 'data' => ['pong' => true]]));
});
