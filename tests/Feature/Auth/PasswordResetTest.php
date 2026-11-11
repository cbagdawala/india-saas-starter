<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

beforeEach(fn () => spa());

function resetToken(User $user): string
{
    return Password::broker()->createToken($user);
}

it('answers the same whether or not the address has an account, and emails a link only to real accounts', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'real@example.com']);

    $known = $this->postJson('/api/auth/forgot-password', ['email' => 'Real@Example.com'])->assertOk()->json();
    $unknown = $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk()->json();

    expect($known)->toBe($unknown);
    Notification::assertSentTo($user, ResetPassword::class);
    Notification::assertCount(1);
});

it('puts a link to the SPA reset page in the email', function () {
    $user = User::factory()->create();
    $url = (new ResetPassword('tok123'))->toMail($user)->actionUrl;

    // built from APP_URL, token and email in the fragment (not sent to the server, not in access logs)
    expect($url)->toBe(rtrim(config('app.url'), '/').'/reset-password#token=tok123&email='.urlencode($user->email));
    $this->get('/reset-password')->assertOk(); // the SPA shell
});

it('throttles reset requests per IP and per address', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();
    $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk(); // broker throttle: silently no second mail
    Notification::assertSentToTimes($user, ResetPassword::class, 1);

    foreach (range(1, 5) as $_) {
        $this->postJson('/api/auth/forgot-password', ['email' => 'x@example.com'])->assertOk();
    }
    $this->postJson('/api/auth/forgot-password', ['email' => 'x@example.com'])->assertStatus(429);
    $this->postJson('/api/auth/forgot-password', ['email' => 'y@example.com'])->assertOk(); // another address from the same IP is not blocked
});

it('resets the password, verifies the email, revokes tokens and works once only', function () {
    $user = User::factory()->unverified()->create(['email' => 'u@example.com']);
    $user->createToken('api');
    $token = resetToken($user);

    $this->postJson('/api/auth/reset-password', ['token' => $token, 'email' => 'u@example.com', 'password' => 'New-Passw0rd-xyz', 'password_confirmation' => 'New-Passw0rd-xyz'])
        ->assertOk();

    $fresh = $user->fresh();
    expect(Hash::check('New-Passw0rd-xyz', $fresh->password))->toBeTrue()->and($fresh->hasVerifiedEmail())->toBeTrue()->and($fresh->tokens()->count())->toBe(0);

    $this->postJson('/api/auth/reset-password', ['token' => $token, 'email' => 'u@example.com', 'password' => 'Another-Passw0rd-1', 'password_confirmation' => 'Another-Passw0rd-1'])
        ->assertUnprocessable()->assertJsonValidationErrors('email');
    expect(Hash::check('Another-Passw0rd-1', $user->fresh()->password))->toBeFalse();

    $this->postJson('/api/auth/login', ['email' => 'u@example.com', 'password' => 'New-Passw0rd-xyz'])->assertOk();
});

it('rejects wrong tokens, wrong emails, expired tokens and weak passwords', function () {
    $user = User::factory()->create(['email' => 'u@example.com']);
    $token = resetToken($user);
    $body = fn (array $o = []) => $o + ['token' => $token, 'email' => 'u@example.com', 'password' => 'New-Passw0rd-xyz', 'password_confirmation' => 'New-Passw0rd-xyz'];

    $this->postJson('/api/auth/reset-password', $body(['token' => 'wrong']))->assertUnprocessable();
    $this->postJson('/api/auth/reset-password', $body(['email' => 'other@example.com']))->assertUnprocessable();
    $this->postJson('/api/auth/reset-password', $body(['password' => 'short', 'password_confirmation' => 'short']))->assertUnprocessable()->assertJsonValidationErrors('password');
    $this->postJson('/api/auth/reset-password', $body(['password_confirmation' => 'different']))->assertUnprocessable();

    $this->travel(61)->minutes();
    $this->postJson('/api/auth/reset-password', $body())->assertUnprocessable();
    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('builds emailed links from APP_URL, not from a poisoned Host header', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create(['email' => 'victim@example.com']);

    $this->withHeaders(['Host' => 'evil.example', 'X-Forwarded-Host' => 'evil.example'])->postJson('/api/auth/forgot-password', ['email' => 'victim@example.com'])->assertOk();
    $this->withHeaders(['Host' => 'evil.example'])->postJson('/api/auth/email/verification-notification')->assertUnauthorized();

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user) {
        $url = ($n->toMail($user))->actionUrl;

        return str_starts_with($url, 'http://localhost:8200/reset-password#token=') && ! str_contains($url, 'evil.example');
    });

    $verify = (new VerifyEmail)->toMail($user)->actionUrl;
    expect($verify)->toStartWith('http://localhost:8200/email/verify/')->not->toContain('evil.example');
});

