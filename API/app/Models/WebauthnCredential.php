<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'credential_id',
    'public_key',
    'public_key_algorithm',
    'name',
    'sign_count',
    'transports',
    'attestation_object',
    'client_data_json',
    'last_used_at',
])]
#[Hidden(['public_key', 'attestation_object', 'client_data_json'])]
class WebauthnCredential extends Model
{
    protected function casts(): array
    {
        return [
            'transports' => 'array',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
