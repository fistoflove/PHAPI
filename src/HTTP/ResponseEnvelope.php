<?php

declare(strict_types=1);

namespace PHAPI\HTTP;

final class ResponseEnvelope
{
    /**
     * Build a success envelope.
     *
     * @param mixed $data
     * @return array{ok: true, data: mixed}
     */
    public static function success(mixed $data): array
    {
        return ['ok' => true, 'data' => $data];
    }

    /**
     * Build an error envelope and return a Response with the given HTTP status.
     *
     * @return Response
     */
    public static function error(string $code, string $message, int $httpStatus = 400): Response
    {
        return Response::json([
            'ok' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $httpStatus);
    }

    /**
     * Build a success envelope and return a Response.
     *
     * @param mixed $data
     * @return Response
     */
    public static function ok(mixed $data, int $httpStatus = 200): Response
    {
        return Response::json(self::success($data), $httpStatus);
    }
}
