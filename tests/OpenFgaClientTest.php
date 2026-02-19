<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\Exceptions\OpenFgaException;
use PHAPI\Services\HttpClient;
use PHAPI\Services\OpenFgaHttpClient;
use PHPUnit\Framework\TestCase;

final class OpenFgaClientTest extends TestCase
{
    public function testCheckReturnsTrueWhenAllowed(): void
    {
        $mock = new MockHttpClient();
        $mock->postJsonWithMetaReturn = ['data' => ['allowed' => true], 'status' => 200, 'body' => ''];

        $client = new OpenFgaHttpClient(['api_url' => 'http://fga:8080', 'store_id' => 's1'], $mock);

        $this->assertTrue($client->check('user:anne', 'viewer', 'document:budget'));
    }

    public function testCheckReturnsFalseWhenDenied(): void
    {
        $mock = new MockHttpClient();
        $mock->postJsonWithMetaReturn = ['data' => ['allowed' => false], 'status' => 200, 'body' => ''];

        $client = new OpenFgaHttpClient(['api_url' => 'http://fga:8080', 'store_id' => 's1'], $mock);

        $this->assertFalse($client->check('user:anne', 'viewer', 'document:budget'));
    }

    public function testCheckSendsCorrectPayload(): void
    {
        $mock = new MockHttpClient();
        $mock->postJsonWithMetaReturn = ['data' => ['allowed' => true], 'status' => 200, 'body' => ''];

        $client = new OpenFgaHttpClient(['api_url' => 'http://fga:8080', 'store_id' => 's1'], $mock);
        $client->check('user:anne', 'viewer', 'document:budget');

        $this->assertSame('http://fga:8080/stores/s1/check', $mock->lastPostUrl);
        $this->assertSame([
            'tuple_key' => [
                'user' => 'user:anne',
                'relation' => 'viewer',
                'object' => 'document:budget',
            ],
        ], $mock->lastPostData);
    }

    public function testCheckIncludesModelIdWhenConfigured(): void
    {
        $mock = new MockHttpClient();
        $mock->postJsonWithMetaReturn = ['data' => ['allowed' => true], 'status' => 200, 'body' => ''];

        $client = new OpenFgaHttpClient([
            'api_url' => 'http://fga:8080',
            'store_id' => 's1',
            'model_id' => 'm1',
        ], $mock);
        $client->check('user:anne', 'viewer', 'document:budget');

        $this->assertSame('m1', $mock->lastPostData['authorization_model_id'] ?? null);
    }

    public function testCheckOmitsModelIdWhenEmpty(): void
    {
        $mock = new MockHttpClient();
        $mock->postJsonWithMetaReturn = ['data' => ['allowed' => true], 'status' => 200, 'body' => ''];

        $client = new OpenFgaHttpClient(['api_url' => 'http://fga:8080', 'store_id' => 's1'], $mock);
        $client->check('user:anne', 'viewer', 'document:budget');

        $this->assertArrayNotHasKey('authorization_model_id', $mock->lastPostData);
    }

    public function testBatchCheckMapsCorrelationIds(): void
    {
        $mock = new MockHttpClient();
        $mock->postJsonWithMetaReturn = [
            'data' => [
                'result' => [
                    'c1' => ['allowed' => true],
                    'c2' => ['allowed' => false],
                ],
            ],
            'status' => 200,
            'body' => '',
        ];

        $client = new OpenFgaHttpClient(['api_url' => 'http://fga:8080', 'store_id' => 's1'], $mock);
        $result = $client->batchCheck([
            ['user' => 'user:anne', 'relation' => 'viewer', 'object' => 'doc:1', 'correlation_id' => 'c1'],
            ['user' => 'user:bob', 'relation' => 'editor', 'object' => 'doc:2', 'correlation_id' => 'c2'],
        ]);

        $this->assertSame(['c1' => true, 'c2' => false], $result);
    }

