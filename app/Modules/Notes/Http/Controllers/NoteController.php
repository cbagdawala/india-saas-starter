<?php

namespace App\Modules\Notes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\Responses\ApiResponse;
use App\Modules\Notes\Http\Requests\StoreNoteRequest;
use App\Modules\Notes\Http\Requests\UpdateNoteRequest;
use App\Modules\Notes\Http\Resources\NoteResource;
use App\Modules\Notes\Models\Note;
use App\Modules\Notes\Services\NoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class NoteController extends Controller
{
    public function __construct(private readonly NoteService $notes) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->query('per_page', 15), 100));

        return ApiResponse::paginated($this->notes->paginateFor($request->user(), $perPage), NoteResource::class, 'Notes');
    }

    public function store(StoreNoteRequest $request): JsonResponse
    {
        $note = $this->notes->create($request->user(), $request->validated());

        return ApiResponse::created('Note created', new NoteResource($note));
    }

    public function show(Note $note): JsonResponse
    {
        Gate::authorize('view', $note);

        return ApiResponse::success('Note', new NoteResource($note));
    }

    public function update(UpdateNoteRequest $request, Note $note): JsonResponse
    {

        return ApiResponse::success('Note updated', new NoteResource($this->notes->update($note, $request->validated())));
    }

    public function destroy(Note $note): JsonResponse
    {
        Gate::authorize('delete', $note);
        $this->notes->delete($note);

        return ApiResponse::deleted('Note deleted');
    }
}
