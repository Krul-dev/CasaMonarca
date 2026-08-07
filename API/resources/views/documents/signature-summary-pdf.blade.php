<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 34px 42px 42px; }
        body { color: #17283f; font-family: "DejaVu Sans", sans-serif; font-size: 9px; line-height: 1.35; }
        h1 { margin: 4px 0 2px; font-size: 22px; }
        h2 { margin: 18px 0 7px; font-size: 13px; }
        p { margin: 4px 0; }
        .brand { color: #8f5308; font-size: 9px; font-weight: bold; letter-spacing: .8px; }
        .notice { margin: 14px 0; padding: 10px; border: 1px solid #d8bd91; background: #f8efe2; }
        .facts { width: 100%; border-collapse: collapse; }
        .facts td { width: 50%; padding: 5px 7px; border: 1px solid #d9dfe6; vertical-align: top; }
        .label { display: block; color: #58677a; font-size: 7px; font-weight: bold; text-transform: uppercase; }
        .mono { font-family: monospace; font-size: 7px; word-break: break-all; }
        .signature { margin: 0 0 8px; padding: 9px; border: 1px solid #cfd7df; page-break-inside: avoid; }
        .signature h3 { margin: 0 0 5px; font-size: 11px; }
        .signature table { width: 100%; border-collapse: collapse; }
        .signature td { width: 50%; padding: 2px 6px 2px 0; vertical-align: top; }
        .footer { margin-top: 18px; padding-top: 8px; border-top: 1px solid #cfd7df; color: #58677a; font-size: 7px; }
    </style>
</head>
<body>
@php
    $en = $locale === 'en';
    $statusLabel = match ($revision->signature_status) {
        'signed' => $en ? 'Signed' : 'Firmado',
        'partially_signed' => $en ? 'Partially signed' : 'Firmado parcialmente',
        default => $revision->signature_status,
    };
@endphp
<div class="brand">CASA MONARCA</div>
<h1>{{ $en ? 'Document signature record' : 'Registro de firmas del documento' }}</h1>
<p>{{ $en ? 'Presentation copy generated from the internal Documents/VCS workspace.' : 'Copia de consulta generada desde el espacio interno de Documentos/VCS.' }}</p>

<div class="notice">
    <strong>{{ $en ? 'Verification notice' : 'Aviso de verificación' }}</strong><br>
    {{ $en
        ? 'This page is a visual record of WebAuthn signatures; it is not a PAdES or Acrobat certificate signature. This presentation PDF has different bytes from the signed revision. Verify the untouched original revision with the Casa Monarca verification package and verify.html.'
        : 'Esta página es un registro visual de firmas WebAuthn; no es una firma PAdES ni una firma de certificado de Acrobat. Este PDF de consulta tiene bytes distintos de la versión firmada. Verifica la versión original sin cambios con el paquete de verificación de Casa Monarca y verify.html.' }}
</div>

<table class="facts">
    <tr>
        <td><span class="label">{{ $en ? 'Document' : 'Documento' }}</span>{{ $document->title }}</td>
        <td><span class="label">{{ $en ? 'Original file' : 'Archivo original' }}</span>{{ $revision->original_file_name }}</td>
    </tr>
    <tr>
        <td><span class="label">{{ $en ? 'Revision' : 'Versión' }}</span>{{ $revision->revision_number }}</td>
        <td><span class="label">{{ $en ? 'Signature status' : 'Estado de firma' }}</span>{{ $statusLabel }}</td>
    </tr>
    <tr>
        <td colspan="2"><span class="label">{{ $en ? 'Original revision SHA-256' : 'SHA-256 de la versión original' }}</span><span class="mono">{{ $revision->sha256 }}</span></td>
    </tr>
    <tr>
        <td><span class="label">{{ $en ? 'Policy progress' : 'Avance de la política' }}</span>{{ $policyTotal > 0 ? "{$policyFulfilled} / {$policyTotal}" : ($en ? 'Open signing; no fixed requirements' : 'Firma abierta; sin requisitos fijos') }}</td>
        <td><span class="label">{{ $en ? 'Generated at (UTC)' : 'Generado en (UTC)' }}</span>{{ $generatedAt }}</td>
    </tr>
</table>

<h2>{{ $en ? 'Collected signatures' : 'Firmas recopiladas' }} ({{ $signatures->count() }})</h2>
@foreach ($signatures as $index => $signature)
    <section class="signature">
        <h3>{{ $index + 1 }}. {{ $signature['name'] ?: ($en ? 'Unknown signer' : 'Firmante desconocido') }}</h3>
        <table>
            <tr>
                <td><span class="label">{{ $en ? 'Role' : 'Rol' }}</span>{{ $signature['role'] }}</td>
                <td><span class="label">Email</span>{{ $signature['email'] ?: ($en ? 'Not recorded' : 'No registrado') }}</td>
            </tr>
            <tr>
                <td><span class="label">CURP</span>{{ $signature['curp'] ?: ($en ? 'Not recorded' : 'No registrada') }}</td>
                <td><span class="label">{{ $en ? 'Signed at (UTC)' : 'Firmado en (UTC)' }}</span>{{ $signature['signedAt'] ?: ($en ? 'Not recorded' : 'No registrado') }}</td>
            </tr>
            <tr>
                <td><span class="label">{{ $en ? 'Expires at (UTC)' : 'Vence en (UTC)' }}</span>{{ $signature['expiresAt'] ?: ($en ? 'No expiry recorded' : 'Sin vencimiento registrado') }}</td>
                <td><span class="label">{{ $en ? 'Validity' : 'Vigencia' }}</span>{{ $signature['isExpired'] ? ($en ? 'Expired' : 'Vencida') : ($en ? 'Current' : 'Vigente') }} · {{ $signature['status'] }}</td>
            </tr>
            <tr>
                <td><span class="label">{{ $en ? 'Signature ID' : 'ID de firma' }}</span>{{ $signature['id'] }}</td>
                <td><span class="label">{{ $en ? 'Public-key fingerprint (SHA-256)' : 'Huella de llave pública (SHA-256)' }}</span><span class="mono">{{ $signature['fingerprint'] ?: ($en ? 'Not recorded' : 'No registrada') }}</span></td>
            </tr>
        </table>
    </section>
@endforeach

<p class="footer">
    {{ $en
        ? 'Authoritative evidence: the original revision file, its SHA-256, the WebAuthn assertion in verification.json, and the server-signed package manifest.'
        : 'Evidencia autoritativa: el archivo original de la versión, su SHA-256, la aserción WebAuthn en verification.json y el manifiesto del paquete firmado por el servidor.' }}
</p>
</body>
</html>
