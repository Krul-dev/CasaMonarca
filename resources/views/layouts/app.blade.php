<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ isset($title) ? $title . ' — ' : '' }}{{ config('app.name', 'Casa Monarca') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700|archivo:700,800,900&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body style="background: var(--cream-50); color: var(--ink-900); font-family: var(--font-body);"
      class="antialiased"
      x-data="{ sidebarOpen: false }">

{{-- ════════════════════════════════════════════════════════════
     SIDEBAR
════════════════════════════════════════════════════════════ --}}

{{-- Mobile overlay --}}
<div x-show="sidebarOpen"
     x-transition:enter="transition-opacity duration-200"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition-opacity duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     @click="sidebarOpen = false"
     style="position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:40;"
     class="lg:hidden"></div>

<aside id="sidebar"
       :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
       style="position:fixed;top:0;left:0;bottom:0;width:240px;z-index:50;
              background:var(--ink-900);color:var(--cream-200);
              display:flex;flex-direction:column;overflow-y:auto;
              transition:transform .2s ease;"
       class="lg:translate-x-0">

    {{-- Logo --}}
    <div style="padding:20px 20px 16px;border-bottom:1px solid oklch(32% 0.018 50);flex-shrink:0;">
        <a href="{{ route('dashboard') }}" style="display:flex;align-items:center;gap:10px;text-decoration:none;">
            <img src="{{ asset('images/logo-casa-monarca.png') }}"
                 alt="Casa Monarca"
                 style="height:34px;width:auto;object-fit:contain;">
        </a>
    </div>

    {{-- Nav --}}
    <nav style="flex:1;padding:16px 0;overflow-y:auto;">
        @php
            $u   = auth()->user();
            $rid = $u->role_id;
            $voluntarioRoleId = \App\Models\Role::where('name','Voluntario')->value('id');

            // Counts for badges
            $pendientesAcceso   = \App\Models\User::where('status','pendiente')->count();
            $sinAreaCount       = \App\Models\User::whereIn('role_id', array_filter([3, 4, $voluntarioRoleId]))->where('status','alta')->whereNull('area_id')->count();
            $membresiaPendiente = $rid === 2
                ? \App\Models\AreaSolicitud::where('area_id', $u->area_id)->where('status','pendiente')->count()
                : \App\Models\AreaSolicitud::where('status','pendiente')->count();
            $casosMios = ($u->area_id) ? \App\Models\Expediente::where('colaborador_id', $u->id)->whereIn('status',['sin_asignar','en_proceso'])->count() : 0;
            $rectPendientes = ($rid <= 4) ? \App\Models\SolicitudRectificacion::whereNotIn('status',['aprobada','rechazada'])->count() : 0;
        @endphp

        {{-- PANEL --}}
        @include('layouts.partials.sidebar-group', [
            'label' => 'Panel',
            'items' => [
                ['label' => 'Inicio', 'route' => 'dashboard', 'active' => request()->routeIs('dashboard'),
                 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>'],
            ]
        ])

        {{-- USUARIOS --}}
        @if($rid <= 2)
        @php
            $usuariosItems = [
                ['label' => 'Colaboradores', 'route' => 'admin.users.colaboradores',
                 'active' => request()->routeIs('admin.users.colaboradores'),
                 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2h5M12 11a4 4 0 100-8 4 4 0 000 8z"/>'],
                ['label' => 'Migrantes', 'route' => 'admin.users.migrantes',
                 'active' => request()->routeIs('admin.users.migrantes'),
                 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>'],
                ['label' => 'Voluntarios', 'route' => 'admin.users.voluntarios',
                 'active' => request()->routeIs('admin.users.voluntarios'),
                 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>'],
                ['label' => 'Sin área', 'route' => 'admin.sin-area',
                 'active' => request()->routeIs('admin.sin-area'),
                 'badge' => $sinAreaCount + $membresiaPendiente,
                 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>'],
            ];
            // Solo admin ve aprobaciones pendientes y lista completa
            if ($rid === 1) {
                array_unshift($usuariosItems,
                    ['label' => 'Aprobar accesos', 'route' => 'admin.users.approvals',
                     'active' => request()->routeIs('admin.users.approvals'),
                     'badge' => $pendientesAcceso,
                     'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>']
                );
            }
        @endphp
        @include('layouts.partials.sidebar-group', ['label' => 'Usuarios', 'items' => $usuariosItems])
        @endif

        {{-- ÁREAS --}}
        @can('puede-actualizar')
        @include('layouts.partials.sidebar-group', [
            'label' => 'Áreas',
            'items' => [
                ['label' => 'Gestión de áreas', 'route' => 'areas.index',
                 'active' => request()->routeIs('areas.*') || request()->routeIs('admin.areas.*'),
                 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16M3 21h18M9 7h1m-1 4h1m4-4h1m-1 4h1"/>'],
            ]
        ])
        @endcan

        {{-- CASOS (staff con área) --}}
        @if($rid >= 2 && $rid <= 4 || ($voluntarioRoleId && $rid == $voluntarioRoleId))
        @php
            $casosItems = [];
            if ($u->area_id) {
                $casosItems[] = [
                    'label' => 'Mis casos', 'route' => 'casos.mios',
                    'active' => request()->routeIs('casos.mios'),
                    'badge' => $casosMios,
                    'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>',
                ];
                $casosItems[] = [
                    'label' => 'Mi área', 'route' => 'mi-area.index',
                    'active' => request()->routeIs('mi-area.*'),
                    'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2h5M12 11a4 4 0 100-8 4 4 0 000 8z"/>',
                ];
            } else {
                $casosItems[] = [
                    'label' => 'Solicitar área', 'route' => 'mi-area.index',
                    'active' => request()->routeIs('mi-area.*'),
                    'badge_warn' => true,
                    'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>',
                ];
            }
        @endphp
        @include('layouts.partials.sidebar-group', ['label' => 'Casos', 'items' => $casosItems])
        @endif

        {{-- DOCUMENTOS ARCO (todo el staff) --}}
        @if($rid >= 1 && $rid <= 4)
        @include('layouts.partials.sidebar-group', [
            'label' => 'Documentos',
            'items' => [
                ['label' => 'Solicitudes ARCO', 'route' => 'rectificaciones.feed',
                 'active' => request()->routeIs('rectificaciones.*'),
                 'badge'  => $rectPendientes,
                 'icon'   => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>'],
            ]
        ])
        @endif

        {{-- SEGURIDAD (admin only) --}}
        @if($rid === 1)
        @include('layouts.partials.sidebar-group', [
            'label' => 'Seguridad',
            'items' => [
                ['label' => 'Certificados PKI', 'route' => 'admin.certificados.index',
                 'active' => request()->routeIs('admin.certificados.*'),
                 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>'],
                ['label' => 'Log de documentos', 'route' => 'admin.log.documentos',
                 'active' => request()->routeIs('admin.log.documentos'),
                 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>'],
                ['label' => 'Diagnóstico', 'route' => 'admin.diagnostico',
                 'active' => request()->routeIs('admin.diagnostico'),
                 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>'],
            ]
        ])
        @endif
    </nav>

    {{-- User footer --}}
    <div style="padding:14px 16px;border-top:1px solid oklch(32% 0.018 50);flex-shrink:0;">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
            <div style="width:32px;height:32px;border-radius:999px;flex-shrink:0;
                        background:var(--brand-orange-deep);display:flex;align-items:center;
                        justify-content:center;font-family:var(--font-display);font-weight:800;
                        font-size:13px;color:var(--cream-50);">
                {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
            </div>
            <div style="flex:1;min-width:0;">
                <p style="font-size:13px;font-weight:600;color:var(--cream-100);
                           white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                    {{ auth()->user()->name }}
                </p>
                <p style="font-size:11px;color:oklch(55% 0.012 50);margin-top:1px;">
                    {{ auth()->user()->role?->name ?? 'Usuario' }}
                </p>
            </div>
        </div>
        <div style="display:flex;gap:8px;">
            <a href="{{ route('profile.edit') }}"
               style="flex:1;display:flex;align-items:center;justify-content:center;gap:5px;
                      padding:7px;border-radius:var(--r-sm);font-size:11px;font-weight:600;
                      color:oklch(60% 0.012 50);text-decoration:none;
                      border:1px solid oklch(32% 0.018 50);transition:background .15s;"
               onmouseover="this.style.background='oklch(30% 0.018 50)'"
               onmouseout="this.style.background='transparent'">
                <svg style="width:13px;height:13px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
                Perfil
            </a>
            <form method="POST" action="{{ route('logout') }}" style="flex:1;">
                @csrf
                <button type="submit"
                        style="width:100%;display:flex;align-items:center;justify-content:center;gap:5px;
                               padding:7px;border-radius:var(--r-sm);font-size:11px;font-weight:600;
                               color:oklch(60% 0.012 50);background:transparent;cursor:pointer;
                               border:1px solid oklch(32% 0.018 50);transition:background .15s;"
                        onmouseover="this.style.background='oklch(30% 0.018 50)'"
                        onmouseout="this.style.background='transparent'">
                    <svg style="width:13px;height:13px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                    </svg>
                    Salir
                </button>
            </form>
        </div>
    </div>
</aside>

{{-- ════════════════════════════════════════════════════════════
     MAIN WRAPPER
════════════════════════════════════════════════════════════ --}}
<div style="min-height:100vh;" class="lg:pl-60">

    {{-- Top bar --}}
    <header style="position:sticky;top:0;z-index:30;background:var(--paper);
                   border-bottom:1px solid var(--cream-200);height:56px;
                   display:flex;align-items:center;padding:0 24px;gap:16px;">

        {{-- Hamburger (mobile only) --}}
        <button @click="sidebarOpen = true"
                style="display:flex;align-items:center;justify-content:center;
                       width:36px;height:36px;border-radius:var(--r-sm);
                       background:transparent;border:1px solid var(--cream-200);
                       cursor:pointer;color:var(--ink-700);transition:background .15s;"
                onmouseover="this.style.background='var(--cream-100)'"
                onmouseout="this.style.background='transparent'"
                class="lg:hidden">
            <svg style="width:18px;height:18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
        </button>

        {{-- Page title slot or breadcrumb --}}
        <div style="flex:1;min-width:0;">
            @isset($header)
                <div style="font-family:var(--font-display);font-weight:700;font-size:15px;
                             color:var(--ink-900);">{{ $header }}</div>
            @endisset
        </div>

        {{-- Pending badge (admin/coord) --}}
        @if(isset($pendientesAcceso) && $pendientesAcceso > 0)
        @elseif(isset($rid) && $rid <= 2)
            @php $nb = \App\Models\User::where('status','pendiente')->count(); @endphp
            @if($nb > 0)
            <a href="{{ route('admin.users.approvals') }}"
               style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;
                      border-radius:999px;font-size:12px;font-weight:600;text-decoration:none;
                      background:var(--brand-orange-soft);border:1px solid var(--brand-orange-line);
                      color:var(--brand-orange-deep);">
                <span style="width:7px;height:7px;border-radius:999px;
                             background:var(--brand-orange-deep);display:inline-block;"></span>
                {{ $nb }} pendiente{{ $nb !== 1 ? 's' : '' }}
            </a>
            @endif
        @endif

        {{-- User chip --}}
        <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;">
            <div style="width:30px;height:30px;border-radius:999px;
                        background:var(--brand-orange-deep);display:flex;align-items:center;
                        justify-content:center;font-family:var(--font-display);font-weight:800;
                        font-size:12px;color:var(--cream-50);">
                {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
            </div>
            @php $r = auth()->user()->role_id; @endphp
            <span style="font-size:11px;font-family:var(--font-display);font-weight:700;
                          letter-spacing:0.08em;text-transform:uppercase;padding:3px 8px;
                          border-radius:999px;
                          {{ $r===1 ? 'background:oklch(94% 0.04 25);color:var(--brand-red)' :
                             ($r===2 ? 'background:var(--brand-orange-soft);color:var(--brand-orange-deep)' :
                             'background:var(--cream-100);color:var(--ink-500)') }}">
                Nv.{{ $r }}
            </span>
        </div>
    </header>

    {{-- Page content --}}
    <main style="padding:28px 28px 48px;">
        {{ $slot }}
    </main>
</div>

</body>
</html>
