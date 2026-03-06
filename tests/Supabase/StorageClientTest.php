<?php

declare(strict_types=1);

namespace PHAPI\Tests\Supabase;

use PHAPI\Supabase\Exceptions\SupabaseStorageException;
use PHAPI\Supabase\Storage\StorageClient;
use PHAPI\Supabase\SupabaseConfig;
use PHPUnit\Framework\TestCase;

final class StorageClientTest extends TestCase
{
    private FakeTransport $transport;
    private SupabaseConfig $config;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->config = new SupabaseConfig([
            'url' => 'https://test.supabase.co',
            'anon_key' => 'anon-key',
        ]);
    }

    private function client(): StorageClient
    {
        return new StorageClient($this->transport, $this->config, 'user-token');
    }

    // ─── Buckets ─────────────────────────────────────────────────────

    public function testListBuckets(): void
    {
        $this->transport->addResponse([
            'data' => [['id' => 'avatars', 'name' => 'avatars']],
            'status' => 200,
            'body' => '[]',
        ]);

        $result = $this->client()->listBuckets();

        $this->assertCount(1, $result);
        $this->assertSame('avatars', $result[0]['id']);
        $this->assertStringContainsString('/storage/v1/bucket', $this->transport->lastRequest()['path']);
    }

    public function testCreateBucket(): void
    {
        $this->transport->addResponse([
            'data' => ['name' => 'uploads'],
            'status' => 200,
            'body' => '{}',
        ]);

        $result = $this->client()->createBucket('uploads', ['public' => true]);

        $this->assertSame('uploads', $result['name']);
        $this->assertSame('POST', $this->transport->lastRequest()['method']);
    }

    public function testGetBucket(): void
    {
        $this->transport->addResponse([
            'data' => ['id' => 'avatars', 'name' => 'avatars', 'public' => true],
            'status' => 200,
            'body' => '{}',
        ]);

        $result = $this->client()->getBucket('avatars');

        $this->assertSame('avatars', $result['id']);
        $this->assertTrue($result['public']);
        $this->assertSame('GET', $this->transport->lastRequest()['method']);
        $this->assertStringContainsString('/storage/v1/bucket/avatars', $this->transport->lastRequest()['path']);
    }

    public function testGetBucketThrowsOnNotFound(): void
    {
        $this->transport->addResponse([
            'data' => ['message' => 'Bucket not found'],
            'status' => 404,
            'body' => '{}',
        ]);

        $this->expectException(SupabaseStorageException::class);
        $this->client()->getBucket('nonexistent');
    }

    public function testUpdateBucket(): void
    {
        $this->transport->addResponse([
            'data' => ['message' => 'Successfully updated'],
            'status' => 200,
            'body' => '{}',
        ]);

        $result = $this->client()->updateBucket('avatars', ['public' => false, 'file_size_limit' => 5242880]);

        $this->assertSame('PUT', $this->transport->lastRequest()['method']);
        $this->assertStringContainsString('/storage/v1/bucket/avatars', $this->transport->lastRequest()['path']);
        $this->assertFalse($this->transport->lastRequest()['body']['public']);
        $this->assertSame(5242880, $this->transport->lastRequest()['body']['file_size_limit']);
    }

    public function testDeleteBucket(): void
    {
        $this->transport->addResponse(['data' => null, 'status' => 200, 'body' => '']);

        $this->client()->deleteBucket('old-bucket');

        $this->assertSame('DELETE', $this->transport->lastRequest()['method']);
        $this->assertStringContainsString('/storage/v1/bucket/old-bucket', $this->transport->lastRequest()['path']);
    }

    public function testEmptyBucket(): void
    {
        $this->transport->addResponse([
            'data' => ['message' => 'Successfully emptied'],
            'status' => 200,
            'body' => '{}',
        ]);

        $result = $this->client()->emptyBucket('avatars');

        $this->assertSame('POST', $this->transport->lastRequest()['method']);
        $this->assertStringContainsString('/storage/v1/bucket/avatars/empty', $this->transport->lastRequest()['path']);
    }

    public function testEnsureBucketCreatesWhenNotFound(): void
    {
        // First call: getBucket returns 404
        $this->transport->addResponse([
            'data' => ['message' => 'Bucket not found'],
            'status' => 404,
            'body' => '{}',
        ]);
        // Second call: createBucket succeeds
        $this->transport->addResponse([
            'data' => ['name' => 'new-bucket'],
            'status' => 200,
            'body' => '{}',
        ]);

        $this->client()->ensureBucket('new-bucket', ['public' => true]);

        $requests = $this->transport->allRequests();
        $this->assertCount(2, $requests);
        $this->assertSame('GET', $requests[0]['method']); // getBucket
        $this->assertSame('POST', $requests[1]['method']); // createBucket
    }

    public function testEnsureBucketUpdatesWhenExists(): void
    {
        // First call: getBucket returns 200
        $this->transport->addResponse([
            'data' => ['id' => 'existing', 'name' => 'existing', 'public' => false],
            'status' => 200,
            'body' => '{}',
        ]);
        // Second call: updateBucket
        $this->transport->addResponse([
            'data' => ['message' => 'Successfully updated'],
            'status' => 200,
            'body' => '{}',
        ]);

        $this->client()->ensureBucket('existing', ['public' => true]);

        $requests = $this->transport->allRequests();
        $this->assertCount(2, $requests);
        $this->assertSame('GET', $requests[0]['method']); // getBucket
        $this->assertSame('PUT', $requests[1]['method']); // updateBucket
    }

    public function testEnsureBucketSkipsUpdateWhenNoOptions(): void
    {
        // getBucket returns 200 — no update needed
        $this->transport->addResponse([
            'data' => ['id' => 'existing', 'name' => 'existing'],
            'status' => 200,
            'body' => '{}',
        ]);

        $this->client()->ensureBucket('existing');

        $requests = $this->transport->allRequests();
        $this->assertCount(1, $requests); // Only getBucket, no update
    }

    // ─── Files ───────────────────────────────────────────────────────

    public function testUpload(): void
    {
        $this->transport->addResponse([
            'data' => ['Key' => 'avatars/user.png'],
            'status' => 200,
            'body' => '{}',
        ]);

        $result = $this->client()->from('avatars')->upload('user.png', 'file-contents', 'image/png');

        $this->assertSame('avatars/user.png', $result['Key']);
        $this->assertSame('POST', $this->transport->lastRequest()['method']);
        $this->assertStringContainsString('/storage/v1/object/avatars/user.png', $this->transport->lastRequest()['path']);
    }

    public function testUploadThrowsWithoutBucket(): void
    {
        $this->expectException(SupabaseStorageException::class);
        $this->expectExceptionMessage('No bucket selected');
        $this->client()->upload('file.txt', 'data');
    }

    public function testDownload(): void
    {
        $this->transport->addResponse([
            'data' => null,
            'status' => 200,
            'body' => 'binary-content',
        ]);

        $content = $this->client()->from('avatars')->download('user.png');

        $this->assertSame('binary-content', $content);
        $this->assertSame('GET', $this->transport->lastRequest()['method']);
    }

    public function testDeleteFiles(): void
    {
        $this->transport->addResponse([
            'data' => [['name' => 'file1.txt']],
            'status' => 200,
            'body' => '[]',
        ]);

        $result = $this->client()->from('docs')->delete(['file1.txt', 'file2.txt']);

        $this->assertCount(1, $result);
        $this->assertSame('DELETE', $this->transport->lastRequest()['method']);
        $this->assertSame(['file1.txt', 'file2.txt'], $this->transport->lastRequest()['body']['prefixes']);
    }

    public function testCopy(): void
    {
        $this->transport->addResponse(['data' => ['Key' => 'copy.txt'], 'status' => 200, 'body' => '{}']);

        $this->client()->from('docs')->copy('original.txt', 'copy.txt');

        $this->assertSame('POST', $this->transport->lastRequest()['method']);
        $this->assertStringContainsString('/storage/v1/object/copy', $this->transport->lastRequest()['path']);
        $this->assertSame('docs', $this->transport->lastRequest()['body']['bucketId']);
    }

    public function testMove(): void
    {
        $this->transport->addResponse(['data' => ['Key' => 'new.txt'], 'status' => 200, 'body' => '{}']);

        $this->client()->from('docs')->move('old.txt', 'new.txt');

        $this->assertStringContainsString('/storage/v1/object/move', $this->transport->lastRequest()['path']);
    }

    public function testListFiles(): void
    {
        $this->transport->addResponse([
            'data' => [['name' => 'file1.txt'], ['name' => 'file2.txt']],
            'status' => 200,
            'body' => '[]',
        ]);

        $result = $this->client()->from('docs')->list('subfolder');

        $this->assertCount(2, $result);
        $this->assertSame('POST', $this->transport->lastRequest()['method']);
        $this->assertSame('subfolder', $this->transport->lastRequest()['body']['prefix']);
    }

    // ─── URL Helpers ─────────────────────────────────────────────────

    public function testPublicUrl(): void
    {
        $url = $this->client()->from('avatars')->publicUrl('user.png');

        $this->assertSame(
            'https://test.supabase.co/storage/v1/object/public/avatars/user.png',
            $url
        );
    }

    public function testCreateSignedUrl(): void
    {
        $this->transport->addResponse([
            'data' => ['signedURL' => '/storage/v1/object/sign/avatars/user.png?token=abc'],
            'status' => 200,
            'body' => '{}',
        ]);

        $result = $this->client()->from('avatars')->createSignedUrl('user.png', 3600);

        $this->assertStringStartsWith('https://test.supabase.co/storage/v1', $result['signedURL']);
    }

    public function testCreateSignedUploadUrl(): void
    {
        $this->transport->addResponse([
            'data' => ['url' => '/storage/v1/upload/sign/avatars/new.png'],
            'status' => 200,
            'body' => '{}',
        ]);

        $result = $this->client()->from('avatars')->createSignedUploadUrl('new.png');

        $this->assertArrayHasKey('url', $result);
    }

    // ─── Batch Signed URLs ────────────────────────────────────────────

    public function testCreateSignedUrls(): void
    {
        $this->transport->addResponse([
            'data' => [
                ['signedURL' => '/storage/v1/object/sign/docs/a.txt?token=t1'],
                ['signedURL' => '/storage/v1/object/sign/docs/b.txt?token=t2'],
            ],
            'status' => 200,
            'body' => '[]',
        ]);

        $result = $this->client()->from('docs')->createSignedUrls(['a.txt', 'b.txt'], 3600);

        $this->assertCount(2, $result);
        $this->assertStringStartsWith('https://test.supabase.co/storage/v1', $result[0]['signedURL']);
        $this->assertStringStartsWith('https://test.supabase.co/storage/v1', $result[1]['signedURL']);
        $this->assertSame('POST', $this->transport->lastRequest()['method']);
        $this->assertSame(['a.txt', 'b.txt'], $this->transport->lastRequest()['body']['paths']);
        $this->assertSame(3600, $this->transport->lastRequest()['body']['expiresIn']);
    }

    public function testUploadToSignedUrl(): void
    {
        $this->transport->addResponse([
            'data' => ['Key' => 'docs/file.txt'],
            'status' => 200,
            'body' => '{}',
        ]);

        $result = $this->client()->uploadToSignedUrl(
            'https://test.supabase.co/storage/v1/object/upload/sign/docs/file.txt?token=abc',
            'file content',
            'text/plain',
        );

        $this->assertSame('docs/file.txt', $result['Key']);
        $this->assertSame('PUT', $this->transport->lastRequest()['method']);
    }

    public function testGetPublicUrlAlias(): void
    {
        $url = $this->client()->from('avatars')->getPublicUrl('user.png');
        $this->assertSame(
            'https://test.supabase.co/storage/v1/object/public/avatars/user.png',
            $url
        );
    }

    public function testRemoveAlias(): void
    {
        $this->transport->addResponse([
            'data' => [['name' => 'file1.txt']],
            'status' => 200,
            'body' => '[]',
        ]);

        $this->client()->from('docs')->remove(['file1.txt']);

        $this->assertSame('DELETE', $this->transport->lastRequest()['method']);
    }

    // ─── Errors ──────────────────────────────────────────────────────

    public function testUploadThrowsOnError(): void
    {
        $this->transport->addResponse([
            'data' => ['message' => 'Bucket not found'],
            'status' => 404,
            'body' => '{}',
        ]);

        $this->expectException(SupabaseStorageException::class);
        $this->client()->from('nonexistent')->upload('file.txt', 'data');
    }
}
