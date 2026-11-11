<?php

use App\Models\User;
use App\Modules\Auth\Services\AccountService;
use App\Modules\Billing\Events\BillingEventOccurred;
use App\Modules\Billing\Models\BillingWebhookEvent;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Invoicing\Exceptions\InvoicingException;
use App\Modules\Invoicing\Listeners\IssueInvoiceForPayment;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Providers\InvoicingServiceProvider;
use App\Modules\Invoicing\Services\InvoiceService;
use App\Modules\Invoicing\Support\AmountInWords;
use App\Modules\Invoicing\Support\InvoiceNumberAllocator;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

beforeEach(function () {
    spa();
    configureSeller();
});

function payViaConfirm(object $test, User $user, string $paymentId = 'pay_x'): array
{
    $checkout = $test->actingAs($user)->postJson('/api/billing/checkout', ['plan' => 'starter'])->assertCreated()->json('data');
    $test->postJson('/api/billing/confirm', ['razorpay_subscription_id' => $checkout['reference'], 'razorpay_payment_id' => $paymentId, 'razorpay_signature' => $checkout['signature']])->assertOk();

    return $checkout;
}

function deliver(object $test, array $event, string $eventId): void
{
    [$raw, $headers] = signedWebhook($event);
    $test->call('POST', '/api/billing/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_RAZORPAY_SIGNATURE' => $headers['X-Razorpay-Signature'], 'HTTP_X_RAZORPAY_EVENT_ID' => $eventId], $raw)->assertOk();
}

function chargeEvent(string $subId, string $paymentId, int $amount = 49900): array
{
    return ['event' => 'subscription.charged', 'payload' => ['subscription' => ['entity' => ['id' => $subId, 'status' => 'active']], 'payment' => ['entity' => ['id' => $paymentId, 'amount' => $amount]]]];
}

it('does not double-issue when Razorpay redelivers the SAME event id', function () {
    $user = User::factory()->create();
    $checkout = payViaConfirm($this, $user, 'pay_1');

    deliver($this, chargeEvent($checkout['reference'], 'pay_2'), 'evt_same');
    deliver($this, chargeEvent($checkout['reference'], 'pay_2'), 'evt_same');

    expect(Invoice::withoutTenancy()->count())->toBe(2);
});

it('recovers a failed auto-issue with invoicing:backfill, which is safe to rerun', function () {
    configureSeller(['gstin' => null]); // seller not configured: the confirmation succeeds but no invoice is issued
    $user = User::factory()->create();
    $checkout = payViaConfirm($this, $user, 'pay_1');
    deliver($this, chargeEvent($checkout['reference'], 'pay_2'), 'evt_renewal'); // a renewal that also fails to invoice
    expect(Invoice::withoutTenancy()->count())->toBe(0);

    $this->artisan('invoicing:backfill')->assertFailed(); // still unconfigured: skipped, loudly
    expect(Invoice::withoutTenancy()->count())->toBe(0);

    configureSeller();
    // Redelivery of the SAME event is dropped by the ledger, so only the backfill can repair this.
    deliver($this, chargeEvent($checkout['reference'], 'pay_2'), 'evt_renewal');
    expect(Invoice::withoutTenancy()->count())->toBe(0);

    $this->artisan('invoicing:backfill')->expectsOutputToContain('Issued 2 invoice(s)')->assertSuccessful();
    expect(Invoice::withoutTenancy()->orderBy('id')->pluck('payment_id')->sort()->values()->all())->toBe(['pay_1', 'pay_2']);

    $this->artisan('invoicing:backfill')->expectsOutputToContain('Issued 0 invoice(s)')->assertSuccessful();
    expect(Invoice::withoutTenancy()->count())->toBe(2);
});

it('is scheduled every fifteen minutes', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'invoicing:backfill'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('*/15 * * * *');
});

it('never issues invoices for the fake gateway outside local and testing', function () {
    $subscription = Subscription::factory()->active()->create(['last_payment_id' => 'pay_1']); // gateway = fake
    app()['env'] = 'staging';

    $this->artisan('invoicing:backfill')->expectsOutputToContain('Issued 0')->assertSuccessful();
    app(IssueInvoiceForPayment::class)->handle(new BillingEventOccurred('subscription.charged', [
        'gateway' => 'fake', 'gateway_payment_id' => 'pay_9', 'tenant_id' => $subscription->tenant_id, 'plan' => 'starter', 'amount' => 49900, 'charged_amount' => 49900,
    ]));

    expect(Invoice::withoutTenancy()->count())->toBe(0)->and(InvoiceService::gatewayAllowed('fake'))->toBeFalse()->and(InvoiceService::gatewayAllowed('razorpay'))->toBeTrue();

    app()['env'] = 'testing';
    $this->artisan('invoicing:backfill')->expectsOutputToContain('Issued 1')->assertSuccessful();
});

