<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\Responses\ApiResponse;
use App\Modules\Billing\Services\BillingServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /api/billing/webhook — Razorpay events. Everything in the body is untrusted until the HMAC verifies. */
class WebhookController extends Controller
{
    public function __invoke(Request $request, BillingServiceInterface $billing): JsonResponse
    {
        $secret = (string) config('billing.razorpay.webhook_secret');
        if ($secret === '') {
            return ApiResponse::error('Webhook secret is not configured.', 503);
        }

        $raw = $request->getContent();
        $expected = hash_hmac('sha256', $raw, $secret);
        if (! hash_equals($expected, (string) $request->header('X-Razorpay-Signature'))) {
            return ApiResponse::error('Invalid signature.', 400);
        }

        $event = json_decode($raw, true);
        if (! is_array($event) || ! isset($event['event'])) {
            return ApiResponse::error('Malformed payload.', 422);
        }

        $eventId = (string) ($request->header('X-Razorpay-Event-Id') ?: ($event['id'] ?? hash('sha256', $raw)));
        $processed = $billing->handleWebhook($eventId, $event);

        return ApiResponse::success($processed ? 'Processed' : 'Duplicate ignored', ['duplicate' => ! $processed]);
    }
}
