<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'document_id',
    'parent_revision_id',
    'created_by_user_id',
    'revision_number',
    'storage_disk',
    'storage_path',
    'original_file_name',
    'mime_type',
    'size_bytes',
    'sha256',
    'signature_status',
    'diff_metadata',
])]
class DocumentRevision extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'diff_metadata' => 'array',
            'size_bytes' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function parentRevision(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_revision_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(DocumentSignature::class);
    }
}
