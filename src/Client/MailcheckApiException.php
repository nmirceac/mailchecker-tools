<?php

namespace MailcheckerTools\Client;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/** An API error ({"error": {"message", "status", "errors"?}}) or a connection problem (status 0). */
class MailcheckApiException extends RuntimeException
{
    /** @param  array<string, list<string>>  $errors  validation errors by field */
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly array $errors = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public static function fromResponse(Response $response): self
    {
        $error = (array) $response->json('error');

        return new self(
            (string) ($error['message'] ?? "HTTP {$response->status()}"),
            $response->status(),
            is_array($error['errors'] ?? null) ? $error['errors'] : [],
        );
    }

    public static function connection(Throwable $e): self
    {
        return new self('Could not reach the Mail Checker: '.$e->getMessage(), 0, [], $e);
    }

    /** Worth trying again later: unreachable, rate limited or a server error. */
    public function isRetryable(): bool
    {
        return $this->status === 0 || $this->status === 429 || $this->status >= 500;
    }
}
