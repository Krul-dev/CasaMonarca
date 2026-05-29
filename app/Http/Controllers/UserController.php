<?php

namespace App\Http\Controllers;

use App\Models\ActividadLog;
use App\Models\Area;
use App\Models\Certificado;
use App\Models\Documento;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class UserController extends Controller
{
    /**
     * Aprueba un colaborador pendiente y genera su par de llaves RSA-2048.
     * La llave privada se muestra UNA SOLA VEZ y nunca se almacena.
     */
    public function approve(User $user): RedirectResponse
    {
        Gate::authorize('puede-eliminar'); // solo admin aprueba usuarios

        $user->update([
            'status'      => 'alta',
            'approved_by' => auth()->id(),
        ]);

        // Solo los coordinadores reciben par de claves PKI + archivo .pem
        if ($user->role_id === 2) {
            $config = [
                'digest_alg'       => 'sha256',
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ];
            $resource  = openssl_pkey_new($config);
            $details   = openssl_pkey_get_details($resource);
            $publicKey = $details['key'];

            openssl_pkey_export($resource, $privateKeyPem);

            $fingerprint = hash('sha256', $publicKey);

            $certificado = Certificado::create([
                'user_id'     => $user->id,
                'emitido_por' => auth()->id(),
                'public_key'  => $publicKey,
                'fingerprint' => $fingerprint,
                'algoritmo'   => 'RSA-2048',
                'emitido_at'  => now(),
                'vence_at'    => now()->addYears(2),
                'status'      => 'activo',
            ]);

            ActividadLog::registrar('aprobó_usuario', $user, [
                'usuario'        => $user->name,
                'certificado_id' => $certificado->id,
                'fingerprint'    => $fingerprint,
            ]);

            session([
                'private_key_once'   => $privateKeyPem,
                'approved_user_name' => $user->name,
                'approved_user_role' => $user->role_id,
            ]);

            return redirect()->route('admin.aprobacion.exitosa');
        }

        // Migrante: generar password aleatoria de 10 chars, mostrar una sola vez
        if ($user->role_id === 5) {
            $passwordPlain = strtoupper(substr(str_shuffle('ABCDEFGHJKMNPQRSTUVWXYZ'), 0, 3))
                           . rand(100, 999)
                           . strtolower(substr(str_shuffle('abcdefghjkmnpqrstuvwxyz'), 0, 3));

            $user->update(['password' => \Illuminate\Support\Facades\Hash::make($passwordPlain)]);

            ActividadLog::registrar('aprobó_migrante', $user, ['usuario' => $user->name]);

            session([
                'private_key_once'   => $passwordPlain,
                'approved_user_name' => $user->name,
                'approved_user_role' => $user->role_id,
            ]);

            return redirect()->route('admin.aprobacion.exitosa');
        }

        // Operativo y Usuario: solo se activa, no hay credencial a mostrar
        ActividadLog::registrar('aprobó_usuario', $user, ['usuario' => $user->name]);

        return redirect()->route('admin.users.approvals')
            ->with('status', "Acceso habilitado para {$user->name}.");
    }

    public function aprobacionExitosa(): \Illuminate\View\View
    {
        // Si no hay llave en sesión, redirigir (no se puede ver dos veces)
        if (! session()->has('private_key_once')) {
            return redirect()->route('admin.users.approvals')
                ->with('status', 'La llave privada solo puede verse una vez y ya fue entregada.');
        }

        $privateKey       = session()->pull('private_key_once');
        $userName         = session()->pull('approved_user_name');
        $approvedUserRole = session()->pull('approved_user_role');

        return view('admin.aprobacion-exitosa', compact('privateKey', 'userName', 'approvedUserRole'));
    }

    public function reject(User $user): RedirectResponse
    {
        Gate::authorize('puede-eliminar');

        $user->update(['status' => 'baja']);

        ActividadLog::registrar('rechazó_usuario', $user, ['usuario' => $user->name]);

        return back()->with('status', "Solicitud de {$user->name} rechazada.");
    }

    public function revoke(User $user): RedirectResponse
    {
        Gate::authorize('puede-eliminar');

        $user->update(['status' => 'revocacion']);

        // Revocar certificados activos
        Certificado::where('user_id', $user->id)
            ->where('status', 'activo')
            ->update(['status' => 'revocado', 'revocado_at' => now()]);

        ActividadLog::registrar('revocó_acceso', $user, ['usuario' => $user->name]);

        return back()->with('status', "Acceso revocado para {$user->name}.");
    }

    public function restore(User $user): RedirectResponse
    {
        Gate::authorize('puede-eliminar');

        $user->update(['status' => 'alta']);

        ActividadLog::registrar('restauró_acceso', $user, ['usuario' => $user->name]);

        return back()->with('status', "Acceso restaurado para {$user->name}.");
    }

    public function toggleRole(User $user): RedirectResponse
    {
        Gate::authorize('puede-eliminar');

        $nuevoRol = ($user->role_id == 3) ? 2 : 3;
        $user->update(['role_id' => $nuevoRol]);

        ActividadLog::registrar('cambió_rol', $user, [
            'usuario'   => $user->name,
            'rol_antes' => $user->getOriginal('role_id'),
            'rol_nuevo' => $nuevoRol,
        ]);

        return back()->with('status', "Rol de {$user->name} actualizado.");
    }

    public function destroy(User $user): RedirectResponse
    {
        Gate::authorize('puede-eliminar');

        // Revocar certificados antes de borrar
        Certificado::where('user_id', $user->id)
            ->where('status', 'activo')
            ->update(['status' => 'revocado', 'revocado_at' => now()]);

        // Registrar en log ANTES de borrar (para capturar el nombre)
        ActividadLog::registrar('borró_usuario', $user, [
            'usuario' => $user->name,
            'email'   => $user->email,
            'role_id' => $user->role_id,
            'area_id' => $user->area_id,
        ]);

        $user->delete();

        return back()->with('status', 'Usuario eliminado. Su rastro histórico se ha preservado.');
    }

    public function show(User $user): View
    {
        Gate::authorize('puede-actualizar');

        $usuario = $user->load(['area', 'role', 'certificados', 'migrantePerfil']);

        $documentosIdentidad = $user->role_id === 5
            ? Documento::where('user_id', $user->id)
                ->where('categoria', 'identidad')
                ->latest()
                ->get()
            : collect();

        return view('admin.users.show', compact('usuario', 'documentosIdentidad'));
    }

    public function index(): View
    {
        Gate::authorize('puede-eliminar');

        $users = User::with(['area', 'role'])->orderBy('created_at', 'desc')->get();
        $areas = Area::all();
        $roles = Role::all();

        return view('admin.users.index', compact('users', 'areas', 'roles'));
    }

    public function colaboradores(): View
    {
        Gate::authorize('puede-actualizar');

        $users = User::with(['area', 'role'])
            ->whereIn('role_id', [2, 3, 4])
            ->orderBy('created_at', 'desc')
            ->get();
        $areas = Area::all();
        $roles = Role::whereIn('id', [2, 3, 4])->get();

        return view('admin.users.group', [
            'users'  => $users,
            'areas'  => $areas,
            'roles'  => $roles,
            'titulo' => 'Colaboradores',
            'grupo'  => 'colaboradores',
        ]);
    }

    public function migrantes(): View
    {
        Gate::authorize('puede-actualizar');

        $rolMigrante = Role::where('name', 'Migrante')->first();

        $users = User::with(['area', 'role'])
            ->where('role_id', $rolMigrante?->id ?? 5)
            ->orderBy('created_at', 'desc')
            ->get();

        return view('admin.users.group', [
            'users'  => $users,
            'areas'  => collect(),
            'roles'  => collect(),
            'titulo' => 'Migrantes',
            'grupo'  => 'migrantes',
        ]);
    }

    public function voluntarios(): View
    {
        Gate::authorize('puede-actualizar');

        $rolVoluntario = Role::where('name', 'Voluntario')->first();

        $users = $rolVoluntario
            ? User::with(['area', 'role'])
                ->where('role_id', $rolVoluntario->id)
                ->orderBy('created_at', 'desc')
                ->get()
            : collect();

        $areas = Area::all();

        return view('admin.users.group', [
            'users'  => $users,
            'areas'  => $areas,
            'roles'  => collect(),
            'titulo' => 'Voluntarios',
            'grupo'  => 'voluntarios',
        ]);
    }

    public function updateCredentials(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('puede-eliminar');

        $rules = [];
        if ($request->filled('email')) {
            $rules['email'] = 'email|unique:users,email,' . $user->id;
        }
        if ($request->filled('password')) {
            $rules['password'] = 'min:8|confirmed';
        }

        if (empty($rules)) {
            return back()->with('status', 'No se proporcionaron cambios.');
        }

        $request->validate($rules);

        $data = [];
        if ($request->filled('email')) {
            $data['email'] = $request->email;
        }
        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $user->update($data);

        ActividadLog::registrar('actualizó_credenciales', $user, [
            'usuario' => $user->name,
            'campos'  => array_keys($data),
        ]);

        return back()->with('status', "Credenciales de {$user->name} actualizadas correctamente.");
    }

    public function pendingApprovals(): View
    {
        Gate::authorize('puede-eliminar');

        $pendientes = User::where('status', 'pendiente')->with(['area', 'role'])->get();

        return view('admin.users.approvals', compact('pendientes'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('puede-actualizar');

        $request->validate([
            'role_id' => 'required|exists:roles,id',
            'area_id' => 'nullable|exists:areas,id',
        ]);

        $user->update([
            'role_id' => $request->role_id,
            'area_id' => $request->area_id,
        ]);

        ActividadLog::registrar('actualizó_usuario', $user, [
            'usuario'    => $user->name,
            'role_nuevo' => $request->role_id,
            'area_nueva' => $request->area_id,
        ]);

        return back()->with('status', "Datos de {$user->name} actualizados.");
    }
}
