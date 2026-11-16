<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mcp;

use App\Models\GitVerseCredential;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;

final class GitVerseConnectController
{
    public function create(Request $request, ClientRepository $clients): View
    {
        $query = $this->oauthQuery($request);
        $client = $clients->find((string) ($query['client_id'] ?? ''));

        if (! $client || ! in_array($query['redirect_uri'] ?? '', $client->redirect_uris, true)) {
            abort(403, 'The pending OAuth client is invalid.');
        }

        return view('gitverse-connect', [
            'clientName' => $client->name,
            'callbackHost' => parse_url($query['redirect_uri'], PHP_URL_HOST),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->oauthQuery($request);

        $validated = $request->validate([
            'gitverse_token' => ['required', 'string', 'min:12', 'max:8192'],
        ]);

        $personalToken = $validated['gitverse_token'];

        try {
            $probe = Http::withToken($personalToken)
                ->withHeaders(['Accept' => 'application/json, text/event-stream'])
                ->withOptions(['allow_redirects' => false])
                ->timeout(15)
                ->post((string) config('gitverse.endpoint'), [
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => 'initialize',
                    'params' => [
                        'protocolVersion' => '2025-03-26',
                        'capabilities' => new \stdClass,
                        'clientInfo' => ['name' => 'gitverse-ai-mcp-proxy', 'version' => '1.0.0'],
                    ],
                ]);
        } catch (ConnectionException) {
            return back()->withErrors([
                'gitverse_token' => 'GitVerse MCP недоступен. Попробуй позже.',
            ]);
        }

        if (! $probe->successful()) {
            return back()->withErrors([
                'gitverse_token' => 'GitVerse не принял токен или не ответил на MCP initialize.',
            ]);
        }

        $fingerprint = hash('sha256', $personalToken);

        $user = DB::transaction(static function () use ($fingerprint, $personalToken): User {
            $credential = GitVerseCredential::query()
                ->where('fingerprint', $fingerprint)
                ->lockForUpdate()
                ->first();

            if ($credential?->user !== null) {
                $user = $credential->user;
            } else {
                $user = User::query()->create([
                    'name' => 'GitVerse account',
                    'email' => Str::uuid().'@gitverse.invalid',
                    'password' => Str::random(80),
                ]);
            }

            $credential ??= new GitVerseCredential(['fingerprint' => $fingerprint]);
            $credential->user()->associate($user);
            $credential->encrypted_token = $personalToken;
            $credential->save();

            return $user;
        });

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('mcp.authorization_url', $request->session()->get('url.intended'));

        return redirect()->intended('/up');
    }

    /**
     * @return array<string, string>
     */
    private function oauthQuery(Request $request): array
    {
        $intended = $request->session()->get('url.intended');

        if (! is_string($intended)
            || parse_url($intended, PHP_URL_HOST) !== parse_url(OAuthMetadataController::base(), PHP_URL_HOST)
            || parse_url($intended, PHP_URL_PATH) !== '/oauth/authorize') {
            abort(403, 'Start the OAuth authorization from your AI client.');
        }

        parse_str((string) parse_url($intended, PHP_URL_QUERY), $parameters);

        if (! is_array($parameters) || ! is_string($parameters['client_id'] ?? null)
            || ! is_string($parameters['redirect_uri'] ?? null)) {
            abort(403, 'Invalid pending OAuth request.');
        }

        return $parameters;
    }
}
