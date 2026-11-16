<?php

declare(strict_types=1);

use App\Http\Controllers\Mcp\McpProxyController;
use App\Http\Controllers\Mcp\OAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/.well-known/oauth-protected-resource', [OAuthController::class, 'protectedResource']);
Route::get('/.well-known/oauth-protected-resource/mcp', [OAuthController::class, 'protectedResource']);
Route::get('/.well-known/oauth-authorization-server', [OAuthController::class, 'serverMetadata']);
Route::post('/oauth/register', [OAuthController::class, 'register'])->middleware('throttle:20,1');
Route::post('/oauth/token', [OAuthController::class, 'token'])->middleware('throttle:40,1');
Route::match(['GET', 'POST', 'DELETE'], '/mcp', [McpProxyController::class, 'handle']);
