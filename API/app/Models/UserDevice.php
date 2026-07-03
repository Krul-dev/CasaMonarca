<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'device_identifier_hash',
    'alias',
    'user_agent',
    'last_ip_address',
    'first_seen_at',
    'last_seen_at',
    'last_login_at',
    'trusted_at',
    'revoked_at',
])]
class UserDevice extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_login_at' => 'datetime',
            'trusted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function identifierPreview(): string
    {
        return substr($this->device_identifier_hash, 0, 16);
    }
}
