<?php

namespace App\Modules\Onboarding\Services;

use App\Models\User;

/** First-run onboarding shown after signup. Steps are product copy in config/onboarding.php. */
class OnboardingService implements OnboardingServiceInterface
{
    /** @return array{steps: array<int, array<string, string>>, completed: bool, onboarded_at: string|null} */
    public function state(User $user): array
    {
        return [
            'steps' => array_values(array_filter(
                (array) config('onboarding.steps'),
                fn ($step) => config('billing.enabled', true) || ($step['key'] ?? null) !== 'plan',
            )),
            'completed' => $user->onboarded_at !== null,
            'onboarded_at' => $user->onboarded_at?->toIso8601String(),
        ];
    }

    public function complete(User $user): User
    {
        if ($user->onboarded_at === null) {
            $user->forceFill(['onboarded_at' => now()])->save();
        }

        return $user;
    }
}
