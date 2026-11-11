<?php

namespace App\Modules\Notes\Services;

use App\Models\User;
use App\Modules\Notes\Models\Note;
use Illuminate\Pagination\LengthAwarePaginator;

class NoteService
{
    /** @return LengthAwarePaginator<int, Note> */
    public function paginateFor(User $user, int $perPage = 15): LengthAwarePaginator
    {
        // Tenant-scoped by BelongsToTenant: every member of the current workspace sees the workspace's notes.
        return Note::query()->latest('id')->paginate($perPage);
    }

    /** @param array<string, mixed> $data */
    public function create(User $user, array $data): Note
    {
        $note = new Note($data);
        $note->user_id = $user->id;
        $note->save();

        return $note;
    }

    /** @param array<string, mixed> $data */
    public function update(Note $note, array $data): Note
    {
        $note->fill($data)->save();

        return $note;
    }

    public function delete(Note $note): void
    {
        $note->delete();
    }
}
