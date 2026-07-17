<?php

namespace Redeyed;

/**
 * Official Redeyed API client (PHP).
 *
 * Free to use — the developer API endpoints (AI tools, IP reputation, account)
 * are authenticated with your Redeyed API key, so the client refuses to run
 * without one. Get a key at https://redeyed.com/developers.
 *
 * Sentinel captcha verification is different: it does NOT use the developer API
 * key. Each site has its own Secret Key (reCAPTCHA/Turnstile-style) that
 * authenticates the server-side verify call. Grab your Site Key + Secret Key
 * from the Redeyed Lab under Sentinel → Sites (the Secret Key is shown once).
 *
 * Dependency-free (uses cURL), so it works in any PHP project or CMS.
 */
class Client
{
    private string $apiKey;
    private string $secretKey;
    private string $baseUrl;
    private string $siteBaseUrl;
    private int $timeout;

    /**
     * @param  string  $apiKey  Your Redeyed API key (required for AI/IP/account endpoints).
     * @param  array{base_url?: string, site_base_url?: string, secret_key?: string, timeout?: int}  $options
     */
    public function __construct(string $apiKey, array $options = [])
    {
        if (trim($apiKey) === '') {
            throw new RedeyedException(
                'A Redeyed API key is required to activate this client. Create one at https://redeyed.com/developers.',
                'no_api_key',
                401
            );
        }

        $this->apiKey = $apiKey;
        $this->secretKey = (string) ($options['secret_key'] ?? getenv('SENTINEL_SECRET_KEY') ?: '');
        $this->baseUrl = rtrim($options['base_url'] ?? 'https://redeyed.com/api/v1', '/');
        // Sentinel verify lives at the site root (/sentinel/siteverify), not under /api/v1.
        $this->siteBaseUrl = rtrim($options['site_base_url'] ?? 'https://redeyed.com', '/');
        $this->timeout = (int) ($options['timeout'] ?? 60);
    }

    /** Account + key info: plan, scopes, remaining quota. */
    public function me(): array
    {
        return $this->request('GET', '/me');
    }

    /** IP reputation score + signals. Omit $ip to score the caller. */
    public function ip(?string $ip = null): array
    {
        return $this->request('GET', $ip ? '/ip/'.rawurlencode($ip) : '/ip');
    }

    /**
     * Verify a Sentinel human-verification token (server-to-server).
     *
     * Uses your site's Secret Key (from the Redeyed Lab → Sentinel → Sites) —
     * NOT the developer API key. Posts to {site_base_url}/sentinel/siteverify
     * with {"secret": "...", "response": "<token>", "remoteip": "<client-ip>"}.
     *
     * Because this is a server-to-server call, the visitor's IP is sent as
     * "remoteip" so the token is matched against the IP that actually solved the
     * challenge — otherwise Sentinel sees this server's IP and the token never
     * matches ("verified but the form fails" behind proxies/CDNs). When you do
     * not pass an IP, one is auto-detected in a proxy-aware way (see clientIp()).
     * Pass an explicit IP to override that detection.
     *
     * Fail-open: if no Secret Key is configured, verification is skipped and a
     * successful result is returned so a mis-configured deploy never locks users
     * out. Base your own "is Sentinel configured?" check on hasSecret().
     *
     * @param  string       $token      The token returned by the Sentinel widget.
     * @param  string|null  $remoteIp   Client IP; auto-detected (proxy-aware) when null.
     * @return array{success: bool, outcome: string, score: float|int}
     */
    public function verify(string $token, ?string $remoteIp = null): array
    {
        // Fail open when no Secret Key is present (see hasSecret()).
        if (! $this->hasSecret()) {
            return ['success' => true, 'outcome' => 'skipped_no_secret', 'score' => 0];
        }

        // Default to a proxy-aware client IP when the caller doesn't pass one.
        if ($remoteIp === null) {
            $remoteIp = self::clientIp();
        }

        $body = [
            'secret' => $this->secretKey,
            'response' => $token,
        ];
        if ($remoteIp !== null && $remoteIp !== '') {
            $body['remoteip'] = $remoteIp;
        }

        $json = $this->request('POST', '/sentinel/siteverify', $body, false);

        return [
            'success' => ($json['success'] ?? false) === true,
            'outcome' => (string) ($json['outcome'] ?? ''),
            'score' => $json['score'] ?? 0,
        ];
    }

