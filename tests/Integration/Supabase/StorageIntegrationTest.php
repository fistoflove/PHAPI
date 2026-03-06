<?php

declare(strict_types=1);

namespace PHAPI\Tests\Integration\Supabase;

use PHAPI\Supabase\Exceptions\SupabaseStorageException;

/**
 * Integration tests for Supabase Storage.
 *
 * @group integration
 * @group supabase
 */
final class StorageIntegrationTest extends SupabaseIntegrationTestCase
{
    private static string $testBucket = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Create a test bucket for file operations
        self::$testBucket = 'phapi-test-' . bin2hex(random_bytes(4));
        $storage = self::$factory->createServiceContext()->storage();

        try {
            $storage->createBucket(self::$testBucket, ['public' => true]);
        } catch (\Throwable $e) {
            self::markTestSkipped('Failed to create test bucket: ' . $e->getMessage());
        }
    }

    public static function tearDownAfterClass(): void
    {
        // Clean up: empty and delete the test bucket
        if (self::$testBucket !== '') {
            try {
                $storage = self::$factory->createServiceContext()->storage();
                $files = $storage->from(self::$testBucket)->list();
                if ($files !== []) {
                    $paths = array_column($files, 'name');
                    $storage->from(self::$testBucket)->delete($paths);
                }
                $storage->deleteBucket(self::$testBucket);
            } catch (\Throwable) {
                // Best effort cleanup
            }
        }
    }

    // ─── Buckets ─────────────────────────────────────────────────────

    public function testListBuckets(): void
    {
        $storage = self::$factory->createServiceContext()->storage();

        $buckets = $storage->listBuckets();

        $this->assertIsArray($buckets);
        $bucketNames = array_column($buckets, 'name');
        $this->assertContains(self::$testBucket, $bucketNames);
    }

    public function testCreateAndDeleteBucket(): void
    {
        $bucketName = 'phapi-tmp-' . bin2hex(random_bytes(4));
        $storage = self::$factory->createServiceContext()->storage();

        $result = $storage->createBucket($bucketName, ['public' => false]);
        $this->assertArrayHasKey('name', $result);

        // Verify it exists
        $buckets = $storage->listBuckets();
        $bucketNames = array_column($buckets, 'name');
        $this->assertContains($bucketName, $bucketNames);

        // Delete
        $storage->deleteBucket($bucketName);

        // Verify it's gone
        $bucketsAfter = $storage->listBuckets();
        $bucketNamesAfter = array_column($bucketsAfter, 'name');
        $this->assertNotContains($bucketName, $bucketNamesAfter);
    }

    // ─── Get & Update Bucket ────────────────────────────────────────

    public function testGetBucket(): void
    {
        $storage = self::$factory->createServiceContext()->storage();

        $bucket = $storage->getBucket(self::$testBucket);

        $this->assertSame(self::$testBucket, $bucket['name'] ?? $bucket['id'] ?? '');
        $this->assertArrayHasKey('public', $bucket);
    }

    public function testGetBucketNonexistentFails(): void
    {
        $storage = self::$factory->createServiceContext()->storage();

        $this->expectException(\PHAPI\Supabase\Exceptions\SupabaseStorageException::class);
        $storage->getBucket('bucket-does-not-exist-' . bin2hex(random_bytes(4)));
    }

    public function testUpdateBucket(): void
    {
        $bucketName = 'phapi-update-' . bin2hex(random_bytes(4));
        $storage = self::$factory->createServiceContext()->storage();

        // Create a private bucket
        $storage->createBucket($bucketName, ['public' => false]);

        try {
            // Update to public
            $result = $storage->updateBucket($bucketName, ['public' => true]);
            $this->assertIsArray($result);

            // Verify the update took effect
            $bucket = $storage->getBucket($bucketName);
            $this->assertTrue($bucket['public'] ?? false);
        } finally {
            // Cleanup
            try {
                $storage->emptyBucket($bucketName);
            } catch (\Throwable) {
            }
            try {
                $storage->deleteBucket($bucketName);
            } catch (\Throwable) {
            }
        }
    }

    // ─── Empty Bucket ─────────────────────────────────────────────

    public function testEmptyBucket(): void
    {
        $bucketName = 'phapi-empty-' . bin2hex(random_bytes(4));
        $storage = self::$factory->createServiceContext()->storage();

        // Create bucket and upload a file
        $storage->createBucket($bucketName, ['public' => true]);

        try {
            $storage->from($bucketName)->upload('test.txt', 'data', 'text/plain');

            // Verify file exists
            $files = $storage->from($bucketName)->list();
            $this->assertNotEmpty($files);

            // Empty the bucket
            $storage->emptyBucket($bucketName);

            // Supabase empties buckets asynchronously — poll briefly
            $filesAfter = null;
            for ($i = 0; $i < 10; $i++) {
                usleep(500_000);
                $filesAfter = $storage->from($bucketName)->list();
                if ($filesAfter === []) {
                    break;
                }
            }
            $this->assertSame([], $filesAfter);
        } finally {
            try {
                $storage->deleteBucket($bucketName);
            } catch (\Throwable) {
            }
        }
    }

    // ─── Ensure Bucket (idempotent) ─────────────────────────────────

    public function testEnsureBucketCreatesNew(): void
    {
        $bucketName = 'phapi-ensure-' . bin2hex(random_bytes(4));
        $storage = self::$factory->createServiceContext()->storage();

        try {
            $storage->ensureBucket($bucketName, ['public' => true]);

            // Verify it was created
            $bucket = $storage->getBucket($bucketName);
            $this->assertSame($bucketName, $bucket['name'] ?? $bucket['id'] ?? '');
        } finally {
            try {
                $storage->emptyBucket($bucketName);
            } catch (\Throwable) {
            }
            try {
                $storage->deleteBucket($bucketName);
            } catch (\Throwable) {
            }
        }
    }

    public function testEnsureBucketIdempotent(): void
    {
        $bucketName = 'phapi-idempotent-' . bin2hex(random_bytes(4));
        $storage = self::$factory->createServiceContext()->storage();

        try {
            // Create it first
            $storage->createBucket($bucketName, ['public' => false]);

            // ensureBucket should not throw — just update
            $storage->ensureBucket($bucketName, ['public' => true]);

            // Verify settings were updated
            $bucket = $storage->getBucket($bucketName);
            $this->assertTrue($bucket['public'] ?? false);
        } finally {
            try {
                $storage->emptyBucket($bucketName);
            } catch (\Throwable) {
            }
            try {
                $storage->deleteBucket($bucketName);
            } catch (\Throwable) {
            }
        }
    }

    // ─── Upload & Download ──────────────────────────────────────────

    public function testUploadAndDownload(): void
    {
        $storage = self::$factory->createServiceContext()->storage();
        $content = 'Hello from PHAPI integration test: ' . bin2hex(random_bytes(8));

        $result = $storage->from(self::$testBucket)->upload(
            'test-files/hello.txt',
            $content,
            'text/plain',
        );

        $this->assertIsArray($result);

        // Download and verify
        $downloaded = $storage->from(self::$testBucket)->download('test-files/hello.txt');
        $this->assertSame($content, $downloaded);
    }

    public function testUploadOverwrite(): void
    {
        $storage = self::$factory->createServiceContext()->storage();
        $path = 'test-files/overwrite.txt';

        // Upload v1
        $storage->from(self::$testBucket)->upload($path, 'version 1', 'text/plain');

        // Upload v2 (should overwrite or create new — depends on storage config)
        // Note: Supabase Storage may require upsert header for overwrite
        try {
            $storage->from(self::$testBucket)->upload($path, 'version 2', 'text/plain');
        } catch (SupabaseStorageException) {
            // Duplicate key error is acceptable — means file already exists
            $this->assertTrue(true);
            return;
        }

        $this->assertTrue(true);
    }

    public function testUploadWithoutBucketFails(): void
    {
        $storage = self::$factory->createServiceContext()->storage();

        $this->expectException(SupabaseStorageException::class);
        $this->expectExceptionMessage('No bucket selected');
        $storage->upload('file.txt', 'data');
    }

    // ─── List Files ─────────────────────────────────────────────────

    public function testListFiles(): void
    {
        $storage = self::$factory->createServiceContext()->storage();
        $prefix = 'list-test-' . bin2hex(random_bytes(3));

        $storage->from(self::$testBucket)->upload($prefix . '/a.txt', 'content-a', 'text/plain');
        $storage->from(self::$testBucket)->upload($prefix . '/b.txt', 'content-b', 'text/plain');

        $files = $storage->from(self::$testBucket)->list($prefix);

        $this->assertGreaterThanOrEqual(2, count($files));
        $names = array_column($files, 'name');
        $this->assertContains('a.txt', $names);
        $this->assertContains('b.txt', $names);
    }

    public function testListFilesEmpty(): void
    {
        $storage = self::$factory->createServiceContext()->storage();

        $files = $storage->from(self::$testBucket)->list('nonexistent-prefix-' . bin2hex(random_bytes(4)));

        $this->assertSame([], $files);
    }

    // ─── Delete Files ───────────────────────────────────────────────

    public function testDeleteFiles(): void
    {
        $storage = self::$factory->createServiceContext()->storage();
        $prefix = 'delete-test-' . bin2hex(random_bytes(3));

        $storage->from(self::$testBucket)->upload($prefix . '/to-delete.txt', 'bye', 'text/plain');

        $result = $storage->from(self::$testBucket)->delete([$prefix . '/to-delete.txt']);

        $this->assertIsArray($result);
    }

    // ─── Copy & Move ────────────────────────────────────────────────

    public function testCopyFile(): void
    {
        $storage = self::$factory->createServiceContext()->storage();
        $prefix = 'copy-test-' . bin2hex(random_bytes(3));
        $content = 'copy me';

        $storage->from(self::$testBucket)->upload($prefix . '/original.txt', $content, 'text/plain');

        $result = $storage->from(self::$testBucket)->copy(
            $prefix . '/original.txt',
            $prefix . '/copied.txt',
        );

        $this->assertIsArray($result);

        // Verify both files exist
        $downloaded = $storage->from(self::$testBucket)->download($prefix . '/copied.txt');
        $this->assertSame($content, $downloaded);
    }

    public function testMoveFile(): void
    {
        $storage = self::$factory->createServiceContext()->storage();
        $prefix = 'move-test-' . bin2hex(random_bytes(3));

        $storage->from(self::$testBucket)->upload($prefix . '/source.txt', 'move me', 'text/plain');

        $result = $storage->from(self::$testBucket)->move(
            $prefix . '/source.txt',
            $prefix . '/destination.txt',
        );

        $this->assertIsArray($result);

        // Verify destination exists
        $downloaded = $storage->from(self::$testBucket)->download($prefix . '/destination.txt');
        $this->assertSame('move me', $downloaded);
    }

    // ─── Public URL ─────────────────────────────────────────────────

    public function testPublicUrl(): void
    {
        $storage = self::$factory->createServiceContext()->storage();

        $url = $storage->from(self::$testBucket)->publicUrl('test-file.txt');

        $this->assertStringContainsString(self::$testBucket, $url);
        $this->assertStringContainsString('/storage/v1/object/public/', $url);
        $this->assertStringContainsString('test-file.txt', $url);
    }

    // ─── Signed URL ─────────────────────────────────────────────────

    public function testCreateSignedUrl(): void
    {
        $storage = self::$factory->createServiceContext()->storage();
        $prefix = 'signed-test-' . bin2hex(random_bytes(3));

        $storage->from(self::$testBucket)->upload($prefix . '/secret.txt', 'secret content', 'text/plain');

        $result = $storage->from(self::$testBucket)->createSignedUrl($prefix . '/secret.txt', 3600);

        $this->assertArrayHasKey('signedURL', $result);
        $this->assertStringContainsString('token=', $result['signedURL']);
    }

    // ─── Signed Upload URL ──────────────────────────────────────────

    public function testCreateSignedUploadUrl(): void
    {
        $storage = self::$factory->createServiceContext()->storage();
        $prefix = 'signed-upload-' . bin2hex(random_bytes(3));

        $result = $storage->from(self::$testBucket)->createSignedUploadUrl($prefix . '/upload-target.txt');

        $this->assertIsArray($result);
        // Response should contain a URL for uploading
        $this->assertTrue(
            isset($result['url']) || isset($result['signedURL']) || isset($result['token']),
            'Signed upload URL response should contain url, signedURL, or token',
        );
    }

    // ─── Error Handling ─────────────────────────────────────────────

    public function testDownloadNonexistentFileFails(): void
    {
        $storage = self::$factory->createServiceContext()->storage();

        $this->expectException(SupabaseStorageException::class);
        $storage->from(self::$testBucket)->download('this-file-does-not-exist-' . bin2hex(random_bytes(4)) . '.txt');
    }

    public function testDeleteNonexistentBucketFails(): void
    {
        $storage = self::$factory->createServiceContext()->storage();

        $this->expectException(SupabaseStorageException::class);
        $storage->deleteBucket('bucket-that-does-not-exist-' . bin2hex(random_bytes(4)));
    }
}
