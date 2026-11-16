<?php

declare(strict_types=1);

use App\Http\Controllers\Mcp\McpProxyController;
use App\Http\Controllers\Mcp\OAuthMetadataController;
use App\Http\Controllers\Mcp\PassportAuthorizationController;
use Illuminate\Support\Facades\Route;

Route::get('/.well-known/oauth-protected-resource', [OAuthMetadataController::class, 'protectedResource']);
Route::get('/.well-known/oauth-protected-resource/mcp', [OAuthMetadataController::class, 'protectedResource']);
Route::get('/.well-known/oauth-authorization-server', [OAuthMetadataController::class, 'authorizationServer']);
Route::post('/oauth/register', [OAuthMetadataController::class, 'register'])->middleware('throttle:20,1');
Route::post('/oauth/token', [PassportAuthorizationController::class, 'token'])->middleware('throttle:40,1');

Route::match(['GET', 'POST', 'DELETE'], '/mcp', [McpProxyController::class, 'handle']);
