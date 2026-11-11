<?php

use App\Models\User;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Services\SenderVerifier;
use App\Modules\Support\Services\TicketClassifier;
use App\Modules\Tenancy\Services\TenantService;
use Illuminate\Testing\TestResponse;

const INBOUND_TOKEN = 'inbound-token-0123456789abcdef0123456789';

beforeEach(fn () => config(['support.inbound_token' => INBOUND_TOKEN]));

function brevoItem(array $overrides = []): array
{
    return $overrides + [
        'Uuid' => ['8c6a2f3e-0000-4000-8000-000000000001'],
        'MessageId' => '<CAF=abc123@mail.gmail.com>',
        'InReplyTo' => null,
        'From' => ['Name' => 'Meera Shah', 'Address' => 'Meera@Example.com'],
        'To' => [['Name' => null, 'Address' => 'support@example.com']],
        'Subject' => 'Refund please, call me on +91 98765 43210 or meera@example.com',
        'RawTextBody' => "Hi,\nI was charged twice. My number is 9876543210.\n",
        'RawHtmlBody' => '<p>Hi</p>',
        'SentAtDate' => 'Thu, 15 Oct 2026 10:00:00 +0530',
        'Attachments' => [],
        'Headers' => ['Authentication-Results' => 'mx.brevo.test; dkim=pass header.d=example.com header.s=s1; spf=pass smtp.mailfrom=bounce@example.com; dmarc=pass header.from=example.com'],
    ];
}

function inbound(array $payload, array $headers = []): TestResponse
{
    return test()->postJson('/api/support/inbound', $payload, $headers + ['Authorization' => 'Bearer '.INBOUND_TOKEN]);
}

it('refuses inbound mail without the shared token', function () {
    $this->postJson('/api/support/inbound', ['items' => [brevoItem()]])->assertUnauthorized();
    $this->postJson('/api/support/inbound', ['items' => [brevoItem()]], ['Authorization' => 'Bearer wrong'])->assertUnauthorized();

    config(['support.inbound_token' => '']);
    $this->postJson('/api/support/inbound', ['items' => [brevoItem()]], ['Authorization' => 'Bearer '])->assertStatus(503);

    expect(SupportTicket::withoutTenancy()->count())->toBe(0);
});

it('accepts the token as a basic-auth password but never as a query parameter', function () {
    $this->postJson('/api/support/inbound?token='.INBOUND_TOKEN, ['items' => [brevoItem()]])->assertUnauthorized();

    $this->postJson('/api/support/inbound', ['items' => [brevoItem()]], ['Authorization' => 'Basic '.base64_encode('inbound:'.INBOUND_TOKEN)])
        ->assertOk()->assertJsonPath('data.created', 1);
});

it('stores a Brevo inbound email locally', function () {
    User::factory()->create(['email' => 'meera@example.com']);

    inbound(['items' => [brevoItem()]])->assertOk()->assertJsonPath('data.created', 1);

    $ticket = SupportTicket::withoutTenancy()->sole();
    expect($ticket->from_email)->toBe('meera@example.com')
        ->and($ticket->from_name)->toBe('Meera Shah')
        ->and($ticket->user_id)->toBeNull() // no trusted authserv-id configured: never linked
        ->and($ticket->sender_verified)->toBeFalse()
        ->and($ticket->category)->toBe('billing')
        ->and($ticket->source)->toBe('email')
        ->and($ticket->body_text)->toContain('charged twice')
        ->and($ticket->status->value)->toBe('open');

});

it('links an inbound ticket to the account only when the trusted MX authenticated the sender', function () {
    config(['support.trusted_authserv_id' => 'mx.brevo.test']);
    $customer = User::factory()->create(['email' => 'meera@example.com']);

    // forged header from another authserv-id: not trusted
    inbound(['items' => [brevoItem(['MessageId' => '<forged@x>', 'Headers' => ['Authentication-Results' => 'evil.test; dmarc=pass header.from=example.com']])]])->assertOk();
    // trusted MX, but DKIM/SPF for an unaligned domain and no dmarc
    inbound(['items' => [brevoItem(['MessageId' => '<unaligned@x>', 'Headers' => ['authentication-results' => ['mx.brevo.test; dkim=pass header.d=attacker.com; spf=pass smtp.mailfrom=a@attacker.com; dmarc=fail header.from=example.com']]])]])->assertOk();
    inbound(['items' => [brevoItem()]])->assertOk();

    expect(SupportTicket::withoutTenancy()->where('message_id', '<forged@x>')->value('user_id'))->toBeNull()
        ->and(SupportTicket::withoutTenancy()->where('message_id', '<unaligned@x>')->value('user_id'))->toBeNull()
        ->and(SupportTicket::withoutTenancy()->where('message_id', '<CAF=abc123@mail.gmail.com>')->first())
        ->user_id->toBe($customer->id)
        ->sender_verified->toBeTrue();
});

