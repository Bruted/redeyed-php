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
     * with {"secret": "...", "response": "<token>"} and, optionally, the
     * client's IP as "remoteip".
     *
     * Fail-open: if no Secret Key is configured, verification is skipped and a
     * successful result is returned so a mis-configured deploy never locks users
     * out. Base your own "is Sentinel configured?" check on hasSecret().
     *
     * @param  string       $token      The token returned by the Sentinel widget.
     * @param  string|null  $remoteIp   Optional client IP address.
     * @return array{success: bool, outcome: string, score: float|int}
     */
    public function verify(string $token, ?string $remoteIp = null): array
    {
        // Fail open when no Secret Key is present (see hasSecret()).
        if (! $this->hasSecret()) {
            return ['success' => true, 'outcome' => 'skipped_no_secret', 'score' => 0];
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
            'User-Agent: redeyed-php/1.1',
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
