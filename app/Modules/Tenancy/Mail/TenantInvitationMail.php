<?php

namespace App\Modules\Tenancy\Mail;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Sent synchronously on purpose: the plaintext token must not be written to a queue table. */
class TenantInvitationMail extends Mailable
{
    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $inviterName,
        public readonly string $acceptUrl,
        public readonly int $expiresInDays,
    ) {}

    public function envelope(): Envelope
    {
        // Fixed subject: the workspace name is chosen by the inviter (anyone can create a workspace), so it must never
        // appear in the subject or be rendered as markup. It only appears HTML-escaped in the body.
        return new Envelope(subject: "You've been invited to a workspace on ".config('app.name'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.tenant-invitation', text: 'emails.tenant-invitation-text');
    }
}
