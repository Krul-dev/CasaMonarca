<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MigrantArcoRequest extends Model
{
    protected $fillable = [
        'registry_entry_id',
        'requested_by',
        'requested_by_role',
        'request_type',
        'reason',
        'original_payload_json',
        'proposed_payload_json',
        'original_payload_hash',
        'proposed_payload_hash',
        'status',
        'escalated_to_admin',
        'resolved_by',
        'resolved_by_role',
        'resolution_reason',
        'completed_at',
    ];

    protected $casts = [
        'escalated_to_admin' => 'boolean',
        'original_payload_json' => 'array',
        'proposed_payload_json' => 'array',
        'completed_at' => 'datetime',
    ];

    public function registryEntry(): BelongsTo
    {
        return $this->belongsTo(MigrantRegistryEntry::class, 'registry_entry_id')->withTrashed();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(MigrantArcoSignature::class, 'arco_request_id')->with('actor:id,name,email,role');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(MigrantArcoStatusHistory::class, 'arco_request_id');
    }

    public function artifact(): HasOne
    {
        return $this->hasOne(MigrantArcoArtifact::class, 'arco_request_id');
    }
}
