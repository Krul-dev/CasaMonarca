<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MigrantArcoRequest extends Model
{
    protected $fillable = [
        'registry_entry_id',
        'requested_by',
        'requested_by_role',
        'request_type',
        'reason',
        'status',
        'escalated_to_admin',
        'resolved_by',
        'resolved_by_role',
        'resolution_reason',
    ];

    protected $casts = [
        'escalated_to_admin' => 'boolean',
    ];

    public function registryEntry(): BelongsTo
    {
        return $this->belongsTo(MigrantRegistryEntry::class, 'registry_entry_id');
    }
}
