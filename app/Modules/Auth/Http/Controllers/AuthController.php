<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\Responses\ApiResponse;
use App\Modules\Auth\Http\Requests\LoginRequest;
use App\Modules\Auth\Http\Requests\RegisterRequest;
use App\Modules\Auth\Http\Resources\UserResource;
use App\Modules\Auth\Services\AuthServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthServiceInterface $auth) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->auth->login(
            $request,
            $request->validated('email'),
            $request->validated('password'),
            (bool) $request->validated('remember', false),
        );

        return ApiResponse::success('Logged in', new UserResource($user));
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->auth->register($request, $request->validated());

        return ApiResponse::created('Registered', new UserResource($user));
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request);

        return ApiResponse::success('Logged out');
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success('Current user', new UserResource($request->user()));
    }
}
