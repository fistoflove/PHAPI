<?php

declare(strict_types=1);

namespace PHAPI\Supabase\Exceptions;

use PHAPI\Exceptions\PhapiException;

/**
 * @api
 */
class SupabaseException extends PhapiException
{
    private int $httpStatus;
    private string $details;
    private string $hint;

    public function __construct(
        string $message = '',
        int $httpStatus = 0,
        string $details = '',
        string $hint = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
        $this->httpStatus = $httpStatus;
        $this->details = $details;
        $this->hint = $hint;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function details(): string
    {
        return $this->details;
    }

    public function hint(): string
    {
        return $this->hint;
    }

    /**
     * @param array{data: mixed, status: int, body: string} $response
     */
    public static function fromResponse(array $response, string $context = ''): static
    {
        $data = $response['data'];
        $message = '';
        $details = '';
        $hint = '';

        if (is_array($data)) {
            $message = (string) ($data['message'] ?? $data['error_description'] ?? $data['msg'] ?? $data['error'] ?? '');
            $details = (string) ($data['details'] ?? '');
            $hint = (string) ($data['hint'] ?? '');
        }

        if ($message === '') {
            $message = $context !== '' ? $context : 'Supabase request failed';
        }

        return new static($message, $response['status'], $details, $hint); // @phpstan-ignore new.static
    }
}
