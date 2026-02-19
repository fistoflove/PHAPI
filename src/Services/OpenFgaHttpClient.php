<?php

declare(strict_types=1);

namespace PHAPI\Services;

use PHAPI\Exceptions\OpenFgaException;

final class OpenFgaHttpClient implements OpenFgaClient
{
    private readonly string $baseUrl;

    /** @var array<string, string> */
    private readonly array $headers;

    private readonly string $modelId;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        array $config,
        private readonly HttpClient $http,
    ) {
        $apiUrl = rtrim((string) ($config['api_url'] ?? 'http://localhost:8080'), '/');
        $storeId = (string) ($config['store_id'] ?? '');
        $this->modelId = (string) ($config['model_id'] ?? '');
        $apiToken = (string) ($config['api_token'] ?? '');

        $this->baseUrl = $storeId !== '' ? $apiUrl . '/stores/' . $storeId : $apiUrl;

        $headers = [];
        if ($apiToken !== '') {
            $headers['Authorization'] = 'Bearer ' . $apiToken;
        }
        $this->headers = $headers;
    }

    public function check(string $user, string $relation, string $object): bool
    {
        $payload = [
            'tuple_key' => [
                'user' => $user,
                'relation' => $relation,
                'object' => $object,
            ],
        ];

        if ($this->modelId !== '') {
            $payload['authorization_model_id'] = $this->modelId;
        }

        $response = $this->post('/check', $payload);

        return (bool) ($response['allowed'] ?? false);
    }

    public function batchCheck(array $checks): array
    {
        $items = [];
        foreach ($checks as $check) {
            $items[] = [
                'tuple_key' => [
                    'user' => $check['user'],
                    'relation' => $check['relation'],
                    'object' => $check['object'],
                ],
                'correlation_id' => $check['correlation_id'],
            ];
        }

        $payload = ['checks' => $items];

        if ($this->modelId !== '') {
            $payload['authorization_model_id'] = $this->modelId;
        }

        $response = $this->post('/batch-check', $payload);

        $results = [];
        foreach ($response['result'] ?? [] as $correlationId => $result) {
            $results[$correlationId] = (bool) ($result['allowed'] ?? false);
        }

        return $results;
    }

    public function writeTuples(array $writes): void
    {
        $tuples = [];
        foreach ($writes as $tuple) {
            $tuples[] = [
                'user' => $tuple['user'],
                'relation' => $tuple['relation'],
                'object' => $tuple['object'],
            ];
        }

        $payload = [
            'writes' => ['tuple_keys' => $tuples],
        ];

        if ($this->modelId !== '') {
            $payload['authorization_model_id'] = $this->modelId;
        }

        $this->post('/write', $payload);
    }

    public function deleteTuples(array $deletes): void
    {
        $tuples = [];
        foreach ($deletes as $tuple) {
            $tuples[] = [
                'user' => $tuple['user'],
                'relation' => $tuple['relation'],
                'object' => $tuple['object'],
            ];
        }

        $payload = [
            'deletes' => ['tuple_keys' => $tuples],
        ];

        if ($this->modelId !== '') {
            $payload['authorization_model_id'] = $this->modelId;
        }

        $this->post('/write', $payload);
    }

    public function readTuples(?string $user, ?string $relation, ?string $object): array
    {
        // OpenFGA requires tuple_key.object to include at least the type when
        // tuple_key is present.  When the caller omits object we must drop
        // tuple_key entirely (returns all tuples) and filter client-side.
        $needsClientFilter = false;
        $payload = [];

        if ($object !== null) {
            // object is provided → we can build a valid tuple_key
            $tupleKey = ['object' => $object];
            if ($user !== null) {
                $tupleKey['user'] = $user;
            }
            if ($relation !== null) {
                $tupleKey['relation'] = $relation;
            }
            $payload['tuple_key'] = $tupleKey;
        } else {
            // No object → omit tuple_key and filter after fetch
            $needsClientFilter = ($user !== null || $relation !== null);
        }

        $response = $this->post('/read', $payload);

        $tuples = [];
        foreach ($response['tuples'] ?? [] as $entry) {
            $key = $entry['key'] ?? [];
            $tupleUser = (string) ($key['user'] ?? '');
            $tupleRelation = (string) ($key['relation'] ?? '');
            $tupleObject = (string) ($key['object'] ?? '');

            if ($needsClientFilter) {
                if ($user !== null && $tupleUser !== $user) {
                    continue;
                }
                if ($relation !== null && $tupleRelation !== $relation) {
                    continue;
                }
            }

            $tuples[] = [
                'user' => $tupleUser,
                'relation' => $tupleRelation,
                'object' => $tupleObject,
            ];
        }

        return $tuples;
    }

    public function listObjects(string $user, string $relation, string $type): array
    {
        $payload = [
            'user' => $user,
            'relation' => $relation,
            'type' => $type,
        ];

        if ($this->modelId !== '') {
            $payload['authorization_model_id'] = $this->modelId;
        }

        $response = $this->post('/list-objects', $payload);

        return $response['objects'] ?? [];
    }

    public function listUsers(string $object, string $relation, string $userType): array
    {
        [$objectType, $objectId] = explode(':', $object, 2) + ['', ''];

        $payload = [
            'object' => [
                'type' => $objectType,
                'id' => $objectId,
            ],
            'relation' => $relation,
            'user_filters' => [
                ['type' => $userType],
            ],
        ];

        if ($this->modelId !== '') {
            $payload['authorization_model_id'] = $this->modelId;
        }

        $response = $this->post('/list-users', $payload);

        $users = [];
        foreach ($response['users'] ?? [] as $entry) {
            $obj = $entry['object'] ?? null;
            if (is_array($obj) && isset($obj['type'], $obj['id'])) {
                $users[] = $obj['type'] . ':' . $obj['id'];
            }
        }

        return $users;
    }

    public function expand(string $relation, string $object): array
    {
        $payload = [
            'tuple_key' => [
                'relation' => $relation,
                'object' => $object,
            ],
        ];

        if ($this->modelId !== '') {
            $payload['authorization_model_id'] = $this->modelId;
        }

        $response = $this->post('/expand', $payload);

        return $response['tree'] ?? [];
    }

    public function writeAuthorizationModel(array $typeDefinitions, string $schemaVersion): string
    {
        $payload = [
            'type_definitions' => $typeDefinitions,
            'schema_version' => $schemaVersion,
        ];

        $response = $this->post('/authorization-models', $payload);

        return (string) ($response['authorization_model_id'] ?? '');
    }

    public function readAuthorizationModel(?string $id = null): array
    {
        if ($id !== null) {
            return $this->get('/authorization-models/' . $id);
        }

        $response = $this->get('/authorization-models');

        return $response['authorization_models'][0] ?? [];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     *
     * @throws OpenFgaException
     */
    private function post(string $path, array $data): array
    {
        $url = $this->baseUrl . $path;

        try {
            $meta = $this->http->postJsonWithMeta($url, $data, $this->headers);
        } catch (\Throwable $e) {
            throw new OpenFgaException(
                'transport_error',
                'OpenFGA request failed: ' . $e->getMessage(),
                0,
            );
        }

        if ($meta['status'] < 200 || $meta['status'] >= 300) {
            $this->throwFromResponse($meta['status'], $meta['data']);
        }

        return $meta['data'] ?? [];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws OpenFgaException
     */
    private function get(string $path): array
    {
        $url = $this->baseUrl . $path;

        try {
            $meta = $this->http->getJsonWithMeta($url, $this->headers);
        } catch (\Throwable $e) {
            throw new OpenFgaException(
                'transport_error',
                'OpenFGA request failed: ' . $e->getMessage(),
                0,
            );
        }

        if ($meta['status'] < 200 || $meta['status'] >= 300) {
            $this->throwFromResponse($meta['status'], $meta['data']);
        }

        return $meta['data'] ?? [];
    }

    /**
     * @param int $httpStatus
     * @param array<string, mixed>|null $data
     *
     * @throws OpenFgaException
     */
    private function throwFromResponse(int $httpStatus, ?array $data): never
    {
        $code = (string) ($data['code'] ?? 'unknown_error');
        $message = (string) ($data['message'] ?? 'OpenFGA returned HTTP ' . $httpStatus);

        throw new OpenFgaException($code, $message, $httpStatus);
    }
}
