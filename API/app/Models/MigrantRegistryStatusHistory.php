<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MigrantRegistryStatusHistory extends Model
{
    protected $table = 'migrant_registry_status_history';

    protected $fillable = [
        'registry_entry_id',
        'from_status',
        'to_status',
        'changed_by',
        'changed_by_role',
        'reason',
        'signature_id',
    ];

    public function registryEntry(): BelongsTo
    {
        return $this->belongsTo(MigrantRegistryEntry::class, 'registry_entry_id');
    }

    public function signature(): BelongsTo
    {
        return $this->belongsTo(MigrantRegistrySignature::class, 'signature_id');
    }
}
