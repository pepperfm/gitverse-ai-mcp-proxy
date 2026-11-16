<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Подключение GitVerse · MCP</title>
    <style>
        :root { color-scheme: dark; font-family: ui-sans-serif, system-ui, -apple-system, sans-serif; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; background: #101114; color: #f1f2f4; }
        main { width: 100%; max-width: 420px; border: 1px solid #34353b; border-radius: 18px; background: #1c1d21; padding: 32px; box-shadow: 0 12px 40px #0004; }
        .mark { width: 44px; height: 44px; border: 1px solid #4e5160; background: #2a2c34; display: grid; place-items: center; border-radius: 12px; font-weight: 800; letter-spacing: -1px; }
        h1 { margin: 20px 0 8px; font-size: 24px; letter-spacing: -.5px; }
        p { color: #acafb8; font-size: 14px; line-height: 1.6; margin: 0 0 22px; }
        .client { color: #e1e3e8; font-weight: 600; overflow-wrap: anywhere; }
        label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; }
        input { width: 100%; font: inherit; padding: 13px 14px; border: 1px solid #41444d; border-radius: 10px; color: #f1f2f4; outline: none; background: #121317; }
        input:focus-visible { border-color: #adb6fe; box-shadow: 0 0 0 3px #7d86ed24; }
        button { width: 100%; background: #f0f1f5; border: 0; border-radius: 10px; margin-top: 16px; padding: 13px 15px; color: #15161b; font: inherit; font-size: 14px; font-weight: 700; cursor: pointer; }
        button:hover { background: #dfe2eb; }
        .error { color: #ffaaa7; font-size: 13px; margin-top: 8px; }
        small { color: #979ba6; display: block; font-size: 12px; line-height: 1.6; margin-top: 24px; }
        a { color: #bac2ff; }
    </style>
</head>
<body>
<main>
    <div class="mark" aria-hidden="true">GV</div>
    <h1>Подключить GitVerse</h1>
    <p>Приложение <span class="client">{{ $clientName }}</span> запрашивает доступ к GitVerse через этот MCP-сервер. Вставь личный токен один раз, чтобы подтвердить подключение.</p>

    <form method="post" action="{{ url('/oauth/authorize') }}" autocomplete="off">
        @csrf
        <label for="gitverse_token">Персональный токен GitVerse</label>
        <input id="gitverse_token" name="gitverse_token" type="password" required autofocus
               autocomplete="off" spellcheck="false" minlength="12" aria-describedby="privacy-note">
        @error('gitverse_token')
            <div class="error" role="alert">{{ $message }}</div>
        @enderror
        <button type="submit">Подключить к ChatGPT</button>
    </form>

    <small id="privacy-note">Токен проверяется через официальный GitVerse MCP и сохраняется в зашифрованном виде на твоём сервере. ChatGPT получает только отдельный OAuth-токен прокси, а не токен GitVerse. Права доступа ограничены правами токена GitVerse.</small>
</main>
</body>
</html>
