<?php

declare(strict_types=1);

namespace App\Bootstrap;

use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

class WithMiddleware
{
    public function __invoke(Middleware $middleware): void
    {
        $middleware->redirectGuestsTo(
            static fn (Request $request): string => match (true) {
                $request->is('panel', 'panel/*') => '/panel/login',
                $request->is('oauth/authorize') => '/connect',
                default => '/login',
            },
        );

        $middleware->redirectUsersTo(
            static fn (Request $request): string => $request->is('panel', 'panel/*')
                ? '/panel'
                : '/dashboard',
        );
    }
}
