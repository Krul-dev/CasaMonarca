<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'document_revision_id',
    'signed_by_user_id',
    'signature_type',
    'verification_status',
    'signed_at',
    'signature_hash',
    'metadata',
])]
class DocumentSignature extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'signed_at' => 'datetime',
        ];
    }

    public function documentRevision(): BelongsTo
    {
        return $this->belongsTo(DocumentRevision::class);
    }

    public function signedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by_user_id');
    }

    public function fulfilledRequirement(): HasOne
    {
        return $this->hasOne(DocumentSignatureRequirement::class, 'fulfilled_by_signature_id');
    }
}
