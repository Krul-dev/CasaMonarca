<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MigrantRegistryDocument extends Model
{
    use SoftDeletes;

    protected $hidden = ['storage_disk', 'storage_path'];

    protected $fillable = [
        'registry_entry_id',
        'label',
        'original_file_name',
        'mime_type',
        'size_bytes',
        'sha256',
        'storage_disk',
        'storage_path',
        'uploaded_by',
        'uploaded_by_role',
        'purged_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'purged_at' => 'datetime',
    ];

    public function registryEntry(): BelongsTo
    {
        return $this->belongsTo(MigrantRegistryEntry::class, 'registry_entry_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
