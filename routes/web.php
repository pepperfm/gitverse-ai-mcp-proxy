<?php

declare(strict_types=1);

use App\Http\Controllers\Mcp\GitVerseConnectController;
use App\Http\Controllers\Mcp\PassportAuthorizationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

Route::get('/', static fn (): Response => Inertia::render('Home'))->name('home');

Route::get('/connect', [GitVerseConnectController::class, 'create'])->middleware('throttle:30,1');
Route::post('/connect', [GitVerseConnectController::class, 'store'])->middleware('throttle:5,1');

Route::get('/oauth/authorize', [PassportAuthorizationController::class, 'authorize'])->middleware('throttle:30,1');
Route::post('/oauth/authorize', [PassportAuthorizationController::class, 'approve'])
    ->middleware(['auth:web', 'throttle:20,1']);