    public function testWriteTuplesSendsCorrectPayload(): void
    {
        $mock = new MockHttpClient();
        $mock->postJsonWithMetaReturn = ['data' => [], 'status' => 200, 'body' => ''];

        $client = new OpenFgaHttpClient(['api_url' => 'http://fga:8080', 'store_id' => 's1'], $mock);
        $client->writeTuples([
            ['user' => 'user:anne', 'relation' => 'viewer', 'object' => 'doc:1'],
        ]);

        $this->assertSame('http://fga:8080/stores/s1/write', $mock->lastPostUrl);
        $this->assertSame([
            'writes' => [
                'tuple_keys' => [
                    ['user' => 'user:anne', 'relation' => 'viewer', 'object' => 'doc:1'],
                ],
            ],
        ], $mock->lastPostData);
    }

    public function testDeleteTuplesSendsCorrectPayload(): void
    {
        $mock = new MockHttpClient();
        $mock->postJsonWithMetaReturn = ['data' => [], 'status' => 200, 'body' => ''];

        $client = new OpenFgaHttpClient(['api_url' => 'http://fga:8080', 'store_id' => 's1'], $mock);
        $client->deleteTuples([
            ['user' => 'user:anne', 'relation' => 'viewer', 'object' => 'doc:1'],
        ]);

        $this->assertSame('http://fga:8080/stores/s1/write', $mock->lastPostUrl);
        $this->assertSame([
            'deletes' => [
                'tuple_keys' => [
                    ['user' => 'user:anne', 'relation' => 'viewer', 'object' => 'doc:1'],
                ],
            ],
        ], $mock->lastPostData);
    }

    public function testWriteTuplesThrowsOnApiError(): void
    {
        $mock = new MockHttpClient();
        $mock->postJsonWithMetaReturn = [
            'data' => ['code' => 'validation_error', 'message' => 'Invalid tuple'],
            'status' => 400,
            'body' => '',
        ];

        $client = new OpenFgaHttpClient(['api_url' => 'http://fga:8080', 'store_id' => 's1'], $mock);

        $this->expectException(OpenFgaException::class);
        $this->expectExceptionMessage('Invalid tuple');

        $client->writeTuples([
            ['user' => 'user:anne', 'relation' => 'viewer', 'object' => 'doc:1'],
        ]);
    }

    public function testReadTuplesFilters(): void
    {
        $mock = new MockHttpClient();
        $mock->postJsonWithMetaReturn = [
            'data' => [
                'tuples' => [
                    ['key' => ['user' => 'user:anne', 'relation' => 'viewer', 'object' => 'doc:1']],
                ],
            ],
            'status' => 200,
            'body' => '',
        ];

        $client = new OpenFgaHttpClient(['api_url' => 'http://fga:8080', 'store_id' => 's1'], $mock);
        $result = $client->readTuples('user:anne', null, 'doc:1');

        $this->assertSame([
            ['user' => 'user:anne', 'relation' => 'viewer', 'object' => 'doc:1'],
        ], $result);

        $this->assertSame([
            'tuple_key' => ['object' => 'doc:1', 'user' => 'user:anne'],
        ], $mock->lastPostData);
    }

    public function testReadTuplesWithNullObjectOmitsTupleKeyAndFiltersClientSide(): void
    {
        $mock = new MockHttpClient();
        $mock->postJsonWithMetaReturn = [
            'data' => [
                'tuples' => [
                    ['key' => ['user' => 'role:admin#member', 'relation' => 'granted', 'object' => 'global_permission:yard.manage']],
                    ['key' => ['user' => 'role:viewer#member', 'relation' => 'granted', 'object' => 'global_permission:yard.read']],
                    ['key' => ['user' => 'role:admin#member', 'relation' => 'granted', 'object' => 'app_permission:myapp:view']],
                ],
            ],
            'status' => 200,
            'body' => '',
        ];

        $client = new OpenFgaHttpClient(['api_url' => 'http://fga:8080', 'store_id' => 's1'], $mock);
        $result = $client->readTuples('role:admin#member', 'granted', null);

        // Should filter to only tuples matching user and relation
        $this->assertSame([
            ['user' => 'role:admin#member', 'relation' => 'granted', 'object' => 'global_permission:yard.manage'],
            ['user' => 'role:admin#member', 'relation' => 'granted', 'object' => 'app_permission:myapp:view'],
        ], $result);

        // tuple_key must be omitted when object is null
        $this->assertSame([], $mock->lastPostData);
    }

