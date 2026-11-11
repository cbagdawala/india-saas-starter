<?php

namespace App\Modules\Support\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\Responses\ApiResponse;
use App\Modules\Support\Services\SupportServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/support/inbound — Brevo inbound-parse webhook. Brevo does not sign inbound payloads, so the webhook
 * carries a shared secret in a header only: `Authorization: Bearer <SUPPORT_INBOUND_TOKEN>`, or HTTP basic auth
 * with any user and the token as password (https://inbound:<token>@host/...). Never in the query string (logs).
 * The email content is untrusted input; it is stored and forwarded (redacted) but never acted on.
 */
class InboundEmailController extends Controller
{
    public function __invoke(Request $request, SupportServiceInterface $support): JsonResponse
    {
        $expected = (string) config('support.inbound_token');
        if (strlen($expected) < 32) {
            return ApiResponse::error('Support inbound is not configured.', 503);
        }

        $presented = (string) ($request->bearerToken() ?: $request->getPassword() ?: '');
        if ($presented === '' || ! hash_equals(hash('sha256', $expected), hash('sha256', $presented))) {
            return ApiResponse::unauthorized('Invalid token.');
        }

        $payload = json_decode($request->getContent(), true);
        $items = is_array($payload) ? ($payload['items'] ?? (isset($payload['From']) ? [$payload] : null)) : null;
        if (! is_array($items) || ! array_is_list($items)) {
            return ApiResponse::error('Malformed payload.', 422);
        }

        return ApiResponse::success('Processed', $support->ingestInbound($items));
    }
}
