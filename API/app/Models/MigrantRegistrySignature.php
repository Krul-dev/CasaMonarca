<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MigrantRegistrySignature extends Model
{
    protected $fillable = [
        'registry_entry_id',
        'actor_user_id',
        'actor_role',
        'action_type',
        'algorithm',
        'signature_payload',
        'public_key_ref',
        'verified_at',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
    ];

    public function registryEntry(): BelongsTo
    {
        return $this->belongsTo(MigrantRegistryEntry::class, 'registry_entry_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
