<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SolicitudRectificacion extends Model
{
    protected $table = 'solicitudes_rectificacion';

    protected $fillable = [
        'documento_id',
        'solicitante_id',
        'doc_nombre',
        'doc_etiqueta',
        'tipo',
        'descripcion',
        'tomado_por',
        'documento_propuesta_id',
        'status',
        'aprobada_por',
        'firma_b64',
        'aprobada_at',
    ];

    protected $casts = [
        'aprobada_at' => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function documento(): BelongsTo
    {
        return $this->belongsTo(Documento::class, 'documento_id');
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitante_id');
    }

    public function tomadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tomado_por');
    }

    public function propuesta(): BelongsTo
    {
        return $this->belongsTo(Documento::class, 'documento_propuesta_id');
    }

    public function aprobadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobada_por');
    }

    // ── Helpers ───────────────────────────────────────────────────

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pendiente'            => 'Pendiente',
            'en_proceso'           => 'En proceso',
            'pendiente_aprobacion' => 'Esperando aprobación',
            'aprobada'             => 'Aprobada',
            'rechazada'            => 'Rechazada',
            default                => $this->status,
        };
    }

    public function tipoLabel(): string
    {
        return $this->tipo === 'rectificacion' ? 'Corrección' : 'Cancelación';
    }
}
