<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mcp;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Http\Controllers\AccessTokenController;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

final class PassportAuthorizationController
{
    public function authorize(
        Request $request,
        ServerRequestInterface $psrRequest,
        ResponseInterface $psrResponse,
        AuthorizationViewResponse $view,
        AuthorizationController $passport,
    ): Response|AuthorizationViewResponse {
        if (
            $request->query('code_challenge_method') !== 'S256'
            || !is_string($request->query('code_challenge'))
            || $request->query('resource') !== OAuthMetadataController::resource()
            || preg_match('/^[A-Za-z0-9_-]{43}$/D', $request->query('code_challenge')) !== 1
        ) {
            abort(400, 'Invalid MCP authorization request.');
        }

        // A prior browser session must never silently authorize another AI client.
        if ($request->session()->get('mcp.authorization_url') !== $request->fullUrl()) {
            Auth::guard('web')->logout();
            $request->session()->forget('mcp.authorization_url');
        }

        return $passport->authorize($psrRequest, $request, $psrResponse, $view);
    }

    public function approve(
        Request $request,
        ResponseInterface $psrResponse,
        ApproveAuthorizationController $passport,
    ): Response {
        $response = $passport->approve($request, $psrResponse);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->header('X-Inertia') && $response->isRedirection()) {
            return Inertia::location((string) $response->headers->get('Location'));
        }

        return $response;
    }

    public function token(
        Request $request,
        ServerRequestInterface $psrRequest,
        ResponseInterface $psrResponse,
        AccessTokenController $passport,
    ): Response {
        if ($request->input('resource') !== OAuthMetadataController::resource()) {
            return response()
                ->json(['error' => 'invalid_target'], 400)
                ->header('Cache-Control', 'no-store');
        }

        $response = $passport->issueToken($psrRequest, $psrResponse);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
