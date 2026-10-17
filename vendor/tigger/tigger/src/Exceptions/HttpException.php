<?php

declare(strict_types=1);

namespace Tigger\Exceptions;

use RuntimeException;
use Throwable;

/**
 * An exception that maps directly to an HTTP error response.
 *
 * Throw it from any handler or middleware; the application converts it
 * into a response with the given status code.
 */
class HttpException extends RuntimeException
{
    /**
     * @param int                  $statusCode HTTP status code (e.g. 404).
     * @param string               $message    Public message. Defaults to the standard reason phrase.
     * @param array<string,string> $headers    Extra headers to send with the error response.
     * @param Throwable|null       $previous   Previous exception, if any.
     */
    public function __construct(
        private readonly int $statusCode,
        string $message = '',
        private readonly array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : self::phrase($statusCode), $statusCode, $previous);
    }

    /**
     * Returns the HTTP status code.
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Returns the extra headers for the error response.
     *
     * @return array<string,string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Returns the standard reason phrase for a status code.
     *
     * @param int $status HTTP status code.
     *
     * @return string
     */
    public static function phrase(int $status): string
    {
        return match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            409 => 'Conflict',
            413 => 'Payload Too Large',
            415 => 'Unsupported Media Type',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            503 => 'Service Unavailable',
            default => 'Error',
        };
    }
}