it('refuses to boot in production with a real billing driver and an invalid seller, and reports degraded health', function () {
    config(['billing.driver' => 'razorpay', 'billing.enabled' => true, 'invoicing.enabled' => true, 'invoicing.seller.gstin' => null]);

    // outside production-like environments it only reports
    $this->getJson('/health')->assertOk()->assertJsonPath('status', 'degraded')->assertJsonPath('reason', 'invoicing_not_configured');

    app()['env'] = 'production';
    expect(fn () => (new InvoicingServiceProvider(app()))->boot())->toThrow(LogicException::class, 'INVOICING_SELLER_GSTIN');

    configureSeller();
    (new InvoicingServiceProvider(app()))->boot();
    $this->getJson('/health')->assertOk()->assertJsonPath('status', 'ok');

    config(['invoicing.enabled' => false, 'invoicing.seller.gstin' => null]); // explicit opt-out
    (new InvoicingServiceProvider(app()))->boot();
    $this->getJson('/health')->assertJsonPath('status', 'ok');
});

it('does not require a seller while the fake driver is in use', function () {
    config(['billing.driver' => 'fake', 'invoicing.seller.gstin' => null]);
    $this->getJson('/health')->assertJsonPath('status', 'ok');
});

it('requires a complete billing profile before checkout and tells the SPA why', function () {
    $user = User::factory()->create();
    $user->currentTenant->forceFill(['legal_name' => null, 'address_line1' => null, 'city' => null, 'state_code' => null])->save();

    $this->actingAs($user)->postJson('/api/billing/checkout', ['plan' => 'starter'])->assertUnprocessable()
        ->assertJsonValidationErrors('billing_profile', 'errors')->assertJsonPath('errors.billing_profile.0', fn ($m) => str_contains($m, 'legal name, address, city, state'));
    expect(Subscription::withoutTenancy()->count())->toBe(0);

    setBillingProfile($user->currentTenant, ['state_code' => '29']);
    $this->actingAs($user)->postJson('/api/billing/checkout', ['plan' => 'starter'])->assertCreated();
});

it('derives the state from a valid GSTIN when no state is set', function () {
    $user = User::factory()->create();
    $user->currentTenant->forceFill(['state_code' => null, 'gstin' => '29AABCT1332L1ZA'])->save();

    $this->actingAs($user)->postJson('/api/billing/checkout', ['plan' => 'starter'])->assertCreated();

    $invoice = app(InvoiceService::class)->issue($user->currentTenant->fresh(), [['description' => 'x', 'quantity' => 1, 'unit_price' => 11800]], ['payment_method' => 'manual']);
    expect($invoice->supply_type)->toBe('inter')->and($invoice->place_of_supply)->toBe('29-Karnataka')->and($invoice->buyer['state_code'])->toBe('29');
});

it('refuses back-dated, future-dated and closed-financial-year invoices', function () {
    $user = User::factory()->create();
    $tenant = $user->currentTenant;
    $service = app(InvoiceService::class);
    $line = [['description' => 'x', 'quantity' => 1, 'unit_price' => 49900]];

    $service->issue($tenant, $line, ['payment_method' => 'manual']);

    foreach ([now()->subDays(40), now()->addDays(2), Carbon::parse('2026-03-31 12:00', 'UTC')] as $bad) {
        expect(fn () => $service->issue($tenant, $line, ['payment_method' => 'manual', 'issued_at' => $bad]))->toThrow(InvoicingException::class, 'date');
    }

    // within the same financial year but before the series' latest invoice: also refused
    $this->travelTo(now()->addDays(3));
    $service->issue($tenant, $line, ['payment_method' => 'manual']);
    $latest = Invoice::withoutTenancy()->max('issued_at');
    $this->travelBack();
    // an explicit date before the series' latest invoice is refused ...
    expect(fn () => $service->issue($tenant, $line, ['payment_method' => 'manual', 'issued_at' => now()]))->toThrow(InvoicingException::class, 'earlier than the latest');
    // ... while an automatic "now" (read under the counter lock) is clamped to the latest date instead of failing, so a clock that
    // runs a little behind on one app server can never make a payment lose its invoice.
    $clamped = $service->issue($tenant, $line, ['payment_method' => 'manual']);
    expect($clamped->issued_at->utc()->format('Y-m-d H:i:s'))->toBe($latest);
    expect(Invoice::withoutTenancy()->pluck('sequence')->all())->toBe([1, 2, 3]); // the refused attempt left no gap
});

