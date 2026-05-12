<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-200 leading-tight">Detalle de Usuario</h2>
    </x-slot>

    <div class="max-w-4xl mx-auto px-4 py-8 space-y-6">

        @if(session('status'))
            <div class="flex items-center gap-2 bg-green-50 border border-green-200 rounded-xl px-5 py-3 text-sm text-green-700">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                {{ session('status') }}
            </div>
        @endif

        {{-- Breadcrumb --}}
        <div class="flex items-center gap-2 text-xs text-gray-400">
            <a href="{{ route('dashboard') }}" class="hover:text-indigo-500 transition">Panel</a>
            <span>/</span>
            <a href="{{ route('admin.users.index') }}" class="hover:text-indigo-500 transition">Usuarios</a>
            <span>/</span>
            <span class="text-gray-600">{{ $usuario->name }}</span>
        </div>

        {{-- Perfil + acciones --}}
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
            <div class="flex flex-col sm:flex-row sm:items-start gap-5">

                {{-- Avatar --}}
                <div class="w-14 h-14 rounded-full flex items-center justify-center shrink-0 text-xl font-bold
                    @if($usuario->status === 'alta') bg-indigo-100 text-indigo-700
                    @elseif($usuario->status === 'revocacion') bg-orange-100 text-orange-700
                    @else bg-gray-100 text-gray-500 @endif">
                    {{ strtoupper(substr($usuario->name, 0, 1)) }}
                </div>

                {{-- Info --}}
                <div class="flex-1">
                    <div class="flex flex-wrap items-center gap-2 mb-1">
                        <h2 class="text-lg font-bold text-gray-800">{{ $usuario->name }}</h2>
                        @if($usuario->status === 'alta')
                            <span class="px-2.5 py-0.5 bg-green-100 text-green-700 text-xs font-semibold rounded-full">Activo</span>
                        @elseif($usuario->status === 'pendiente')
                            <span class="px-2.5 py-0.5 bg-amber-100 text-amber-700 text-xs font-semibold rounded-full">Pendiente</span>
                        @elseif($usuario->status === 'revocacion')
                            <span class="px-2.5 py-0.5 bg-orange-100 text-orange-700 text-xs font-semibold rounded-full">Suspendido</span>
                        @else
                            <span class="px-2.5 py-0.5 bg-gray-100 text-gray-600 text-xs font-semibold rounded-full">Baja</span>
                        @endif
                    </div>
                    <p class="text-sm text-gray-500">{{ $usuario->email }}</p>
                    <div class="flex flex-wrap gap-3 mt-2 text-xs text-gray-500">
                        <span>Rol: <strong class="text-gray-700">{{ $usuario->role?->name ?? '—' }}</strong></span>
                        <span>Área: <strong class="text-gray-700">{{ $usuario->area?->nombre ?? 'Sin área' }}</strong></span>
                        <span>Registrado: <strong class="text-gray-700">{{ $usuario->created_at->format('d/m/Y') }}</strong></span>
                        @if($usuario->approved_by)
                            <span>Aprobado por: <strong class="text-gray-700">{{ \App\Models\User::find($usuario->approved_by)?->name ?? 'Admin' }}</strong></span>
                        @endif
                    </div>
                </div>

                {{-- Acciones rápidas --}}
                <div class="flex flex-col gap-2 shrink-0">
                    @if($usuario->status === 'alta')
                        <form action="{{ route('users.revoke', $usuario->id) }}" method="POST">
                            @csrf
                            <button type="submit"
                                    onclick="return confirm('¿Suspender a {{ addslashes($usuario->name) }}? Se revocarán sus certificados activos.')"
                                    class="w-full inline-flex items-center justify-center gap-1.5 px-4 py-2 border border-red-300 text-red-600 hover:bg-red-50 text-xs font-semibold rounded-full transition">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636"/>
                                </svg>
                                Suspender acceso
                            </button>
                        </form>
                    @elseif($usuario->status === 'revocacion')
                        <form action="{{ route('users.restore', $usuario->id) }}" method="POST">
                            @csrf
                            <button type="submit"
                                    class="w-full inline-flex items-center justify-center gap-1.5 px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-xs font-semibold rounded-full transition">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                </svg>
                                Restaurar acceso
                            </button>
                        </form>
                    @endif

                    @can('puede-eliminar')
                    <form action="{{ route('users.destroy', $usuario->id) }}" method="POST">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                                onclick="return confirm('¿BORRAR PERMANENTEMENTE a {{ addslashes($usuario->name) }}? Su rastro histórico se preservará en el log de actividad.')"
                                class="w-full inline-flex items-center justify-center gap-1.5 px-4 py-2 border border-gray-300 text-gray-500 hover:border-red-300 hover:text-red-500 text-xs font-semibold rounded-full transition">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                            </svg>
                            Eliminar usuario
                        </button>
                    </form>
                    @endcan
                </div>
            </div>
        </div>

        {{-- Cambio de credenciales --}}
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
            <h3 class="font-semibold text-gray-800 text-sm mb-4">Cambiar credenciales</h3>
            <form action="{{ route('admin.users.updateCredentials', $usuario->id) }}" method="POST"
                  class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                @csrf
                @method('PATCH')

                <div class="sm:col-span-2">
                    <label class="block text-xs font-medium text-gray-600 mb-1">Nuevo correo electrónico</label>
                    <input type="email" name="email" value="{{ old('email', $usuario->email) }}"
                           class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-indigo-400 focus:outline-none @error('email') border-red-400 @enderror">
                    @error('email')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Nueva contraseña <span class="text-gray-400">(dejar vacío para no cambiar)</span></label>
                    <input type="password" name="password"
                           class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-indigo-400 focus:outline-none @error('password') border-red-400 @enderror"
                           placeholder="Mínimo 8 caracteres">
                    @error('password')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Confirmar contraseña</label>
                    <input type="password" name="password_confirmation"
                           class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-indigo-400 focus:outline-none"
                           placeholder="Repetir contraseña">
                </div>

                <div class="sm:col-span-2 flex justify-end">
                    <button type="submit"
                            class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-full transition">
                        Guardar cambios
                    </button>
                </div>
            </form>
        </div>

        {{-- Certificados del usuario --}}
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 flex items-center gap-2">
                <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                </svg>
                <h3 class="font-semibold text-gray-800 text-sm">Certificados digitales</h3>
                <span class="ml-auto text-xs text-gray-400">Solo se muestra la llave pública</span>
            </div>

            @forelse($usuario->certificados as $cert)
            <div class="px-6 py-4 border-b border-gray-50 last:border-0">
                <div class="flex flex-wrap items-start gap-3">
                    <div class="flex-1 space-y-1 min-w-0">
                        <div class="flex flex-wrap gap-2 items-center">
                            @if($cert->status === 'activo')
                                <span class="px-2 py-0.5 bg-green-100 text-green-700 text-xs font-semibold rounded-full">Activo</span>
                            @elseif($cert->status === 'revocado')
                                <span class="px-2 py-0.5 bg-red-100 text-red-700 text-xs font-semibold rounded-full">Revocado</span>
                            @else
                                <span class="px-2 py-0.5 bg-gray-100 text-gray-600 text-xs font-semibold rounded-full">Vencido</span>
                            @endif
                            <span class="text-xs text-gray-500 font-mono truncate">{{ substr($cert->fingerprint, 0, 24) }}…</span>
                        </div>
                        <div class="flex flex-wrap gap-4 text-xs text-gray-400">
                            <span>{{ $cert->algoritmo }}</span>
                            <span>Emitido: {{ $cert->emitido_at->format('d/m/Y') }}</span>
                            <span class="{{ $cert->vence_at->isPast() ? 'text-red-500' : '' }}">
                                Vence: {{ $cert->vence_at->format('d/m/Y') }}
                            </span>
                            @if($cert->revocado_at)
                                <span class="text-red-500">Revocado: {{ $cert->revocado_at->format('d/m/Y') }}</span>
                            @endif
                        </div>
                    </div>
                    @if($cert->status === 'activo')
                    <form action="{{ route('admin.certificados.revoke', $cert->id) }}" method="POST">
                        @csrf
                        <button type="submit"
                                onclick="return confirm('¿Revocar este certificado?')"
                                class="px-3 py-1.5 border border-red-300 text-red-600 hover:bg-red-50 text-xs font-semibold rounded-full transition">
                            Revocar
                        </button>
                    </form>
                    @endif
                </div>
            </div>
            @empty
            <div class="px-6 py-8 text-center text-sm text-gray-400">
                Este usuario no tiene certificados registrados.
            </div>
            @endforelse
        </div>

    </div>
</x-app-layout>
