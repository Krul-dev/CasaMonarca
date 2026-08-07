<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 38px 42px; }
        body { color: #172b45; font-family: "DejaVu Sans", sans-serif; font-size: 9px; line-height: 1.45; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        h2 { border-bottom: 1px solid #a6292e; font-size: 12px; margin: 18px 0 6px; padding-bottom: 4px; }
        p { margin: 5px 0; }
        table { border-collapse: collapse; page-break-inside: auto; width: 100%; }
        tr { page-break-inside: avoid; }
        td, th { border-bottom: 1px solid #d9dee5; overflow-wrap: anywhere; padding: 5px; text-align: left; vertical-align: top; }
        th { font-weight: 700; }
        .header { border-bottom: 3px solid #a6292e; margin-bottom: 18px; padding-bottom: 10px; }
        .meta { color: #52657a; }
        .question th { width: 35%; }
        .hash { font-family: monospace; font-size: 7px; }
        .footer { color: #68798b; font-size: 7px; margin-top: 22px; }
    </style>
</head>
<body>
@php
    $statusLabels = [
        'pending_review' => 'Pendiente de revisión',
        'pending_approval' => 'Pendiente de aprobación',
        'changes_requested' => 'Cambios solicitados',
        'approved' => 'Aprobado',
        'rejected' => 'Rechazado',
    ];
    $roleLabels = [
        'admin' => 'Administración',
        'coordinator' => 'Coordinación',
        'non_coordinator' => 'Personal no coordinador',
        'volunteer' => 'Voluntariado',
    ];
    $actionLabels = [
        'submit' => 'Registro enviado',
        'review' => 'Revisión aprobada',
        'approve' => 'Registro aprobado',
        'reject' => 'Registro rechazado',
    ];
@endphp
<div class="header">
    <strong>Casa Monarca Ayuda Humanitaria al Migrante A.B.P.</strong>
    <h1>Expediente de registro migrante</h1>
    <div class="meta">Registro #{{ $entry->id }} · Generado {{ now()->format('d/m/Y H:i:s') }}</div>
</div>

<h2>Procedencia y estado actual</h2>
<table class="question">
    <tr><th>Identificador del registro</th><td>{{ $entry->id }}</td></tr>
    <tr><th>Fecha de creación</th><td>{{ $entry->created_at?->format('d/m/Y H:i:s') ?? 'No disponible' }}</td></tr>
    <tr><th>Última actualización</th><td>{{ $entry->updated_at?->format('d/m/Y H:i:s') ?? 'No disponible' }}</td></tr>
    <tr><th>Estado actual</th><td>{{ $statusLabels[$entry->current_status] ?? str($entry->current_status)->replace('_', ' ')->title() }}</td></tr>
    <tr><th>Registrado por</th><td>{{ $entry->creator?->name ?? 'Usuario no disponible' }} · {{ $entry->creator?->email ?? 'Sin correo disponible' }} · {{ $roleLabels[$entry->created_by_role] ?? $entry->created_by_role }}</td></tr>
</table>

@if(count($questionnaireSections ?? []) > 0)
    @foreach($questionnaireSections as $section)
        <h2>{{ $section['title'] }}</h2>
        <table class="question">
            @foreach($section['answers'] as $answer)
                <tr><th>{{ $answer['question'] }}</th><td>{{ $answer['answer'] }}</td></tr>
            @endforeach
        </table>
    @endforeach
@else
    <h2>Datos del registro</h2>
    <table class="question">
        @foreach(($entry->payload_json ?? []) as $key => $value)
            <tr><th>{{ str($key)->headline() }}</th><td>{{ is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE) }}</td></tr>
        @endforeach
    </table>
@endif

<h2>Cadena de firmas del registro</h2>
@if($entry->signatures->isNotEmpty())
    <table>
        <tr><th>Acción</th><th>Persona firmante</th><th>Rol</th><th>CURP</th><th>Verificada</th></tr>
        @foreach($entry->signatures as $signature)
            <tr>
                <td>{{ $actionLabels[$signature->action_type] ?? str($signature->action_type)->replace('_', ' ')->title() }}</td>
                <td>{{ $signature->actor?->name ?? 'Usuario no disponible' }}<br><span class="meta">{{ $signature->actor?->email }}</span></td>
                <td>{{ $roleLabels[$signature->actor_role] ?? $signature->actor_role }}</td>
                <td>{{ $signature->actor?->curp ?? 'No registrada' }}</td>
                <td>{{ $signature->verified_at?->format('d/m/Y H:i:s') ?? 'No disponible' }}</td>
            </tr>
        @endforeach
    </table>
@else
    <p class="meta">Este registro todavía no tiene firmas verificadas.</p>
@endif

<h2>Historial de estados</h2>
@if($entry->statusHistory->isNotEmpty())
    <table>
        <tr><th>Estado anterior</th><th>Estado nuevo</th><th>Responsable</th><th>Motivo</th><th>Fecha</th></tr>
        @foreach($entry->statusHistory as $history)
            <tr>
                <td>{{ $history->from_status ? ($statusLabels[$history->from_status] ?? str($history->from_status)->replace('_', ' ')->title()) : 'Inicio' }}</td>
                <td>{{ $statusLabels[$history->to_status] ?? str($history->to_status)->replace('_', ' ')->title() }}</td>
                <td>{{ $history->changer?->name ?? 'Usuario no disponible' }}<br><span class="meta">{{ $roleLabels[$history->changed_by_role] ?? $history->changed_by_role }}</span></td>
                <td>{{ $history->reason ?: 'Sin motivo registrado' }}</td>
                <td>{{ $history->created_at?->format('d/m/Y H:i:s') ?? 'No disponible' }}</td>
            </tr>
        @endforeach
    </table>
@else
    <p class="meta">No hay cambios de estado registrados.</p>
@endif

<h2>Inventario de documentos de soporte</h2>
@if($entry->documents->isNotEmpty())
    <table>
        <tr><th>Documento</th><th>Tipo y tamaño</th><th>SHA-256</th><th>Cargado por</th></tr>
        @foreach($entry->documents as $document)
            <tr>
                <td>{{ $document->label ? $document->label.' · '.$document->original_file_name : $document->original_file_name }}</td>
                <td>{{ $document->mime_type ?? 'No disponible' }}<br>{{ number_format(($document->size_bytes ?? 0) / 1024, 1) }} KB</td>
                <td class="hash">{{ $document->sha256 }}</td>
                <td>{{ $document->uploader?->name ?? 'Usuario no disponible' }}<br><span class="meta">{{ $roleLabels[$document->uploaded_by_role] ?? $document->uploaded_by_role }} · {{ $document->created_at?->format('d/m/Y H:i:s') }}</span></td>
            </tr>
        @endforeach
    </table>
@else
    <p class="meta">Este registro no tiene documentos de soporte activos.</p>
@endif

<p class="footer">Documento confidencial generado automáticamente a partir del expediente vigente. La descarga fue autorizada mediante una llave de acceso WebAuthn y quedó registrada en la bitácora de auditoría. Este PDF contiene un inventario de documentos; no incluye los archivos adjuntos.</p>
</body>
</html>
