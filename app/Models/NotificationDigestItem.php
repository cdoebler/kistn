<?php

namespace App\Models;

use Database\Factories\NotificationDigestItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<int|string, mixed>|null $data
 */
#[Fillable(['user_id', 'project_id', 'type', 'ecosystem', 'data', 'created_at'])]
#[WithoutTimestamps]
class NotificationDigestItem extends Model
{
    /** @use HasFactory<NotificationDigestItemFactory> */
    use HasFactory;

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
