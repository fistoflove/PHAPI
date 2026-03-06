<?php

declare(strict_types=1);

namespace PHAPI\Supabase\Functions;

use PHAPI\Supabase\Exceptions\SupabaseException;
use PHAPI\Supabase\SupabaseConfig;
use PHAPI\Supabase\SupabaseTransport;

/**
 * Supabase Edge Functions client.
 *
 * @api
 */
final class EdgeFunctionsClient
{
    private const FUNCTIONS_PREFIX = '/functions/v1';

    public function __construct(
        private readonly SupabaseTransport $transport,
        private readonly SupabaseConfig $config,
        private readonly ?string $accessToken = null,
    ) {
    }

    /**
     * Invoke an Edge Function.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, mixed> $options  Keys: headers, method, region
     * @return array{data: mixed, error: null}|array{data: null, error: array<string, mixed>}
     */
    public function invoke(string $functionName, ?array $body = null, array $options = []): array
    {
        $method = strtoupper((string) ($options['method'] ?? 'POST'));
        $headers = $this->config->headers($this->accessToken);

        if (isset($options['headers']) && is_array($options['headers'])) {
            $headers = array_merge($headers, $options['headers']);
        }

        if (isset($options['region'])) {
            $headers['x-region'] = (string) $options['region'];
        }

        $path = self::FUNCTIONS_PREFIX . '/' . rawurlencode($functionName);

        $response = $this->transport->request($method, $path, $body, $headers);

        if ($response['status'] >= 400) {
            return [
                'data' => null,
                'error' => [
                    'message' => is_array($response['data']) ? ($response['data']['message'] ?? $response['body']) : $response['body'],
                    'status' => $response['status'],
                ],
            ];
        }

        return [
            'data' => $response['data'] ?? $response['body'],
            'error' => null,
        ];
    }
}