it('signs out another browser session after a password reset', function () {
    $user = User::factory()->create(['email' => 'two@example.com']);

    // Browser A signs in for real (session + cookie), and keeps working.
    $login = $this->withCredentials()->postJson('/api/auth/login', ['email' => 'two@example.com', 'password' => 'password'])->assertOk();
    $cookieName = config('session.cookie');
    $sessionCookie = $login->getCookie($cookieName, false)->getValue();
    $this->withCredentials()->withUnencryptedCookie($cookieName, $sessionCookie)->getJson('/api/auth/me')->assertOk();

    // The password is reset from somewhere else.
    $this->flushSession();
    $token = resetToken($user);
    $this->postJson('/api/auth/reset-password', ['token' => $token, 'email' => 'two@example.com', 'password' => 'New-Passw0rd-xyz', 'password_confirmation' => 'New-Passw0rd-xyz'])->assertOk();

    // Browser A's old session is dead (its stored password hash no longer matches).
    app('auth')->forgetGuards();
    $this->withCredentials()->withUnencryptedCookie($cookieName, $sessionCookie)->getJson('/api/auth/me')->assertUnauthorized();
});

it('deletes the user database sessions on reset when the database session driver is used', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->create();
    $other = User::factory()->create();
    foreach ([$user->id, $user->id, $other->id] as $i => $id) {
        DB::table('sessions')->insert(['id' => "s{$i}", 'user_id' => $id, 'ip_address' => '127.0.0.1', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()]);
    }

    $this->postJson('/api/auth/reset-password', ['token' => resetToken($user), 'email' => $user->email, 'password' => 'New-Passw0rd-xyz', 'password_confirmation' => 'New-Passw0rd-xyz'])->assertOk();

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)->and(DB::table('sessions')->where('user_id', $other->id)->count())->toBe(1);
});

it('kills the known-device cookie and clears the login lock-out on a password reset', function () {
    config(['accounts.login.delay_after' => 2]);
    $user = User::factory()->create(['email' => 'dev@example.com']);
    $device = loginFrom($this, '10.0.0.1', 'dev@example.com', 'password')->assertOk()->getCookie('known_device', false)->getValue();
    $this->postJson('/api/auth/logout');

    foreach (range(1, 2) as $i) {
        loginFrom($this, "10.7.0.{$i}", 'dev@example.com', 'wrong', ['known_device' => ''])->assertStatus(422);
    }
    loginFrom($this, '10.7.1.1', 'dev@example.com', 'password', ['known_device' => ''])->assertStatus(429); // locked for unknown devices
    loginFrom($this, '10.7.1.2', 'dev@example.com', 'password', ['known_device' => $device])->assertOk();  // known device is exempt
    $this->postJson('/api/auth/logout');

    // lock again, then reset the password
    foreach (range(1, 2) as $i) {
        loginFrom($this, "10.7.2.{$i}", 'dev@example.com', 'wrong', ['known_device' => ''])->assertStatus(422);
    }
    $this->postJson('/api/auth/reset-password', ['token' => resetToken($user), 'email' => 'dev@example.com', 'password' => 'New-Passw0rd-xyz', 'password_confirmation' => 'New-Passw0rd-xyz'])->assertOk();

    // lock-out gone for everyone ...
    loginFrom($this, '10.7.3.1', 'dev@example.com', 'New-Passw0rd-xyz', ['known_device' => ''])->assertOk();
    $this->postJson('/api/auth/logout');
    // ... and the old device cookie no longer exempts anyone: lock the account again, the stale cookie is refused
    foreach (range(1, 2) as $i) {
        loginFrom($this, "10.7.4.{$i}", 'dev@example.com', 'wrong', ['known_device' => ''])->assertStatus(422);
    }
    loginFrom($this, '10.7.5.1', 'dev@example.com', 'New-Passw0rd-xyz', ['known_device' => $device])->assertStatus(429);
});