    public function testListObjectsReturnsObjectIds(): void
    {
        $mock = new MockHttpClient();
        $mock->postJsonWithMetaReturn = [
            'data' => ['objects' => ['document:budget', 'document:roadmap']],
            'status' => 200,
            'body' => '',
        ];

        $client = new OpenFgaHttpClient(['api_url' => 'http://fga:8080', 'store_id' => 's1'], $mock);
        $result = $client->listObjects('user:anne', 'viewer', 'document');

        $this->assertSame(['document:budget', 'document:roadmap'], $result);
    }

    public function testListUsersReturnsUserIds(): void
    {
        $mock = new MockHttpClient();
        $mock->postJsonWithMetaReturn = [
            'data' => [
                'users' => [
                    ['object' => ['type' => 'user', 'id' => 'anne']],
                    ['object' => ['type' => 'user', 'id' => 'bob']],
                ],
            ],
            'status' => 200,
            'body' => '',
        ];

        $client = new OpenFgaHttpClient(['api_url' => 'http://fga:8080', 'store_id' => 's1'], $mock);
        $result = $client->listUsers('document:budget', 'viewer', 'user');

        $this->assertSame(['user:anne', 'user:bob'], $result);
    }

    public function testExpandReturnsTree(): void
    {
        $mock = new MockHttpClient();
        $mock->postJsonWithMetaReturn = [
            'data' => ['tree' => ['root' => ['union' => ['nodes' => []]]]],
            'status' => 200,
            'body' => '',
        ];

        $client = new OpenFgaHttpClient(['api_url' => 'http://fga:8080', 'store_id' => 's1'], $mock);
        $result = $client->expand('viewer', 'document:budget');

        $this->assertSame(['root' => ['union' => ['nodes' => []]]], $result);
    }

    public function testWriteAuthorizationModelReturnsId(): void
    {
        $mock = new MockHttpClient();
        $mock->postJsonWithMetaReturn = [
            'data' => ['authorization_model_id' => 'model-abc'],
            'status' => 201,
            'body' => '',
        ];

        $client = new OpenFgaHttpClient(['api_url' => 'http://fga:8080', 'store_id' => 's1'], $mock);
        $result = $client->writeAuthorizationModel([['type' => 'document']], '1.1');

        $this->assertSame('model-abc', $result);
        $this->assertSame('http://fga:8080/stores/s1/authorization-models', $mock->lastPostUrl);
    }

    public function testReadAuthorizationModelUsesLatest(): void
    {
        $mock = new MockHttpClient();
        $mock->getJsonWithMetaReturn = [
            'data' => ['authorization_models' => [['id' => 'model-latest']]],
            'status' => 200,
            'body' => '',
        ];

        $client = new OpenFgaHttpClient(['api_url' => 'http://fga:8080', 'store_id' => 's1'], $mock);
        $result = $client->readAuthorizationModel(null);

        $this->assertSame('http://fga:8080/stores/s1/authorization-models', $mock->lastGetUrl);
        $this->assertSame(['id' => 'model-latest'], $result);
    }

    public function testReadAuthorizationModelUsesSpecificId(): void
    {
        $mock = new MockHttpClient();
        $mock->getJsonWithMetaReturn = [
            'data' => ['id' => 'model-abc', 'type_definitions' => []],
            'status' => 200,
            'body' => '',
        ];

        $client = new OpenFgaHttpClient(['api_url' => 'http://fga:8080', 'store_id' => 's1'], $mock);
        $result = $client->readAuthorizationModel('model-abc');

        $this->assertSame('http://fga:8080/stores/s1/authorization-models/model-abc', $mock->lastGetUrl);
        $this->assertSame(['id' => 'model-abc', 'type_definitions' => []], $result);
    }

