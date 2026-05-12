<?php

use App\Http\Controllers\AreaController;
use App\Http\Controllers\AreaMembresiaController;
use App\Http\Controllers\CasoController;
use App\Http\Controllers\CertificadoController;
use App\Http\Controllers\DiagnosticoController;
use App\Http\Controllers\MigranteSolicitudController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use App\Models\Area;

Route::get('/', fn() => view('welcome'));

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'checkstatus'])
    ->name('dashboard');

Route::middleware(['auth', 'checkstatus'])->group(function () {

    // Perfil
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Áreas
    Route::get('/areas', [AreaController::class, 'index'])->name('areas.index');
    Route::get('/admin/areas/{area}', function (Area $area) {
        $user = auth()->user();
        if (Gate::denies('puede-eliminar') && $user->area_id !== $area->id) {
            abort(403, 'No tienes permiso para acceder a esta área.');
        }
        $area->load(['users' => fn($q) => $q->with('role')]);
        return view('admin.areas.show', compact('area'));
    })->name('admin.areas.show');

    // Membresía de áreas
    Route::get('/mi-area',                                       [AreaMembresiaController::class, 'miArea'])->name('mi-area.index');
    Route::post('/mi-area/solicitar',                            [AreaMembresiaController::class, 'solicitarArea'])->name('mi-area.solicitar');
    Route::delete('/mi-area/solicitud',                          [AreaMembresiaController::class, 'cancelarSolicitud'])->name('mi-area.cancelar');
    Route::get('/admin/sin-area',                                [AreaMembresiaController::class, 'sinArea'])->name('admin.sin-area');
    Route::post('/area-solicitudes/{solicitud}/aprobar',         [AreaMembresiaController::class, 'aprobar'])->name('membresia.aprobar');
    Route::post('/area-solicitudes/{solicitud}/rechazar',        [AreaMembresiaController::class, 'rechazar'])->name('membresia.rechazar');
    Route::post('/admin/asignar-area',                           [AreaMembresiaController::class, 'asignarDirecto'])->name('membresia.asignar');
    Route::delete('/admin/usuarios/{usuario}/remover-area',      [AreaMembresiaController::class, 'removerDeArea'])->name('membresia.remover');

    // Gestión de casos por área
    Route::get('/areas/{area}/bandeja',                                    [CasoController::class, 'bandeja'])->name('casos.bandeja');
    Route::post('/solicitudes/{solicitud}/postularse',                     [CasoController::class, 'postularse'])->name('casos.postularse');
    Route::delete('/solicitudes/{solicitud}/postulacion',                  [CasoController::class, 'retirarPostulacion'])->name('casos.postulacion.retirar');
    Route::post('/solicitudes/{solicitud}/aprobar',                        [CasoController::class, 'aprobarCaso'])->name('casos.aprobar');
    Route::post('/solicitudes/{solicitud}/rechazar',                       [CasoController::class, 'rechazar'])->name('casos.rechazar');
    Route::get('/mis-casos',                                               [CasoController::class, 'misCasos'])->name('casos.mios');
    Route::get('/casos/{expediente}',                                      [CasoController::class, 'show'])->name('casos.show');
    Route::post('/casos/{expediente}/nota',                                [CasoController::class, 'agregarNota'])->name('casos.nota');
    Route::post('/casos/{expediente}/documento',                           [CasoController::class, 'subirDocumento'])->name('casos.documento');
    Route::patch('/casos/{expediente}/documentos/{documento}/editar',      [CasoController::class, 'editarDocumento'])->name('casos.documento.editar');
    Route::delete('/casos/{expediente}/documentos/{documento}',            [CasoController::class, 'eliminarDocumento'])->name('casos.documento.eliminar');
    Route::post('/casos/{expediente}/resolver',                            [CasoController::class, 'resolver'])->name('casos.resolver');

    // Gestión de usuarios
    Route::get('/admin/usuarios',                          [UserController::class, 'index'])->name('admin.users.index');
    Route::get('/admin/colaboradores',                     [UserController::class, 'colaboradores'])->name('admin.users.colaboradores');
    Route::get('/admin/migrantes',                         [UserController::class, 'migrantes'])->name('admin.users.migrantes');
    Route::get('/admin/voluntarios',                       [UserController::class, 'voluntarios'])->name('admin.users.voluntarios');
    Route::get('/admin/usuarios/{user}',                   [UserController::class, 'show'])->name('admin.users.show');
    Route::get('/admin/aprobaciones',                      [UserController::class, 'pendingApprovals'])->name('admin.users.approvals');
    Route::patch('/admin/usuarios/{user}/update',          [UserController::class, 'update'])->name('admin.users.update');
    Route::patch('/admin/usuarios/{user}/credentials',     [UserController::class, 'updateCredentials'])->name('admin.users.updateCredentials');

    Route::post('/usuarios/{user}/approve',    [UserController::class, 'approve'])->name('users.approve');
    Route::post('/usuarios/{user}/reject',     [UserController::class, 'reject'])->name('users.reject');
    Route::post('/usuarios/{user}/revoke',     [UserController::class, 'revoke'])->name('users.revoke');
    Route::post('/usuarios/{user}/restore',    [UserController::class, 'restore'])->name('users.restore');
    Route::post('/usuarios/{user}/toggle-role',[UserController::class, 'toggleRole'])->name('users.toggleRole');
    Route::delete('/usuarios/{user}',          [UserController::class, 'destroy'])->name('users.destroy');

    // Post-aprobación: llave privada una sola vez
    Route::get('/admin/aprobacion-exitosa',    [UserController::class, 'aprobacionExitosa'])->name('admin.aprobacion.exitosa');

    // Certificados digitales
    Route::get('/admin/certificados',                              [CertificadoController::class, 'index'])->name('admin.certificados.index');
    Route::delete('/admin/certificados/{certificado}',             [CertificadoController::class, 'destroy'])->name('admin.certificados.destroy');

    // Diagnóstico del sistema (solo admin)
    Route::get('/admin/diagnostico', [DiagnosticoController::class, 'index'])->name('admin.diagnostico');

    // Log de acciones sobre documentos (solo admin)
    Route::get('/admin/log-documentos', function () {
        Gate::authorize('puede-eliminar');
        $logs = \App\Models\DocumentoAccionLog::with(['usuario', 'expediente', 'documento'])
            ->latest()
            ->paginate(30);
        return view('admin.log-documentos', compact('logs'));
    })->name('admin.log.documentos');
});

// Portal de migrantes (requiere autenticación + status alta)
Route::middleware(['auth', 'checkstatus'])->prefix('mi-espacio')->name('migrante.')->group(function () {
    Route::get('/',                                        [MigranteSolicitudController::class, 'dashboard'])->name('dashboard');
    Route::get('/solicitudes',                             [MigranteSolicitudController::class, 'index'])->name('solicitudes.index');
    Route::get('/solicitudes/nueva',                       [MigranteSolicitudController::class, 'create'])->name('solicitudes.create');
    Route::post('/solicitudes',                            [MigranteSolicitudController::class, 'store'])->name('solicitudes.store');
    Route::post('/solicitudes/{solicitud}/resolver',       [MigranteSolicitudController::class, 'resolver'])->name('solicitudes.resolver');
    Route::get('/caso/{expediente}/documentos',            [MigranteSolicitudController::class, 'verDocumentos'])->name('caso.documentos');
});

require __DIR__.'/auth.php';
