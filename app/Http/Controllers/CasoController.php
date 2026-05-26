<?php

namespace App\Http\Controllers;

use App\Models\ActividadLog;
use App\Models\Area;
use App\Models\Documento;
use App\Models\DocumentoAccionLog;
use App\Models\Expediente;
use App\Models\Postulacion;
use App\Models\Solicitud;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class CasoController extends Controller
{
    // Any area member (roles 1-4 matching area_id, or admin) can access the bandeja
    private function verificarAccesoArea(int $areaId): void
    {
        $user = auth()->user();
        if ($user->role_id === 1) return;
        if ($user->area_id !== $areaId || $user->role_id > 4) {
            abort(403, 'No tienes acceso a esta área.');
        }
    }

    // Only the assigned colaborador, coordinator of the area, or admin can see case details
    private function verificarAccesoCaso(Expediente $exp): void
    {
        $user = auth()->user();
        if ($user->role_id === 1) return;
        if ($user->role_id === 2 && $user->area_id === $exp->area_id) return;
        if ($exp->colaborador_id === $user->id) return;
        abort(403, 'No tienes acceso a este caso.');
    }

    // Only coordinators (role ≤ 2) can approve/reject solicitudes and edit/delete documents
    private function verificarCoordinador(): void
    {
        if (auth()->user()->role_id > 2) {
            abort(403, 'Solo el coordinador puede realizar esta acción.');
        }
    }

    // Verify coordinator PEM private key matches their stored certificate fingerprint
    private function verificarPem(string $pemInput): void
    {
        $user = auth()->user();
        $cert = $user->certificadoActivo;

        if (!$cert) {
            abort(403, 'No tienes un certificado activo asociado a tu cuenta.');
        }

        $privKey = openssl_pkey_get_private($pemInput);
        if (!$privKey) {
            abort(422, 'La llave privada es inválida o está mal formateada.');
        }

        $details     = openssl_pkey_get_details($privKey);
        $fingerprint = hash('sha256', $details['key']);

        if ($fingerprint !== $cert->fingerprint) {
            abort(403, 'La llave privada no corresponde a tu certificado registrado.');
        }
    }

    // ─── Bandeja del área ────────────────────────────────────────────────────

    public function bandeja(Area $area): View
    {
        $this->verificarAccesoArea($area->id);

        $user          = auth()->user();
        $esCoordinador = $user->role_id <= 2;

        // All area members see pending solicitudes
        $pendientes = Solicitud::where('area_id', $area->id)
            ->where('status', 'pendiente')
            ->with(['migrantePerfil', 'solicitante', 'postulaciones.colaborador'])
            ->latest()
            ->get();

        // Track which solicitudes this user has already volunteered for
        $misPostulaciones = $esCoordinador
            ? collect()
            : Postulacion::where('user_id', $user->id)
                ->whereIn('solicitud_id', $pendientes->pluck('id'))
                ->pluck('solicitud_id')
                ->flip();

        // Active cases: coordinators see all; colaboradores only see their own
        $enProcesoQuery = Expediente::where('area_id', $area->id)
            ->whereIn('status', ['sin_asignar', 'en_proceso'])
            ->with(['solicitudes.migrantePerfil', 'colaborador']);

        if (!$esCoordinador) {
            $enProcesoQuery->where('colaborador_id', $user->id);
        }

        $enProceso = $enProcesoQuery->latest()->get();

        // Resolved: same restriction
        $terminadosQuery = Expediente::where('area_id', $area->id)
            ->where('status', 'terminado')
            ->with(['solicitudes.migrantePerfil', 'colaborador']);

        if (!$esCoordinador) {
            $terminadosQuery->where('colaborador_id', $user->id);
        }

        $terminados = $terminadosQuery->latest()->take(10)->get();

        // Area colaboradores for coordinator's assignment dropdown
        $colaboradoresArea = $esCoordinador
            ? User::where('area_id', $area->id)
                ->whereIn('role_id', [3, 4])
                ->where('status', 'alta')
                ->orderBy('name')
                ->get()
            : collect();

        return view('staff.bandeja', compact(
            'area', 'pendientes', 'enProceso', 'terminados',
            'esCoordinador', 'misPostulaciones', 'colaboradoresArea'
        ));
    }

    // ─── Postulaciones ───────────────────────────────────────────────────────

    // Colaborador volunteers to handle a solicitud
    public function postularse(Request $request, Solicitud $solicitud): RedirectResponse
    {
        $user = auth()->user();
        $this->verificarAccesoArea($solicitud->area_id);

        if ($user->role_id <= 2) {
            return back()->with('error', 'Los coordinadores no se postulan; directamente asignan colaboradores.');
        }

        if ($solicitud->status !== 'pendiente') {
            return back()->with('error', 'Esta solicitud ya no está disponible para postulación.');
        }

        $request->validate(['nota' => ['nullable', 'string', 'max:500']]);

        Postulacion::firstOrCreate(
            ['solicitud_id' => $solicitud->id, 'user_id' => $user->id],
            ['nota' => $request->nota]
        );

        return back()->with('status', 'Te has postulado. El coordinador revisará tu oferta.');
    }

    // Colaborador withdraws their offer
    public function retirarPostulacion(Solicitud $solicitud): RedirectResponse
    {
        $user = auth()->user();

        if ($solicitud->status !== 'pendiente') {
            return back()->with('error', 'No puedes retirar una postulación en este estado.');
        }

        Postulacion::where('solicitud_id', $solicitud->id)
            ->where('user_id', $user->id)
            ->delete();

        return back()->with('status', 'Postulación retirada.');
    }

    // ─── Aprobación de caso (solo coordinador) ───────────────────────────────

    public function aprobarCaso(Request $request, Solicitud $solicitud): RedirectResponse
    {
        $this->verificarCoordinador();
        $this->verificarAccesoArea($solicitud->area_id);

        if ($solicitud->status !== 'pendiente') {
            return back()->with('error', 'Esta solicitud ya fue atendida.');
        }

        $request->validate([
            'colaborador_id' => ['required', 'exists:users,id'],
        ]);

        $colaborador = User::findOrFail($request->colaborador_id);

        // Admin can assign anyone; coordinator restricted to own area
        if (auth()->user()->role_id !== 1 && $colaborador->area_id !== $solicitud->area_id) {
            return back()->with('error', 'El colaborador seleccionado no pertenece a esta área.');
        }

        $expediente = Expediente::create([
            'folio'              => Expediente::generarFolio(),
            'migrante_perfil_id' => $solicitud->migrante_perfil_id,
            'colaborador_id'     => $colaborador->id,
            'area_id'            => $solicitud->area_id,
            'status'             => 'en_proceso',
        ]);

        $solicitud->update([
            'status'        => 'en_proceso',
            'expediente_id' => $expediente->id,
            'atendida_por'  => auth()->id(),
        ]);

        ActividadLog::registrar('abrió_caso', $expediente, [
            'folio'       => $expediente->folio,
            'solicitud_id' => $solicitud->id,
            'coordinador' => auth()->user()->name,
            'colaborador' => $colaborador->name,
        ]);

        return redirect()->route('casos.bandeja', $solicitud->area_id)
            ->with('status', "Caso {$expediente->folio} creado y asignado a {$colaborador->name}.");
    }

    // ─── Rechazar solicitud (solo coordinador) ───────────────────────────────

    public function rechazar(Solicitud $solicitud): RedirectResponse
    {
        $this->verificarCoordinador();
        $this->verificarAccesoArea($solicitud->area_id);

        if ($solicitud->status !== 'pendiente') {
            return back()->with('error', 'Solo se pueden rechazar solicitudes pendientes.');
        }

        request()->validate(['motivo' => ['nullable', 'string', 'max:500']]);

        $solicitud->update(['status' => 'rechazada', 'atendida_por' => auth()->id()]);

        ActividadLog::registrar('rechazó_solicitud', $solicitud, [
            'coordinador' => auth()->user()->name,
            'motivo'      => request()->motivo,
        ]);

        return back()->with('status', 'Solicitud rechazada.');
    }

    // ─── Detalle del caso ────────────────────────────────────────────────────

    public function show(Expediente $expediente): View
    {
        $this->verificarAccesoCaso($expediente);

        $expediente->load([
            'solicitudes.migrantePerfil',
            'solicitudes.solicitante',
            'colaborador',
            'documentos.autor',
            'documentos.firmas.firmante',
            'resueltoPor',
            'area',
        ]);

        $user            = auth()->user();
        $esCoordinador   = $user->role_id <= 2;
        $esMiCaso        = $expediente->colaborador_id === $user->id;
        $tieneCertActivo = $esCoordinador && $user->certificadoActivo !== null;

        return view('staff.caso.show', compact('expediente', 'esCoordinador', 'esMiCaso', 'tieneCertActivo'));
    }

    // ─── Mis casos (vista de tareas del colaborador) ─────────────────────────

    public function misCasos(): View
    {
        $user = auth()->user();

        if ($user->role_id < 2 || $user->role_id > 4) {
            abort(403, 'Esta vista es para colaboradores.');
        }

        $activos = Expediente::where('colaborador_id', $user->id)
            ->whereIn('status', ['sin_asignar', 'en_proceso'])
            ->with(['solicitudes.migrantePerfil', 'area'])
            ->latest()
            ->get();

        $terminados = Expediente::where('colaborador_id', $user->id)
            ->where('status', 'terminado')
            ->with(['solicitudes.migrantePerfil', 'area'])
            ->latest()
            ->take(20)
            ->get();

        return view('staff.mis-casos', compact('activos', 'terminados'));
    }

    // ─── Notas ───────────────────────────────────────────────────────────────

    public function agregarNota(Request $request, Expediente $expediente): RedirectResponse
    {
        $this->verificarAccesoCaso($expediente);

        $request->validate(['nota' => ['required', 'string', 'max:2000']]);

        $notasActuales = $expediente->notas ?? '';
        $nueva = now()->format('d/m/Y H:i') . ' — ' . auth()->user()->name . "\n" . $request->nota;
        $expediente->update(['notas' => $notasActuales ? $notasActuales . "\n\n" . $nueva : $nueva]);

        ActividadLog::registrar('agregó_nota', $expediente, ['folio' => $expediente->folio]);

        return back()->with('status', 'Nota agregada al expediente.');
    }

    // ─── Documentos ──────────────────────────────────────────────────────────

    // Upload: only assigned colaborador or coordinator/admin; immutable after upload
    public function subirDocumento(Request $request, Expediente $expediente): RedirectResponse
    {
        $this->verificarAccesoCaso($expediente);

        if ($expediente->status === 'terminado') {
            return back()->with('error', 'No se pueden agregar documentos a un caso terminado.');
        }

        $request->validate([
            'documento' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx,jpg,jpeg,png'],
            'nombre'    => ['required', 'string', 'max:255'],
        ]);

        $file = $request->file('documento');
        $ruta = $file->store("expedientes/{$expediente->id}", 'local');
        $hash = hash_file('sha256', $file->getRealPath());

        Documento::create([
            'expediente_id' => $expediente->id,
            'subido_por'    => auth()->id(),
            'categoria'     => 'expediente',
            'nombre'        => $request->nombre,
            'tipo'          => $file->getClientOriginalExtension(),
            'ruta_storage'  => $ruta,
            'hash_sha256'   => $hash,
        ]);

        ActividadLog::registrar('subió_documento', $expediente, [
            'folio'  => $expediente->folio,
            'nombre' => $request->nombre,
        ]);

        return back()->with('status', 'Documento subido. No puede ser modificado.');
    }

    // Edit document name: coordinator only, requires PEM key, creates audit log
    public function editarDocumento(Request $request, Expediente $expediente, Documento $documento): RedirectResponse
    {
        $this->verificarCoordinador();
        $this->verificarAccesoArea($expediente->area_id);

        $request->validate([
            'nombre'    => ['required', 'string', 'max:255'],
            'pem_llave' => ['required', 'string'],
        ]);

        $this->verificarPem($request->pem_llave);

        $nombreAnterior = $documento->nombre;
        $documento->update(['nombre' => $request->nombre]);

        DocumentoAccionLog::create([
            'documento_id'  => $documento->id,
            'expediente_id' => $expediente->id,
            'user_id'       => auth()->id(),
            'accion'        => 'editado',
            'detalle'       => [
                'nombre_anterior' => $nombreAnterior,
                'nombre_nuevo'    => $request->nombre,
            ],
        ]);

        return back()->with('status', 'Documento actualizado. Acción registrada en el log de auditoría.');
    }

    // Delete document: coordinator only, requires PEM key, creates audit log
    public function eliminarDocumento(Request $request, Expediente $expediente, Documento $documento): RedirectResponse
    {
        $this->verificarCoordinador();
        $this->verificarAccesoArea($expediente->area_id);

        $request->validate(['pem_llave' => ['required', 'string']]);

        $this->verificarPem($request->pem_llave);

        DocumentoAccionLog::create([
            'documento_id'  => $documento->id,
            'expediente_id' => $expediente->id,
            'user_id'       => auth()->id(),
            'accion'        => 'eliminado',
            'detalle'       => [
                'nombre'  => $documento->nombre,
                'tipo'    => $documento->tipo,
                'hash'    => $documento->hash_sha256,
            ],
        ]);

        Storage::disk('local')->delete($documento->ruta_storage);
        $documento->delete();

        return back()->with('status', 'Documento eliminado. Acción registrada en el log de auditoría.');
    }

    // ─── Resolver caso ───────────────────────────────────────────────────────

    public function resolver(Expediente $expediente): RedirectResponse
    {
        $this->verificarAccesoCaso($expediente);

        if ($expediente->status === 'terminado') {
            return back()->with('error', 'Este caso ya está terminado.');
        }

        $expediente->update([
            'status'      => 'terminado',
            'resuelto_por' => auth()->id(),
            'resuelto_at' => now(),
        ]);

        Solicitud::where('expediente_id', $expediente->id)
            ->whereIn('status', ['pendiente', 'en_proceso'])
            ->update(['status' => 'completada']);

        ActividadLog::registrar('resolvió_caso', $expediente, [
            'folio'        => $expediente->folio,
            'resuelto_por' => auth()->user()->name,
        ]);

        return redirect()->route('casos.bandeja', $expediente->area_id)
            ->with('status', "Caso {$expediente->folio} marcado como resuelto.");
    }
}
