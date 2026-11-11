<?php

namespace App\Modules\Onboarding\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\Responses\ApiResponse;
use App\Modules\Onboarding\Services\OnboardingServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    public function __construct(private readonly OnboardingServiceInterface $onboarding) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success('Onboarding', $this->onboarding->state($request->user()));
    }

    public function complete(Request $request): JsonResponse
    {
        return ApiResponse::success('Onboarding completed', $this->onboarding->state($this->onboarding->complete($request->user())));
    }
}
