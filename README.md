# redeyed/sdk (PHP)

Official PHP client for the [Redeyed API](https://redeyed.com/developers) — AI creator tools, **Sentinel** human verification & IP reputation. Dependency‑free (uses cURL), works in any PHP project or CMS.

> **Free to install. Activated by your API key.**
> The client refuses to run without a Redeyed API key (used for the AI, IP‑reputation and account endpoints). Create one in your Laboratory panel under **Developer → API Keys**.
>
> **Sentinel captcha verification does NOT use a developer API key.** Like reCAPTCHA/Turnstile, each site has its own **Site Key** (public, renders the widget) and **Secret Key** (verifies server‑side). Grab both from the Redeyed Lab under **Sentinel → Sites** — the Secret Key is shown once.

## Install

```bash
composer require redeyed/sdk
```

Requires **PHP 8.1+** with the `curl` and `json` extensions.

## Quick start

```php
use Redeyed\Client;
use Redeyed\RedeyedException;

// A developer API key is required — the client throws without one.
// The Sentinel Secret Key is separate (see below) and used only by verify().
$redeyed = new Client(getenv('REDEYED_API_KEY'), [
    'secret_key' => getenv('SENTINEL_SECRET_KEY'), // your site's Sentinel Secret Key
]);

try {
    $me  = $redeyed->me();                    // account + remaining quota
    $rep = $redeyed->ip('8.8.8.8');           // IP reputation (omit arg = caller)

    // Server-side captcha check. Pass the token from the widget (rendered with
    // your public Site Key) and, optionally, the visitor's IP. Passes when
    // $result['success'] === true.
    $result = $redeyed->verify($tokenFromWidget, $_SERVER['REMOTE_ADDR'] ?? null);
    if (! $result['success']) {
        // reject the submission — outcome: $result['outcome'], score: $result['score']
    }

    $chat = $redeyed->aiChat(['messages' => [['role' => 'user', 'content' => 'Three taglines, please.']]]);
    $para = $redeyed->aiParaphrase(['text' => 'Make this confident.']);
    $img  = $redeyed->aiImage(['prompt' => 'a neon fox, cinematic']);
} catch (RedeyedException $e) {
    // $e->errorCode (e.g. insufficient_scope), $e->status (e.g. 403), $e->getMessage()
}
```

## Methods

| Method | Endpoint | Scope |
|---|---|---|
| `me()` | `GET /me` | `account:read` |
| `ip(?string $ip)` | `GET /ip/{ip?}` | `sentinel:ip` |
| `verify(string $token, ?string $remoteIp = null)` | `POST /sentinel/siteverify` | Site Secret Key |
| `aiChat(array $params)` | `POST /ai/chat` | `ai:chat` |
| `aiParaphrase(array $params)` | `POST /ai/paraphrase` | `ai:paraphrase` |
| `aiImage(array $params)` | `POST /ai/image` | `ai:image` |

Each returns the unwrapped `data` array, or throws `RedeyedException` on an error response.

## Sentinel captcha verification

No developer API key is involved. Each site gets a **Site Key** and a **Secret Key** from the Redeyed Lab under **Sentinel → Sites** (the Secret Key is shown once).

- **Site Key** — public. Render the widget with it on your page.
- **Secret Key** — private. Verifies the token server‑side. Pass it as the `secret_key` option (or set `SENTINEL_SECRET_KEY`).

`verify()` POSTs `{"secret": "…", "response": "<token>", "remoteip": "<ip>"}` to `POST /sentinel/siteverify` (no `X-Api-Key` header). The response is:

```php
['success' => true|false, 'outcome' => '…', 'score' => 0.9]
```

Verification passes when `success === true`. If no Secret Key is configured, `verify()` **fails open** (returns `success => true`, `outcome => 'skipped_no_secret'`) so a mis‑configured deploy never locks users out — use `hasSecret()` to check whether it's actually wired up.

## Configuration

```php
new Client($apiKey, [
    'secret_key'    => getenv('SENTINEL_SECRET_KEY'), // Sentinel Secret Key for verify()
    'base_url'      => 'https://redeyed.com/api/v1',   // developer API base (AI/IP/account)
    'site_base_url' => 'https://redeyed.com',          // Sentinel verify base (/sentinel/siteverify)
    'timeout'       => 60,                              // seconds
]);
```

## License

MIT © Redeyed Corporation. Support: dev@redeyed.com
