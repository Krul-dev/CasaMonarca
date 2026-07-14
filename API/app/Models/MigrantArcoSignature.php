<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MigrantArcoSignature extends Model
{
    protected $hidden = ['signature_payload'];

    protected $fillable = ['arco_request_id', 'actor_user_id', 'actor_role', 'action_type', 'algorithm', 'signature_payload', 'public_key_ref', 'verified_at'];

    protected $casts = ['verified_at' => 'datetime'];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
