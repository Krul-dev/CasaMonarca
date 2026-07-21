<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        body { color: #172b45; font-family: "DejaVu Sans", sans-serif; font-size: 10px; line-height: 1.45; }
        h1 { font-size: 18px; margin: 0 0 4px; } h2 { border-bottom: 1px solid #a6292e; font-size: 13px; margin-top: 20px; padding-bottom: 4px; }
        .header { border-bottom: 3px solid #a6292e; margin-bottom: 22px; padding-bottom: 12px; }
        .meta { color: #52657a; } table { border-collapse: collapse; width: 100%; } td, th { border-bottom: 1px solid #d9dee5; padding: 6px; text-align: left; vertical-align: top; }
        th { width: 34%; } .footer { color: #68798b; font-size: 8px; margin-top: 24px; }
    </style>
</head>
<body>
<div class="header"><strong>Casa Monarca Ayuda Humanitaria al Migrante A.B.P.</strong><h1>Respuesta al Derecho de Acceso</h1><div class="meta">Solicitud ARCO #{{ $arco->id }} · Generado {{ now()->format('d/m/Y H:i:s') }}</div></div>
<p>El presente documento reúne la información vigente asociada al registro solicitado y su procedencia dentro del sistema.</p>
<h2>Datos del registro</h2>
@if(count($questionnaireSections ?? []) > 0)
@foreach($questionnaireSections as $section)
<h2>{{ $section['title'] }}</h2>
<table>@foreach($section['answers'] as $answer)<tr><th>{{ $answer['question'] }}</th><td>{{ $answer['answer'] }}</td></tr>@endforeach</table>
@endforeach
@else
<table>@foreach(($arco->registryEntry?->payload_json ?? $arco->original_payload_json ?? []) as $key => $value)<tr><th>{{ str($key)->headline() }}</th><td>{{ is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE) }}</td></tr>@endforeach</table>
@endif
<h2>Procedencia</h2>
<table><tr><th>Identificador del registro</th><td>{{ $arco->registry_entry_id }}</td></tr><tr><th>Fecha de creación</th><td>{{ $arco->registryEntry?->created_at }}</td></tr><tr><th>Estado</th><td>{{ $arco->registryEntry?->current_status }}</td></tr><tr><th>Solicitado por</th><td>{{ $arco->requester?->name }} ({{ $arco->requested_by_role }})</td></tr></table>
<h2>Documentos adjuntos</h2>
@if(($arco->registryEntry?->documents?->count() ?? 0) > 0)
<table><tr><th>Archivo</th><th>Tipo</th><th>SHA-256</th><th>Cargado por</th></tr>@foreach($arco->registryEntry->documents as $document)<tr><td>{{ $document->label ? $document->label.' — '.$document->original_file_name : $document->original_file_name }}</td><td>{{ $document->mime_type ?? 'n/d' }}</td><td>{{ $document->sha256 }}</td><td>{{ $document->uploaded_by_role }} · {{ $document->created_at }}</td></tr>@endforeach</table>
@else
<p class="meta">Este registro no tiene documentos adjuntos.</p>
@endif
<h2>Cadena de firmas</h2>
<table><tr><th>Acción</th><th>Rol</th><th>Fecha</th></tr>@foreach($arco->signatures as $signature)<tr><td>{{ $signature->action_type }}</td><td>{{ $signature->actor_role }}</td><td>{{ $signature->verified_at }}</td></tr>@endforeach</table>
<h2>Historial de la solicitud</h2>
<table><tr><th>Estado anterior</th><th>Estado nuevo</th><th>Fecha</th></tr>@foreach($arco->statusHistory as $history)<tr><td>{{ $history->from_status ?? 'Inicio' }}</td><td>{{ $history->to_status }}</td><td>{{ $history->created_at }}</td></tr>@endforeach</table>
<p class="footer">Documento generado automáticamente a partir de una solicitud ARCO aprobada mediante firmas WebAuthn verificadas.</p>
</body>
</html>
