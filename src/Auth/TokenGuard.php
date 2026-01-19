<?php

namespace PHAPI\Auth;

use PHAPI\HTTP\Request;
use PHAPI\HTTP\RequestContext;

class TokenGuard implements GuardInterface
{
    private $resolver;
    private ?array $user = null;
    private ?int $lastRequestId = null;

    public function __construct(callable $resolver)
    {
        $this->resolver = $resolver;
    }

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

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function id(): ?string
    {
        $user = $this->user();
        if ($user === null) {
            return null;
        }
        $id = $user['id'] ?? $user['user_id'] ?? null;
        return $id === null ? null : (string)$id;
    }

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
