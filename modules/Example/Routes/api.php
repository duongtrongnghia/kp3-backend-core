<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Example\Http\Controllers\ExampleController;

/*
| Loaded by BaseModuleServiceProvider under prefix "api/v1" + middleware "api".
| Resulting URIs: /api/v1/examples ...
*/
Route::middleware('auth:sanctum')->group(function (): void {
    Route::apiResource('examples', ExampleController::class);
});
