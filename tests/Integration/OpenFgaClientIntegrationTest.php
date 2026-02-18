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
