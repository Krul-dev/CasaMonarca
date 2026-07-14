<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MigrantArcoArtifact extends Model
{
    protected $hidden = ['storage_disk', 'storage_path'];

    protected $fillable = ['arco_request_id', 'storage_disk', 'storage_path', 'filename', 'mime_type', 'byte_size', 'sha256', 'generated_at', 'purged_at'];

    protected $casts = ['generated_at' => 'datetime', 'purged_at' => 'datetime'];
}
