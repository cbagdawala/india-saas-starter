<?php

use App\Models\User;
use App\Modules\Tenancy\Enums\TenantRole;
use App\Modules\Tenancy\Mail\TenantInvitationMail;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

// Multi-process tests need committed data, so they migrate fresh instead of wrapping a transaction (MySQL only, see the test).
pest()->extend(TestCase::class)->use(DatabaseMigrations::class)->in('Concurrency');

/*
| Shared helpers
*/

/** Sanctum treats requests from a stateful domain as SPA (session) requests. */
function spa(): TestCase
{
    return test()->withHeaders(['Referer' => 'http://localhost:8200', 'Accept' => 'application/json']);
}

function signedWebhook(array $event, ?string $secret = null, array $headers = []): array
{
    $raw = json_encode($event);

    return [$raw, ['X-Razorpay-Signature' => hash_hmac('sha256', $raw, $secret ?? config('billing.razorpay.webhook_secret')), 'Content-Type' => 'application/json'] + $headers];
}

/** Add $user to $tenant with a role (they keep their own current workspace unless $switch). */
function addMember(Tenant $tenant, User $user, TenantRole $role = TenantRole::Member, bool $switch = true): User
{
    $tenant->users()->attach($user->id, ['role' => $role->value]);
    if ($switch) {
        $user->forceFill(['current_tenant_id' => $tenant->id])->save();
    }

    return $user->refresh();
}

/** The email-link token of the most recent invitation mail (requires Mail::fake()). */
function lastInviteToken(): string
{
    $mail = Mail::sent(TenantInvitationMail::class)->last();
    parse_str((string) parse_url($mail->acceptUrl, PHP_URL_QUERY), $query);

    return $query['token'];
}

/** Seller = a Maharashtra (27) registered operator. Placeholder data. */
function configureSeller(array $override = []): void
{
    config(['invoicing.seller' => $override + [
        'legal_name' => 'Example Software Pvt Ltd',
        'address' => '1 Example Road, Mumbai, Maharashtra 400001',
        'gstin' => '27AAPFU0939F1ZV',
        'state_code' => '27',
        'pan' => 'AAPFU0939F',
        'email' => 'billing@example.com',
    ], 'invoicing.upi.vpa' => 'example@okbank', 'invoicing.upi.payee_name' => null]);
}

function setBillingProfile(Tenant $tenant, array $profile): void
{
    $tenant->forceFill($profile + ['legal_name' => 'Buyer Pvt Ltd', 'address_line1' => '5 Buyer Street', 'city' => 'Bengaluru', 'pincode' => '560001', 'country' => 'IN'])->save();
}

function loginFrom(object $test, string $ip, string $email, string $password, array $cookies = []): TestResponse
{
    // The route's own throttle:10,1 is not what is under test here (the AuthService limits are), so keep it out of the way.
    return $test->withoutMiddleware(ThrottleRequests::class)->withCredentials()->withServerVariables(['REMOTE_ADDR' => $ip])->withUnencryptedCookies($cookies)->postJson('/api/auth/login', ['email' => $email, 'password' => $password]);
}
