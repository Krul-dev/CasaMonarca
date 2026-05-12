<nav x-data="{ open: false }" class="bg-white dark:bg-gray-800 border-b border-gray-100 dark:border-gray-700">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                {{-- Logo --}}
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('dashboard') }}">
                        <x-application-logo class="block h-9 w-auto fill-current text-gray-800 dark:text-gray-200" />
                    </a>
                </div>

                {{-- Navigation Links --}}
                <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                    <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                        Panel
                    </x-nav-link>

                    @can('puede-eliminar')
                        <x-nav-link :href="route('admin.users.colaboradores')" :active="request()->routeIs('admin.users.colaboradores')">
                            Colaboradores
                        </x-nav-link>
                        <x-nav-link :href="route('admin.users.migrantes')" :active="request()->routeIs('admin.users.migrantes')">
                            Migrantes
                        </x-nav-link>
                        <x-nav-link :href="route('admin.users.voluntarios')" :active="request()->routeIs('admin.users.voluntarios')">
                            Voluntarios
                        </x-nav-link>
                        <x-nav-link :href="route('admin.users.approvals')" :active="request()->routeIs('admin.users.approvals')">
                            Bandeja de Accesos
                            @php $pendientes = \App\Models\User::where('status','pendiente')->count(); @endphp
                            @if($pendientes > 0)
                                <span class="ml-1.5 inline-flex items-center justify-center w-4 h-4 bg-amber-500 text-white text-xs font-bold rounded-full">
                                    {{ $pendientes > 9 ? '9+' : $pendientes }}
                                </span>
                            @endif
                        </x-nav-link>
                        <x-nav-link :href="route('admin.certificados.index')" :active="request()->routeIs('admin.certificados.*')">
                            Certificados
                        </x-nav-link>
                        <x-nav-link :href="route('admin.diagnostico')" :active="request()->routeIs('admin.diagnostico')">
                            Diagnóstico
                        </x-nav-link>
                    @endcan

                    @can('puede-actualizar')
                        <x-nav-link :href="route('areas.index')" :active="request()->routeIs('areas.*')">
                            Áreas
                        </x-nav-link>
                    @endcan

                    {{-- Mis casos: colaboradores con área (role 3-4) --}}
                    @php
                        $roleId    = Auth::user()->role_id ?? 0;
                        $tieneArea = Auth::user()->area_id !== null;
                    @endphp
                    @php $voluntarioRoleId = \App\Models\Role::where('name','Voluntario')->value('id'); @endphp
                    @if($roleId >= 3 && ($roleId <= 4 || $roleId === $voluntarioRoleId))
                        @if($tieneArea)
                            <x-nav-link :href="route('casos.mios')" :active="request()->routeIs('casos.mios')">
                                Mis casos
                                @php $activosMios = \App\Models\Expediente::where('colaborador_id', Auth::id())->whereIn('status',['sin_asignar','en_proceso'])->count(); @endphp
                                @if($activosMios > 0)
                                    <span class="ml-1.5 inline-flex items-center justify-center w-4 h-4 bg-blue-500 text-white text-xs font-bold rounded-full">
                                        {{ $activosMios > 9 ? '9+' : $activosMios }}
                                    </span>
                                @endif
                            </x-nav-link>
                        @else
                            {{-- Sin área: mostrar enlace para solicitarla --}}
                            <x-nav-link :href="route('mi-area.index')" :active="request()->routeIs('mi-area.*')">
                                <span class="flex items-center gap-1">
                                    <span class="w-2 h-2 rounded-full bg-amber-400 animate-pulse"></span>
                                    Mi área
                                </span>
                            </x-nav-link>
                        @endif
                    @endif

                    {{-- Sin área: badge para coordinadores/admin --}}
                    @if($roleId <= 2)
                        @php $sinAreaCount = \App\Models\User::whereIn('role_id', array_filter([3, 4, $voluntarioRoleId ?? null]))->where('status','alta')->whereNull('area_id')->count(); @endphp
                        @php $membresiaPendiente = $roleId === 2 ? \App\Models\AreaSolicitud::where('area_id', Auth::user()->area_id)->where('status','pendiente')->count() : \App\Models\AreaSolicitud::where('status','pendiente')->count(); @endphp
                        <x-nav-link :href="route('admin.sin-area')" :active="request()->routeIs('admin.sin-area')">
                            Sin área
                            @if($sinAreaCount + $membresiaPendiente > 0)
                                <span class="ml-1.5 inline-flex items-center justify-center w-4 h-4 bg-amber-500 text-white text-xs font-bold rounded-full">
                                    {{ min(9, $sinAreaCount + $membresiaPendiente) }}{{ ($sinAreaCount + $membresiaPendiente > 9) ? '+' : '' }}
                                </span>
                            @endif
                        </x-nav-link>
                    @endif

                    @can('puede-eliminar')
                        <x-nav-link :href="route('admin.log.documentos')" :active="request()->routeIs('admin.log.documentos')">
                            Log docs
                        </x-nav-link>
                    @endcan
                </div>
            </div>

            {{-- Settings Dropdown --}}
            <div class="hidden sm:flex sm:items-center sm:ms-6">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 dark:text-gray-400 bg-white dark:bg-gray-800 hover:text-gray-700 dark:hover:text-gray-300 focus:outline-none transition ease-in-out duration-150">
                            <div class="flex items-center gap-2">
                                <span class="w-6 h-6 rounded-full bg-indigo-100 text-indigo-700 text-xs font-bold flex items-center justify-center shrink-0">
                                    {{ strtoupper(substr(Auth::user()->name, 0, 1)) }}
                                </span>
                                {{ Auth::user()->name }}
                                {{-- Nivel badge --}}
                                @php $r = Auth::user()->role_id; @endphp
                                <span class="text-xs px-1.5 py-0.5 rounded font-semibold
                                    @if($r===1) bg-red-100 text-red-600
                                    @elseif($r===2) bg-indigo-100 text-indigo-600
                                    @elseif($r===3) bg-teal-100 text-teal-600
                                    @elseif($r===4) bg-green-100 text-green-600
                                    @else bg-gray-100 text-gray-500 @endif">
                                    Nv.{{ $r }}
                                </span>
                            </div>
                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <div class="px-4 py-2 border-b border-gray-100">
                            <p class="text-xs text-gray-500">{{ Auth::user()->role?->name }}</p>
                            @if(Auth::user()->area)
                                <p class="text-xs text-gray-400">{{ Auth::user()->area->nombre }}</p>
                            @endif
                        </div>

                        <x-dropdown-link :href="route('profile.edit')">
                            Perfil
                        </x-dropdown-link>

                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-dropdown-link :href="route('logout')"
                                    onclick="event.preventDefault(); this.closest('form').submit();">
                                Cerrar sesión
                            </x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>

            {{-- Hamburger --}}
            <div class="-me-2 flex items-center sm:hidden">
                <button @click="open = ! open" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 dark:text-gray-500 hover:text-gray-500 dark:hover:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-900 focus:outline-none focus:bg-gray-100 dark:focus:bg-gray-900 focus:text-gray-500 dark:focus:text-gray-400 transition duration-150 ease-in-out">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    {{-- Responsive Navigation Menu --}}
    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden">
        <div class="pt-2 pb-3 space-y-1">
            <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                Panel
            </x-responsive-nav-link>

            @can('puede-eliminar')
                <x-responsive-nav-link :href="route('admin.users.colaboradores')" :active="request()->routeIs('admin.users.colaboradores')">
                    Colaboradores
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('admin.users.migrantes')" :active="request()->routeIs('admin.users.migrantes')">
                    Migrantes
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('admin.users.voluntarios')" :active="request()->routeIs('admin.users.voluntarios')">
                    Voluntarios
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('admin.users.approvals')" :active="request()->routeIs('admin.users.approvals')">
                    Bandeja de Accesos
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('admin.certificados.index')" :active="request()->routeIs('admin.certificados.*')">
                    Certificados
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('admin.diagnostico')" :active="request()->routeIs('admin.diagnostico')">
                    Diagnóstico
                </x-responsive-nav-link>
            @endcan

            @can('puede-actualizar')
                <x-responsive-nav-link :href="route('areas.index')" :active="request()->routeIs('areas.*')">
                    Áreas
                </x-responsive-nav-link>
            @endcan

            @php $roleId = Auth::user()->role_id ?? 0; $tieneArea = Auth::user()->area_id !== null; $voluntarioRoleId = \App\Models\Role::where('name','Voluntario')->value('id'); @endphp
            @if($roleId >= 3 && ($roleId <= 4 || $roleId === $voluntarioRoleId))
                @if($tieneArea)
                    <x-responsive-nav-link :href="route('casos.mios')" :active="request()->routeIs('casos.mios')">
                        Mis casos
                    </x-responsive-nav-link>
                @else
                    <x-responsive-nav-link :href="route('mi-area.index')" :active="request()->routeIs('mi-area.*')">
                        Mi área (solicitar asignación)
                    </x-responsive-nav-link>
                @endif
            @endif

            @if($roleId <= 2)
                <x-responsive-nav-link :href="route('admin.sin-area')" :active="request()->routeIs('admin.sin-area')">
                    Sin área
                </x-responsive-nav-link>
            @endif

            @can('puede-eliminar')
                <x-responsive-nav-link :href="route('admin.log.documentos')" :active="request()->routeIs('admin.log.documentos')">
                    Log docs
                </x-responsive-nav-link>
            @endcan
        </div>

        <div class="pt-4 pb-1 border-t border-gray-200 dark:border-gray-600">
            <div class="px-4">
                <div class="font-medium text-base text-gray-800 dark:text-gray-200">{{ Auth::user()->name }}</div>
                <div class="font-medium text-sm text-gray-500">{{ Auth::user()->role?->name }}</div>
            </div>

            <div class="mt-3 space-y-1">
                <x-responsive-nav-link :href="route('profile.edit')">
                    Perfil
                </x-responsive-nav-link>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-responsive-nav-link :href="route('logout')"
                            onclick="event.preventDefault(); this.closest('form').submit();">
                        Cerrar sesión
                    </x-responsive-nav-link>
                </form>
            </div>
        </div>
    </div>
</nav>
