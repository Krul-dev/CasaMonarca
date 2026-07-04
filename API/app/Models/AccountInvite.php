<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'email',
    'role',
    'invited_by_user_id',
    'token_hash',
    'expires_at',
    'verified_out_of_band_at',
    'verified_out_of_band_by_user_id',
    'verification_method',
    'verification_note',
    'issued_at',
    'used_at',
    'revoked_at',
])]
class AccountInvite extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'expires_at' => 'datetime',
            'verified_out_of_band_at' => 'datetime',
            'issued_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function verifiedOutOfBandBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_out_of_band_by_user_id');
    }

    public function statusLabel(): string
    {
        if ($this->revoked_at !== null) {
            return 'revoked';
        }

        if ($this->used_at !== null) {
            return 'redeemed';
        }

        if ($this->issued_at !== null && $this->expires_at !== null && $this->expires_at->isPast()) {
            return 'expired';
        }

        if ($this->issued_at !== null) {
            return 'issued';
        }

        if ($this->verified_out_of_band_at !== null) {
            return 'verified';
        }

        return 'draft';
    }
}
