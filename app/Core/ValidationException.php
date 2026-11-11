<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Input validation failure. Carries per-field errors for the 422 response.
 */
final class ValidationException extends HttpException
{
    /** @var array<string, array<int, string>> */
    private array $errors;

    /**
     * @param array<string, array<int, string>> $errors
     */
    public function __construct(array $errors, string $message = 'The submitted data is invalid.')
    {
        parent::__construct(422, 'VALIDATION_FAILED', $message, ['errors' => $errors]);
        $this->errors = $errors;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
