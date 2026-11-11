<?php

namespace App\Modules\Support\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\Responses\ApiResponse;
use App\Modules\Support\Http\Requests\StoreSupportTicketRequest;
use App\Modules\Support\Http\Resources\SupportTicketResource;
use App\Modules\Support\Services\SupportServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Contact form for signed-in users; lists only the caller's own tickets. */
class SupportTicketController extends Controller
{
    public function __construct(private readonly SupportServiceInterface $support) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->query('per_page', 15), 50));

        return ApiResponse::paginated($this->support->paginateFor($request->user(), $perPage), SupportTicketResource::class, 'Support tickets');
    }

    public function store(StoreSupportTicketRequest $request): JsonResponse
    {
        $ticket = $this->support->createFromForm($request->user(), $request->validated('subject'), $request->validated('body'));

        return ApiResponse::created('Ticket created', new SupportTicketResource($ticket));
    }
}
