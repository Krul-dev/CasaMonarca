<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'document_id',
    'sequence',
    'signer_role',
    'signer_user_id',
    'fulfilled_by_signature_id',
    'fulfilled_at',
])]
class DocumentSignatureRequirement extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fulfilled_at' => 'datetime',
            'sequence' => 'integer',
            'signer_role' => UserRole::class,
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function signerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signer_user_id');
    }

    public function fulfilledBySignature(): BelongsTo
    {
        return $this->belongsTo(DocumentSignature::class, 'fulfilled_by_signature_id');
    }

    public function isFulfilled(): bool
    {
        return $this->fulfilled_at !== null || $this->fulfilled_by_signature_id !== null;
    }

    public function matchesUser(User $user): bool
    {
        if ($this->signer_user_id !== null) {
            return (int) $this->signer_user_id === (int) $user->getKey();
        }

        return $this->signer_role !== null && $this->signer_role === $user->role;
    }
}
