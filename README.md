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
    // your public Site Key). The visitor's IP is auto-detected (proxy-aware) and
    // sent as `remoteip`; pass a second argument to override. Passes when
    // $result['success'] === true.
    $result = $redeyed->verify($tokenFromWidget);
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
| `kmsCreateKey($alias, $type, $description)` | `POST /kms/keys` | `kms:keys` |
| `kmsKeys()` | `GET /kms/keys` | `kms:keys` |
| `kmsKey($alias)` | `GET /kms/keys/{alias}` | `kms:keys` |
| `kmsRotate($alias)` | `POST /kms/keys/{alias}/rotate` | `kms:keys` |
| `kmsEncrypt($alias, $plaintext, $aad)` | `POST /kms/keys/{alias}/encrypt` | `kms:encrypt` |
| `kmsDecrypt($alias, $ciphertext, $aad)` | `POST /kms/keys/{alias}/decrypt` | `kms:decrypt` |
| `kmsDataKey($alias, $bytes)` | `POST /kms/keys/{alias}/data-key` | `kms:encrypt` |
| `kmsDecryptDataKey($alias, $wrappedKey)` | `POST /kms/keys/{alias}/data-key/decrypt` | `kms:decrypt` |
| `kmsSign($alias, $message)` | `POST /kms/keys/{alias}/sign` | `kms:sign` |
| `kmsVerify($alias, $message, $signature)` | `POST /kms/keys/{alias}/verify` | `kms:sign` |

Each returns the unwrapped `data` array, or throws `RedeyedException` on an error response.

## Encryption (KMS)

Managed-key encryption over the developer API — grant your key the `kms:*` scopes. Redeyed holds the key material; you call the API to use a key.

```php
// One-time: create a key (symmetric for encrypt/decrypt, or 'signing' for Ed25519).
$redeyed->kmsCreateKey('orders', 'symmetric');

// Encrypt a small secret. Optional $aad is bound into the ciphertext and must
// match on decrypt — great for tying a value to a record id.
$enc = $redeyed->kmsEncrypt('orders', '4111 1111 1111 1111', 'order:8842');
$ciphertext = $enc['ciphertext'];

// Decrypt (returns plaintext_b64, plus plaintext when valid UTF-8).
$dec = $redeyed->kmsDecrypt('orders', $ciphertext, 'order:8842');
echo $dec['plaintext'];

// Envelope encryption for large data: get a data key, encrypt locally, store the
// wrapped key beside your data, then unwrap it when you need to read.
$dk = $redeyed->kmsDataKey('orders');            // ['plaintext_key_b64' => ..., 'wrapped_key' => ...]
$plainKey = $redeyed->kmsDecryptDataKey('orders', $dk['wrapped_key'])['plaintext_key_b64'];

// Signing keys (Ed25519):
$sig = $redeyed->kmsSign('webhooks', $payload)['signature'];
$ok  = $redeyed->kmsVerify('webhooks', $payload, $sig)['valid'];
```

Rotate a symmetric key with `kmsRotate('orders')` — the key version is embedded in every ciphertext, so data encrypted before the rotation still decrypts. Full reference: <https://redeyed.com/docs>.

## Sentinel captcha verification

No developer API key is involved. Each site gets a **Site Key** and a **Secret Key** from the Redeyed Lab under **Sentinel → Sites** (the Secret Key is shown once).

- **Site Key** — public. Render the widget with it on your page.
- **Secret Key** — private. Verifies the token server‑side. Pass it as the `secret_key` option (or set `SENTINEL_SECRET_KEY`).

`verify()` POSTs `{"secret": "…", "response": "<token>", "remoteip": "<ip>"}` to `POST /sentinel/siteverify` (no `X-Api-Key` header). The response is:

```php
['success' => true|false, 'outcome' => '…', 'score' => 0.9]
```

Verification passes when `success === true`.

**Proxy-aware `remoteip`.** Because verification is a server-to-server call, the visitor's IP is sent as `remoteip` so the token is matched against the IP that actually solved the challenge — otherwise Sentinel sees your server's IP and the token never matches ("verified but the form fails" behind proxies/CDNs). When you don't pass an IP, `verify()` auto-detects one via `Client::clientIp()`, preferring `CF-Connecting-IP`, then the first `X-Forwarded-For` entry, then `X-Real-IP`, then `REMOTE_ADDR` (each validated as a real IP). Forwarded headers are client-spoofable, so only rely on them behind a proxy/CDN that sets them; pass an explicit IP to `verify($token, $ip)` to bypass detection. If no Secret Key is configured, `verify()` **fails open** (returns `success => true`, `outcome => 'skipped_no_secret'`) so a mis‑configured deploy never locks users out — use `hasSecret()` to check whether it's actually wired up.

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
