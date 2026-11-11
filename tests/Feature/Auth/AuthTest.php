<?php

use App\Models\User;

beforeEach(fn () => spa());

it('registers a user and signs them in', function () {
    $this->postJson('/api/auth/register', [
        'name' => 'Asha', 'email' => 'Asha@Example.com', 'password' => 'Secret-pass-1', 'password_confirmation' => 'Secret-pass-1',
    ])->assertCreated()->assertJsonPath('success', true)->assertJsonPath('data.email', 'asha@example.com');

    $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.name', 'Asha');
    expect(User::count())->toBe(1);
});

it('validates registration input', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->postJson('/api/auth/register', ['name' => '', 'email' => 'taken@example.com', 'password' => 'short'])
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonValidationErrors(['name', 'email', 'password'], 'errors');
});

it('logs in with valid credentials and never exposes the password', function () {
    User::factory()->create(['email' => 'a@example.com']);

    $this->postJson('/api/auth/login', ['email' => 'a@example.com', 'password' => 'password'])->assertOk();

    $json = $this->getJson('/api/auth/me')->assertOk()->json('data');
    expect($json)->not->toHaveKeys(['password', 'remember_token']);
});

it('rejects invalid credentials', function () {
    User::factory()->create(['email' => 'a@example.com']);

    $this->postJson('/api/auth/login', ['email' => 'a@example.com', 'password' => 'wrong'])
        ->assertStatus(422)->assertJsonValidationErrorFor('email', 'errors');
});

it('requires auth for /api/auth/me', function () {
    $this->getJson('/api/auth/me')->assertUnauthorized()->assertJsonPath('success', false);
});

it('logs out', function () {
    $this->actingAs(User::factory()->create())->postJson('/api/auth/logout')->assertOk();

    $this->assertGuest('web');
});

it('throttles after 5 failed logins', function () {
    User::factory()->create(['email' => 'a@example.com']);

    foreach (range(1, 5) as $_) {
        $this->postJson('/api/auth/login', ['email' => 'a@example.com', 'password' => 'wrong'])->assertStatus(422);
    }

    $this->postJson('/api/auth/login', ['email' => 'a@example.com', 'password' => 'wrong'])->assertStatus(429);
});

it('slows guessing across many IPs without locking the real owner out of their own device', function () {
    config(['accounts.login.delay_after' => 3, 'accounts.login.max_delay' => 60]);
    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $other = User::factory()->create(['email' => 'other@example.com']);

    $device = loginFrom($this, '10.0.0.1', 'owner@example.com', 'password')->assertOk()->getCookie('known_device', false)?->getValue();
    expect($device)->toMatch('/^\d+\.[0-9a-f]{64}$/');
    $this->postJson('/api/auth/logout');

    // A botnet guesses from a fresh IP each time (the per-IP limit never triggers).
    foreach (range(1, 3) as $i) {
        loginFrom($this, "10.1.0.{$i}", 'owner@example.com', 'wrong')->assertStatus(422);
    }
    // Unknown devices are now delayed, even with the correct password; a forged cookie or another account's cookie exempts nobody ...
    loginFrom($this, '10.2.0.1', 'owner@example.com', 'password')->assertStatus(429);
    loginFrom($this, '10.2.0.3', 'owner@example.com', 'password', ['known_device' => $other->id.'.'.str_repeat('a', 64)])->assertStatus(429);
    loginFrom($this, '10.2.0.4', 'owner@example.com', 'password', ['known_device' => $owner->id.'.'.str_repeat('0', 64)])->assertStatus(429);
    $otherDevice = loginFrom($this, '10.0.0.7', 'other@example.com', 'password')->assertOk()->getCookie('known_device', false)->getValue();
    $this->postJson('/api/auth/logout');
    loginFrom($this, '10.2.0.5', 'owner@example.com', 'password', ['known_device' => $otherDevice])->assertStatus(429);
    // ... but the owner's own browser (signed known-device cookie) still gets in.
    loginFrom($this, '10.2.0.2', 'owner@example.com', 'password', ['known_device' => $device])->assertOk();
    $this->postJson('/api/auth/logout');

    // The delay is temporary (and doubles), it is not a permanent lock-out.
    foreach (range(1, 3) as $i) {
        loginFrom($this, "10.3.0.{$i}", 'owner@example.com', 'wrong', ['known_device' => ''])->assertStatus(422);
    }
    loginFrom($this, '10.3.1.1', 'owner@example.com', 'password')->assertStatus(429);
    $this->travel(2)->minutes();
    loginFrom($this, '10.4.0.1', 'owner@example.com', 'password')->assertOk();
});

it('has a hard per-email ceiling that known devices are exempt from', function () {
    config(['accounts.login.delay_after' => 1000, 'accounts.login.ceiling' => 6]);
    User::factory()->create(['email' => 'owner@example.com']);
    $device = loginFrom($this, '10.0.0.1', 'owner@example.com', 'password')->assertOk()->getCookie('known_device', false)->getValue();
    $this->postJson('/api/auth/logout');

    foreach (range(1, 6) as $i) {
        loginFrom($this, "10.5.0.{$i}", 'owner@example.com', 'wrong')->assertStatus(422);
    }

    loginFrom($this, '10.5.1.1', 'owner@example.com', 'password')->assertStatus(429);
    loginFrom($this, '10.5.1.2', 'owner@example.com', 'password', ['known_device' => $device])->assertOk();
});

it('still hard-limits one IP to 5 attempts a minute even for a known device', function () {
    User::factory()->create(['email' => 'owner@example.com']);
    $device = loginFrom($this, '10.0.0.1', 'owner@example.com', 'password')->getCookie('known_device', false)->getValue();
    $this->postJson('/api/auth/logout');

    foreach (range(1, 5) as $_) {
        loginFrom($this, '10.9.9.9', 'owner@example.com', 'wrong', ['known_device' => $device])->assertStatus(422);
    }
    loginFrom($this, '10.9.9.9', 'owner@example.com', 'password', ['known_device' => $device])->assertStatus(429);
});
