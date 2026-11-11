<?php

namespace App\Modules\Tenancy\Models;

use App\Models\User;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Enums\TenantRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['email', 'role'])]
#[Hidden(['token_hash'])]
class TenantInvitation extends Model
{
    use BelongsToTenant, SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => TenantRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }
}
