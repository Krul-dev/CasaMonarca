<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'original_document_id',
    'title',
    'deleted_by_user_id',
    'deleted_at',
    'last_sha256',
    'revision_count',
    'metadata',
])]
class DocumentTombstone extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'deleted_at' => 'datetime',
            'metadata' => 'array',
            'revision_count' => 'integer',
        ];
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by_user_id');
    }
}
