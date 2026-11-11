<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\Responses\ApiResponse;
use App\Modules\Tenancy\Http\Requests\AcceptInvitationRequest;
use App\Modules\Tenancy\Http\Requests\StoreTenantRequest;
use App\Modules\Tenancy\Http\Resources\TenantResource;
use App\Modules\Tenancy\Services\InvitationService;
use App\Modules\Tenancy\Services\TenantService;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Workspace list / create / switch / accept-invite: user-level routes that work without a current workspace. */
class TenantController extends Controller
{
    public function __construct(
        private readonly TenantService $tenants,
        private readonly InvitationService $invitations,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success('Workspaces', TenantResource::collection($this->tenants->workspacesFor($request->user())));
    }

    public function store(StoreTenantRequest $request): JsonResponse
    {
        $tenant = $this->tenants->create($request->user(), $request->validated('name'));

        return ApiResponse::created('Workspace created', $this->resource($request, $tenant->id));
    }

    public function switch(Request $request, int $tenantId): JsonResponse
    {
        $tenant = $this->tenants->switchTo($request->user(), $tenantId);

        return ApiResponse::success('Workspace switched', $this->resource($request, $tenant->id));
    }

    public function accept(AcceptInvitationRequest $request): JsonResponse
    {
        $tenant = $this->invitations->accept($request->user(), $request->validated('token'));

        return ApiResponse::success('Invitation accepted', $this->resource($request, $tenant->id));
    }

    /** The current workspace (requires the `tenant` middleware). */
    public function current(Request $request): JsonResponse
    {
        $tenant = $this->context->tenantOrFail();

        return ApiResponse::success('Current workspace', ['id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug, 'role' => $this->context->role()?->value]);
    }

    /** The workspace as the signed-in user sees it (with their role); is_current reads the user's fresh state. */
    private function resource(Request $request, int $tenantId): TenantResource
    {
        return new TenantResource($request->user()->tenants()->whereKey($tenantId)->firstOrFail());
    }
}
