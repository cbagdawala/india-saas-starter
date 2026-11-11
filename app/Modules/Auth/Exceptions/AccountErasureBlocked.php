<?php

namespace App\Modules\Auth\Exceptions;

use App\Http\Resources\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/** The account cannot be erased right now (sole owner of a shared or paying workspace). Rendered as 409. */
class AccountErasureBlocked extends RuntimeException
{
    /** @param list<string> $reasons */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct('Your account cannot be deleted yet.');
    }

    public function render(): JsonResponse
    {
        return ApiResponse::error($this->getMessage(), 409, ['account' => $this->reasons]);
    }
}