    public function testAuthHeaderIncludedWhenTokenSet(): void
    {
        $mock = new MockHttpClient();
        $mock->postJsonWithMetaReturn = ['data' => ['allowed' => true], 'status' => 200, 'body' => ''];

        $client = new OpenFgaHttpClient([
            'api_url' => 'http://fga:8080',
            'store_id' => 's1',
            'api_token' => 'my-secret-token',
        ], $mock);
        $client->check('user:anne', 'viewer', 'doc:1');

        $this->assertSame('Bearer my-secret-token', $mock->lastPostHeaders['Authorization'] ?? null);
    }

    public function testNoAuthHeaderWhenTokenEmpty(): void
    {
        $mock = new MockHttpClient();
        $mock->postJsonWithMetaReturn = ['data' => ['allowed' => true], 'status' => 200, 'body' => ''];

        $client = new OpenFgaHttpClient(['api_url' => 'http://fga:8080', 'store_id' => 's1'], $mock);
        $client->check('user:anne', 'viewer', 'doc:1');

        $this->assertArrayNotHasKey('Authorization', $mock->lastPostHeaders);
    }

    public function testBaseUrlConstructedCorrectly(): void
    {
        $mock = new MockHttpClient();
        $mock->postJsonWithMetaReturn = ['data' => ['allowed' => true], 'status' => 200, 'body' => ''];

        $client = new OpenFgaHttpClient([
            'api_url' => 'http://openfga:8080/',
            'store_id' => 'store-123',
        ], $mock);
        $client->check('user:anne', 'viewer', 'doc:1');

        $this->assertSame('http://openfga:8080/stores/store-123/check', $mock->lastPostUrl);
    }

    public function testOpenFgaExceptionCarriesContext(): void
    {
        $mock = new MockHttpClient();
        $mock->postJsonWithMetaReturn = [
            'data' => ['code' => 'authorization_model_not_found', 'message' => 'Model not found'],
            'status' => 404,
            'body' => '',
        ];

        $client = new OpenFgaHttpClient(['api_url' => 'http://fga:8080', 'store_id' => 's1'], $mock);

        try {
            $client->check('user:anne', 'viewer', 'doc:1');
            $this->fail('Expected OpenFgaException');
        } catch (OpenFgaException $e) {
            $this->assertSame('authorization_model_not_found', $e->fgaCode());
            $this->assertSame('Model not found', $e->getMessage());
            $this->assertSame(404, $e->httpStatus());
        }
    }
}

/**
 * A minimal mock HttpClient that captures requests and returns canned responses.
 * No Swoole dependency required.
 */
class MockHttpClient implements HttpClient
{
    public ?string $lastGetUrl = null;
    /** @var array<string, string> */
    public array $lastGetHeaders = [];

    public ?string $lastPostUrl = null;
    /** @var array<string, mixed> */
    public array $lastPostData = [];
    /** @var array<string, string> */
    public array $lastPostHeaders = [];

    /** @var array{data: array<string, mixed>|null, status: int, body: string} */
    public array $getJsonWithMetaReturn = ['data' => null, 'status' => 200, 'body' => ''];

    /** @var array{data: array<string, mixed>|null, status: int, body: string} */
    public array $postJsonWithMetaReturn = ['data' => null, 'status' => 200, 'body' => ''];

    public function getJson(string $url, array $headers = []): array
    {
        $meta = $this->getJsonWithMeta($url, $headers);
        return $meta['data'] ?? [];
    }

    public function getJsonWithMeta(string $url, array $headers = []): array
    {
        $this->lastGetUrl = $url;
        $this->lastGetHeaders = $headers;
        return $this->getJsonWithMetaReturn;
    }

    public function postFormWithMeta(string $url, array $form, array $headers = []): array
    {
        return ['data' => null, 'status' => 200, 'body' => ''];
    }

    public function postJson(string $url, array $data, array $headers = []): array
    {
        $meta = $this->postJsonWithMeta($url, $data, $headers);
        return $meta['data'] ?? [];
    }

    public function postJsonWithMeta(string $url, array $data, array $headers = []): array
    {
        $this->lastPostUrl = $url;
        $this->lastPostData = $data;
        $this->lastPostHeaders = $headers;
        return $this->postJsonWithMetaReturn;
    }
}
