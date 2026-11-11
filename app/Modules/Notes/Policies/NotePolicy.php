<?php

namespace App\Modules\Notes\Policies;

use App\Models\User;
use App\Modules\Notes\Models\Note;
use App\Modules\Tenancy\TenantContext;

/**
 * Notes are shared inside a workspace: any member can read them; the author or a workspace owner/admin can change
 * or delete them. The tenant check is a second line behind the global scope (a note of another tenant is never allowed).
 */
class NotePolicy
{
    public function view(User $user, Note $note): bool
    {
        return $this->sameTenant($note);
    }

    public function update(User $user, Note $note): bool
    {
        return $this->sameTenant($note) && $this->authorOrAdmin($user, $note);
    }

    public function delete(User $user, Note $note): bool
    {
        return $this->update($user, $note);
    }

    private function sameTenant(Note $note): bool
    {
        $current = app(TenantContext::class)->id();

        return $current !== null && (int) $note->tenant_id === $current;
    }

    private function authorOrAdmin(User $user, Note $note): bool
    {
        return (int) $note->user_id === (int) $user->id || (app(TenantContext::class)->role()?->isAdmin() ?? false);
    }
}
