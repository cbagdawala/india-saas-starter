<?php

namespace App\Modules\Support\Models;

use App\Models\User;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\SupportTicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Customer support ticket. from_email / from_name / body_text are PII and stay in this database. */
#[Fillable(['user_id', 'source', 'message_id', 'from_email', 'from_name', 'subject', 'body_text', 'category', 'sender_verified', 'status', 'received_at'])]
#[Hidden(['message_id'])]
#[UseFactory(SupportTicketFactory::class)]
class SupportTicket extends Model
{
    /** @use HasFactory<SupportTicketFactory> */
    use BelongsToTenant, HasFactory;

    public const SOURCE_EMAIL = 'email';

    public const SOURCE_FORM = 'form';

    /** Form tickets always belong to a workspace; unlinked inbound email (no known sender) lives outside any tenant. */
    public function tenantIsRequired(): bool
    {
        return false;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'sender_verified' => 'boolean',
            'received_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
