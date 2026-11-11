<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\Responses\ApiResponse;
use App\Modules\Tenancy\Enums\TenantRole;
use App\Modules\Tenancy\Http\Requests\InviteMemberRequest;
use App\Modules\Tenancy\Http\Resources\InvitationResource;
use App\Modules\Tenancy\Models\TenantInvitation;
use App\Modules\Tenancy\Services\InvitationService;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/** Pending invitations of the current workspace. {invitation} binding is tenant-scoped, so other tenants' ids give 404. */
class InvitationController extends Controller
{
    public function __construct(private readonly InvitationService $invitations, private readonly TenantContext $context) {}

    public function index(): JsonResponse
    {
        Gate::authorize('manageMembers', $this->context->tenantOrFail());

        return ApiResponse::success('Invitations', InvitationResource::collection($this->invitations->pending()));
    }

    public function store(InviteMemberRequest $request): JsonResponse
    {
        $tenant = $this->context->tenantOrFail();
        $role = TenantRole::from($request->validated('role'));
        Gate::authorize('invite', [$tenant, $role]);

        $invitation = $this->invitations->invite($tenant, $request->user(), $request->validated('email'), $role);

        return ApiResponse::created('Invitation sent', new InvitationResource($invitation));
    }

    public function destroy(TenantInvitation $invitation): JsonResponse
    {
        Gate::authorize('manageMembers', $this->context->tenantOrFail());
        $this->invitations->revoke($invitation);

        return ApiResponse::deleted('Invitation revoked');
    }
}
