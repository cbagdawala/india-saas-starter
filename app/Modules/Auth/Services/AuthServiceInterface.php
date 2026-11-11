<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use Illuminate\Http\Request;

interface AuthServiceInterface
{
    public function register(Request $request, array $data): User;

    public function login(Request $request, string $email, string $password, bool $remember = false): User;

    public function logout(Request $request): void;
}
