<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Casa Monarca — Portal de Acceso</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700|archivo:700,800,900&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body style="background: var(--cream-50); color: var(--ink-900);" class="antialiased min-h-screen flex flex-col">

    {{-- Nav superior --}}
    <nav style="background: var(--paper); border-bottom: 1px solid var(--cream-200);">
        <div class="max-w-5xl mx-auto px-6 py-3 flex items-center justify-between">
            <a href="/">
                <img src="{{ asset('images/logo-casa-monarca.png') }}"
                     alt="Casa Monarca"
                     class="h-10 w-auto">
            </a>
            @if (Route::has('login'))
                <div class="flex items-center gap-3">
                    @auth
                        <a href="{{ url('/dashboard') }}" class="cm-btn cm-btn-ghost" style="font-size:13px; padding:8px 18px;">
                            Dashboard
                        </a>
                    @else
                        <a href="{{ route('login') }}" class="cm-btn cm-btn-ghost" style="font-size:13px; padding:8px 18px;">
                            Iniciar sesión
                        </a>
                        @if (Route::has('tipo-registro'))
                            <a href="{{ route('tipo-registro') }}" class="cm-btn cm-btn-primary" style="font-size:13px; padding:8px 18px;">
                                Registrarse
                            </a>
                        @endif
                    @endauth
                </div>
            @endif
        </div>
    </nav>

    {{-- Hero --}}
    <main class="flex-1 flex items-center justify-center px-6 py-16">
        <div class="max-w-xl w-full text-center">

            <div class="mb-8">
                <img src="{{ asset('images/logo-casa-monarca.png') }}"
                     alt="Casa Monarca"
                     class="h-24 w-auto mx-auto">
            </div>

            <div class="cm-eyebrow mb-4">Portal de gestión</div>
            <h1 class="cm-display mb-4" style="font-size: clamp(2.5rem, 6vw, 4rem);">
                Casa Monarca
            </h1>
            <p style="color: var(--ink-500); font-size: 16px; line-height: 1.65;" class="mb-10 max-w-md mx-auto">
                Ayuda Humanitaria al Migrante — Sistema de gestión de casos, documentos y expedientes del albergue.
            </p>

            <div class="cm-divider-orange mx-auto mb-10"></div>

            {{-- CTAs --}}
            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                <a href="{{ route('tipo-acceso') }}" class="cm-btn cm-btn-primary" style="font-size:15px; padding:14px 32px;">
                    Ingresar al portal
                </a>
                <a href="{{ route('tipo-registro') }}" class="cm-btn cm-btn-ghost" style="font-size:15px; padding:14px 32px;">
                    Crear una cuenta
                </a>
            </div>

            {{-- Acceso migrante --}}
            <div class="mt-8">
                <a href="{{ route('migrante.login') }}"
                   style="display:inline-flex; align-items:center; gap:10px; padding:14px 20px;
                          background: var(--brand-orange-soft); border-radius: var(--r-md);
                          border: 1px solid var(--brand-orange-line); text-decoration:none;
                          font-size:14px; color: var(--ink-900);">
                    <svg style="width:18px;height:18px;color:var(--brand-orange-deep);flex-shrink:0;"
                         fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                    </svg>
                    <span>¿Eres migrante del albergue?
                        <strong style="color:var(--brand-orange-deep);">Ingresa aquí →</strong>
                    </span>
                </a>
            </div>

        </div>
    </main>

    {{-- Footer --}}
    <footer style="background: var(--ink-900); color: var(--ink-400);" class="py-5 px-6">
        <div class="max-w-5xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-2 text-xs">
            <span style="color: var(--cream-200);">© {{ date('Y') }} Casa Monarca — Ayuda Humanitaria al Migrante, A.B.P.</span>
            <span>Monterrey, N.L. · Desde 2014</span>
        </div>
    </footer>

</body>
</html>