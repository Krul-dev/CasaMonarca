<?php

namespace App\Http\Controllers;

use App\Models\ActividadLog;
use App\Models\Documento;
use App\Models\Firma;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FirmaController extends Controller
{
    /**
     * Issue a one-time nonce for the coordinator to sign.
     * What gets signed: "{nonce}:{documento.hash_sha256}"
     * This binds the signature to this specific document and session.
     */
    public function challenge(Documento $documento): JsonResponse
    {
        $user = auth()->user();

        if ($user->role_id > 2) {
            return response()->json(['error' => 'Solo coordinadores pueden firmar documentos.'], 403);
        }

        if (! $user->certificadoActivo) {
            return response()->json(['error' => 'No tienes un certificado activo.'], 422);
        }

        if ($documento->categoria !== 'expediente') {
            return response()->json(['error' => 'Solo se firman documentos de expediente.'], 422);
        }

        // Check the coordinator has access to this expediente's area
        if ($user->role_id === 2 && $documento->expediente?->area_id !== $user->area_id) {
            return response()->json(['error' => 'No tienes acceso a este expediente.'], 403);
        }

        $nonce   = Str::random(48);
        $payload = $nonce . ':' . $documento->hash_sha256;

        session([
            'firma_nonce_' . $documento->id => $nonce,
        ]);

        return response()->json([
            'payload'      => $payload,
            'documento_id' => $documento->id,
        ]);
    }

    /**
     * Verify the RSA-SHA256 signature and create the Firma record.
     * Uses the public key from certificados — the private key never reaches the server.
     */
    public function store(Request $request, Documento $documento): RedirectResponse
    {
        $request->validate([
            'signature' => ['required', 'string'],
        ]);

        $user = auth()->user();

        abort_if($user->role_id > 2, 403, 'Solo coordinadores pueden firmar documentos.');
        abort_if(! $user->certificadoActivo, 403, 'No tienes un certificado activo.');

        // Replay protection: nonce must exist in session
        $nonceKey = 'firma_nonce_' . $documento->id;
        $nonce    = session($nonceKey);
        abort_if(! $nonce, 422, 'Sesión de firma expirada. Intenta de nuevo.');
        session()->forget($nonceKey);

        // Prevent double-signing the same document
        $yaFirmado = Firma::where('documento_id', $documento->id)
                          ->where('firmado_por', $user->id)
                          ->exists();
        abort_if($yaFirmado, 422, 'Ya firmaste este documento.');

        $cert      = $user->certificadoActivo;
        $pubKey    = openssl_pkey_get_public($cert->public_key);
        abort_if(! $pubKey, 500, 'No se pudo cargar la llave pública del certificado.');

        $payload       = $nonce . ':' . $documento->hash_sha256;
        $sigBytes      = base64_decode($request->signature, true);
        abort_if($sigBytes === false, 422, 'Formato de firma inválido.');

        $result = openssl_verify($payload, $sigBytes, $pubKey, OPENSSL_ALGO_SHA256);

        if ($result !== 1) {
            return back()->with('firma_error_' . $documento->id,
                'La firma no es válida. Verifica que usas la llave correcta.');
        }

        Firma::create([
            'documento_id'  => $documento->id,
            'firmado_por'   => $user->id,
            'certificado_id'=> $cert->id,
            'firma_b64'     => $request->signature,
            'firmado_at'    => now(),
        ]);

        ActividadLog::registrar('firmó_documento', $documento, [
            'nombre'      => $documento->nombre,
            'fingerprint' => substr($cert->fingerprint, 0, 16) . '…',
            'expediente'  => $documento->expediente?->folio,
        ]);

        return back()->with('firma_ok_' . $documento->id, 'Documento firmado correctamente.');
    }
}
