<?php

use App\Models\User;

it('returns the configured onboarding steps and marks completion once', function () {
    $user = User::factory()->create();

    spa()->actingAs($user)->getJson('/api/onboarding')->assertOk()
        ->assertJsonCount(3, 'data.steps')
        ->assertJsonPath('data.steps.0.key', config('onboarding.steps.0.key'))
        ->assertJsonPath('data.completed', false);

    spa()->actingAs($user)->postJson('/api/onboarding/complete')->assertOk()->assertJsonPath('data.completed', true);
    $first = $user->fresh()->onboarded_at;

    $this->travel(1)->hours();
    spa()->actingAs($user)->postJson('/api/onboarding/complete')->assertOk();
    expect($user->fresh()->onboarded_at->equalTo($first))->toBeTrue();

    spa()->actingAs($user)->getJson('/api/auth/me')->assertJsonPath('data.onboarded_at', $first->toIso8601String());
});

it('requires auth', function () {
    $this->getJson('/api/onboarding')->assertUnauthorized();
    $this->postJson('/api/onboarding/complete')->assertUnauthorized();
});

it('serves the SPA shell for the welcome page', function () {
    $this->withoutVite()->get('/welcome')->assertOk()->assertSee('id="app"', false);
});
