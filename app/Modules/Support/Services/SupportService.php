<?php

namespace App\Modules\Support\Services;

use App\Models\User;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Events\SupportTicketCreated;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Tenancy\Services\TenantService;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SupportService implements SupportServiceInterface
{
    public function __construct(
        private readonly SenderVerifier $senders,
        private readonly TicketClassifier $classifier,
    ) {}

    /** @return LengthAwarePaginator<int, SupportTicket> */
    public function paginateFor(User $user, int $perPage = 15): LengthAwarePaginator
    {
        // Tenant-scoped; inside the workspace a member sees their own tickets, an owner/admin sees all of them.
        $query = SupportTicket::query();
        if (! (app(TenantContext::class)->role()?->isAdmin() ?? false)) {
            $query->where('user_id', $user->id);
        }

        return $query->latest('id')->paginate($perPage);
    }

    /** Contact form ticket from a signed-in user. */
    public function createFromForm(User $user, string $subject, string $body): SupportTicket
    {
        return $this->store([
            'user_id' => $user->id,
            'source' => SupportTicket::SOURCE_FORM,
            'from_email' => $user->email,
            'from_name' => $user->name,
            'subject' => $this->cleanLine($subject, 255),
            'body_text' => $this->cleanBody($body),
            'sender_verified' => $user->hasVerifiedEmail(), // a signed-in user with an unverified address is not a verified sender
            'received_at' => now(),
        ]);
    }

    /**
     * Brevo inbound-parse items ({"items": [{MessageId, From: {Name, Address}, Subject, RawTextBody, RawHtmlBody,
     * ExtractedMarkdownMessage, SentAtDate, Headers, ...}]}). Everything here is untrusted. Duplicates (same MessageId)
     * and items without a valid sender are skipped. A ticket is linked to a user only when the sender is verified
     * (SenderVerifier), so a spoofed From cannot attach a ticket to someone's account.
     *
     * @param  array<int, mixed>  $items
     * @return array{created: int, duplicates: int, rejected: int}
     */
    public function ingestInbound(array $items): array
    {
        $result = ['created' => 0, 'duplicates' => 0, 'rejected' => 0];

        foreach (array_slice($items, 0, 50) as $item) {
            $from = is_array($item) ? ($item['From'] ?? null) : null;
            $address = is_array($from) ? Str::lower(trim((string) ($from['Address'] ?? ''))) : '';
            if ($address === '' || strlen($address) > 254 || ! filter_var($address, FILTER_VALIDATE_EMAIL)) {
                $result['rejected']++;

                continue;
            }

            $subject = $this->cleanLine((string) ($item['Subject'] ?? ''), 255) ?: '(no subject)';
            $body = $this->bodyOf($item);
            $messageId = Str::limit(trim((string) ($item['MessageId'] ?? '')), 250, '');
            if ($messageId === '') {
                $messageId = 'sha256:'.hash('sha256', $address."\n".$subject."\n".($item['SentAtDate'] ?? '')."\n".$body);
            }

            if (SupportTicket::withoutTenancy()->where('message_id', $messageId)->exists()) {
                $result['duplicates']++;

                continue;
            }

            $verified = $this->senders->verified($item, $address);
            // Link only an account whose email was itself verified: anyone can register an address they do not own, and
            // would otherwise receive that person's support mail. The ticket goes to the sender's own (personal) workspace,
            // not whichever workspace they are currently in; everything else stays outside every tenant.
            $sender = $verified ? User::query()->where('email', $address)->whereNotNull('email_verified_at')->first(['id']) : null;
            $tenantId = $sender ? app(TenantService::class)->personalWorkspaceId($sender) : null;

            try {
                $this->store([
                    'user_id' => $sender?->id,
                    'sender_verified' => $verified,
                    'source' => SupportTicket::SOURCE_EMAIL,
                    'message_id' => $messageId,
                    'from_email' => $address,
                    'from_name' => $this->cleanLine((string) ($from['Name'] ?? ''), 255) ?: null,
                    'subject' => $subject,
                    'body_text' => $body,
                    'received_at' => $this->parseDate($item['SentAtDate'] ?? null),
                ], $tenantId);
                $result['created']++;
            } catch (UniqueConstraintViolationException) {
                $result['duplicates']++; // concurrent delivery of the same message
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  int|null  $tenantId  explicit tenant for system-created tickets; null = the current tenant (request) or none
     */
    private function store(array $attributes, ?int $tenantId = null): SupportTicket
    {
        return DB::transaction(function () use ($attributes, $tenantId) {
            $attributes['category'] = $this->classifier->classify($attributes['subject'], $attributes['body_text']);
            $ticket = new SupportTicket($attributes + ['status' => TicketStatus::Open]);
            $ticket->tenant_id = $tenantId;
            $ticket->save();
            SupportTicketCreated::dispatch($ticket); // hook point: listen to SupportTicketCreated for notifications

            return $ticket;
        });
    }

    /** @param array<string, mixed> $item */
    private function bodyOf(array $item): string
    {
        foreach (['RawTextBody', 'ExtractedMarkdownMessage'] as $key) {
            if (is_string($item[$key] ?? null) && trim($item[$key]) !== '') {
                return $this->cleanBody($item[$key]);
            }
        }
        $html = is_string($item['RawHtmlBody'] ?? null) ? $item['RawHtmlBody'] : '';

        return $this->cleanBody(html_entity_decode(strip_tags((string) preg_replace('#<(script|style)\b.*?</\1>#is', '', $html)), ENT_QUOTES | ENT_HTML5));
    }

    private function cleanLine(string $value, int $max): string
    {
        return Str::limit(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value)), $max, '');
    }

    private function cleanBody(string $value): string
    {
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', str_replace("\r\n", "\n", $value));

        return Str::limit(trim($value), (int) config('support.max_body_chars', 20000), '');
    }

    private function parseDate(mixed $value): Carbon
    {
        try {
            $date = is_string($value) && $value !== '' ? Carbon::parse($value) : now();

            return $date->isFuture() ? now() : $date; // a sender-controlled date never lands in the future
        } catch (Throwable) {
            return now();
        }
    }
}
