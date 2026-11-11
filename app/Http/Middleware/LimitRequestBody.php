<?php

namespace App\Http\Middleware;

use App\Http\Resources\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Rejects request bodies larger than the given number of bytes (declared Content-Length or actual size) with 413. */
class LimitRequestBody
{
    public function handle(Request $request, Closure $next, int|string $maxBytes): Response
    {
        $max = (int) $maxBytes;
        $declared = (int) $request->server('CONTENT_LENGTH', 0);

        if ($declared > $max || strlen((string) $request->getContent()) > $max) {
            return ApiResponse::error('Request body too large.', 413);
        }

        return $next($request);
    }
}
