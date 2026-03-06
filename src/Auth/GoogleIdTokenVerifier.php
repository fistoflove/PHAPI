<?php

declare(strict_types=1);

namespace PHAPI\Auth;

use PHAPI\Services\HttpClient;

/**
 * @api
 */
final class GoogleIdTokenVerifier
{
    private const ALLOWED_ISSUERS = [
        'accounts.google.com',
        'https://accounts.google.com',
    ];

    /** @var array<string, string>|null */
    private ?array $cachedCertificates = null;
    private int $cacheExpiresAt = 0;

    /** @var (callable(string): array<string, mixed>)|null */
    private $certificateProvider;

    /** @var callable(): int */
    private $clock;

    /**
     * @param (callable(string): array<string, mixed>)|null $certificateProvider
     * @param (callable(): int)|null $clock
     */
    public function __construct(
        private readonly string $certsUrl,
        private readonly ?HttpClient $httpClient = null,
        ?callable $certificateProvider = null,
        ?callable $clock = null,
        private readonly int $clockSkewSeconds = 60,
        private readonly int $cacheTtl = 300,
    ) {
        $this->certificateProvider = $certificateProvider;
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @return array<string, mixed>
     */
    public function verify(string $idToken, string $expectedAudience, ?string $expectedNonce = null): array
    {
        if ($expectedAudience === '') {
            throw new AuthException('missing_oauth_client_id');
        }

        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw new AuthException('invalid_token_format');
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $header = $this->decodePart($headerB64, 'header');
        $claims = $this->decodePart($payloadB64, 'payload');

        $kid = $header['kid'] ?? null;
        $alg = $header['alg'] ?? null;

        if (!is_string($kid) || $kid === '') {
            throw new AuthException('missing_kid');
        }

        if ($alg !== 'RS256') {
            throw new AuthException('unsupported_alg');
        }

        $signature = $this->decodeBase64Url($signatureB64);
        if ($signature === null) {
            throw new AuthException('invalid_signature_encoding');
        }

        $certificates = $this->getCertificates();
        $certificate = $certificates[$kid] ?? null;
        if (!is_string($certificate) || $certificate === '') {
            throw new AuthException('unknown_kid');
        }

        $signedPayload = $headerB64 . '.' . $payloadB64;
        $verificationResult = openssl_verify($signedPayload, $signature, $certificate, OPENSSL_ALGO_SHA256);

        if ($verificationResult !== 1) {
            throw new AuthException('invalid_signature');
        }

        $this->validateClaims($claims, $expectedAudience, $expectedNonce);

        return [
            'sub' => (string) $claims['sub'],
            'email' => (string) $claims['email'],
            'email_verified' => true,
            'name' => $claims['name'] ?? null,
            'picture' => $claims['picture'] ?? null,
            'iss' => (string) $claims['iss'],
            'aud' => $claims['aud'],
            'exp' => (int) $claims['exp'],
            'hd' => $claims['hd'] ?? null,
            'nonce' => $claims['nonce'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePart(string $encoded, string $name): array
    {
        $decoded = $this->decodeBase64Url($encoded);
        if ($decoded === null) {
            throw new AuthException(sprintf('invalid_%s_encoding', $name));
        }

        $json = json_decode($decoded, true);
        if (!is_array($json)) {
            throw new AuthException(sprintf('invalid_%s_json', $name));
        }

        return $json;
    }

    private function decodeBase64Url(string $value): ?string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * @return array<string, string>
     */
    private function getCertificates(): array
    {
        $now = call_user_func($this->clock);

        if ($this->cachedCertificates !== null && $now < $this->cacheExpiresAt) {
            return $this->cachedCertificates;
        }

        if ($this->certificateProvider !== null) {
            $result = call_user_func($this->certificateProvider, $this->certsUrl);

            $this->cachedCertificates = $this->normalizeCertificates($result);
            $this->cacheExpiresAt = $now + $this->cacheTtl;

            return $this->cachedCertificates;
        }

        if (!$this->httpClient instanceof HttpClient) {
            throw new AuthException('cert_fetcher_unavailable');
        }

        try {
            $meta = $this->httpClient->getJsonWithMeta($this->certsUrl);
        } catch (\Throwable) {
            throw new AuthException('failed_to_fetch_certs');
        }

        $status = $meta['status'];
        $decoded = $meta['data'];
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            throw new AuthException('invalid_certs_json');
        }

        $this->cachedCertificates = $this->normalizeCertificates($decoded);
        $this->cacheExpiresAt = $now + $this->cacheTtl;

        return $this->cachedCertificates;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, string>
     */
    private function normalizeCertificates(array $payload): array
    {
        if (isset($payload['keys']) && is_array($payload['keys'])) {
            return $this->normalizeJwks($payload['keys']);
        }

        $certs = [];
        foreach ($payload as $kid => $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }

            $certs[$kid] = $value;
        }

        if ($certs === []) {
            throw new AuthException('empty_certs');
        }

        return $certs;
    }

    /**
     * @param array<int, mixed> $keys
     *
     * @return array<string, string>
     */
    private function normalizeJwks(array $keys): array
    {
        $certs = [];

        foreach ($keys as $key) {
            if (!is_array($key)) {
                continue;
            }

            $kid = $key['kid'] ?? null;
            if (!is_string($kid) || $kid === '') {
                continue;
            }

            $x5c = $key['x5c'] ?? null;
            if (is_array($x5c) && isset($x5c[0]) && is_string($x5c[0]) && $x5c[0] !== '') {
                $certBody = chunk_split($x5c[0], 64, "\n");
                $certs[$kid] = "-----BEGIN CERTIFICATE-----\n" . $certBody . "-----END CERTIFICATE-----\n";
                continue;
            }

            $n = $key['n'] ?? null;
            $e = $key['e'] ?? null;
            if (is_string($n) && $n !== '' && is_string($e) && $e !== '') {
                $pem = $this->buildRsaPublicKeyPem($n, $e);
                if ($pem !== null) {
                    $certs[$kid] = $pem;
                }
            }
        }

        if ($certs === []) {
            throw new AuthException('empty_jwks');
        }

        return $certs;
    }

    private function buildRsaPublicKeyPem(string $nB64Url, string $eB64Url): ?string
    {
        $modulus = $this->decodeBase64Url($nB64Url);
        $exponent = $this->decodeBase64Url($eB64Url);

        if (!is_string($modulus) || $modulus === '' || !is_string($exponent) || $exponent === '') {
            return null;
        }

        $rsaPublicKey = $this->asn1Sequence(
            $this->asn1Integer($modulus) .
            $this->asn1Integer($exponent)
        );

        $algorithmIdentifier = $this->asn1Sequence(
            $this->asn1ObjectIdentifier("\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01") .
            $this->asn1Null()
        );

        $subjectPublicKeyInfo = $this->asn1Sequence(
            $algorithmIdentifier .
            $this->asn1BitString($rsaPublicKey)
        );

        $body = chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n");

        return "-----BEGIN PUBLIC KEY-----\n" . $body . "-----END PUBLIC KEY-----\n";
    }

    private function asn1Sequence(string $value): string
    {
        return "\x30" . $this->asn1Length(strlen($value)) . $value;
    }

    private function asn1Integer(string $value): string
    {
        $value = ltrim($value, "\x00");
        if ($value === '') {
            $value = "\x00";
        }
        if ((ord($value[0]) & 0x80) !== 0) {
            $value = "\x00" . $value;
        }

        return "\x02" . $this->asn1Length(strlen($value)) . $value;
    }

    private function asn1ObjectIdentifier(string $value): string
    {
        return "\x06" . $this->asn1Length(strlen($value)) . $value;
    }

    private function asn1Null(): string
    {
        return "\x05\x00";
    }

    private function asn1BitString(string $value): string
    {
        $payload = "\x00" . $value;
        return "\x03" . $this->asn1Length(strlen($payload)) . $payload;
    }

    private function asn1Length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xFF) . $bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function validateClaims(array $claims, string $expectedAudience, ?string $expectedNonce = null): void
    {
        $issuer = $claims['iss'] ?? null;
        if (!is_string($issuer) || !in_array($issuer, self::ALLOWED_ISSUERS, true)) {
            throw new AuthException('invalid_issuer');
        }

        if (!$this->isExpectedAudience($claims['aud'] ?? null, $expectedAudience)) {
            throw new AuthException('invalid_audience');
        }

        $exp = $claims['exp'] ?? null;
        if (!is_numeric($exp)) {
            throw new AuthException('missing_exp');
        }

        $now = call_user_func($this->clock);
        if ((int) $exp + $this->clockSkewSeconds < $now) {
            throw new AuthException('token_expired');
        }

        $sub = $claims['sub'] ?? null;
        if (!is_string($sub) || $sub === '') {
            throw new AuthException('missing_sub');
        }

        $email = $claims['email'] ?? null;
        if (!is_string($email) || $email === '') {
            throw new AuthException('missing_email');
        }

        $emailVerified = $claims['email_verified'] ?? null;
        if ($emailVerified !== true && $emailVerified !== 'true' && $emailVerified !== 1 && $emailVerified !== '1') {
            throw new AuthException('email_not_verified');
        }

        if ($expectedNonce !== null) {
            $nonce = $claims['nonce'] ?? null;
            if (!is_string($nonce) || $nonce === '' || !hash_equals($expectedNonce, $nonce)) {
                throw new AuthException('invalid_nonce');
            }
        }
    }

    /**
     * @param mixed $audienceClaim
     */
    private function isExpectedAudience(mixed $audienceClaim, string $expectedAudience): bool
    {
        if (is_string($audienceClaim)) {
            return $audienceClaim === $expectedAudience;
        }

        if (!is_array($audienceClaim)) {
            return false;
        }

        foreach ($audienceClaim as $candidate) {
            if ($candidate === $expectedAudience) {
                return true;
            }
        }

        return false;
    }
}
