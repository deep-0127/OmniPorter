<?php

declare(strict_types=1);

namespace OmniPorter\Exceptions;

use Illuminate\Contracts\Validation\Validator;

/**
 * Thrown when the OmniPorter import pipeline encounters an unrecoverable error.
 *
 * Row-level validation failures are NOT thrown as exceptions — they are logged
 * into the ImportDetailsCache and surfaced in the result report. This exception
 * is reserved for structural / configuration failures (e.g. missing unique-key
 * definition, misconfigured cache driver, etc.).
 */
class ImportException extends OmniPorterException
{
    public function __construct(
        protected ?Validator $validator = null,
        protected ?int $rowIndex = null,
        $message = null,
        $code = 0,
        \Throwable $previous = null
    ) {
        if ($message === null && $validator !== null) {
            $message = implode(' ', $validator->errors()->all());
        }

        parent::__construct($message ?? 'Import error', $code, $previous);
    }

    public function getValidator(): ?Validator
    {
        return $this->validator;
    }

    public function getRowIndex(): ?int
    {
        return $this->rowIndex;
    }
}
