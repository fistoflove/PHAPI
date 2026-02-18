<?php

declare(strict_types=1);

namespace PHAPI\Tests\Integration;

use PHAPI\Services\OpenFgaHttpClient;
use PHAPI\Services\SwooleHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for OpenFgaHttpClient against a real OpenFGA instance.
 *
 * Requires: OpenFGA running on http://localhost:8080 (docker compose up).
 *
 * @group integration
 * @group openfga
 */
final class OpenFgaClientIntegrationTest extends TestCase
{
    private static string $storeId = '';
    private static string $modelId = '';
    private static SwooleHttpClient $http;

    public static function setUpBeforeClass(): void
    {
        $apiUrl = getenv('OPENFGA_API_URL') ?: 'http://localhost:8080';

        // Check if OpenFGA is reachable
        $ch = curl_init($apiUrl . '/healthz');
        if ($ch === false) {
            self::markTestSkipped('curl not available');
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($result === false || $httpCode !== 200) {
            self::markTestSkipped('OpenFGA not available at ' . $apiUrl);
        }

        self::$http = new SwooleHttpClient();

        // Create a test store
        $storeResponse = self::fgaPost($apiUrl . '/stores', ['name' => 'phapi-integration-test']);
        self::$storeId = (string) ($storeResponse['id'] ?? '');

        if (self::$storeId === '') {
            self::markTestSkipped('Failed to create OpenFGA store');
        }

        // Write a test authorization model
        $modelResponse = self::fgaPost(
            $apiUrl . '/stores/' . self::$storeId . '/authorization-models',
            [
                'schema_version' => '1.1',
                'type_definitions' => [
                    [
                        'type' => 'user',
                    ],
                    [
                        'type' => 'org',
                        'relations' => [
                            'member' => [
                                'this' => (object) [],
                            ],
                        ],
                        'metadata' => [
                            'relations' => [
                                'member' => [
                                    'directly_related_user_types' => [
                                        ['type' => 'user'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    [
                        'type' => 'document',
                        'relations' => [
                            'viewer' => [
                                'this' => (object) [],
                            ],
                            'editor' => [
                                'this' => (object) [],
                            ],
                            'owner' => [
                                'this' => (object) [],
                            ],
                            'org' => [
                                'this' => (object) [],
                            ],
                            'org_viewer' => [
                                'computedUserset' => [
                                    'relation' => 'member',
                                    'object' => '',
                                ],
                                'tupleToUserset' => [
                                    'tupleset' => [
                                        'relation' => 'org',
                                    ],
                                    'computedUserset' => [
                                        'relation' => 'member',
                                    ],
                                ],
                            ],
                        ],
                        'metadata' => [
                            'relations' => [
                                'viewer' => [
                                    'directly_related_user_types' => [
                                        ['type' => 'user'],
                                    ],
                                ],
                                'editor' => [
                                    'directly_related_user_types' => [
                                        ['type' => 'user'],
                                    ],
                                ],
                                'owner' => [
                                    'directly_related_user_types' => [
                                        ['type' => 'user'],
                                    ],
                                ],
                                'org' => [
                                    'directly_related_user_types' => [
                                        ['type' => 'org'],
                                    ],
                                ],
                                'org_viewer' => [
                                    'directly_related_user_types' => [],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        );

        self::$modelId = (string) ($modelResponse['authorization_model_id'] ?? '');
    }

    private function createClient(): OpenFgaHttpClient
    {
        $apiUrl = getenv('OPENFGA_API_URL') ?: 'http://localhost:8080';

        return new OpenFgaHttpClient(
            [
                'api_url' => $apiUrl,
                'store_id' => self::$storeId,
                'model_id' => self::$modelId,
            ],
            self::$http,
        );
    }

    public function testWriteAndReadTuples(): void
    {
        $client = $this->createClient();

        $client->writeTuples([
            ['user' => 'user:alice', 'relation' => 'viewer', 'object' => 'document:readme'],
        ]);

        $tuples = $client->readTuples('user:alice', 'viewer', 'document:readme');

        $this->assertCount(1, $tuples);
        $this->assertSame('user:alice', $tuples[0]['user']);
        $this->assertSame('viewer', $tuples[0]['relation']);
        $this->assertSame('document:readme', $tuples[0]['object']);
    }

    public function testCheckWithDirectRelationship(): void
    {
        $client = $this->createClient();

        $client->writeTuples([
            ['user' => 'user:bob', 'relation' => 'editor', 'object' => 'document:spec'],
        ]);

        $this->assertTrue($client->check('user:bob', 'editor', 'document:spec'));
    }

    public function testCheckDenied(): void
    {
        $client = $this->createClient();

        $this->assertFalse($client->check('user:nobody', 'editor', 'document:spec'));
    }

    public function testBatchCheckMixed(): void
    {
        $client = $this->createClient();

        $client->writeTuples([
            ['user' => 'user:carol', 'relation' => 'owner', 'object' => 'document:design'],
        ]);

        $results = $client->batchCheck([
            [
                'user' => 'user:carol',
                'relation' => 'owner',
                'object' => 'document:design',
                'correlation_id' => 'allowed-check',
            ],
            [
                'user' => 'user:nobody',
                'relation' => 'owner',
                'object' => 'document:design',
                'correlation_id' => 'denied-check',
            ],
        ]);

        $this->assertTrue($results['allowed-check']);
        $this->assertFalse($results['denied-check']);
    }

    public function testListObjectsForUser(): void
    {
        $client = $this->createClient();

        $client->writeTuples([
            ['user' => 'user:dave', 'relation' => 'viewer', 'object' => 'document:plan'],
            ['user' => 'user:dave', 'relation' => 'viewer', 'object' => 'document:report'],
        ]);

        $objects = $client->listObjects('user:dave', 'viewer', 'document');

        $this->assertContains('document:plan', $objects);
        $this->assertContains('document:report', $objects);
    }

    public function testDeleteTuplesRemovesAccess(): void
    {
        $client = $this->createClient();

        $client->writeTuples([
            ['user' => 'user:eve', 'relation' => 'viewer', 'object' => 'document:temp'],
        ]);

        $this->assertTrue($client->check('user:eve', 'viewer', 'document:temp'));

        $client->deleteTuples([
            ['user' => 'user:eve', 'relation' => 'viewer', 'object' => 'document:temp'],
        ]);

        $this->assertFalse($client->check('user:eve', 'viewer', 'document:temp'));
    }

    public function testWriteAndReadAuthorizationModel(): void
    {
        $client = $this->createClient();

        $model = $client->readAuthorizationModel(self::$modelId);

        $this->assertNotEmpty($model);
    }

    public function testExpandRelationship(): void
    {
        $client = $this->createClient();

        $client->writeTuples([
            ['user' => 'user:frank', 'relation' => 'viewer', 'object' => 'document:notes'],
        ]);

        $tree = $client->expand('viewer', 'document:notes');

        $this->assertNotEmpty($tree);
    }

    public function testListUsersForObject(): void
    {
        $client = $this->createClient();

        $client->writeTuples([
            ['user' => 'user:grace', 'relation' => 'viewer', 'object' => 'document:shared'],
            ['user' => 'user:heidi', 'relation' => 'viewer', 'object' => 'document:shared'],
            ['user' => 'user:ivan', 'relation' => 'editor', 'object' => 'document:shared'],
        ]);

        $viewers = $client->listUsers('document:shared', 'viewer', 'user');

        $this->assertContains('user:grace', $viewers);
        $this->assertContains('user:heidi', $viewers);
        $this->assertNotContains('user:ivan', $viewers);
    }

    public function testCheckWithUsersetResolution(): void
    {
        $client = $this->createClient();

        // user:judy is member of org:acme, org:acme is the org of document:roadmap
        // → judy should have org_viewer on document:roadmap via tupleToUserset
        $client->writeTuples([
            ['user' => 'user:judy', 'relation' => 'member', 'object' => 'org:acme'],
            ['user' => 'org:acme', 'relation' => 'org', 'object' => 'document:roadmap'],
        ]);

        $this->assertTrue($client->check('user:judy', 'org_viewer', 'document:roadmap'));
        $this->assertFalse($client->check('user:nobody', 'org_viewer', 'document:roadmap'));
    }

    public function testWriteAuthorizationModelViaClient(): void
    {
        $client = $this->createClient();

        $modelId = $client->writeAuthorizationModel(
            [
                ['type' => 'user'],
                [
                    'type' => 'folder',
                    'relations' => [
                        'viewer' => ['this' => (object) []],
                    ],
                    'metadata' => [
                        'relations' => [
                            'viewer' => [
                                'directly_related_user_types' => [
                                    ['type' => 'user'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '1.1',
        );

        $this->assertNotEmpty($modelId);
        $this->assertIsString($modelId);
    }

    public function testReadLatestAuthorizationModel(): void
    {
        $client = $this->createClient();

        $model = $client->readAuthorizationModel(null);

        $this->assertNotEmpty($model);
        $this->assertArrayHasKey('id', $model);
    }

    public function testReadTuplesWithPartialFilters(): void
    {
        $client = $this->createClient();

        $client->writeTuples([
            ['user' => 'user:kate', 'relation' => 'viewer', 'object' => 'document:partial-test'],
            ['user' => 'user:kate', 'relation' => 'editor', 'object' => 'document:partial-test'],
        ]);

        // Filter by user only
        $byUser = $client->readTuples('user:kate', null, null);
        $this->assertGreaterThanOrEqual(2, count($byUser));

        // Filter by object only
        $byObject = $client->readTuples(null, null, 'document:partial-test');
        $this->assertGreaterThanOrEqual(2, count($byObject));

        // Filter by relation only
        $byRelation = $client->readTuples(null, 'viewer', null);
        $this->assertGreaterThanOrEqual(1, count($byRelation));
    }

    public function testBatchWriteAndDeleteMultipleTuples(): void
    {
        $client = $this->createClient();

        // Batch write 3 tuples at once
        $client->writeTuples([
            ['user' => 'user:liam', 'relation' => 'viewer', 'object' => 'document:batch1'],
            ['user' => 'user:liam', 'relation' => 'viewer', 'object' => 'document:batch2'],
            ['user' => 'user:liam', 'relation' => 'viewer', 'object' => 'document:batch3'],
        ]);

        $objects = $client->listObjects('user:liam', 'viewer', 'document');
        $this->assertContains('document:batch1', $objects);
        $this->assertContains('document:batch2', $objects);
        $this->assertContains('document:batch3', $objects);

        // Batch delete 2 of them
        $client->deleteTuples([
            ['user' => 'user:liam', 'relation' => 'viewer', 'object' => 'document:batch1'],
            ['user' => 'user:liam', 'relation' => 'viewer', 'object' => 'document:batch2'],
        ]);

        $this->assertFalse($client->check('user:liam', 'viewer', 'document:batch1'));
        $this->assertFalse($client->check('user:liam', 'viewer', 'document:batch2'));
        $this->assertTrue($client->check('user:liam', 'viewer', 'document:batch3'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function fgaPost(string $url, array $data): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            self::fail('curl_init failed');
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_THROW_ON_ERROR));
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        $body = curl_exec($ch);
        curl_close($ch);

        if (!is_string($body)) {
            return [];
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }
}
