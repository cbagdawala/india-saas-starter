<?php

namespace App\Modules\Notes\Models;

use App\Models\User;
use App\Modules\Notes\Policies\NotePolicy;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\NoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['title', 'body'])]
#[UseFactory(NoteFactory::class)]
#[UsePolicy(NotePolicy::class)]
class Note extends Model
{
    /** @use HasFactory<NoteFactory> */
    use BelongsToTenant, HasFactory;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
