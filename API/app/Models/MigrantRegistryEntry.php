<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MigrantRegistryEntry extends Model
{
    protected $fillable = [
        'created_by',
        'created_by_role',
        'current_status',
        'current_assignee_role',
        'payload_json',
    ];

    protected $casts = [
        'payload_json' => 'array',
    ];

    public function signatures(): HasMany
    {
        return $this->hasMany(MigrantRegistrySignature::class, 'registry_entry_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(MigrantRegistryStatusHistory::class, 'registry_entry_id');
    }

    public function arcoRequests(): HasMany
    {
        return $this->hasMany(MigrantArcoRequest::class, 'registry_entry_id');
    }
}
