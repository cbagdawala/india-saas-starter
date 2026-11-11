<?php

use App\Models\User;
use App\Modules\Notes\Models\Note;

beforeEach(fn () => spa());

it('requires auth', function () {
    $note = Note::factory()->create();
    $this->getJson('/api/notes')->assertUnauthorized();
    $this->postJson('/api/notes', ['title' => 'x'])->assertUnauthorized();
    $this->getJson("/api/notes/{$note->id}")->assertUnauthorized();
    $this->putJson("/api/notes/{$note->id}", ['title' => 'x'])->assertUnauthorized();
    $this->deleteJson("/api/notes/{$note->id}")->assertUnauthorized();
});

it('creates a note owned by the user', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->postJson('/api/notes', ['title' => 'Hello', 'body' => 'World', 'user_id' => 999])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Hello')
        ->assertJsonPath('data.body', 'World')
        ->assertJsonMissingPath('data.user_id');
    expect(Note::withoutTenancy()->first()->user_id)->toBe($user->id);
});

it('allows a null body', function () {
    $this->actingAs(User::factory()->create())->postJson('/api/notes', ['title' => 'T'])
        ->assertCreated()->assertJsonPath('data.body', null);
});

it('lists only own notes, paginated', function () {
    $user = User::factory()->create();
    Note::factory()->count(3)->for($user)->create();
    Note::factory()->count(2)->create();
    $this->actingAs($user)->getJson('/api/notes')
        ->assertOk()
        ->assertJsonCount(3, 'data.data')
        ->assertJsonPath('data.meta.total', 3);
});

it('shows own note', function () {
    $user = User::factory()->create();
    $note = Note::factory()->for($user)->create();
    $this->actingAs($user)->getJson("/api/notes/{$note->id}")->assertOk()->assertJsonPath('data.id', $note->id);
});

it('updates own note', function () {
    $user = User::factory()->create();
    $note = Note::factory()->for($user)->create();
    $this->actingAs($user)->putJson("/api/notes/{$note->id}", ['title' => 'New', 'body' => null])
        ->assertOk()->assertJsonPath('data.title', 'New')->assertJsonPath('data.body', null);
    expect($note->fresh()->title)->toBe('New');
});

it('deletes own note', function () {
    $user = User::factory()->create();
    $note = Note::factory()->for($user)->create();
    $this->actingAs($user)->deleteJson("/api/notes/{$note->id}")->assertOk();
    expect(Note::withoutTenancy()->count())->toBe(0);
});

it('validates input', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->postJson('/api/notes', [])->assertUnprocessable()->assertJsonValidationErrors('title');
    $this->actingAs($user)->postJson('/api/notes', ['title' => str_repeat('a', 121)])->assertUnprocessable()->assertJsonValidationErrors('title');
    $this->actingAs($user)->postJson('/api/notes', ['title' => 'ok', 'body' => ['x']])->assertUnprocessable()->assertJsonValidationErrors('body');
    $note = Note::factory()->for($user)->create();
    $this->actingAs($user)->putJson("/api/notes/{$note->id}", ['title' => ''])->assertUnprocessable()->assertJsonValidationErrors('title');
});

it('hides notes of other workspaces (404, never 403)', function () {
    $owner = User::factory()->create();
    $note = Note::factory()->for($owner)->create(['title' => 'Mine']);
    $other = User::factory()->create();

    $this->actingAs($other)->getJson("/api/notes/{$note->id}")->assertNotFound();
    $this->actingAs($other)->putJson("/api/notes/{$note->id}", ['title' => 'Hacked'])->assertNotFound();
    $this->actingAs($other)->deleteJson("/api/notes/{$note->id}")->assertNotFound();
    $this->actingAs($other)->putJson("/api/notes/{$note->id}", ['title' => ''])->assertNotFound();
    expect($note->fresh()->title)->toBe('Mine');
});

it('returns 404 for missing note', function () {
    $this->actingAs(User::factory()->create())->getJson('/api/notes/9999')->assertNotFound();
});

it('partially updates a note', function () {
    $user = User::factory()->create();
    $note = Note::factory()->for($user)->create(['title' => 'Keep']);
    $this->actingAs($user)->patchJson("/api/notes/{$note->id}", ['body' => 'changed'])->assertOk();
    expect($note->fresh())->title->toBe('Keep')->body->toBe('changed');
});

it('honours per_page', function () {
    $user = User::factory()->create();
    Note::factory()->count(3)->for($user)->create();
    $this->actingAs($user)->getJson('/api/notes?per_page=2')
        ->assertOk()->assertJsonCount(2, 'data.data')->assertJsonPath('data.meta.last_page', 2);
});
