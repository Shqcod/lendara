<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class DomainError extends RuntimeException
{
    public function __construct(public readonly string $domainCode, string $message, public readonly int $status = 409, public readonly array $errors = [])
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(), 'code' => $this->domainCode, 'errors' => (object) $this->errors,
        ], $this->status);
    }
}