it('validates the number width at boot and warns near capacity', function () {
    $allocator = app(InvoiceNumberAllocator::class);

    config(['invoicing.number_width' => 5, 'invoicing.series.invoice' => 'INV', 'invoicing.series.credit_note' => 'CN']);
    $allocator->assertConfigured();

    config(['invoicing.number_width' => 8]); // INV/26-27/ + 8 digits = 18
    expect(fn () => $allocator->assertConfigured())->toThrow(InvoicingException::class);
    expect(fn () => (new InvoicingServiceProvider(app()))->boot())->toThrow(InvoicingException::class);

    config(['invoicing.number_width' => 4, 'invoicing.series.invoice' => 'INVOICE']); // INVOICE/26-27/9999 = 18
    expect(fn () => $allocator->assertConfigured())->toThrow(InvoicingException::class);

    config(['invoicing.number_width' => 3, 'invoicing.series.invoice' => 'INV']);
    $allocator->assertConfigured();
    expect($allocator->format('INV', '2026-27', 999))->toBe('INV/26-27/999');
    expect(fn () => $allocator->format('INV', '2026-27', 1000))->toThrow(InvoicingException::class, 'exhausted');

    Log::spy();
    $user = User::factory()->create();
    foreach (range(1, 900) as $_) {
        DB::table('invoice_sequences')->updateOrInsert(['seller_key' => '27AAPFU0939F1ZV', 'series' => 'INV', 'financial_year' => '2026-27'], ['last_number' => 898, 'created_at' => now(), 'updated_at' => now()]);
        break;
    }
    app(InvoiceService::class)->issue($user->currentTenant, [['description' => 'x', 'quantity' => 1, 'unit_price' => 100]], ['payment_method' => 'manual']);
    app(InvoiceService::class)->issue($user->currentTenant, [['description' => 'x', 'quantity' => 1, 'unit_price' => 100]], ['payment_method' => 'manual']);
    Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'nearly full'))->atLeast()->once();
});

it('issues partial credit notes from the operator command', function () {
    $user = User::factory()->create();
    $invoice = app(InvoiceService::class)->issue($user->currentTenant, [['description' => 'Annual', 'quantity' => 1, 'unit_price' => 100000]], ['payment_method' => 'manual']);

    $this->artisan('invoicing:credit-note', ['number' => $invoice->number, 'reason' => 'Goodwill', '--amount' => 25000])->expectsOutput('CN/26-27/00001')->assertSuccessful();
    $this->artisan('invoicing:credit-note', ['number' => $invoice->number, 'reason' => 'Too much', '--amount' => 90000])->assertFailed();
    $this->artisan('invoicing:credit-note', ['number' => $invoice->number, 'reason' => 'Rest'])->assertFailed(); // full credit would exceed what is left

    expect(Invoice::withoutTenancy()->where('type', 'credit_note')->sum('total'))->toEqual(25000);
});

it('locks the invoice when marking it paid, so a second settle fails', function () {
    $user = User::factory()->create();
    $service = app(InvoiceService::class);
    $invoice = $service->issue($user->currentTenant, [['description' => 'x', 'quantity' => 1, 'unit_price' => 100]], ['payment_method' => 'manual']);
    $stale = Invoice::withoutTenancy()->find($invoice->id); // a second operator holding the unpaid copy

    $service->markPaid($invoice, 'A');

    expect(fn () => $service->markPaid($stale, 'B'))->toThrow(InvoicingException::class);
    expect(Invoice::withoutTenancy()->find($invoice->id)->payment_reference)->toBe('A');
});

it('refuses negative amounts in words', function () {
    expect(fn () => AmountInWords::fromPaise(-1))->toThrow(InvalidArgumentException::class);
});

it('backfills at the amount the gateway charged (from the ledger), not the plan price', function () {
    $user = User::factory()->create();
    $subscription = Subscription::factory()->active()->create(['user_id' => $user->id, 'tenant_id' => $user->current_tenant_id, 'last_payment_id' => 'pay_big', 'amount' => 49900]);
    BillingWebhookEvent::create(['event_id' => 'evt_big', 'type' => 'subscription.charged', 'processed_at' => now(), 'payload' => chargeEvent($subscription->gateway_subscription_id, 'pay_big', 59900)]);

    $this->artisan('invoicing:backfill')->expectsOutputToContain('Issued 1')->assertSuccessful();

    expect(Invoice::withoutTenancy()->where('payment_id', 'pay_big')->sole()->total)->toBe(59900);
});

it('still issues credit notes for an erased (soft-deleted) workspace, (the foreign key makes a truly missing one impossible)', function () {
    $user = User::factory()->create();
    $invoice = app(InvoiceService::class)->issue($user->currentTenant, [['description' => 'x', 'quantity' => 1, 'unit_price' => 100000]], ['payment_method' => 'manual']);
    app(AccountService::class)->erase($user);
    expect(Tenant::find($invoice->tenant_id))->toBeNull(); // soft-deleted

    $this->artisan('invoicing:credit-note', ['number' => $invoice->number, 'reason' => 'Account erased, refund'])->expectsOutput('CN/26-27/00001')->assertSuccessful();
});
