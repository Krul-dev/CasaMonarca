<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MigrantArcoStatusHistory extends Model
{
    protected $table = 'migrant_arco_status_history';

    protected $fillable = ['arco_request_id', 'from_status', 'to_status', 'changed_by', 'changed_by_role', 'reason', 'signature_id'];
}
