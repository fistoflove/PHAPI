<?php

declare(strict_types=1);

namespace PHAPI\Services;

interface OpenFgaClient
{
    /**
     * Check whether a user has a specific relation with an object.
     *
     * @param string $user  e.g. "user:anne"
     * @param string $relation  e.g. "viewer"
     * @param string $object  e.g. "document:budget"
     * @return bool
     *
     * @throws \PHAPI\Exceptions\OpenFgaException
     */
    public function check(string $user, string $relation, string $object): bool;

    /**
     * Batch-check multiple authorization tuples in a single request.
     *
     * Each entry must have keys: user, relation, object, and correlation_id.
     *
     * @param array<int, array{user: string, relation: string, object: string, correlation_id: string}> $checks
     * @return array<string, bool> Map of correlation_id => allowed
     *
     * @throws \PHAPI\Exceptions\OpenFgaException
     */
    public function batchCheck(array $checks): array;

    /**
     * Write relationship tuples.
     *
     * Each entry must have keys: user, relation, object.
     *
     * @param array<int, array{user: string, relation: string, object: string}> $writes
     * @return void
     *
     * @throws \PHAPI\Exceptions\OpenFgaException
     */
    public function writeTuples(array $writes): void;

    /**
     * Delete relationship tuples.
     *
     * Each entry must have keys: user, relation, object.
     *
     * @param array<int, array{user: string, relation: string, object: string}> $deletes
     * @return void
     *
     * @throws \PHAPI\Exceptions\OpenFgaException
     */
    public function deleteTuples(array $deletes): void;

    /**
     * Read stored tuples, optionally filtered by user, relation, and/or object.
     *
     * @param string|null $user
     * @param string|null $relation
     * @param string|null $object
     * @return array<int, array{user: string, relation: string, object: string}>
     *
     * @throws \PHAPI\Exceptions\OpenFgaException
     */
    public function readTuples(?string $user, ?string $relation, ?string $object): array;

    /**
     * List all objects of a given type that a user has a specific relation with.
     *
     * @param string $user  e.g. "user:anne"
     * @param string $relation  e.g. "viewer"
     * @param string $type  e.g. "document"
     * @return array<int, string> List of object IDs (e.g. ["document:budget", "document:roadmap"])
     *
     * @throws \PHAPI\Exceptions\OpenFgaException
     */
    public function listObjects(string $user, string $relation, string $type): array;

    /**
     * List all users of a given type that have a specific relation with an object.
     *
     * @param string $object  e.g. "document:budget"
     * @param string $relation  e.g. "viewer"
     * @param string $userType  e.g. "user"
     * @return array<int, string> List of user IDs (e.g. ["user:anne", "user:bob"])
     *
     * @throws \PHAPI\Exceptions\OpenFgaException
     */
    public function listUsers(string $object, string $relation, string $userType): array;

    /**
     * Expand the relationship tree for a given relation and object.
     *
     * @param string $relation  e.g. "viewer"
     * @param string $object  e.g. "document:budget"
     * @return array<string, mixed> Expand tree structure
     *
     * @throws \PHAPI\Exceptions\OpenFgaException
     */
    public function expand(string $relation, string $object): array;

    /**
     * Write an authorization model and return the new model ID.
     *
     * @param array<int, array<string, mixed>> $typeDefinitions
     * @param string $schemaVersion  e.g. "1.1"
     * @return string The new authorization_model_id
     *
     * @throws \PHAPI\Exceptions\OpenFgaException
     */
    public function writeAuthorizationModel(array $typeDefinitions, string $schemaVersion): string;

    /**
     * Read an authorization model. If $id is null, reads the latest model.
     *
     * @param string|null $id
     * @return array<string, mixed> The authorization model
     *
     * @throws \PHAPI\Exceptions\OpenFgaException
     */
    public function readAuthorizationModel(?string $id = null): array;
}
