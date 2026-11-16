<?php

declare(strict_types=1);

use App\Http\Controllers\Mcp\OAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', static fn () => redirect('/up'));

Route::get('/oauth/authorize', [OAuthController::class, 'authorize'])->middleware('throttle:30,1');
Route::post('/oauth/authorize', [OAuthController::class, 'approve'])->middleware('throttle:5,1');
