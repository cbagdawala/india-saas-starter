<?php

namespace App\Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\Responses\ApiResponse;
use App\Modules\Invoicing\Http\Resources\InvoiceResource;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Services\InvoicePdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/** Invoices of the current workspace (tenant-scoped; another workspace's id is a 404). Any member can list and download. */
class InvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->query('per_page', 15), 100));

        return ApiResponse::paginated(Invoice::query()->latest('id')->paginate($perPage), InvoiceResource::class, 'Invoices');
    }

    public function show(Invoice $invoice): JsonResponse
    {
        Gate::authorize('view', $invoice);

        return ApiResponse::success('Invoice', new InvoiceResource($invoice->load('lines')));
    }

    public function pdf(Invoice $invoice, InvoicePdf $pdf): Response
    {
        Gate::authorize('view', $invoice);

        return response($pdf->render($invoice->load('lines')), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.str_replace('/', '-', $invoice->number).'.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
