<?php

namespace App\Modules\Landing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Services\BillingServiceInterface;
use App\Modules\Landing\Services\LandingServiceInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;

/**
 * GET / : signed-in users get the SPA (dashboard); guests get the server-rendered landing page in their A/B bucket.
 * Two first-party cookies: the visitor id (httpOnly) and the assigned slug (read by JS for Plausible props).
 */
class LandingController extends Controller
{
    public function __construct(
        private readonly LandingServiceInterface $landing,
    ) {}

    public function __invoke(Request $request): Response
    {
        if (Auth::guard('web')->check()) {
            return response()->view('app');
        }

        $cookies = (array) config('landing.cookies');
        $visitorId = $request->cookie($cookies['visitor']);
        if (! $this->landing->isValidVisitorId($visitorId)) {
            $visitorId = $this->landing->newVisitorId();
        }

        $variant = $this->landing->pick($visitorId);
        $content = $this->landing->content($variant);
        $minutes = (int) $cookies['days'] * 24 * 60;
        $secure = (bool) config('session.secure');

        return response()
            ->view('landing.show', [
                'content' => $content,
                'variant' => $content['slug'],
                'plans' => config('billing.enabled', true) ? app(BillingServiceInterface::class)->plans() : [],
            ])
            ->withCookie(Cookie::make($cookies['visitor'], $visitorId, $minutes, '/', null, $secure, true, false, 'lax'))
            ->withCookie(Cookie::make($cookies['variant'], $content['slug'], $minutes, '/', null, $secure, false, false, 'lax'));
    }
}
