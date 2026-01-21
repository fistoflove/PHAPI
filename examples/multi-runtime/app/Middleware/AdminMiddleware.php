<?php

declare(strict_types=1);

namespace PHAPI\Examples\MultiRuntime\Middleware;

use PHAPI\HTTP\Request;
use PHAPI\HTTP\Response;

final class AdminMiddleware
{
    public function __invoke(Request $request, callable $next): Response
    {
        $token = $request->header('authorization');
        if ($token !== 'admin-token') {
            return Response::json(['error' => 'Unauthorized'], 401);
        }
        return $next($request);
    }
}
