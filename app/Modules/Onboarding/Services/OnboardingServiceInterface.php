<?php

namespace App\Modules\Onboarding\Services;

use App\Models\User;

interface OnboardingServiceInterface
{
    /** @return array{steps: array<int, array<string, string>>, completed: bool, onboarded_at: string|null} */
    public function state(User $user): array;

    /** Sets onboarded_at once (idempotent). */
    public function complete(User $user): User;
}
