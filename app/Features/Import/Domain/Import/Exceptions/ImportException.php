<?php

namespace App\Features\Import\Domain\Import\Exceptions;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\MessageBag;

class ImportException extends ValidationException
{
    public function __construct(?\Illuminate\Validation\Validator $validator = null, private int $row = 0, string $customMessage = null)
    {
        if (!$validator) {
            $errors = new MessageBag([
                'import' => [$customMessage ?? "Import failed at row {$row}."]
            ]);

            $validator = Validator::make([], []);
            $validator->errors()->merge($errors);
        }

        parent::__construct($validator, response()->json([
            'status' => 'fail',
            'message' => "Validation failed at row {$row}",
            'errors' => $validator->errors()
        ], 422));
    }

    public function getResponseMessage(): string
    {
        return "Validation failed at row {$this->row}";
    }
}
