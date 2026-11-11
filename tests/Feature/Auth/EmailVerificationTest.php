<?php

use App\Models\User;
use App\Modules\Tenancy\Models\TenantInvitation;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

beforeEach(fn () => spa());

function verificationUrl(User $user, int $minutes = 60): string
{
    return URL::temporarySignedRoute('verification.verify', now()->addMinutes($minutes), ['id' => $user->id, 'hash' => sha1($user->email)]);
}

it('sends a verification email on registration and reports the status', function () {
    Notification::fake();

    $this->postJson('/api/auth/register', [
        'name' => 'Asha', 'email' => 'asha@example.com', 'password' => 'Secret-pass-1', 'password_confirmation' => 'Secret-pass-1',
    ])->assertCreated()->assertJsonPath('data.email_verified', false);

    Notification::assertSentTo(User::sole(), VerifyEmail::class);
});

it('verifies through the signed link without a session', function () {
    $user = User::factory()->unverified()->create();

    $this->get(verificationUrl($user))->assertRedirect('/verify-email?status=success');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('rejects a tampered, wrong-hash or expired link', function () {
    $user = User::factory()->unverified()->create();
    $other = User::factory()->unverified()->create();

    $this->get(str_replace("/verify/{$user->id}/", "/verify/{$other->id}/", verificationUrl($user)))->assertRedirect('/verify-email?status=invalid');
    $this->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1('someone@else.com')]))->assertRedirect('/verify-email?status=invalid');
    $this->get(route('verification.verify', ['id' => $user->id, 'hash' => sha1($user->email)]))->assertRedirect('/verify-email?status=invalid'); // unsigned

    $expired = verificationUrl($user, 60);
    $this->travel(61)->minutes();
    $this->get($expired)->assertRedirect('/verify-email?status=invalid');

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('resends the verification email, throttled, and is a no-op once verified', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->postJson('/api/auth/email/verification-notification')->assertOk();
    Notification::assertSentToTimes($user, VerifyEmail::class, 1);

    foreach (range(1, 2) as $_) {
        $this->actingAs($user)->postJson('/api/auth/email/verification-notification')->assertOk();
    }
    $this->actingAs($user)->postJson('/api/auth/email/verification-notification')->assertStatus(429);

    $verified = User::factory()->create();
    $this->actingAs($verified)->postJson('/api/auth/email/verification-notification')->assertOk()->assertJsonPath('message', 'Email already verified');
    Notification::assertNotSentTo($verified, VerifyEmail::class);
});

it('requires authentication to resend the verification email', function () {
    $this->postJson('/api/auth/email/verification-notification')->assertUnauthorized();
});

it('refuses invitation acceptance and billing checkout until the email is verified', function () {
    Mail::fake();
    $owner = User::factory()->create();
    $this->actingAs($owner)->postJson('/api/tenant/invitations', ['email' => 'new@example.com', 'role' => 'member'])->assertCreated();
    $token = lastInviteToken();

    $invitee = User::factory()->unverified()->create(['email' => 'new@example.com']);
    $this->actingAs($invitee)->postJson('/api/invitations/accept', ['token' => $token])->assertForbidden();
    expect(TenantInvitation::withoutTenancy()->whereNull('accepted_at')->count())->toBe(1)->and($invitee->tenants()->count())->toBe(1);

    $this->actingAs($invitee)->postJson('/api/billing/checkout', ['plan' => 'starter'])->assertForbidden();

    $invitee->markEmailAsVerified();
    $this->actingAs($invitee->fresh())->postJson('/api/billing/checkout', ['plan' => 'starter'])->assertCreated();
    $this->actingAs($invitee->fresh())->postJson('/api/invitations/accept', ['token' => $token])->assertOk();
});
