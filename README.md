# redeyed/sdk (PHP)

Official PHP client for the [Redeyed API](https://redeyed.com/developers) — AI creator tools, **Sentinel** human verification & IP reputation. Dependency‑free (uses cURL), works in any PHP project or CMS.

> **Free to install. Activated by your API key.**
> The client refuses to run without a Redeyed API key. Create one in your Laboratory panel under **Developer → API Keys**.

## Install

```bash
composer require redeyed/sdk
```

Requires **PHP 8.1+** with the `curl` and `json` extensions.

## Quick start

```php
use Redeyed\Client;
use Redeyed\RedeyedException;

// A key is required — the client throws without one.
$redeyed = new Client(getenv('REDEYED_API_KEY'));

try {
    $me  = $redeyed->me();                    // account + remaining quota
    $rep = $redeyed->ip('8.8.8.8');           // IP reputation (omit arg = caller)
    $ok  = $redeyed->verify($tokenFromWidget, ['site_key' => 'st_pub_…']);

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
| `verify(string $token, array $params = [])` | `POST /verify` | `sentinel:verify` |
| `aiChat(array $params)` | `POST /ai/chat` | `ai:chat` |
| `aiParaphrase(array $params)` | `POST /ai/paraphrase` | `ai:paraphrase` |
| `aiImage(array $params)` | `POST /ai/image` | `ai:image` |

Each returns the unwrapped `data` array, or throws `RedeyedException` on an error response.

## Configuration

```php
new Client($apiKey, [
    'base_url' => 'https://redeyed.com/api/v1', // override for self-hosted/staging
    'timeout'  => 60,                            // seconds
]);
```

## License

MIT © Redeyed Corporation. Support: dev@redeyed.com
