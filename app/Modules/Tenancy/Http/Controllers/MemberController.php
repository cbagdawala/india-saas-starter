<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\Responses\ApiResponse;
use App\Modules\Tenancy\Enums\TenantRole;
use App\Modules\Tenancy\Http\Requests\UpdateMemberRoleRequest;
use App\Modules\Tenancy\Http\Resources\MemberResource;
use App\Modules\Tenancy\Services\TenantService;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Members of the current workspace. Every route here runs behind the `tenant` middleware. */
class MemberController extends Controller
{
    public function __construct(private readonly TenantService $tenants, private readonly TenantContext $context) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success('Members', MemberResource::collection($this->tenants->members($this->context->tenantOrFail())));
    }

    public function update(UpdateMemberRoleRequest $request, int $userId): JsonResponse
    {
        $tenant = $this->context->tenantOrFail();
        Gate::authorize('changeRole', $tenant);

        $this->tenants->changeRole($tenant, $userId, TenantRole::from($request->validated('role')), $request->user());

        return ApiResponse::success('Role updated', new MemberResource($tenant->users()->whereKey($userId)->firstOrFail()));
    }

    public function destroy(Request $request, int $userId): JsonResponse
    {
        $tenant = $this->context->tenantOrFail();
        $target = $tenant->users()->whereKey($userId)->first() ?? abort(404);
        Gate::authorize('removeMember', [$tenant, TenantRole::from($target->pivot->role)]);

        $this->tenants->removeMember($tenant, $userId, $request->user());

        return ApiResponse::deleted('Member removed');
    }

    public function leave(Request $request): JsonResponse
    {
        $tenant = $this->context->tenantOrFail();
        Gate::authorize('leave', $tenant);

        $this->tenants->leave($tenant, $request->user());

        return ApiResponse::deleted('You left the workspace');
    }
}
