# Changelog

## 1.1.0

- Add the **KMS (Encryption API)** methods: `kmsCreateKey()`, `kmsKeys()`, `kmsKey()`, `kmsRotate()`, `kmsEncrypt()`, `kmsDecrypt()`, `kmsDataKey()`, `kmsDecryptDataKey()`, `kmsSign()` and `kmsVerify()`. Managed-key encryption (XChaCha20-Poly1305) and Ed25519 signing over `/api/v1/kms/*`, authenticated with your developer API key (grant it the `kms:*` scopes).

## 1.0.2

- Send proxy-aware `remoteip` on verification so the token matches the IP that solved the challenge (fixes "Verified but form fails" behind proxies/CDNs). `verify()` now auto-detects a proxy-aware client IP when no IP is passed — preferring `CF-Connecting-IP`, then the first `X-Forwarded-For` entry, then `X-Real-IP`, then `REMOTE_ADDR` (each `FILTER_VALIDATE_IP`-checked) — via the new public `Client::clientIp()` helper. Pass an explicit IP to `verify()` to override.

## 1.0.1

- Verify via `/sentinel/siteverify` using the site Secret Key (no API key).

## 1.0.0

- Initial release: AI tools, Sentinel verification and IP reputation over the Redeyed developer API.
