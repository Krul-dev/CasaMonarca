<?php

namespace App\Http\Controllers;

use App\Models\ActividadLog;
use App\Models\Area;
use App\Models\AreaSolicitud;
use App\Models\Certificado;
use App\Models\Expediente;
use App\Models\User;

class DashboardController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        if ($user->role_id === 5) {
            return redirect()->route('migrante.dashboard');
        }

        if ($user->role_id === 1) {
            return $this->dashboardAdmin($user);
        }

        if ($user->role_id === 2) {
            return $this->dashboardCoordinador($user);
        }

        if ($user->role_id === 3) {
            return $this->dashboardOperativo($user);
        }

        // Nivel 4 — Usuario (becarios, voluntarios, servicio social, recepción)
        return $this->dashboardUsuario($user);
    }

    private function dashboardAdmin($user)
    {
        // Core counts
        $totalActivos     = User::where('status', 'alta')->whereIn('role_id', [2, 3, 4])->count();
        $totalPendientes  = User::where('status', 'pendiente')->count();
        $totalMigrantes   = User::where('role_id', 5)->where('status', 'alta')->count();
        $expedientesActivos = Expediente::whereIn('status', ['sin_asignar', 'en_proceso'])->count();
        $totalCerts       = Certificado::where('status', 'activo')->count();

        // Users waiting for approval (show list, not just count)
        $usuariosPendientes = User::where('status', 'pendiente')
            ->with('role')
            ->latest()
            ->take(10)
            ->get();

        // Recent activity (last 15 entries)
        $actividadReciente = ActividadLog::latest()
            ->take(15)
            ->get();

        $areas = Area::withCount([
            'users as colaboradores_activos' => fn($q) => $q->where('status', 'alta'),
        ])->get();

        return view('admin.dashboard', compact(
            'totalActivos', 'totalPendientes', 'totalMigrantes',
            'expedientesActivos', 'totalCerts',
            'usuariosPendientes', 'actividadReciente', 'areas'
        ));
    }

    private function dashboardCoordinador($user)
    {
        $areas               = Area::where('id', $user->area_id)->withCount('users')->get();
        $totalUsuarios       = User::where('area_id', $user->area_id)->where('status', 'alta')->count();
        $pendientes          = User::where('area_id', $user->area_id)->where('status', 'pendiente')->get();
        $solicitudesMembresia = AreaSolicitud::where('area_id', $user->area_id)->where('status', 'pendiente')->count();
        $sinArea             = null;
        $solicitudPendiente  = null;

        return view('dashboard', compact('areas', 'totalUsuarios', 'pendientes', 'solicitudesMembresia', 'sinArea', 'solicitudPendiente'));
    }

    private function dashboardOperativo($user)
    {
        $areas               = collect();
        $totalUsuarios       = 0;
        $pendientes          = collect();
        $solicitudesMembresia = 0;
        $sinArea             = null;
        $solicitudPendiente  = $user->areaSolicitudPendiente;

        return view('dashboard', compact('areas', 'totalUsuarios', 'pendientes', 'solicitudesMembresia', 'sinArea', 'solicitudPendiente'));
    }

    private function dashboardUsuario($user)
    {
        $areas               = collect();
        $totalUsuarios       = 0;
        $pendientes          = collect();
        $solicitudesMembresia = 0;
        $sinArea             = !$user->area_id;
        $solicitudPendiente  = $user->areaSolicitudPendiente;

        return view('dashboard', compact('areas', 'totalUsuarios', 'pendientes', 'solicitudesMembresia', 'sinArea', 'solicitudPendiente'));
    }
}
