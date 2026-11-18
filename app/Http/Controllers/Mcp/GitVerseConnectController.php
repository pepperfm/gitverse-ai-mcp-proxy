<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mcp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\ClientRepository;
use Symfony\Component\HttpFoundation\Response;
use App\Models\GitVerseCredential;
use App\Models\User;

final class GitVerseConnectController
{
    public function create(Request $request, ClientRepository $clients): \Inertia\Response
    {
        $query = $this->oauthQuery($request);
        $client = $clients->find((string) ($query['client_id'] ?? ''));

        if (!$client || !in_array($query['redirect_uri'] ?? '', $client->redirect_uris, true)) {
            abort(403, 'The pending OAuth client is invalid.');
        }

        return inertia('Connect', [
            'clientName' => $client->name,
            'callbackHost' => parse_url($query['redirect_uri'], PHP_URL_HOST),
        ]);
    }

    public function store(Request $request): Response
    {
        $this->oauthQuery($request);

        $validated = $request->validate([
            'gitverse_token' => ['required', 'string', 'min:12', 'max:8192'],
        ]);

        $personalToken = $validated['gitverse_token'];

        // Validate the PAT through the regular GitVerse API. An MCP initialize
        // response may remain open as SSE and make a synchronous HTTP probe
        // time out even when the server and token are valid.
        try {
            $probe = Http::withToken($personalToken)
                ->accept('application/vnd.gitverse.object+json;version=1')
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(5)
                ->timeout(12)
                ->get('https://api.gitverse.ru/user');
        } catch (ConnectionException $exception) {
            report($exception);

            return back()->withErrors([
                'gitverse_token' => 'Не удалось связаться с GitVerse API. Попробуй позже.',
            ]);
        }

        if ($probe->status() === 401) {
            return back()->withErrors([
                'gitverse_token' => 'GitVerse отклонил токен. Проверь его и попробуй снова.',
            ]);
        }

        if ($probe->status() === 403) {
            return back()->withErrors([
                'gitverse_token' => 'У токена нет права на чтение данных пользователя GitVerse.',
            ]);
        }

        if ($probe->status() === 429) {
            return back()->withErrors([
                'gitverse_token' => 'GitVerse ограничил число запросов. Попробуй позже.',
            ]);
        }

        if (!$probe->successful()) {
            return back()->withErrors([
                'gitverse_token' => 'Не удалось проверить токен: GitVerse API вернул HTTP '.$probe->status().'.',
            ]);
        }

        if (!is_string($probe->json('login')) || $probe->json('login') === '') {
            return back()->withErrors([
                'gitverse_token' => 'GitVerse API вернул некорректный ответ. Попробуй позже.',
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
                    'email' => str()->uuid()->toString() . '@gitverse.invalid',
                    'password' => str()->random(80),
                ]);
            }

            $credential ??= new GitVerseCredential(['fingerprint' => $fingerprint]);
            $credential->user()->associate($user);
            $credential->encrypted_token = $personalToken;
            $credential->save();

            return $user;
        });

        auth('web')->login($user);
        $request->session()->regenerate();

        $authorizationUrl = $request->session()->pull('url.intended');
        $request->session()->put('mcp.authorization_url', $authorizationUrl);

        // OAuth navigation must leave the Inertia XHR lifecycle. Otherwise the
        // browser follows Passport redirects to an external callback via XHR,
        // where the client's CORS policy rejects the request.
        if ($request->header('X-Inertia')) {
            return inertia()->location($authorizationUrl);
        }

        return redirect()->to($authorizationUrl);
    }

    /**
     * @return array<string, string>
     */
    private function oauthQuery(Request $request): array
    {
        $intended = $request->session()->get('url.intended');

        if (
            !is_string($intended)
            || parse_url($intended, PHP_URL_PATH) !== '/oauth/authorize'
            || parse_url($intended, PHP_URL_HOST) !== parse_url(OAuthMetadataController::base(), PHP_URL_HOST)
        ) {
            abort(403, 'Start the OAuth authorization from your AI client.');
        }

        parse_str((string) parse_url($intended, PHP_URL_QUERY), $parameters);

        if (
            !is_array($parameters)
            || !is_string($parameters['client_id'] ?? null)
            || !is_string($parameters['redirect_uri'] ?? null)
        ) {
            abort(403, 'Invalid pending OAuth request.');
        }

        return $parameters;
    }
}
