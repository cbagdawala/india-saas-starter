<?php

use App\Models\User;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Services\InvoicePdf;
use App\Modules\Invoicing\Services\InvoiceService;
use App\Modules\Tenancy\Enums\TenantRole;

beforeEach(function () {
    spa();
    configureSeller();
    $this->owner = User::factory()->create();
    $this->tenant = $this->owner->currentTenant;
    setBillingProfile($this->tenant, ['state_code' => '29']);
    $this->invoice = app(InvoiceService::class)->issue($this->tenant, [['description' => 'Annual plan', 'quantity' => 1, 'unit_price' => 118000]], ['payment_method' => 'manual']);
});

it('lists and shows invoices for any member, with the tax breakdown', function () {
    $member = addMember($this->tenant, User::factory()->create());

    $this->actingAs($member)->getJson('/api/invoices')->assertOk()->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.data.0.number', 'INV/26-27/00001')
        ->assertJsonMissingPath('data.data.0.seller');

    $this->actingAs($member)->getJson("/api/invoices/{$this->invoice->id}")->assertOk()
        ->assertJsonPath('data.status', 'unpaid')->assertJsonPath('data.igst', 18000)->assertJsonPath('data.total_in_words', 'Rupees One Thousand One Hundred Eighty Only')
        ->assertJsonPath('data.reverse_charge', false)->assertJsonPath('data.buyer.legal_name', 'Buyer Pvt Ltd')->assertJsonPath('data.lines.0.sac', '998314');
});

it('does not show another workspace invoices (404) or let anonymous users in', function () {
    $other = User::factory()->create();

    $this->actingAs($other)->getJson("/api/invoices/{$this->invoice->id}")->assertNotFound();
    $this->actingAs($other)->getJson("/api/invoices/{$this->invoice->id}/pdf")->assertNotFound();
    $this->actingAs($other)->getJson('/api/invoices')->assertJsonPath('data.meta.total', 0);
});

it('requires authentication', function () {
    $this->getJson('/api/invoices')->assertUnauthorized();
    $this->getJson("/api/invoices/{$this->invoice->id}/pdf")->assertUnauthorized();
    $this->getJson('/api/billing-profile')->assertUnauthorized();
});

it('downloads a PDF', function () {
    $response = $this->actingAs($this->owner)->get("/api/invoices/{$this->invoice->id}/pdf", ['Accept' => 'application/pdf'])->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect($response->headers->get('Content-Disposition'))->toContain('INV-26-27-00001.pdf')->and(substr($response->getContent(), 0, 5))->toBe('%PDF-');
});

it('renders PDFs for every supply type, credit notes, and paid invoices without error', function () {
    $service = app(InvoiceService::class);
    $pdf = app(InvoicePdf::class);
    $documents = [$this->invoice, $service->issueCreditNote($this->invoice, 'Duplicate')];

    setBillingProfile($this->tenant, ['state_code' => '27']);
    $documents[] = $service->issue($this->tenant, [['description' => 'Plan', 'quantity' => 2, 'unit_price' => 49900, 'discount' => 100]], ['payment_method' => 'gateway', 'paid' => true]);
    setBillingProfile($this->tenant, ['country' => 'US', 'state_code' => null]);
    $documents[] = $service->issue($this->tenant, [['description' => 'Plan', 'quantity' => 1, 'unit_price' => 49900]]);

    foreach ($documents as $document) {
        expect(substr($pdf->render($document->fresh()), 0, 5))->toBe('%PDF-');
    }
});

it('limits the billing profile to owners and admins and validates it', function () {
    $admin = addMember($this->tenant, User::factory()->create(), TenantRole::Admin);
    $member = addMember($this->tenant, User::factory()->create());
    $payload = ['legal_name' => 'Acme Traders', 'address_line1' => '1 MG Road', 'city' => 'Pune', 'pincode' => '411001', 'country' => 'IN', 'state_code' => '27', 'gstin' => '27aapfu0939f1zv'];

    $this->actingAs($member)->getJson('/api/billing-profile')->assertForbidden();
    $this->actingAs($member)->putJson('/api/billing-profile', $payload)->assertForbidden();

    $this->actingAs($admin)->putJson('/api/billing-profile', $payload)->assertOk()->assertJsonPath('data.gstin', '27AAPFU0939F1ZV')->assertJsonPath('data.complete', true);
    $this->actingAs($this->owner)->getJson('/api/billing-profile')->assertOk()->assertJsonPath('data.legal_name', 'Acme Traders');
    expect($this->tenant->fresh()->state_code)->toBe('27');

    // an issued invoice is unaffected
    expect(Invoice::withoutTenancy()->find($this->invoice->id)->buyer['legal_name'])->toBe('Buyer Pvt Ltd');
});

it('rejects bad billing profile input', function () {
    $ok = ['legal_name' => 'Acme', 'address_line1' => '1 MG Road', 'city' => 'Pune', 'pincode' => '411001', 'country' => 'IN', 'state_code' => '27'];
    $put = fn (array $change) => $this->actingAs($this->owner)->putJson('/api/billing-profile', $change + $ok);

    $put(['gstin' => '27AAPFU0939F1ZW'])->assertUnprocessable()->assertJsonValidationErrors('gstin');          // checksum
    $put(['gstin' => '29AABCT1332L1ZA'])->assertUnprocessable()->assertJsonValidationErrors('state_code');       // state vs GSTIN mismatch
    $put(['state_code' => '99'])->assertUnprocessable()->assertJsonValidationErrors('state_code');               // Centre Jurisdiction
    $put(['state_code' => '25'])->assertUnprocessable()->assertJsonValidationErrors('state_code');               // retired
    $put(['state_code' => null])->assertUnprocessable()->assertJsonValidationErrors('state_code');               // required for India
    $put(['pincode' => '12345'])->assertUnprocessable()->assertJsonValidationErrors('pincode');
    $put(['legal_name' => ''])->assertUnprocessable()->assertJsonValidationErrors('legal_name');
    $put(['country' => 'US', 'state_code' => null, 'pincode' => null, 'gstin' => '27AAPFU0939F1ZV'])->assertUnprocessable()->assertJsonValidationErrors('gstin');
    $put(['country' => 'US', 'state_code' => null, 'pincode' => null, 'gstin' => null])->assertOk();
    $put(['state_code' => 7])->assertOk()->assertJsonPath('data.state_code', '07');                              // normalised
});

it('does not accept tenant_id or other columns through the billing profile', function () {
    $this->actingAs($this->owner)->putJson('/api/billing-profile', ['legal_name' => 'Acme', 'address_line1' => 'x', 'city' => 'Pune', 'pincode' => '411001', 'country' => 'IN', 'state_code' => '27', 'name' => 'Hijack', 'slug' => 'hijack'])->assertOk();

    expect($this->tenant->fresh()->name)->not->toBe('Hijack')->and($this->tenant->fresh()->slug)->not->toBe('hijack');
});
