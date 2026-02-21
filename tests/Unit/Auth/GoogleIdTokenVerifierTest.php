<?php

declare(strict_types=1);

namespace PHAPI\Tests\Unit\Auth;

use PHAPI\Auth\AuthException;
use PHAPI\Auth\GoogleIdTokenVerifier;
use PHPUnit\Framework\TestCase;

final class GoogleIdTokenVerifierTest extends TestCase
{
    public function testVerifyAcceptsValidToken(): void
    {
        [$privateKey, $publicKey] = $this->makeKeyPair();

        $now = 1_700_000_000;
        $token = $this->createToken(
            privateKey: $privateKey,
            claims: [
                'iss' => 'https://accounts.google.com',
                'aud' => 'client-123',
                'exp' => $now + 3600,
                'sub' => 'subject-1',
                'email' => 'dev@company.com',
                'email_verified' => true,
            ],
        );

        $verifier = new GoogleIdTokenVerifier(
            certsUrl: 'https://example.test/certs',
            certificateProvider: static fn (): array => ['kid-test' => $publicKey],
            clock: static fn (): int => $now,
        );

        $result = $verifier->verify($token, 'client-123');

        self::assertSame('dev@company.com', $result['email']);
        self::assertSame('subject-1', $result['sub']);
    }

    public function testVerifyRejectsInvalidAudience(): void
    {
        [$privateKey, $publicKey] = $this->makeKeyPair();

        $now = 1_700_000_000;
        $token = $this->createToken(
            privateKey: $privateKey,
            claims: [
                'iss' => 'accounts.google.com',
                'aud' => 'other-client',
                'exp' => $now + 3600,
                'sub' => 'subject-1',
                'email' => 'dev@company.com',
                'email_verified' => true,
            ],
        );

        $verifier = new GoogleIdTokenVerifier(
            certsUrl: 'https://example.test/certs',
            certificateProvider: static fn (): array => ['kid-test' => $publicKey],
            clock: static fn (): int => $now,
        );

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('invalid_audience');

        $verifier->verify($token, 'client-123');
    }

    public function testVerifyRejectsExpiredToken(): void
    {
        [$privateKey, $publicKey] = $this->makeKeyPair();

        $now = 1_700_000_000;
        $token = $this->createToken(
            privateKey: $privateKey,
            claims: [
                'iss' => 'accounts.google.com',
                'aud' => 'client-123',
                'exp' => $now - 120,
                'sub' => 'subject-1',
                'email' => 'dev@company.com',
                'email_verified' => true,
            ],
        );

        $verifier = new GoogleIdTokenVerifier(
            certsUrl: 'https://example.test/certs',
            certificateProvider: static fn (): array => ['kid-test' => $publicKey],
            clock: static fn (): int => $now,
        );

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('token_expired');

        $verifier->verify($token, 'client-123');
    }

    public function testVerifyAcceptsJwksWithModulusAndExponent(): void
    {
        $keys = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($keys === false) {
            self::fail('Failed to generate RSA keypair for JWKS test');
        }

        openssl_pkey_export($keys, $privateKey);
        $details = openssl_pkey_get_details($keys);
        if (!is_array($details) || !isset($details['rsa']) || !is_array($details['rsa']) || !is_string($privateKey)) {
            self::fail('Failed to extract RSA details for JWKS test');
        }

        $rsa = $details['rsa'];
        if (!isset($rsa['n'], $rsa['e']) || !is_string($rsa['n']) || !is_string($rsa['e'])) {
            self::fail('Missing RSA modulus/exponent');
        }

        $now = 1_700_000_000;
        $token = $this->createToken(
            privateKey: $privateKey,
            claims: [
                'iss' => 'https://accounts.google.com',
                'aud' => 'client-123',
                'exp' => $now + 3600,
                'sub' => 'subject-1',
                'email' => 'dev@company.com',
                'email_verified' => true,
            ],
        );

        $verifier = new GoogleIdTokenVerifier(
            certsUrl: 'https://example.test/certs',
            certificateProvider: static fn (): array => [
                'keys' => [[
                    'kid' => 'kid-test',
                    'kty' => 'RSA',
                    'alg' => 'RS256',
                    'use' => 'sig',
                    'n' => rtrim(strtr(base64_encode($rsa['n']), '+/', '-_'), '='),
                    'e' => rtrim(strtr(base64_encode($rsa['e']), '+/', '-_'), '='),
                ]],
            ],
            clock: static fn (): int => $now,
        );

        $result = $verifier->verify($token, 'client-123');

        self::assertSame('dev@company.com', $result['email']);
        self::assertSame('subject-1', $result['sub']);
    }

    public function testVerifyRejectsInvalidTokenFormat(): void
    {
        $verifier = new GoogleIdTokenVerifier(
            certsUrl: 'https://example.test/certs',
            certificateProvider: static fn (): array => ['kid-test' => 'irrelevant'],
        );

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('invalid_token_format');

        $verifier->verify('not.a.valid.jwt.token', 'client-123');
    }

    public function testVerifyRejectsEmptyAudience(): void
    {
        $verifier = new GoogleIdTokenVerifier(
            certsUrl: 'https://example.test/certs',
            certificateProvider: static fn (): array => ['kid-test' => 'irrelevant'],
        );

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('missing_oauth_client_id');

        $verifier->verify('a.b.c', '');
    }

    public function testVerifyThrowsWhenNoCertFetcherAvailable(): void
    {
        $verifier = new GoogleIdTokenVerifier(
            certsUrl: 'https://example.test/certs',
        );

        [$privateKey] = $this->makeKeyPair();
        $token = $this->createToken(
            privateKey: $privateKey,
            claims: [
                'iss' => 'https://accounts.google.com',
                'aud' => 'client-123',
                'exp' => time() + 3600,
                'sub' => 'subject-1',
                'email' => 'dev@company.com',
                'email_verified' => true,
            ],
        );

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('cert_fetcher_unavailable');

        $verifier->verify($token, 'client-123');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function makeKeyPair(): array
    {
        $keys = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($keys === false) {
            self::fail('Failed to generate RSA keypair for test');
        }

        openssl_pkey_export($keys, $privateKey);
        $details = openssl_pkey_get_details($keys);

        if (!is_array($details) || !isset($details['key']) || !is_string($details['key'])) {
            self::fail('Failed to extract public key for test');
        }

        return [$privateKey, $details['key']];
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function createToken(string $privateKey, array $claims): string
    {
        $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'kid-test'];

        $headerEncoded = $this->base64UrlEncode((string) json_encode($header));
        $payloadEncoded = $this->base64UrlEncode((string) json_encode($claims));
        $toSign = $headerEncoded . '.' . $payloadEncoded;

        $signature = '';
        openssl_sign($toSign, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        return $toSign . '.' . $this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
