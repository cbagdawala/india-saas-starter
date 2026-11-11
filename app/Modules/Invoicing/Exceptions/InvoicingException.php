<?php

namespace App\Modules\Invoicing\Exceptions;

use App\Http\Resources\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class InvoicingException extends RuntimeException
{
    public function __construct(string $message, private readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return ApiResponse::error($this->getMessage(), $this->status);
    }
}
