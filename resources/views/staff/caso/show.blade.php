<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-200 leading-tight">
            Caso {{ $expediente->folio ?? '—' }}
        </h2>
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
        @if(session('error'))
            <div class="flex items-center gap-2 bg-red-50 border border-red-200 rounded-xl px-5 py-3 text-sm text-red-700">
                {{ session('error') }}
            </div>
        @endif

        {{-- Breadcrumb --}}
        <div class="flex items-center gap-2 text-xs text-gray-400">
            <a href="{{ route('areas.index') }}" class="hover:text-indigo-500">Áreas</a>
            <span>/</span>
            <a href="{{ route('casos.bandeja', $expediente->area_id) }}" class="hover:text-indigo-500">
                Bandeja {{ $expediente->area?->nombre }}
            </a>
            <span>/</span>
            <span class="text-gray-600 font-mono font-bold">{{ $expediente->folio ?? 'Caso' }}</span>
        </div>

        {{-- ── Cabecera ─────────────────────────────────────────── --}}
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
            <div class="flex flex-col sm:flex-row sm:items-start gap-5">
                <div class="flex-1 min-w-0 space-y-3">

                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-mono text-sm font-bold text-indigo-700 bg-indigo-50 px-3 py-1 rounded-full">
                            {{ $expediente->folio ?? 'Sin folio' }}
                        </span>
                        @if($expediente->status === 'en_proceso')
                            <span class="px-2.5 py-1 bg-blue-100 text-blue-700 text-xs font-semibold rounded-full">En proceso</span>
                        @elseif($expediente->status === 'terminado')
                            <span class="px-2.5 py-1 bg-green-100 text-green-700 text-xs font-semibold rounded-full">Resuelto</span>
                        @else
                            <span class="px-2.5 py-1 bg-gray-100 text-gray-500 text-xs font-semibold rounded-full">Sin asignar</span>
                        @endif
                        <span class="text-xs text-gray-400">Área: {{ $expediente->area?->nombre }}</span>
                    </div>

                    @php $sol = $expediente->solicitudes->first(); @endphp
                    @if($sol)
                    <div>
                        @php
                            $p      = $sol->migrantePerfil;
                            $nombre = $p
                                ? trim($p->nombre . ' ' . $p->primer_apellido . ($p->segundo_apellido ? ' ' . $p->segundo_apellido : ''))
                                : ($sol->solicitante?->name ?? '—');
                        @endphp
                        <p class="text-sm font-semibold text-gray-800">{{ $nombre }}</p>
                        @if($p)
                        <p class="text-xs text-gray-400 mt-0.5">
                            {{ $p->pais_origen }} · {{ $p->genero }} · {{ \Carbon\Carbon::parse($p->fecha_nacimiento)->age }} años
                        </p>
                        @endif
                    </div>

                    <div class="bg-gray-50 rounded-xl p-4">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Solicitud original</p>
                        <p class="text-xs font-semibold text-indigo-700 mb-1">{{ ucfirst($sol->tipo) }}</p>
                        <p class="text-sm text-gray-700 leading-relaxed">{{ $sol->descripcion }}</p>
                        <p class="text-xs text-gray-400 mt-2">Enviada {{ $sol->created_at->format('d/m/Y H:i') }}</p>
                    </div>
                    @endif

                    <div class="flex flex-wrap gap-3 text-xs text-gray-500">
                        <span>Responsable: <strong class="text-gray-700">{{ $expediente->colaborador?->name ?? '—' }}</strong></span>
                        <span>Abierto: <strong class="text-gray-700">{{ $expediente->created_at->format('d/m/Y') }}</strong></span>
                        @if($expediente->status === 'terminado')
                            <span>Resuelto por: <strong class="text-gray-700">{{ $expediente->resueltoPor?->name ?? '—' }}</strong></span>
                            <span>{{ $expediente->resuelto_at?->format('d/m/Y') }}</span>
                        @endif
                    </div>
                </div>

                {{-- Botón resolver --}}
                @if($expediente->status !== 'terminado' && ($esCoordinador || $esMiCaso))
                <div class="shrink-0">
                    <form action="{{ route('casos.resolver', $expediente->id) }}" method="POST">
                        @csrf
                        <button type="submit"
                                onclick="return confirm('¿Marcar este caso como resuelto?')"
                                class="inline-flex items-center gap-2 px-5 py-2.5 bg-green-600 hover:bg-green-700 text-white text-sm font-semibold rounded-full transition shadow-sm">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            Marcar como resuelto
                        </button>
                    </form>
                </div>
                @endif
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

            {{-- ── Notas ─────────────────────────────────────────── --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100">
                    <h3 class="font-semibold text-gray-800 text-sm">Notas del caso</h3>
                </div>
                <div class="px-5 py-4 min-h-[100px]">
                    @if($expediente->notas)
                        <pre class="text-xs text-gray-700 leading-relaxed whitespace-pre-wrap font-sans">{{ $expediente->notas }}</pre>
                    @else
                        <p class="text-xs text-gray-400 italic">Sin notas aún.</p>
                    @endif
                </div>

                @if($expediente->status !== 'terminado' && ($esCoordinador || $esMiCaso))
                <div class="px-5 pb-5 border-t border-gray-100 pt-4">
                    <form action="{{ route('casos.nota', $expediente->id) }}" method="POST" class="space-y-2">
                        @csrf
                        <textarea name="nota" rows="3" required maxlength="2000"
                                  placeholder="Escribe una nota de seguimiento..."
                                  class="w-full text-xs border border-gray-200 rounded-xl px-3 py-2 focus:ring-1 focus:ring-indigo-400 focus:outline-none resize-none"></textarea>
                        <button type="submit"
                                class="px-4 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-full transition">
                            Agregar nota
                        </button>
                    </form>
                </div>
                @endif
            </div>

            {{-- ── Documentos ────────────────────────────────────── --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100">
                    <h3 class="font-semibold text-gray-800 text-sm">Documentos</h3>
                    <p class="text-xs text-gray-400 mt-0.5">{{ $expediente->documentos->count() }} archivo(s) · inmutables una vez subidos</p>
                </div>

                @if($expediente->documentos->isNotEmpty())
                <div class="divide-y divide-gray-100">
                    @foreach($expediente->documentos as $doc)
                    @php $yoFirmé = $tieneCertActivo && $doc->firmas->contains('firmado_por', auth()->id()); @endphp
                    <div class="px-5 py-3" x-data="{ openEditar: false, openEliminar: false, openFirmar: false }">

                        {{-- Per-document firma feedback --}}
                        @if(session('firma_ok_' . $doc->id))
                        <div class="flex items-center gap-2 mb-2 bg-green-50 border border-green-200 rounded-lg px-3 py-2 text-xs text-green-700">
                            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            {{ session('firma_ok_' . $doc->id) }}
                        </div>
                        @endif
                        @if(session('firma_error_' . $doc->id))
                        <div class="flex items-center gap-2 mb-2 bg-red-50 border border-red-200 rounded-lg px-3 py-2 text-xs text-red-700">
                            {{ session('firma_error_' . $doc->id) }}
                        </div>
                        @endif

                        <div class="flex items-center gap-3">
                            <div class="w-7 h-7 rounded bg-indigo-50 flex items-center justify-center shrink-0">
                                <svg class="w-3.5 h-3.5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-semibold text-gray-700 truncate">{{ $doc->nombre }}</p>
                                <p class="text-xs text-gray-400">
                                    {{ strtoupper($doc->tipo) }} · {{ $doc->autor?->name ?? '—' }} · {{ $doc->created_at->format('d/m/Y') }}
                                </p>
                                {{-- Firma badges --}}
                                @if($doc->firmas->isNotEmpty())
                                <div class="flex flex-wrap gap-1 mt-1">
                                    @foreach($doc->firmas as $firma)
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs rounded-full font-medium"
                                          title="Firmado {{ $firma->firmado_at->format('d/m/Y H:i') }}">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                        {{ $firma->firmante?->name ?? 'Firmante eliminado' }}
                                        <span class="text-emerald-400">· {{ $firma->firmado_at->format('d/m/Y') }}</span>
                                    </span>
                                    @endforeach
                                </div>
                                @endif
                            </div>
                            {{-- Coordinator-only actions --}}
                            @if($esCoordinador && $expediente->status !== 'terminado')
                            <div class="flex gap-1 shrink-0">
                                @if($tieneCertActivo && !$yoFirmé)
                                <button @click="openFirmar = !openFirmar; openEditar = false; openEliminar = false"
                                        title="Firmar digitalmente"
                                        class="p-1.5 text-gray-400 hover:text-emerald-600 transition rounded-lg hover:bg-emerald-50">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                                    </svg>
                                </button>
                                @endif
                                <button @click="openEditar = !openEditar; openEliminar = false; openFirmar = false"
                                        title="Editar (requiere llave PEM)"
                                        class="p-1.5 text-gray-400 hover:text-indigo-600 transition rounded-lg hover:bg-indigo-50">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                    </svg>
                                </button>
                                <button @click="openEliminar = !openEliminar; openEditar = false; openFirmar = false"
                                        title="Eliminar (requiere llave PEM)"
                                        class="p-1.5 text-gray-400 hover:text-red-600 transition rounded-lg hover:bg-red-50">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                </button>
                            </div>
                            @endif
                        </div>

                        {{-- Editar documento (requiere PEM) --}}
                        @if($esCoordinador)
                        <div x-show="openEditar" style="display:none" class="mt-3 bg-indigo-50 border border-indigo-200 rounded-xl p-3">
                            <p class="text-xs font-semibold text-indigo-700 mb-2">
                                <svg class="w-3.5 h-3.5 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                                </svg>
                                Se requiere tu llave PEM para editar
                            </p>
                            <form action="{{ route('casos.documento.editar', [$expediente->id, $doc->id]) }}" method="POST" class="space-y-2">
                                @csrf
                                @method('PATCH')
                                <input type="text" name="nombre" value="{{ $doc->nombre }}" required maxlength="255"
                                       class="w-full text-xs border border-indigo-200 rounded-lg px-3 py-1.5 focus:ring-1 focus:ring-indigo-400 focus:outline-none">
                                <textarea name="pem_llave" rows="4" required
                                          placeholder="-----BEGIN RSA PRIVATE KEY-----&#10;...&#10;-----END RSA PRIVATE KEY-----"
                                          class="w-full text-xs font-mono border border-indigo-200 rounded-lg px-3 py-1.5 focus:ring-1 focus:ring-indigo-400 focus:outline-none resize-none"></textarea>
                                <div class="flex gap-2">
                                    <button type="submit" class="px-3 py-1 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-full transition">
                                        Guardar cambio
                                    </button>
                                    <button type="button" @click="openEditar = false" class="px-2 py-1 text-xs text-gray-500 hover:text-gray-700 transition">
                                        Cancelar
                                    </button>
                                </div>
                            </form>
                        </div>

                        {{-- Eliminar documento (requiere PEM) --}}
                        <div x-show="openEliminar" style="display:none" class="mt-3 bg-red-50 border border-red-200 rounded-xl p-3">
                            <p class="text-xs font-semibold text-red-700 mb-2">
                                <svg class="w-3.5 h-3.5 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                                </svg>
                                Confirmar eliminación · requiere tu llave PEM
                            </p>
                            <form action="{{ route('casos.documento.eliminar', [$expediente->id, $doc->id]) }}" method="POST" class="space-y-2">
                                @csrf
                                @method('DELETE')
                                <textarea name="pem_llave" rows="4" required
                                          placeholder="-----BEGIN RSA PRIVATE KEY-----&#10;...&#10;-----END RSA PRIVATE KEY-----"
                                          class="w-full text-xs font-mono border border-red-200 rounded-lg px-3 py-1.5 focus:ring-1 focus:ring-red-400 focus:outline-none resize-none"></textarea>
                                <div class="flex gap-2">
                                    <button type="submit" class="px-3 py-1 bg-red-600 hover:bg-red-700 text-white text-xs font-semibold rounded-full transition">
                                        Eliminar permanentemente
                                    </button>
                                    <button type="button" @click="openEliminar = false" class="px-2 py-1 text-xs text-gray-500 hover:text-gray-700 transition">
                                        Cancelar
                                    </button>
                                </div>
                            </form>
                        </div>
                        {{-- Firma digital panel --}}
                        @if($tieneCertActivo && !$yoFirmé)
                        <div x-show="openFirmar" style="display:none" class="mt-3 bg-emerald-50 border border-emerald-200 rounded-xl p-3">
                            <p class="text-xs font-semibold text-emerald-700 mb-1 flex items-center gap-1">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                Firma digital · Arrastra tu llave .pem o selecciónala
                            </p>
                            <p class="text-xs text-emerald-600 mb-2">La llave privada nunca sale de tu navegador.</p>

                            <div id="firmar-drop-{{ $doc->id }}"
                                 class="border-2 border-dashed border-emerald-300 rounded-lg p-4 text-center"
                                 ondragover="event.preventDefault(); this.style.borderColor='#059669'"
                                 ondragleave="this.style.borderColor=''"
                                 ondrop="event.preventDefault(); this.style.borderColor=''; firmarConPem({{ $doc->id }}, event.dataTransfer.files[0])">
                                <p class="text-xs text-emerald-600">Arrastra el archivo .pem aquí</p>
                                <p class="text-xs text-emerald-500 my-1">— o —</p>
                                <label class="inline-block cursor-pointer px-3 py-1 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold rounded-full transition">
                                    Seleccionar archivo
                                    <input type="file" accept=".pem" class="hidden"
                                           onchange="firmarConPem({{ $doc->id }}, this.files[0]); this.value=''">
                                </label>
                            </div>

                            <div id="firma-status-{{ $doc->id }}" class="text-xs mt-2 hidden"></div>

                            <form id="firma-form-{{ $doc->id }}"
                                  action="{{ route('firmar.store', $doc->id) }}"
                                  method="POST" class="hidden">
                                @csrf
                                <input type="hidden" name="signature" id="firma-sig-{{ $doc->id }}">
                            </form>

                            <button type="button" @click="openFirmar = false" class="mt-2 text-xs text-emerald-600 hover:text-emerald-800">
                                Cancelar
                            </button>
                        </div>
                        @endif

                        @endif
                    </div>
                    @endforeach
                </div>
                @else
                    <div class="px-5 py-6 text-center text-xs text-gray-400">Sin documentos adjuntos.</div>
                @endif

                {{-- Subir documento (colaborador o coordinador, solo si activo) --}}
                @if($expediente->status !== 'terminado' && ($esCoordinador || $esMiCaso))
                <div class="px-5 pb-5 border-t border-gray-100 pt-4">
                    <form action="{{ route('casos.documento', $expediente->id) }}" method="POST" enctype="multipart/form-data" class="space-y-2">
                        @csrf
                        <input type="text" name="nombre" required maxlength="255"
                               placeholder="Nombre del documento"
                               class="w-full text-xs border border-gray-200 rounded-xl px-3 py-2 focus:ring-1 focus:ring-indigo-400 focus:outline-none">
                        <input type="file" name="documento" required
                               accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                               class="w-full text-xs border border-gray-200 rounded-xl px-3 py-2
                                      file:mr-2 file:py-0.5 file:px-2 file:rounded-full file:border-0
                                      file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700">
                        <p class="text-xs text-gray-400">PDF, Word, JPG o PNG · Máx 10 MB · No editable tras subir</p>
                        <button type="submit"
                                class="px-4 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-full transition">
                            Subir documento
                        </button>
                    </form>
                </div>
                @endif
            </div>

        </div>

    </div>

    <script>
    async function firmarConPem(docId, file) {
        if (!file) return;
        const statusEl = document.getElementById('firma-status-' + docId);

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
                setStatus('No se pudo cargar la llave. Asegúrate de que sea la llave RSA privada (.pem) de tu certificado activo.', 'error');
                return;
            }

            setStatus('Solicitando challenge…', 'info');
            const resp = await fetch('{{ url("/firmar/challenge") }}/' + docId, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });

            if (!resp.ok) {
                const err = await resp.json().catch(() => ({}));
                setStatus(err.error ?? 'Error al solicitar el challenge. Recarga e intenta de nuevo.', 'error');
                return;
            }

            const { payload } = await resp.json();

            setStatus('Firmando…', 'info');
            const sigBuf = await crypto.subtle.sign(
                'RSASSA-PKCS1-v1_5', key,
                new TextEncoder().encode(payload)
            );
            const sigB64 = btoa(String.fromCharCode(...new Uint8Array(sigBuf)));

            document.getElementById('firma-sig-' + docId).value = sigB64;
            setStatus('Enviando firma…', 'info');
            document.getElementById('firma-form-' + docId).submit();

        } catch (e) {
            setStatus('Error inesperado: ' + e.message, 'error');
        }
    }
    </script>
</x-app-layout>
