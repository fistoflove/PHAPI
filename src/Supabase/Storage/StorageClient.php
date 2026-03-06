<?php

declare(strict_types=1);

namespace PHAPI\Supabase\Storage;

use PHAPI\Supabase\Exceptions\SupabaseStorageException;
use PHAPI\Supabase\SupabaseConfig;
use PHAPI\Supabase\SupabaseTransport;

/**
 * Supabase Storage client for buckets and objects.
 *
 * @api
 */
final class StorageClient
{
    private const STORAGE_PREFIX = '/storage/v1';

    private ?string $bucket = null;

    public function __construct(
        private readonly SupabaseTransport $transport,
        private readonly SupabaseConfig $config,
        private readonly ?string $accessToken = null,
    ) {
    }

    /**
     * Set the active bucket for file operations.
     */
    public function from(string $bucket): self
    {
        $clone = clone $this;
        $clone->bucket = $bucket;
        return $clone;
    }

    // ─── Bucket Operations ───────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listBuckets(): array
    {
        $response = $this->transport->request(
            'GET',
            self::STORAGE_PREFIX . '/bucket',
            null,
            $this->headers(),
        );

        if ($response['status'] >= 400) {
            throw SupabaseStorageException::fromResponse($response, 'Failed to list buckets');
        }

        return is_array($response['data']) ? $response['data'] : [];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function createBucket(string $name, array $options = []): array
    {
        $body = array_merge(['name' => $name, 'id' => $name], $options);

        $response = $this->transport->request(
            'POST',
            self::STORAGE_PREFIX . '/bucket',
            $body,
            $this->headers(),
        );

        if ($response['status'] >= 400) {
            throw SupabaseStorageException::fromResponse($response, 'Failed to create bucket');
        }

        return is_array($response['data']) ? $response['data'] : [];
    }

    /**
     * Get a bucket by name.
     *
     * @return array<string, mixed>
     */
    public function getBucket(string $name): array
    {
        $response = $this->transport->request(
            'GET',
            self::STORAGE_PREFIX . '/bucket/' . rawurlencode($name),
            null,
            $this->headers(),
        );

        if ($response['status'] >= 400) {
            throw SupabaseStorageException::fromResponse($response, 'Failed to get bucket');
        }

        return is_array($response['data']) ? $response['data'] : [];
    }

    /**
     * Update a bucket's settings.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function updateBucket(string $name, array $options): array
    {
        $response = $this->transport->request(
            'PUT',
            self::STORAGE_PREFIX . '/bucket/' . rawurlencode($name),
            $options,
            $this->headers(),
        );

        if ($response['status'] >= 400) {
            throw SupabaseStorageException::fromResponse($response, 'Failed to update bucket');
        }

        return is_array($response['data']) ? $response['data'] : [];
    }

    public function deleteBucket(string $name): void
    {
        $response = $this->transport->request(
            'DELETE',
            self::STORAGE_PREFIX . '/bucket/' . rawurlencode($name),
            [],
            $this->headers(),
        );

        if ($response['status'] >= 400) {
            throw SupabaseStorageException::fromResponse($response, 'Failed to delete bucket');
        }
    }

    /**
     * Remove all files from a bucket without deleting the bucket itself.
     *
     * @return array<string, mixed>
     */
    public function emptyBucket(string $name): array
    {
        $response = $this->transport->request(
            'POST',
            self::STORAGE_PREFIX . '/bucket/' . rawurlencode($name) . '/empty',
            [],
            $this->headers(),
        );

        if ($response['status'] >= 400) {
            throw SupabaseStorageException::fromResponse($response, 'Failed to empty bucket');
        }

        return is_array($response['data']) ? $response['data'] : [];
    }

    /**
     * Ensure a bucket exists with the given options.
     *
     * Creates the bucket if it does not exist, or updates it if it does.
     * Uses Swoole coroutines when available for non-blocking I/O.
     *
     * @param array<string, mixed> $options
     */
    public function ensureBucket(string $name, array $options = []): void
    {
        try {
            $this->getBucket($name);
            // Bucket exists — update settings if options provided
            if ($options !== []) {
                $this->updateBucket($name, $options);
            }
        } catch (SupabaseStorageException $e) {
            if ($e->getCode() === 404 || str_contains($e->getMessage(), 'not found')) {
                $this->createBucket($name, $options);
            } else {
                throw $e;
            }
        }
    }

    // ─── File Operations ─────────────────────────────────────────────

    /**
     * Upload a file to the active bucket.
     *
     * @return array<string, mixed>
     */
    public function upload(string $path, string $fileContents, string $contentType = 'application/octet-stream'): array
    {
        $this->requireBucket();

        $headers = $this->headers();
        $headers['Content-Type'] = $contentType;
        unset($headers['Accept']);

        $response = $this->transport->requestRaw(
            'POST',
            self::STORAGE_PREFIX . '/object/' . rawurlencode($this->bucket ?? '') . '/' . $this->encodePath($path),
            $fileContents,
            $headers,
        );

        if ($response['status'] >= 400) {
            throw SupabaseStorageException::fromResponse($response, 'Failed to upload file');
        }

        return is_array($response['data']) ? $response['data'] : [];
    }

    /**
     * Download a file from the active bucket.
     */
    public function download(string $path): string
    {
        $this->requireBucket();

        $response = $this->transport->request(
            'GET',
            self::STORAGE_PREFIX . '/object/' . rawurlencode($this->bucket ?? '') . '/' . $this->encodePath($path),
            null,
            $this->headers(),
        );

        if ($response['status'] >= 400) {
            throw SupabaseStorageException::fromResponse($response, 'Failed to download file');
        }

        return $response['body'];
    }

    /**
     * Delete files from the active bucket.
     *
     * @param array<int, string> $paths
     * @return array<int, array<string, mixed>>
     */
    public function delete(array $paths): array
    {
        $this->requireBucket();

        $response = $this->transport->request(
            'DELETE',
            self::STORAGE_PREFIX . '/object/' . rawurlencode($this->bucket ?? ''),
            ['prefixes' => $paths],
            $this->headers(),
        );

        if ($response['status'] >= 400) {
            throw SupabaseStorageException::fromResponse($response, 'Failed to delete files');
        }

        return is_array($response['data']) ? $response['data'] : [];
    }

    /**
     * Copy a file within the active bucket.
     *
     * @return array<string, mixed>
     */
    public function copy(string $fromPath, string $toPath): array
    {
        $this->requireBucket();

        $response = $this->transport->request(
            'POST',
            self::STORAGE_PREFIX . '/object/copy',
            [
                'bucketId' => $this->bucket,
                'sourceKey' => $fromPath,
                'destinationKey' => $toPath,
            ],
            $this->headers(),
        );

        if ($response['status'] >= 400) {
            throw SupabaseStorageException::fromResponse($response, 'Failed to copy file');
        }

        return is_array($response['data']) ? $response['data'] : [];
    }

    /**
     * Move a file within the active bucket.
     *
     * @return array<string, mixed>
     */
    public function move(string $fromPath, string $toPath): array
    {
        $this->requireBucket();

        $response = $this->transport->request(
            'POST',
            self::STORAGE_PREFIX . '/object/move',
            [
                'bucketId' => $this->bucket,
                'sourceKey' => $fromPath,
                'destinationKey' => $toPath,
            ],
            $this->headers(),
        );

        if ($response['status'] >= 400) {
            throw SupabaseStorageException::fromResponse($response, 'Failed to move file');
        }

        return is_array($response['data']) ? $response['data'] : [];
    }

    /**
     * List files in the active bucket.
     *
     * @param array<string, mixed> $options
     * @return array<int, array<string, mixed>>
     */
    public function list(string $prefix = '', array $options = []): array
    {
        $this->requireBucket();

        $body = array_merge(['prefix' => $prefix], $options);

        $response = $this->transport->request(
            'POST',
            self::STORAGE_PREFIX . '/object/list/' . rawurlencode($this->bucket ?? ''),
            $body,
            $this->headers(),
        );

        if ($response['status'] >= 400) {
            throw SupabaseStorageException::fromResponse($response, 'Failed to list files');
        }

        return is_array($response['data']) ? $response['data'] : [];
    }

    // ─── URL Helpers ─────────────────────────────────────────────────

    /**
     * Get a public URL for a file.
     */
    public function publicUrl(string $path): string
    {
        $this->requireBucket();

        return $this->config->url
            . self::STORAGE_PREFIX
            . '/object/public/'
            . rawurlencode($this->bucket ?? '')
            . '/' . $this->encodePath($path);
    }

    /**
     * Create a signed URL for temporary access.
     *
     * @return array<string, mixed>
     */
    public function createSignedUrl(string $path, int $expiresIn): array
    {
        $this->requireBucket();

        $response = $this->transport->request(
            'POST',
            self::STORAGE_PREFIX . '/object/sign/' . rawurlencode($this->bucket ?? '') . '/' . $this->encodePath($path),
            ['expiresIn' => $expiresIn],
            $this->headers(),
        );

        if ($response['status'] >= 400) {
            throw SupabaseStorageException::fromResponse($response, 'Failed to create signed URL');
        }

        $data = is_array($response['data']) ? $response['data'] : [];
        if (isset($data['signedURL'])) {
            $data['signedURL'] = $this->config->url . $data['signedURL'];
        }

        return $data;
    }

    /**
     * Create a signed upload URL.
     *
     * @return array<string, mixed>
     */
    public function createSignedUploadUrl(string $path): array
    {
        $this->requireBucket();

        $response = $this->transport->request(
            'POST',
            self::STORAGE_PREFIX . '/object/upload/sign/' . rawurlencode($this->bucket ?? '') . '/' . $this->encodePath($path),
            [],
            $this->headers(),
        );

        if ($response['status'] >= 400) {
            throw SupabaseStorageException::fromResponse($response, 'Failed to create signed upload URL');
        }

        return is_array($response['data']) ? $response['data'] : [];
    }

    /**
     * Create signed URLs for multiple files in batch.
     *
     * @param array<int, string> $paths
     * @return array<int, array<string, mixed>>
     */
    public function createSignedUrls(array $paths, int $expiresIn): array
    {
        $this->requireBucket();

        $response = $this->transport->request(
            'POST',
            self::STORAGE_PREFIX . '/object/sign/' . rawurlencode($this->bucket ?? ''),
            ['expiresIn' => $expiresIn, 'paths' => $paths],
            $this->headers(),
        );

        if ($response['status'] >= 400) {
            throw SupabaseStorageException::fromResponse($response, 'Failed to create signed URLs');
        }

        $data = is_array($response['data']) ? $response['data'] : [];

        // Prepend base URL to each signedURL
        foreach ($data as &$item) {
            if (isset($item['signedURL'])) {
                $item['signedURL'] = $this->config->url . $item['signedURL'];
            }
        }

        return $data;
    }

    /**
     * Upload a file using a previously created signed upload URL.
     *
     * @return array<string, mixed>
     */
    public function uploadToSignedUrl(string $signedUrl, string $fileContents, string $contentType = 'application/octet-stream'): array
    {
        $headers = $this->headers();
        $headers['Content-Type'] = $contentType;
        unset($headers['Accept']);

        // Extract path from the full signed URL
        $path = parse_url($signedUrl, PHP_URL_PATH);
        $query = parse_url($signedUrl, PHP_URL_QUERY);
        if ($path === false || $path === null) {
            throw new SupabaseStorageException('Invalid signed upload URL', 400);
        }
        $fullPath = ($query !== null && $query !== false && $query !== '') ? $path . '?' . $query : $path;

        $response = $this->transport->requestRaw(
            'PUT',
            $fullPath,
            $fileContents,
            $headers,
        );

        if ($response['status'] >= 400) {
            throw SupabaseStorageException::fromResponse($response, 'Failed to upload to signed URL');
        }

        return is_array($response['data']) ? $response['data'] : [];
    }

    /**
     * Get the public URL for a file (alias matching supabase-js getPublicUrl).
     */
    public function getPublicUrl(string $path): string
    {
        return $this->publicUrl($path);
    }

    /**
     * Delete files (alias matching supabase-js remove()).
     *
     * @param array<int, string> $paths
     * @return array<int, array<string, mixed>>
     */
    public function remove(array $paths): array
    {
        return $this->delete($paths);
    }

    // ─── Internals ───────────────────────────────────────────────────

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return $this->config->headers($this->accessToken);
    }

    private function requireBucket(): void
    {
        if ($this->bucket === null || $this->bucket === '') {
            throw new SupabaseStorageException('No bucket selected. Call from() first.', 400);
        }
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
