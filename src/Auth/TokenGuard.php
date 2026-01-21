<?php

declare(strict_types=1);

namespace PHAPI\Auth;

use PHAPI\HTTP\Request;
use PHAPI\HTTP\RequestContext;

class TokenGuard implements GuardInterface
{
    /**
     * @var callable(string, Request): (array<string, mixed>|null)
     */
    private $resolver;
    /**
     * @var array<string, mixed>|null
     */
    private ?array $user = null;
    private ?int $lastRequestId = null;

    /**
     * Create a token guard.
     *
     * @param callable(string, Request): (array<string, mixed>|null) $resolver
     * @return void
     */
    public function __construct(callable $resolver)
    {
        $this->resolver = $resolver;
    }

    /**
     * Resolve the current user via token.
     *
     * @return array<string, mixed>|null
     */
    public function user(): ?array
    {
        $request = RequestContext::get();
        if ($request instanceof Request) {
            $requestId = spl_object_id($request);
            if ($this->lastRequestId !== $requestId) {
                $this->user = null;
                $this->lastRequestId = $requestId;
            }
        } else {
            $this->user = null;
            $this->lastRequestId = null;
        }

        if ($this->user !== null) {
            return $this->user;
        }

        if (!$request instanceof Request) {
            return null;
        }

        $token = $this->tokenFromRequest($request);
        if ($token === null) {
            return null;
        }

        $user = ($this->resolver)($token, $request);
        if (is_array($user)) {
            $this->user = $user;
        }

        return $this->user;
    }

    /**
     * Determine if a token resolves to a user.
     *
     * @return bool
     */
    public function check(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the resolved user id.
     *
     * @return string|null
     */
    public function id(): ?string
    {
        $user = $this->user();
        if ($user === null) {
            return null;
        }
        $id = $user['id'] ?? $user['user_id'] ?? null;
        return $id === null ? null : (string)$id;
    }

    /**
     * Get the bearer token from the current request.
     *
     * @return string|null
     */
    public function token(): ?string
    {
        $request = RequestContext::get();
        if (!$request instanceof Request) {
            return null;
        }

        return $this->tokenFromRequest($request);
    }

    private function tokenFromRequest(Request $request): ?string
    {
        $header = $request->header('authorization');
        if (is_string($header) && stripos($header, 'bearer ') === 0) {
            return trim(substr($header, 7));
        }

        $query = $request->query('access_token');
        if (is_string($query) && $query !== '') {
            return $query;
        }

        return null;
    }
}
