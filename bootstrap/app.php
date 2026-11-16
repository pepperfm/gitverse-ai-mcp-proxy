<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
        then: static function (): void {
            Route::group(base_path('routes/mcp.php'));

            if (file_exists(base_path('routes/panel.php'))) {
                Route::middleware('web')->group(base_path('routes/panel.php'));
            }
        },
    )
    ->withMiddleware(new \App\Bootstrap\WithMiddleware())
    ->withExceptions(new \App\Bootstrap\WithExceptions())
    ->create();
