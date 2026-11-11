<?php

namespace App\Http\Middleware;

use Illuminate\Session\Middleware\AuthenticateSession;

/**
 * Laravel's AuthenticateSession, pinned to the `web` (session) guard. `auth:sanctum` switches the default guard to the
 * token-style `sanctum` guard for the request, which has no remember-me API, so the stock middleware would fail here.
 * Effect: a session stores the user's password hash; after a password change or reset every other session of that user is
 * logged out on its next request.
 */
class AuthenticateWebSession extends AuthenticateSession
{
    protected function guard()
    {
        return $this->auth->guard('web');
    }
}
