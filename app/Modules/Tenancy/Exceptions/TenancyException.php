<?php

namespace App\Modules\Tenancy\Exceptions;

use App\Http\Resources\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/** A business-rule failure in the Tenancy module, rendered in the API envelope. */
class TenancyException extends RuntimeException
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
