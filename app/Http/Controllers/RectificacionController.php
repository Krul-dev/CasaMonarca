<?php

namespace App\Http\Controllers;

use App\Models\ActividadLog;
use App\Models\Documento;
use App\Models\SolicitudRectificacion;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class RectificacionController extends Controller
{
    // ── Migrant: create request ───────────────────────────────────

    public function solicitar(Request $request, Documento $documento): RedirectResponse
    {
        $user = auth()->user();
        abort_if($user->role_id !== 5, 403);
        abort_if($documento->user_id !== $user->id || $documento->categoria !== 'identidad', 403);

        abort_if(
            SolicitudRectificacion::where('documento_id', $documento->id)
                ->whereNotIn('status', ['aprobada', 'rechazada'])
                ->exists(),
            422,
            'Ya existe una solicitud activa para este documento.'
        );

        $request->validate([
            'tipo'        => ['required', 'in:rectificacion,cancelacion'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
        ]);

        SolicitudRectificacion::create([
            'documento_id'   => $documento->id,
            'solicitante_id' => $user->id,
            'doc_nombre'     => $documento->nombre,
            'doc_etiqueta'   => $documento->etiqueta,
            'tipo'           => $request->tipo,
            'descripcion'    => $request->descripcion,
            'status'         => 'pendiente',
        ]);

        return back()->with('status', 'Solicitud enviada. El personal de Casa Monarca la revisará pronto.');
    }

    // ── Staff: feed of open requests ─────────────────────────────

    public function feed(): View
    {
        abort_if(auth()->user()->role_id > 4, 403);

        $abiertas = SolicitudRectificacion::with([
                'documento.propietario', 'solicitante', 'tomadoPor', 'propuesta',
            ])
            ->whereNotIn('status', ['aprobada', 'rechazada'])
            ->latest()
            ->get();

        $historial = SolicitudRectificacion::with(['solicitante', 'aprobadaPor'])
            ->whereIn('status', ['aprobada', 'rechazada'])
            ->latest()
            ->take(25)
            ->get();

        return view('staff.rectificaciones.feed', compact('abiertas', 'historial'));
    }

    // ── Staff: take a request ─────────────────────────────────────

    public function tomar(SolicitudRectificacion $solicitud): RedirectResponse
    {
        abort_if(auth()->user()->role_id > 4, 403);
        abort_if(
            $solicitud->tomado_por !== null && $solicitud->tomado_por !== auth()->id(),
            422,
            'Ya fue tomada por otro colaborador.'
        );
        abort_if(in_array($solicitud->status, ['aprobada', 'rechazada']), 422, 'Solicitud cerrada.');

        $solicitud->update(['tomado_por' => auth()->id(), 'status' => 'en_proceso']);

        return back()->with('status', 'Solicitud asignada a ti. Puedes gestionarla desde el feed.');
    }

    // ── Staff: upload proposed corrected version (rectificacion only) ──

    public function subirPropuesta(Request $request, SolicitudRectificacion $solicitud): RedirectResponse
    {
        abort_if(auth()->user()->role_id > 4, 403);
        abort_if($solicitud->tipo !== 'rectificacion', 422);
        abort_if(in_array($solicitud->status, ['aprobada', 'rechazada']), 422, 'Solicitud cerrada.');

        $request->validate([
            'archivo' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
            'nombre'  => ['nullable', 'string', 'max:255'],
        ]);

        // Resolve migrant user even if original doc was deleted
        $migrante = $solicitud->documento?->propietario
                 ?? User::find($solicitud->solicitante_id);
        abort_if(!$migrante, 404);

        $file   = $request->file('archivo');
        $nombre = $request->filled('nombre')
            ? $request->nombre
            : (($solicitud->doc_nombre ?? 'Documento') . ' (corrección)');

        $ruta = $file->store("identidad/{$migrante->id}", 'local');
        $hash = hash_file('sha256', $file->getRealPath());

        $propuesta = Documento::create([
            'user_id'      => $migrante->id,
            'subido_por'   => auth()->id(),
            'categoria'    => 'identidad',
            'etiqueta'     => $solicitud->doc_etiqueta ?? $solicitud->documento?->etiqueta ?? 'Otro',
            'nombre'       => $nombre,
            'tipo'         => $file->getClientOriginalExtension(),
            'ruta_storage' => $ruta,
            'hash_sha256'  => $hash,
        ]);

        $solicitud->update([
            'documento_propuesta_id' => $propuesta->id,
            'status'                 => 'pendiente_aprobacion',
        ]);

        return back()->with('status', 'Versión corregida subida. Coordinador debe aprobar con su firma.');
    }

    // ── Coordinator: challenge nonce for approval signature ───────

    public function challenge(SolicitudRectificacion $solicitud): JsonResponse
    {
        $user = auth()->user();

        if ($user->role_id > 2) {
            return response()->json(['error' => 'Solo coordinadores pueden aprobar solicitudes.'], 403);
        }
        if (!$user->certificadoActivo) {
            return response()->json(['error' => 'No tienes un certificado activo.'], 422);
        }
        if (in_array($solicitud->status, ['aprobada', 'rechazada'])) {
            return response()->json(['error' => 'Solicitud ya cerrada.'], 422);
        }
        if ($solicitud->tipo === 'rectificacion' && !$solicitud->propuesta) {
            return response()->json(['error' => 'Aún no se ha subido la versión corregida.'], 422);
        }

        $nonce   = Str::random(48);
        $hash    = $solicitud->propuesta?->hash_sha256
                ?? $solicitud->documento?->hash_sha256
                ?? 'sin-documento';
        $payload = "{$solicitud->tipo}:{$solicitud->id}:{$nonce}:{$hash}";

        session(['rect_nonce_' . $solicitud->id => $nonce]);

        return response()->json(['payload' => $payload, 'solicitud_id' => $solicitud->id]);
    }

    // ── Coordinator: verify signature and complete action ─────────

    public function aprobar(Request $request, SolicitudRectificacion $solicitud): RedirectResponse
    {
        $user = auth()->user();
        abort_if($user->role_id > 2, 403, 'Solo coordinadores pueden aprobar.');
        abort_if(!$user->certificadoActivo, 403, 'No tienes certificado activo.');
        abort_if(in_array($solicitud->status, ['aprobada', 'rechazada']), 422, 'Solicitud ya cerrada.');
        if ($solicitud->tipo === 'rectificacion') {
            abort_if(!$solicitud->propuesta, 422, 'No hay versión corregida aún.');
        }

        $request->validate(['signature' => ['required', 'string']]);

        $nonceKey = 'rect_nonce_' . $solicitud->id;
        $nonce    = session($nonceKey);
        abort_if(!$nonce, 422, 'Sesión de aprobación expirada. Intenta de nuevo.');
        session()->forget($nonceKey);

        $hash    = $solicitud->propuesta?->hash_sha256
                ?? $solicitud->documento?->hash_sha256
                ?? 'sin-documento';
        $payload = "{$solicitud->tipo}:{$solicitud->id}:{$nonce}:{$hash}";

        $cert    = $user->certificadoActivo;
        $pubKey  = openssl_pkey_get_public($cert->public_key);
        abort_if(!$pubKey, 500, 'Error al cargar llave pública.');

        $sigBytes = base64_decode($request->signature, true);
        abort_if($sigBytes === false, 422, 'Formato de firma inválido.');

        if (openssl_verify($payload, $sigBytes, $pubKey, OPENSSL_ALGO_SHA256) !== 1) {
            return back()->with('rect_error_' . $solicitud->id, 'La firma no es válida. Verifica que usas tu llave correcta.');
        }

        DB::transaction(function () use ($solicitud, $user, $request) {
            // Delete original document
            if ($solicitud->documento) {
                Storage::disk('local')->delete($solicitud->documento->ruta_storage);
                $solicitud->documento->delete();
            }
            // For cancelacion: original deleted above, no propuesta
            // For rectificacion: propuesta already exists as active Documento

            $solicitud->update([
                'status'       => 'aprobada',
                'aprobada_por' => $user->id,
                'firma_b64'    => $request->signature,
                'aprobada_at'  => now(),
            ]);
        });

        ActividadLog::registrar('aprobó_rectificacion', $user, [
            'tipo'        => $solicitud->tipo,
            'doc_nombre'  => $solicitud->doc_nombre,
            'solicitante' => $solicitud->solicitante?->name,
        ]);

        return back()->with('status', "Solicitud de {$solicitud->tipoLabel()} aprobada y ejecutada.");
    }

    // ── Coordinator: reject a request ─────────────────────────────

    public function rechazar(SolicitudRectificacion $solicitud): RedirectResponse
    {
        abort_if(auth()->user()->role_id > 2, 403);
        abort_if(in_array($solicitud->status, ['aprobada', 'rechazada']), 422);

        // Discard the uploaded propuesta if any
        if ($solicitud->propuesta) {
            Storage::disk('local')->delete($solicitud->propuesta->ruta_storage);
            $solicitud->propuesta->delete();
        }

        $solicitud->update([
            'status'       => 'rechazada',
            'aprobada_por' => auth()->id(),
            'aprobada_at'  => now(),
        ]);

        return back()->with('status', 'Solicitud rechazada.');
    }
}
