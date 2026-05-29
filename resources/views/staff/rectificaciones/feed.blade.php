<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-200 leading-tight">
            Solicitudes ARCO · Documentos de migrantes
        </h2>
    </x-slot>

    <div class="max-w-5xl mx-auto px-4 py-8 space-y-8">

        @if(session('status'))
        <div class="flex items-center gap-2 bg-green-50 border border-green-200 rounded-xl px-5 py-3 text-sm text-green-700">
            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            {{ session('status') }}
        </div>
        @endif
        @if(session('error'))
        <div class="flex items-center gap-2 bg-red-50 border border-red-200 rounded-xl px-5 py-3 text-sm text-red-700">
            {{ session('error') }}
        </div>
        @endif

        {{-- Encabezado informativo --}}
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm px-6 py-5">
            <h3 class="font-bold text-gray-900 text-base mb-1">Derechos de rectificación y cancelación (ARCO)</h3>
            <p class="text-sm text-gray-500">
                Los migrantes pueden solicitar corrección o eliminación de sus documentos de identidad.
                El personal toma las solicitudes, sube la versión corregida (para rectificaciones), y el coordinador aprueba con su firma digital.
            </p>
        </div>

        {{-- ── Solicitudes abiertas ────────────────────────────── --}}
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <h3 class="font-semibold text-gray-800 text-sm">Solicitudes abiertas</h3>
                <span class="text-xs text-gray-400">{{ $abiertas->count() }} activa(s)</span>
            </div>

            @if($abiertas->isEmpty())
            <div class="px-6 py-10 text-center text-sm text-gray-400">
                No hay solicitudes activas en este momento.
            </div>
            @else
            <div class="divide-y divide-gray-100">
                @foreach($abiertas as $sol)
                @php
                    $statusColor = match($sol->status) {
                        'pendiente'            => 'bg-amber-100 text-amber-700',
                        'en_proceso'           => 'bg-blue-100 text-blue-700',
                        'pendiente_aprobacion' => 'bg-purple-100 text-purple-700',
                        default                => 'bg-gray-100 text-gray-600',
                    };
                    $tipoColor = $sol->tipo === 'cancelacion'
                        ? 'bg-red-50 text-red-700 border-red-200'
                        : 'bg-indigo-50 text-indigo-700 border-indigo-200';
                    $yoTome = $sol->tomado_por === auth()->id();
                    $esCoordinador = auth()->user()->role_id <= 2;
                    $tieneActivo = auth()->user()->certificadoActivo !== null;
                @endphp

                <div class="px-6 py-4" x-data="{ openPropuesta: false, openAprobar: false }">

                    {{-- Feedback per-solicitud --}}
                    @if(session('rect_error_' . $sol->id))
                    <div class="mb-3 text-xs text-red-600 bg-red-50 border border-red-200 rounded-lg px-3 py-2">
                        {{ session('rect_error_' . $sol->id) }}
                    </div>
                    @endif

                    {{-- Header row --}}
                    <div class="flex flex-wrap items-start gap-3 mb-3">
                        <div class="flex-1 min-w-0">
                            <div class="flex flex-wrap items-center gap-2 mb-1">
                                <span class="inline-block px-2 py-0.5 border rounded-full text-xs font-semibold {{ $tipoColor }}">
                                    {{ $sol->tipoLabel() }}
                                </span>
                                <span class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold {{ $statusColor }}">
                                    {{ $sol->statusLabel() }}
                                </span>
                            </div>
                            <p class="text-sm font-semibold text-gray-800">
                                {{ $sol->doc_nombre ?? $sol->documento?->nombre ?? '—' }}
                                @if($sol->doc_etiqueta)
                                <span class="font-normal text-gray-400 text-xs ml-1">· {{ $sol->doc_etiqueta }}</span>
                                @endif
                            </p>
                            <p class="text-xs text-gray-400 mt-0.5">
                                Migrante: <strong class="text-gray-600">{{ $sol->solicitante?->name ?? '—' }}</strong>
                                · Solicitado {{ $sol->created_at->diffForHumans() }}
                                @if($sol->tomadoPor)
                                · Atendido por: <strong class="text-gray-600">{{ $sol->tomadoPor->name }}</strong>
                                @endif
                            </p>
                            @if($sol->descripcion)
                            <p class="text-xs text-gray-500 mt-1 italic">"{{ $sol->descripcion }}"</p>
                            @endif
                        </div>

                        {{-- Download original --}}
                        @if($sol->documento)
                        <a href="{{ route('documentos.download', $sol->documento->id) }}"
                           class="inline-flex items-center gap-1 px-3 py-1.5 bg-gray-50 border border-gray-200 text-xs font-semibold text-gray-600 rounded-full hover:bg-gray-100 transition"
                           title="Descargar original">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                            Original
                        </a>
                        @endif
                        @if($sol->propuesta)
                        <a href="{{ route('documentos.download', $sol->propuesta->id) }}"
                           class="inline-flex items-center gap-1 px-3 py-1.5 bg-indigo-50 border border-indigo-200 text-xs font-semibold text-indigo-700 rounded-full hover:bg-indigo-100 transition"
                           title="Descargar versión corregida">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                            Corrección
                        </a>
                        @endif
                    </div>

                    {{-- Action bar --}}
                    <div class="flex flex-wrap gap-2">

                        {{-- Tomar solicitud --}}
                        @if(!$sol->tomado_por)
                        <form action="{{ route('rectificaciones.tomar', $sol->id) }}" method="POST">
                            @csrf
                            <button type="submit"
                                    class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-full transition">
                                Tomar solicitud
                            </button>
                        </form>
                        @endif

                        {{-- Subir versión corregida --}}
                        @if($sol->tipo === 'rectificacion' && ($yoTome || $esCoordinador) && !$sol->propuesta && !in_array($sol->status, ['pendiente_aprobacion']))
                        <button @click="openPropuesta = !openPropuesta; openAprobar = false"
                                class="px-3 py-1.5 bg-amber-500 hover:bg-amber-600 text-white text-xs font-semibold rounded-full transition">
                            Subir versión corregida
                        </button>
                        @endif

                        {{-- Aprobar con firma (coordinador) --}}
                        @if($esCoordinador && $tieneActivo)
                            @if($sol->tipo === 'cancelacion' && in_array($sol->status, ['pendiente', 'en_proceso']))
                            <button @click="openAprobar = !openAprobar; openPropuesta = false"
                                    class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold rounded-full transition">
                                Aprobar eliminación
                            </button>
                            @elseif($sol->tipo === 'rectificacion' && $sol->status === 'pendiente_aprobacion')
                            <button @click="openAprobar = !openAprobar; openPropuesta = false"
                                    class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold rounded-full transition">
                                Aprobar corrección
                            </button>
                            @endif
                        @endif

                        {{-- Rechazar (coordinador) --}}
                        @if($esCoordinador)
                        <form action="{{ route('rectificaciones.rechazar', $sol->id) }}" method="POST"
                              onsubmit="return confirm('¿Rechazar esta solicitud?')">
                            @csrf
                            <button type="submit"
                                    class="px-3 py-1.5 bg-white border border-red-300 text-red-600 text-xs font-semibold rounded-full hover:bg-red-50 transition">
                                Rechazar
                            </button>
                        </form>
                        @endif
                    </div>

                    {{-- Panel: subir versión corregida --}}
                    <div x-show="openPropuesta" style="display:none" class="mt-4 bg-amber-50 border border-amber-200 rounded-xl p-4">
                        <p class="text-xs font-semibold text-amber-700 mb-3">Subir versión corregida del documento</p>
                        <form action="{{ route('rectificaciones.propuesta', $sol->id) }}" method="POST" enctype="multipart/form-data" class="space-y-2">
                            @csrf
                            <input type="text" name="nombre" placeholder="Nombre descriptivo (opcional)"
                                   class="w-full text-xs border border-amber-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-amber-400 focus:outline-none">
                            <input type="file" name="archivo" required accept=".pdf,.jpg,.jpeg,.png"
                                   class="w-full text-xs border border-amber-200 rounded-lg px-3 py-2 file:mr-2 file:py-0.5 file:px-2 file:rounded-full file:border-0 file:text-xs file:font-semibold file:bg-amber-100 file:text-amber-800">
                            <p class="text-xs text-amber-600">PDF, JPG o PNG · Máx 10 MB</p>
                            <div class="flex gap-2">
                                <button type="submit" class="px-3 py-1 bg-amber-600 hover:bg-amber-700 text-white text-xs font-semibold rounded-full transition">
                                    Subir corrección
                                </button>
                                <button type="button" @click="openPropuesta = false" class="px-2 py-1 text-xs text-gray-500">Cancelar</button>
                            </div>
                        </form>
                    </div>

                    {{-- Panel: aprobar con firma digital --}}
                    @if($esCoordinador && $tieneActivo)
                    <div x-show="openAprobar" style="display:none" class="mt-4 bg-emerald-50 border border-emerald-200 rounded-xl p-4">
                        <p class="text-xs font-semibold text-emerald-700 mb-1">
                            Aprobar con firma digital · Arrastra tu llave .pem
                        </p>
                        <p class="text-xs text-emerald-600 mb-3">
                            @if($sol->tipo === 'cancelacion')
                                Esta acción eliminará el documento del sistema de forma permanente.
                            @else
                                Esta acción reemplazará el documento original por la versión corregida.
                            @endif
                            La llave privada nunca sale de tu navegador.
                        </p>

                        <div class="border-2 border-dashed border-emerald-300 rounded-lg p-4 text-center"
                             ondragover="event.preventDefault(); this.style.borderColor='#059669'"
                             ondragleave="this.style.borderColor=''"
                             ondrop="event.preventDefault(); this.style.borderColor=''; aprobarRectConPem({{ $sol->id }}, event.dataTransfer.files[0])">
                            <p class="text-xs text-emerald-600">Arrastra el archivo .pem aquí</p>
                            <p class="text-xs text-emerald-500 my-1">— o —</p>
                            <label class="inline-block cursor-pointer px-3 py-1 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold rounded-full transition">
                                Seleccionar archivo
                                <input type="file" accept=".pem" class="hidden"
                                       onchange="aprobarRectConPem({{ $sol->id }}, this.files[0]); this.value=''">
                            </label>
                        </div>

                        <div id="rect-status-{{ $sol->id }}" class="text-xs mt-2 hidden"></div>

                        <form id="rect-form-{{ $sol->id }}"
                              action="{{ route('rectificaciones.aprobar', $sol->id) }}"
                              method="POST" class="hidden">
                            @csrf
                            <input type="hidden" name="signature" id="rect-sig-{{ $sol->id }}">
                        </form>

                        <button type="button" @click="openAprobar = false" class="mt-2 text-xs text-emerald-600 hover:text-emerald-800">
                            Cancelar
                        </button>
                    </div>
                    @endif

                </div>
                @endforeach
            </div>
            @endif
        </div>

        {{-- ── Historial reciente ──────────────────────────────── --}}
        @if($historial->isNotEmpty())
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100">
                <h3 class="font-semibold text-gray-800 text-sm">Historial reciente</h3>
            </div>
            <div class="divide-y divide-gray-100">
                @foreach($historial as $sol)
                @php
                    $badge = $sol->status === 'aprobada'
                        ? 'bg-green-100 text-green-700'
                        : 'bg-red-100 text-red-700';
                @endphp
                <div class="px-6 py-3 flex items-center gap-4">
                    <span class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold {{ $badge }}">
                        {{ $sol->statusLabel() }}
                    </span>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-semibold text-gray-700 truncate">
                            {{ $sol->doc_nombre ?? '—' }}
                            <span class="font-normal text-gray-400">· {{ $sol->tipoLabel() }}</span>
                        </p>
                        <p class="text-xs text-gray-400">
                            {{ $sol->solicitante?->name ?? '—' }}
                            · {{ $sol->aprobada_at?->format('d/m/Y H:i') }}
                            @if($sol->aprobadaPor)
                            · Coordinador: {{ $sol->aprobadaPor->name }}
                            @endif
                        </p>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
        @endif

    </div>

    <script>
    async function aprobarRectConPem(solId, file) {
        if (!file) return;
        const statusEl = document.getElementById('rect-status-' + solId);

        function setStatus(msg, type) {
            const colors = { error: 'text-red-600', ok: 'text-emerald-600', info: 'text-gray-500' };
            statusEl.className = 'text-xs mt-2 ' + (colors[type] ?? colors.info);
            statusEl.textContent = msg;
            statusEl.classList.remove('hidden');
        }

        try {
            setStatus('Leyendo llave…', 'info');
            const pem = await file.text();
            const pemBody = pem.replace(/-----[^-\r\n]+-----/g, '').replace(/\s+/g, '');
            const derBuf = Uint8Array.from(atob(pemBody), c => c.charCodeAt(0)).buffer;

            let key;
            try {
                key = await crypto.subtle.importKey(
                    'pkcs8', derBuf,
                    { name: 'RSASSA-PKCS1-v1_5', hash: 'SHA-256' },
                    false, ['sign']
                );
            } catch {
                setStatus('No se pudo leer la llave. Usa la llave RSA privada (.pem) de tu certificado activo.', 'error');
                return;
            }

            setStatus('Solicitando challenge…', 'info');
            const resp = await fetch('{{ url("/staff/rectificaciones") }}/' + solId + '/challenge', {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!resp.ok) {
                const err = await resp.json().catch(() => ({}));
                setStatus(err.error ?? 'Error al solicitar el challenge.', 'error');
                return;
            }

            const { payload } = await resp.json();
            setStatus('Firmando…', 'info');

            const sigBuf = await crypto.subtle.sign(
                'RSASSA-PKCS1-v1_5', key,
                new TextEncoder().encode(payload)
            );
            const sigB64 = btoa(String.fromCharCode(...new Uint8Array(sigBuf)));

            document.getElementById('rect-sig-' + solId).value = sigB64;
            setStatus('Enviando aprobación…', 'info');
            document.getElementById('rect-form-' + solId).submit();

        } catch (e) {
            setStatus('Error inesperado: ' + e.message, 'error');
        }
    }
    </script>
</x-app-layout>
