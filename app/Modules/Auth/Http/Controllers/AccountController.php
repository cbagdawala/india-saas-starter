<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\Responses\ApiResponse;
use App\Modules\Auth\Http\Requests\DeleteAccountRequest;
use App\Modules\Auth\Services\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Personal data rights: a JSON export of what the product holds about the signed-in user, and erasure of the account. */
class AccountController extends Controller
{
    public function __construct(private readonly AccountService $accounts) {}

    public function export(Request $request): JsonResponse
    {
        return response()->json($this->accounts->export($request->user()), 200, [
            'Content-Disposition' => 'attachment; filename="my-data.json"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function destroy(DeleteAccountRequest $request): JsonResponse
    {
        $user = $request->user();

        $this->accounts->erase($user); // checks the blockers again under lock; AccountErasureBlocked renders as 409

        // Not AuthService::logout(): the guard's logout re-saves the user to cycle the remember token, which would insert
        // the deleted row again. Drop the guard's user and kill the session instead.
        Auth::guard('web')->forgetUser();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return ApiResponse::deleted('Your account and personal data were deleted. Issued invoices are kept as legal records.');
    }
}
