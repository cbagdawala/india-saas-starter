<?php

use App\Models\User;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Services\InvoicePdf;
use App\Modules\Invoicing\Services\InvoiceService;
use App\Modules\Invoicing\Support\UpiPayment;

beforeEach(function () {
    spa();
    configureSeller();
    $this->user = User::factory()->create();
    $this->service = app(InvoiceService::class);
    $this->upi = app(UpiPayment::class);
});

function newManual(object $test, int $amount = 49900, array $options = []): Invoice
{
    return $test->service->issue($test->user->currentTenant, [['description' => 'Plan', 'quantity' => 1, 'unit_price' => $amount]], ['payment_method' => 'manual'] + $options);
}

it('builds a properly encoded UPI deep link with a 2-decimal amount', function () {
    config(['invoicing.seller.legal_name' => 'Rao & Sons Pvt Ltd', 'invoicing.upi.vpa' => 'rao.sons@okhdfcbank']);
    $invoice = newManual($this, 49905);

    $link = $this->upi->link($invoice);
    parse_str(substr($link, strlen('upi://pay?')), $q);

    expect($link)->toStartWith('upi://pay?pa=rao.sons%40okhdfcbank&pn=')->and($link)->toContain('pn=Rao%20%26%20Sons%20Pvt%20Ltd')->not->toContain('+')
        ->and($q)->toBe(['pa' => 'rao.sons@okhdfcbank', 'pn' => 'Rao & Sons Pvt Ltd', 'am' => '499.05', 'cu' => 'INR', 'tn' => 'Invoice INV/26-27/00001', 'tr' => 'INV2627'.'00001']);
});

it('formats whole-rupee and paisa amounts with two decimals', function () {
    parse_str(substr($this->upi->link(newManual($this, 100000)), 10), $q);
    expect($q['am'])->toBe('1000.00');
    parse_str(substr($this->upi->link(newManual($this, 5)), 10), $q);
    expect($q['am'])->toBe('0.05');
});

it('uses a separate payee name when configured', function () {
    config(['invoicing.upi.payee_name' => 'Example Pay']);
    parse_str(substr($this->upi->link(newManual($this)), 10), $q);

    expect($q['pn'])->toBe('Example Pay');
});

it('is offered only for unpaid manual tax invoices', function () {
    $paidGateway = newManual($this, 49900, ['payment_method' => 'gateway', 'paid' => true]);
    $unpaid = newManual($this);
    $note = $this->service->issueCreditNote($unpaid, 'x');
    $settled = $this->service->markPaid(Invoice::withoutTenancy()->find(newManual($this)->id), 'ref');

    expect($this->upi->link($unpaid))->not->toBeNull()
        ->and($this->upi->link($paidGateway))->toBeNull()
        ->and($this->upi->link($note))->toBeNull()
        ->and($this->upi->link($settled))->toBeNull();
});

it('is off without a valid VPA', function () {
    $invoice = newManual($this);

    foreach ([null, '', 'no-at-sign', 'a@b', 'bad vpa@okbank', 'x@bank!'] as $vpa) {
        config(['invoicing.upi.vpa' => $vpa]);
        expect($this->upi->link($invoice))->toBeNull();
    }
    foreach (['name@okbank', 'first.last-1_x@ybl', '9876543210@paytm'] as $vpa) {
        expect(UpiPayment::isValidVpa($vpa))->toBeTrue();
    }
});

it('produces a QR as an SVG data URI and shows it only on unpaid manual invoices in the API', function () {
    $unpaid = newManual($this);
    $paid = newManual($this, 49900, ['payment_method' => 'gateway', 'paid' => true]);

    $json = $this->actingAs($this->user)->getJson("/api/invoices/{$unpaid->id}")->assertOk()->json('data.upi');
    expect($json['link'])->toStartWith('upi://pay?')->and($json['qr'])->toStartWith('data:image/svg+xml;base64,')->and($json['note'])->toContain('does not update the invoice automatically')
        ->and(base64_decode(substr($json['qr'], strlen('data:image/svg+xml;base64,'))))->toContain('<svg');

    $this->actingAs($this->user)->getJson("/api/invoices/{$paid->id}")->assertOk()->assertJsonPath('data.upi', null);
});

it('prints the QR on the PDF only for unpaid manual invoices', function () {
    $pdf = app(InvoicePdf::class);
    $unpaid = $pdf->render(newManual($this));
    $paid = $pdf->render(newManual($this, 49900, ['payment_method' => 'gateway', 'paid' => true]));

    expect(substr($unpaid, 0, 5))->toBe('%PDF-')->and(strlen($unpaid))->toBeGreaterThan(strlen($paid));
});
