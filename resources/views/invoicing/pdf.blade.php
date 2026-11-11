@php
    use App\Modules\Invoicing\Support\AmountInWords;
    use App\Modules\Invoicing\Support\Inr;
    $tz = config('invoicing.timezone');
    $isCredit = $invoice->isCreditNote();
    $seller = $invoice->seller;
    $buyer = $invoice->buyer;
    $intra = $invoice->supply_type === 'intra';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $invoice->number }}</title>
<style>
    @page { margin: 28px 30px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #111; }
    h1 { font-size: 16px; margin: 0 0 2px 0; text-align: center; letter-spacing: 1px; }
    .sub { text-align: center; color: #555; margin-bottom: 10px; }
    table { width: 100%; border-collapse: collapse; }
    td, th { border: 1px solid #888; padding: 3px 4px; vertical-align: top; }
    th { background: #eee; text-align: center; }
    .r { text-align: right; }
    .c { text-align: center; }
    .nob td { border: none; padding: 0; }
    .box { border: 1px solid #888; padding: 6px; }
    .mt { margin-top: 8px; }
    .muted { color: #555; }
    .big { font-size: 11px; font-weight: bold; }
</style>
</head>
<body>
<h1>{{ $isCredit ? 'CREDIT NOTE' : 'TAX INVOICE' }}</h1>
<div class="sub">{{ $isCredit ? 'Credit note under Section 34 of the CGST Act, 2017' : 'Original for recipient' }}</div>

<table class="nob"><tr>
<td style="width:50%; padding-right:6px;">
    <div class="box">
        <div class="muted">Supplier</div>
        <div class="big">{{ $seller['legal_name'] }}</div>
        <div>{{ $seller['address'] }}</div>
        <div>GSTIN: <strong>{{ $seller['gstin'] }}</strong> &nbsp; PAN: {{ $seller['pan'] }}</div>
        <div>State: {{ $seller['state_code'] }} - {{ $seller['state_name'] }}</div>
        @if (! empty($seller['email']))<div>{{ $seller['email'] }}</div>@endif
    </div>
</td>
<td style="width:50%; padding-left:6px;">
    <div class="box">
        <table class="nob">
            <tr><td>{{ $isCredit ? 'Credit note no.' : 'Invoice no.' }}</td><td class="r"><strong>{{ $invoice->number }}</strong></td></tr>
            <tr><td>Date</td><td class="r">{{ $invoice->issued_at->setTimezone($tz)->format('d-m-Y') }}</td></tr>
            @if ($isCredit && $invoice->original)
                <tr><td>Against invoice</td><td class="r">{{ $invoice->original->number }} dt. {{ $invoice->original->issued_at->setTimezone($tz)->format('d-m-Y') }}</td></tr>
                <tr><td>Reason</td><td class="r">{{ $invoice->credit_reason }}</td></tr>
            @endif
            <tr><td>Place of supply</td><td class="r">{{ $invoice->place_of_supply }}</td></tr>
            <tr><td>Whether tax is payable under reverse charge</td><td class="r"><strong>No</strong></td></tr>
            @if (! $isCredit)
                <tr><td>Payment</td><td class="r">{{ $invoice->paid_at ? 'Paid '.$invoice->paid_at->setTimezone($tz)->format('d-m-Y') : 'Unpaid' }}</td></tr>
            @endif
        </table>
    </div>
</td>
</tr></table>

<div class="box mt">
    <div class="muted">Recipient (bill to)</div>
    <div class="big">{{ $buyer['legal_name'] }}</div>
    @if (! empty($buyer['address']))<div>{{ $buyer['address'] }}</div>@endif
    <div>
        @if (($buyer['country'] ?? 'IN') === 'IN')
            State: {{ $buyer['state_code'] ?? $seller['state_code'] }} - {{ $buyer['state_name'] ?? $seller['state_name'] }}
        @else
            Country: {{ $buyer['country'] }}
        @endif
        &nbsp; GSTIN: {{ $buyer['gstin'] ?? 'Unregistered' }}
    </div>
</div>

<table class="mt">
<thead>
<tr>
    <th>#</th><th>Description</th><th>HSN/SAC</th><th>Qty</th><th>Unit price</th><th>Discount</th><th>Taxable value</th>
    @if ($intra)<th>CGST</th><th>SGST</th>@else<th>IGST</th>@endif
    <th>Total</th>
</tr>
</thead>
<tbody>
@foreach ($invoice->lines as $line)
<tr>
    <td class="c">{{ $line->position }}</td>
    <td>{{ $line->description }}</td>
    <td class="c">{{ $line->sac }}</td>
    <td class="r">{{ $line->quantity }}</td>
    <td class="r">{{ Inr::format($line->unit_price) }}</td>
    <td class="r">{{ Inr::format($line->discount) }}</td>
    <td class="r">{{ Inr::format($line->taxable_value) }}</td>
    @if ($intra)
        <td class="r">{{ Inr::format($line->cgst) }}<br><span class="muted">@ {{ Inr::rate(intdiv($line->rate_bp, 2)) }}</span></td>
        <td class="r">{{ Inr::format($line->sgst) }}<br><span class="muted">@ {{ Inr::rate(intdiv($line->rate_bp, 2)) }}</span></td>
    @else
        <td class="r">{{ Inr::format($line->igst) }}<br><span class="muted">@ {{ Inr::rate($line->rate_bp) }}</span></td>
    @endif
    <td class="r">{{ Inr::format($line->total) }}</td>
</tr>
@endforeach
<tr>
    <td colspan="6" class="r"><strong>Total</strong></td>
    <td class="r"><strong>{{ Inr::format($invoice->taxable_value) }}</strong></td>
    @if ($intra)
        <td class="r"><strong>{{ Inr::format($invoice->cgst) }}</strong></td><td class="r"><strong>{{ Inr::format($invoice->sgst) }}</strong></td>
    @else
        <td class="r"><strong>{{ Inr::format($invoice->igst) }}</strong></td>
    @endif
    <td class="r"><strong>{{ Inr::format($invoice->total) }}</strong></td>
</tr>
</tbody>
</table>
<div class="muted">Amounts in INR. {{ $invoice->prices_include_tax ? 'Unit prices include GST.' : 'Unit prices exclude GST.' }}</div>

<table class="mt">
<thead>
<tr><th>HSN/SAC</th><th>Taxable value</th><th>Rate</th>@if ($intra)<th>CGST</th><th>SGST</th>@else<th>IGST</th>@endif<th>Total tax</th></tr>
</thead>
<tbody>
@foreach ($summary as $row)
<tr>
    <td class="c">{{ $row['sac'] }}</td>
    <td class="r">{{ Inr::format($row['taxable_value']) }}</td>
    <td class="c">{{ Inr::rate($row['rate_bp']) }}</td>
    @if ($intra)<td class="r">{{ Inr::format($row['cgst']) }}</td><td class="r">{{ Inr::format($row['sgst']) }}</td>@else<td class="r">{{ Inr::format($row['igst']) }}</td>@endif
    <td class="r">{{ Inr::format($row['cgst'] + $row['sgst'] + $row['igst']) }}</td>
</tr>
@endforeach
</tbody>
</table>

<div class="box mt">
    <div><strong>Total {{ $isCredit ? 'credited' : 'invoice value' }}: INR {{ Inr::format($invoice->total) }}</strong></div>
    <div>Amount in words: {{ AmountInWords::fromPaise($invoice->total) }}</div>
    @if ($invoice->tax_note)<div class="muted">{{ $invoice->tax_note }}</div>@endif
</div>

@if ($upi)
<table class="nob mt"><tr>
    <td style="width:130px;"><img src="{{ $upi['qr'] }}" width="120" height="120" alt="UPI QR"></td>
    <td>
        <strong>Pay by UPI</strong><br>
        Scan the QR with any UPI app. Amount INR {{ Inr::format($invoice->total) }}, reference {{ $invoice->number }}.<br>
        <span class="muted">Payment is confirmed only after it is reconciled with our bank statement.</span>
    </td>
</tr></table>
@endif

<div class="muted mt">This is a computer generated document and does not require a signature.</div>
</body>
</html>
