<x-app-layout>
<x-slot name="header">Panel de administración</x-slot>

<div style="max-width:1100px;" class="space-y-6">

    {{-- ── Bienvenida + contadores clave ──────────────────────────── --}}
    <div style="background:var(--ink-900);border-radius:var(--r-lg);padding:24px 28px;
                display:flex;flex-wrap:wrap;gap:20px;align-items:center;
                position:relative;overflow:hidden;">

        {{-- Glow decorativo --}}
        <div style="position:absolute;right:-80px;top:-40px;width:320px;height:320px;border-radius:50%;
                    background:radial-gradient(circle,oklch(72% 0.18 50) 0%,transparent 70%);
                    opacity:0.18;pointer-events:none;"></div>

        <div style="flex:1;min-width:200px;position:relative;">
            <div class="cm-eyebrow" style="color:var(--brand-orange);margin-bottom:6px;">
                Casa Monarca · Admin
            </div>
            <p style="font-family:var(--font-display);font-weight:800;font-size:1.5rem;
                      color:var(--cream-50);line-height:1.2;">
                Bienvenido, {{ auth()->user()->name }}
            </p>
            <p style="font-size:13px;color:oklch(55% 0.012 50);margin-top:4px;">
                {{ now()->isoFormat('dddd D [de] MMMM, YYYY') }}
            </p>
        </div>

        {{-- Contadores --}}
        <div style="display:flex;flex-wrap:wrap;gap:12px;position:relative;">
            @php
                $counters = [
                    ['val' => $totalActivos,       'label' => 'Colaboradores',  'color' => 'var(--cream-200)'],
                    ['val' => $totalMigrantes,      'label' => 'Migrantes',      'color' => 'var(--brand-orange)'],
                    ['val' => $expedientesActivos,  'label' => 'Expedientes',    'color' => 'var(--cream-200)'],
                    ['val' => $totalCerts,          'label' => 'Certs. activos', 'color' => 'var(--cream-200)'],
                ];
            @endphp
            @foreach($counters as $c)
            <div style="text-align:center;padding:12px 18px;border-radius:var(--r-md);
                        background:oklch(28% 0.018 50);min-width:80px;">
                <p style="font-family:var(--font-display);font-weight:800;font-size:1.6rem;
                           color:{{ $c['color'] }};line-height:1;">
                    {{ $c['val'] }}
                </p>
                <p style="font-size:11px;color:oklch(50% 0.012 50);margin-top:4px;white-space:nowrap;">
                    {{ $c['label'] }}
                </p>
            </div>
            @endforeach
        </div>
    </div>

    {{-- ── Dos columnas: pendientes + actividad ────────────────────── --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">

        {{-- Pendientes de aprobación --}}
        <div style="background:var(--paper);border:1px solid var(--cream-200);
                    border-radius:var(--r-lg);overflow:hidden;">

            <div style="padding:16px 20px;border-bottom:1px solid var(--cream-100);
                        display:flex;align-items:center;justify-content:space-between;">
                <div style="display:flex;align-items:center;gap:8px;">
                    @if($totalPendientes > 0)
                        <span style="width:8px;height:8px;border-radius:999px;
                                     background:var(--brand-orange);display:inline-block;
                                     animation:pulse 2s cubic-bezier(.4,0,.6,1) infinite;"></span>
                    @endif
                    <h3 style="font-family:var(--font-display);font-weight:700;font-size:14px;
                               color:var(--ink-900);">
                        Pendientes de aprobación
                    </h3>
                </div>
                <div style="display:flex;align-items:center;gap:10px;">
                    @if($totalPendientes > 0)
                        <span style="font-family:var(--font-display);font-weight:700;font-size:11px;
                                     letter-spacing:0.08em;padding:3px 10px;border-radius:999px;
                                     background:var(--brand-orange-soft);border:1px solid var(--brand-orange-line);
                                     color:var(--brand-orange-deep);">
                            {{ $totalPendientes }}
                        </span>
                    @endif
                    <a href="{{ route('admin.users.approvals') }}"
                       style="font-size:12px;color:var(--brand-orange-deep);font-weight:600;
                              text-decoration:none;">
                        Ver todos →
                    </a>
                </div>
            </div>

            @if($usuariosPendientes->isEmpty())
                <div style="padding:36px 20px;text-align:center;">
                    <div style="width:40px;height:40px;border-radius:999px;
                                background:oklch(94% 0.04 155);display:flex;align-items:center;
                                justify-content:center;margin:0 auto 10px;">
                        <svg style="width:20px;height:20px;color:oklch(55% 0.15 155);"
                             fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <p style="font-size:13px;color:var(--ink-500);">Sin solicitudes pendientes</p>
                </div>
            @else
                <div>
                    @foreach($usuariosPendientes as $pendiente)
                    <div style="display:flex;align-items:center;gap:12px;padding:12px 20px;
                                border-bottom:1px solid var(--cream-100);">
                        <div style="width:32px;height:32px;border-radius:999px;flex-shrink:0;
                                    background:var(--brand-orange-soft);border:1px solid var(--brand-orange-line);
                                    display:flex;align-items:center;justify-content:center;
                                    font-family:var(--font-display);font-weight:800;font-size:12px;
                                    color:var(--brand-orange-deep);">
                            {{ strtoupper(substr($pendiente->name, 0, 1)) }}
                        </div>
                        <div style="flex:1;min-width:0;">
                            <p style="font-size:13px;font-weight:600;color:var(--ink-900);
                                      white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                {{ $pendiente->name }}
                            </p>
                            <p style="font-size:11px;color:var(--ink-400);margin-top:1px;">
                                {{ $pendiente->email }} · {{ $pendiente->role?->name ?? '—' }}
                            </p>
                        </div>
                        <div style="display:flex;gap:6px;flex-shrink:0;">
                            <form method="POST" action="{{ route('users.approve', $pendiente->id) }}">
                                @csrf
                                <button type="submit"
                                        style="padding:5px 12px;border-radius:999px;font-size:11px;
                                               font-weight:700;background:var(--ink-900);
                                               color:var(--cream-50);border:none;cursor:pointer;
                                               transition:opacity .15s;"
                                        onmouseover="this.style.opacity='.8'"
                                        onmouseout="this.style.opacity='1'">
                                    Aprobar
                                </button>
                            </form>
                            <form method="POST" action="{{ route('users.reject', $pendiente->id) }}">
                                @csrf
                                <button type="submit"
                                        onclick="return confirm('¿Rechazar a {{ addslashes($pendiente->name) }}?')"
                                        style="padding:5px 12px;border-radius:999px;font-size:11px;
                                               font-weight:700;background:transparent;
                                               border:1px solid var(--cream-300);
                                               color:var(--ink-500);cursor:pointer;
                                               transition:background .15s;"
                                        onmouseover="this.style.background='var(--cream-100)'"
                                        onmouseout="this.style.background='transparent'">
                                    Rechazar
                                </button>
                            </form>
                        </div>
                    </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Actividad reciente --}}
        <div style="background:var(--paper);border:1px solid var(--cream-200);
                    border-radius:var(--r-lg);overflow:hidden;">

            <div style="padding:16px 20px;border-bottom:1px solid var(--cream-100);
                        display:flex;align-items:center;justify-content:space-between;">
                <h3 style="font-family:var(--font-display);font-weight:700;font-size:14px;
                           color:var(--ink-900);">
                    Actividad reciente
                </h3>
                <a href="{{ route('admin.log.documentos') }}"
                   style="font-size:12px;color:var(--brand-orange-deep);font-weight:600;text-decoration:none;">
                    Log completo →
                </a>
            </div>

            @if($actividadReciente->isEmpty())
                <div style="padding:36px 20px;text-align:center;font-size:13px;color:var(--ink-400);">
                    Sin actividad registrada.
                </div>
            @else
                <div style="max-height:420px;overflow-y:auto;">
                    @foreach($actividadReciente as $log)
                    @php
                        $accion = str_replace('_', ' ', $log->accion);
                        $hora   = $log->created_at->diffForHumans();
                    @endphp
                    <div style="display:flex;align-items:flex-start;gap:10px;
                                padding:11px 20px;border-bottom:1px solid var(--cream-100);">
                        <div style="width:28px;height:28px;border-radius:999px;flex-shrink:0;
                                    background:var(--cream-100);display:flex;align-items:center;
                                    justify-content:center;margin-top:1px;">
                            <svg style="width:13px;height:13px;color:var(--ink-400);"
                                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </div>
                        <div style="flex:1;min-width:0;">
                            <p style="font-size:12px;color:var(--ink-700);line-height:1.4;">
                                <strong style="color:var(--ink-900);">{{ $log->actor_nombre }}</strong>
                                <span style="color:var(--ink-500);"> {{ $accion }}</span>
                                @if(!empty($log->payload))
                                    @php
                                        $detail = collect($log->payload)->first(fn($v,$k) => in_array($k,['usuario','folio','nombre','etiqueta','fingerprint']));
                                    @endphp
                                    @if($detail)
                                        <span style="font-family:monospace;font-size:11px;
                                                     color:var(--brand-orange-deep);"> · {{ $detail }}</span>
                                    @endif
                                @endif
                            </p>
                            <p style="font-size:11px;color:var(--ink-400);margin-top:2px;">
                                {{ $hora }}
                            </p>
                        </div>
                    </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- ── Accesos directos ─────────────────────────────────────────── --}}
    <div>
        <p style="font-size:11px;font-family:var(--font-display);font-weight:700;
                   letter-spacing:0.14em;text-transform:uppercase;color:var(--ink-400);
                   margin-bottom:12px;">
            Accesos directos
        </p>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            @php
                $accesos = [
                    ['label' => 'Aprobar accesos',    'route' => 'admin.users.approvals',   'badge' => $totalPendientes,
                     'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
                    ['label' => 'Colaboradores',      'route' => 'admin.users.colaboradores','badge' => 0,
                     'icon' => 'M17 20h5v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2h5M12 11a4 4 0 100-8 4 4 0 000 8z'],
                    ['label' => 'Migrantes',          'route' => 'admin.users.migrantes',   'badge' => 0,
                     'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
                    ['label' => 'Áreas',              'route' => 'areas.index',             'badge' => 0,
                     'icon' => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16M3 21h18M9 7h1m-1 4h1m4-4h1m-1 4h1'],
                    ['label' => 'Certificados',       'route' => 'admin.certificados.index','badge' => 0,
                     'icon' => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z'],
                    ['label' => 'Diagnóstico',        'route' => 'admin.diagnostico',       'badge' => 0,
                     'icon' => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065zM15 12a3 3 0 11-6 0 3 3 0 016 0z'],
                ];
            @endphp
            @foreach($accesos as $a)
            <a href="{{ route($a['route']) }}"
               style="display:flex;flex-direction:column;align-items:center;gap:8px;
                      padding:16px 12px;border-radius:var(--r-lg);text-decoration:none;
                      background:var(--paper);border:1px solid var(--cream-200);
                      color:var(--ink-700);position:relative;transition:border-color .15s,box-shadow .15s;"
               onmouseover="this.style.borderColor='var(--brand-orange-line)';this.style.boxShadow='var(--shadow-md)'"
               onmouseout="this.style.borderColor='var(--cream-200)';this.style.boxShadow='none'">

                @if($a['badge'] > 0)
                <span style="position:absolute;top:8px;right:8px;min-width:17px;height:17px;
                             border-radius:999px;padding:0 4px;background:var(--brand-orange-deep);
                             color:var(--cream-50);font-size:10px;font-weight:700;
                             display:flex;align-items:center;justify-content:center;
                             font-family:var(--font-display);">
                    {{ $a['badge'] }}
                </span>
                @endif

                <div style="width:38px;height:38px;border-radius:var(--r-md);
                            background:var(--cream-100);display:flex;align-items:center;
                            justify-content:center;">
                    <svg style="width:18px;height:18px;color:var(--ink-600);"
                         fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="{{ $a['icon'] }}"/>
                    </svg>
                </div>
                <span style="font-size:12px;font-weight:600;text-align:center;
                             color:var(--ink-700);line-height:1.3;">
                    {{ $a['label'] }}
                </span>
            </a>
            @endforeach
        </div>
    </div>

    {{-- ── Distribución por áreas ───────────────────────────────────── --}}
    <div style="background:var(--paper);border:1px solid var(--cream-200);
                border-radius:var(--r-lg);overflow:hidden;">
        <div style="padding:16px 20px;border-bottom:1px solid var(--cream-100);">
            <h3 style="font-family:var(--font-display);font-weight:700;font-size:14px;color:var(--ink-900);">
                Colaboradores por área
            </h3>
        </div>
        <div style="padding:4px 0;">
            @foreach($areas as $area)
            <a href="{{ route('admin.areas.show', $area) }}"
               style="display:flex;align-items:center;gap:12px;padding:11px 20px;
                      text-decoration:none;transition:background .12s;"
               onmouseover="this.style.background='var(--cream-50)'"
               onmouseout="this.style.background='transparent'">
                <div style="width:8px;height:8px;border-radius:999px;flex-shrink:0;
                            background:var(--brand-orange);opacity:{{ $area->colaboradores_activos > 0 ? '1' : '0.25' }};"></div>
                <span style="flex:1;font-size:13px;color:var(--ink-800);">{{ $area->nombre }}</span>
                <span style="font-size:12px;font-weight:600;color:var(--ink-500);">
                    {{ $area->colaboradores_activos }}
                    <span style="font-weight:400;color:var(--ink-400);">activos</span>
                </span>
                <svg style="width:14px;height:14px;color:var(--ink-300);"
                     fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
            </a>
            @endforeach
        </div>
    </div>

</div>
</x-app-layout>
