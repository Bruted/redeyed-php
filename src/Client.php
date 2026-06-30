<?php

namespace Redeyed;

/**
 * Official Redeyed API client (PHP).
 *
 * Free to use — but every call is authenticated with your Redeyed API key, so
 * the client refuses to run without one. Dependency-free (uses cURL), so it
 * works in any PHP project or CMS. Get a key at https://redeyed.com/developers.
 */
class Client
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;

    /**
     * @param  string  $apiKey  Your Redeyed API key (required).
     * @param  array{base_url?: string, timeout?: int}  $options
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
        $this->baseUrl = rtrim($options['base_url'] ?? 'https://redeyed.com/api/v1', '/');
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
     * @param  array<string, mixed>  $params  e.g. ['site_key' => '...', 'ip' => '...']
     */
    public function verify(string $token, array $params = []): array
    {
        return $this->request('POST', '/verify', array_merge(['token' => $token], $params));
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
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init($this->baseUrl.$path);
        $headers = [
            'X-Api-Key: '.$this->apiKey,
            'Accept: application/json',
            'User-Agent: redeyed-php/1.0',
        ];

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