    /** Whether a Sentinel Secret Key is configured for server-side verification. */
    public function hasSecret(): bool
    {
        return trim($this->secretKey) !== '';
    }

    /**
     * Best-effort, proxy-aware client IP for Sentinel's "remoteip".
     *
     * Checks the headers a CDN/reverse proxy sets before falling back to
     * REMOTE_ADDR, in this order, returning the first that is a valid IP:
     *   1. CF-Connecting-IP        (Cloudflare)
     *   2. X-Forwarded-For         (first / left-most entry = original client)
     *   3. X-Real-IP               (nginx and friends)
     *   4. REMOTE_ADDR             (direct connection)
     *
     * Returns null when no valid IP is found (e.g. on CLI) so callers can omit
     * "remoteip" entirely. NOTE: forwarded headers are client-spoofable — only
     * trust them when your app sits behind a proxy/CDN that sets them. Pass an
     * explicit IP to verify() to bypass this detection.
     */
    public static function clientIp(): ?string
    {
        $candidates = [];

        if (! empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $candidates[] = $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        if (! empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // May be a comma-separated list; the first entry is the origin client.
            $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
            $candidates[] = $parts[0];
        }
        if (! empty($_SERVER['HTTP_X_REAL_IP'])) {
            $candidates[] = $_SERVER['HTTP_X_REAL_IP'];
        }
        if (! empty($_SERVER['REMOTE_ADDR'])) {
            $candidates[] = $_SERVER['REMOTE_ADDR'];
        }

        foreach ($candidates as $candidate) {
            $ip = trim((string) $candidate);
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                return $ip;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $params */
    public function aiChat(array $params): array
    {
        return $this->request('POST', '/ai/chat', $params);
    }

    /** @param array<string, mixed> $params */
    public function aiParaphrase(array $params): array
    {
        return $this->request('POST', '/ai/paraphrase', $params);
    }

    /** @param array<string, mixed> $params */
    public function aiImage(array $params): array
    {
        return $this->request('POST', '/ai/image', $params);
    }

    // --- KMS (encryption / key management) --------------------------------
    // Managed-key encryption on the Redeyed API. Uses your developer API key;
    // grant it the kms:* scopes. See https://redeyed.com/docs.

    /**
     * Create a managed encryption key.
     *
     * @param  string       $alias        Unique per account; [A-Za-z0-9._-].
     * @param  string       $type         'symmetric' (encrypt/decrypt + data keys) or 'signing' (Ed25519).
     * @param  string|null  $description  Optional human-readable note.
     */
    public function kmsCreateKey(string $alias, string $type = 'symmetric', ?string $description = null): array
    {
        $body = ['alias' => $alias, 'type' => $type];
        if ($description !== null) {
            $body['description'] = $description;
        }

        return $this->request('POST', '/kms/keys', $body);
    }

    /** List your managed keys. */
    public function kmsKeys(): array
    {
        return $this->request('GET', '/kms/keys');
    }

    /** Fetch one key by alias (signing keys include their public_key). */
    public function kmsKey(string $alias): array
    {
        return $this->request('GET', '/kms/keys/'.rawurlencode($alias));
    }

    /** Rotate a symmetric key — mints a new version; old ciphertext still decrypts. */
    public function kmsRotate(string $alias): array
    {
        return $this->request('POST', '/kms/keys/'.rawurlencode($alias).'/rotate');
    }

    /**
     * Encrypt a small payload (≤ 64 KB). Returns ['ciphertext' => ..., 'key_version' => ...].
     *
     * The optional $aad is authenticated but not encrypted — bind it to a record
     * id and pass the SAME value to kmsDecrypt(). For binary data, base64-encode
     * it yourself and call the endpoint's plaintext_b64 field directly.
     */
    public function kmsEncrypt(string $alias, string $plaintext, string $aad = ''): array
    {
        $body = ['plaintext' => $plaintext];
        if ($aad !== '') {
            $body['aad'] = $aad;
        }

        return $this->request('POST', '/kms/keys/'.rawurlencode($alias).'/encrypt', $body);
    }

    /**
     * Decrypt a ciphertext from kmsEncrypt(). Returns ['plaintext_b64' => ...] and,
     * when the result is valid UTF-8, ['plaintext' => ...]. Pass the same $aad.
     */
    public function kmsDecrypt(string $alias, string $ciphertext, string $aad = ''): array
    {
        $body = ['ciphertext' => $ciphertext];
        if ($aad !== '') {
            $body['aad'] = $aad;
        }

        return $this->request('POST', '/kms/keys/'.rawurlencode($alias).'/decrypt', $body);
    }

    /**
     * Mint an envelope data key. Returns ['plaintext_key_b64' => ..., 'wrapped_key' => ...].
     * Encrypt your (large) data locally with the plaintext key, discard it, and
     * store the wrapped key beside the data — your plaintext never leaves you.
     */
    public function kmsDataKey(string $alias, int $bytes = 32): array
    {
        return $this->request('POST', '/kms/keys/'.rawurlencode($alias).'/data-key', ['bytes' => $bytes]);
    }

    /** Unwrap a data key from kmsDataKey(). Returns ['plaintext_key_b64' => ...]. */
    public function kmsDecryptDataKey(string $alias, string $wrappedKey): array
    {
        return $this->request('POST', '/kms/keys/'.rawurlencode($alias).'/data-key/decrypt', ['wrapped_key' => $wrappedKey]);
    }

    /** Sign a message with an Ed25519 signing key. Returns ['signature' => ..., 'public_key' => ...]. */
    public function kmsSign(string $alias, string $message): array
    {
        return $this->request('POST', '/kms/keys/'.rawurlencode($alias).'/sign', ['message' => $message]);
    }

    /** Verify a signature. Returns ['valid' => true|false]. */
    public function kmsVerify(string $alias, string $message, string $signature): array
    {
        return $this->request('POST', '/kms/keys/'.rawurlencode($alias).'/verify', [
            'message' => $message,
            'signature' => $signature,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  bool  $withApiKey  Send the developer API key header. Sentinel
     *                            verify authenticates with the site Secret Key
     *                            in the body instead, so it passes false.
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body = null, bool $withApiKey = true): array
    {
        $base = $withApiKey ? $this->baseUrl : $this->siteBaseUrl;
        $ch = curl_init($base.$path);
        $headers = [
            'Accept: application/json',
            'User-Agent: redeyed-php/1.1.0',
        ];
        if ($withApiKey) {
            $headers[] = 'X-Api-Key: '.$this->apiKey;
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RedeyedException('Could not reach the Redeyed API: '.$err, 'network_error', 0);
        }

        $json = json_decode((string) $raw, true);
        if (! is_array($json)) {
            $json = [];
        }

        if ($status < 200 || $status >= 300 || isset($json['error'])) {
            $error = $json['error'] ?? [];
            throw new RedeyedException(
                is_array($error) ? ($error['message'] ?? 'Request failed with HTTP '.$status.'.') : (string) $error,
                is_array($error) ? ($error['code'] ?? 'http_'.$status) : 'http_'.$status,
                $status,
                $json['meta'] ?? null
            );
        }

        return $json['data'] ?? $json;
    }
}