it('verifies senders by aligned DKIM or SPF from the trusted MX', function () {
    config(['support.trusted_authserv_id' => 'mx.brevo.test']);
    $verifier = new SenderVerifier;
    $item = fn (string $ar) => ['Headers' => ['Authentication-Results' => $ar]];

    expect($verifier->verified($item('mx.brevo.test; dkim=pass header.d=example.com'), 'a@mail.example.com'))->toBeTrue()
        ->and($verifier->verified($item('mx.brevo.test; spf=pass smtp.mailfrom=bounce@example.com'), 'a@example.com'))->toBeTrue()
        ->and($verifier->verified($item('mx.brevo.test; dkim=fail header.d=example.com'), 'a@example.com'))->toBeFalse()
        ->and($verifier->verified($item('mx.brevo.test; dkim=pass header.d=notexample.com'), 'a@example.com'))->toBeFalse()
        ->and($verifier->verified([], 'a@example.com'))->toBeFalse();

    config(['support.trusted_authserv_id' => '']);
    expect($verifier->verified($item('mx.brevo.test; dmarc=pass'), 'a@example.com'))->toBeFalse();
});

it('is idempotent on the Message-ID and rejects items without a valid sender', function () {
    inbound(['items' => [brevoItem()]])->assertOk();
    inbound(['items' => [brevoItem(), brevoItem(['MessageId' => '<other@x>', 'From' => ['Address' => 'not-an-email']])]])
        ->assertOk()->assertJsonPath('data.duplicates', 1)->assertJsonPath('data.rejected', 1)->assertJsonPath('data.created', 0);

    expect(SupportTicket::withoutTenancy()->count())->toBe(1);
});

it('falls back to the HTML body with tags and scripts removed', function () {
    inbound(['items' => [brevoItem(['RawTextBody' => '', 'ExtractedMarkdownMessage' => null, 'RawHtmlBody' => '<p>Hello <b>team</b></p><script>alert(1)</script>'])]])->assertOk();

    expect(SupportTicket::withoutTenancy()->sole()->body_text)->toBe('Hello team');
});

it('rejects malformed and oversized payloads', function () {
    inbound(['nope' => true])->assertUnprocessable();

    config(['support.max_body_bytes' => 100]);
    $this->call('POST', '/api/support/inbound', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.INBOUND_TOKEN], str_repeat('x', 2 * 1024 * 1024))
        ->assertStatus(413);
});

it('lets a signed-in user open a ticket and list only their own', function () {
    $user = User::factory()->create();
    SupportTicket::factory()->create(); // someone else's

    spa()->actingAs($user)->postJson('/api/support/tickets', ['subject' => 'Help', 'body' => 'Something is broken here.'])
        ->assertCreated()->assertJsonPath('data.status', 'open')->assertJsonPath('data.source', 'form');

    spa()->actingAs($user)->getJson('/api/support/tickets')->assertOk()->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.data.0.subject', 'Help');
});

it('validates the contact form and requires auth', function () {
    $this->postJson('/api/support/tickets', ['subject' => 'Help', 'body' => 'Something is broken here.'])->assertUnauthorized();
    spa()->actingAs(User::factory()->create())->postJson('/api/support/tickets', ['subject' => '', 'body' => 'short'])
        ->assertUnprocessable()->assertJsonValidationErrors(['subject', 'body']);
});

it('classifies tickets into coarse categories', function () {
    $classifier = new TicketClassifier;

    expect($classifier->classify('Refund please', ''))->toBe('billing')
        ->and($classifier->classify('Cannot log in', 'password reset never arrives'))->toBe('account')
        ->and($classifier->classify('Export is broken', 'I get an error'))->toBe('bug')
        ->and($classifier->classify('Idea', 'Could you add a dark mode? Would be nice.'))->toBe('feature')
        ->and($classifier->classify('Hello', 'Just saying thanks'))->toBe('other')
        ->and($classifier->classify('Discard', 'scarded'))->toBe('other'); // whole words only
});

it('does not link inbound mail to an account whose email is unverified, and files it in the personal workspace', function () {
    config(['support.trusted_authserv_id' => 'mx.brevo.test']);
    $unverified = User::factory()->unverified()->create(['email' => 'meera@example.com']);
    inbound(['items' => [brevoItem(['MessageId' => '<one@x>'])]])->assertOk();

    expect(SupportTicket::withoutTenancy()->where('message_id', '<one@x>')->first())->user_id->toBeNull()->tenant_id->toBeNull()->sender_verified->toBeTrue();

    $unverified->markEmailAsVerified();
    $personal = $unverified->current_tenant_id;
    $other = app(TenantService::class)->create($unverified, 'Second workspace'); // now the *current* one
    inbound(['items' => [brevoItem(['MessageId' => '<two@x>'])]])->assertOk();

    expect(SupportTicket::withoutTenancy()->where('message_id', '<two@x>')->first())->user_id->toBe($unverified->id)->tenant_id->toBe($personal)->not->toBe($other->id);
});

it('marks form tickets from unverified accounts as not sender-verified', function () {
    $user = User::factory()->unverified()->create();
    spa()->actingAs($user)->postJson('/api/support/tickets', ['subject' => 'Help', 'body' => 'Something is broken here.'])->assertCreated();

    expect(SupportTicket::withoutTenancy()->sole()->sender_verified)->toBeFalse();
});
