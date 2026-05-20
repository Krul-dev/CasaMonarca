<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Iniciar sesión — Casa Monarca</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700|archivo:700,800,900&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body style="background: var(--cream-50); color: var(--ink-900); font-family: var(--font-body);"
      class="antialiased min-h-screen">

<div class="min-h-screen grid lg:grid-cols-2">

    {{-- ── Columna izquierda: formulario ── --}}
    <div style="background: var(--cream-50);" class="flex flex-col px-8 sm:px-16 lg:px-20 py-12">

        <a href="/">
            <img src="{{ asset('images/logo-casa-monarca.png') }}"
                 alt="Casa Monarca"
                 class="h-12 w-auto">
        </a>

        <div class="flex-1 flex flex-col justify-center max-w-sm w-full mt-10">

            <div class="cm-eyebrow mb-3">Portal de colaboradores</div>
            <h1 class="cm-display mb-3" style="font-size: 2.6rem;">
                Bienvenido<br>de regreso.
            </h1>
            <p style="color: var(--ink-500); font-size: 15px; line-height: 1.6;" class="mb-8">
                Ingresa con tu correo institucional. Si aún no tienes acceso, solicítalo a tu coordinador.
            </p>

            @if (session('status'))
                <div style="background: var(--brand-orange-soft); border: 1px solid var(--brand-orange-line);
                            border-radius: var(--r-sm); padding: 10px 14px; font-size: 13px;
                            color: var(--ink-700);" class="mb-6">
                    {{ session('status') }}
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}" class="space-y-5">
                @csrf

                <div>
                    <label for="email"
                           style="display:block; font-size:11px; font-family:var(--font-display);
                                  font-weight:700; letter-spacing:0.12em; text-transform:uppercase;
                                  color:var(--ink-700); margin-bottom:8px;">
                        Correo electrónico
                    </label>
                    <input id="email" type="email" name="email"
                           value="{{ old('email') }}"
                           placeholder="nombre@casamonarca.org.mx"
                           autocomplete="username" autofocus required
                           style="width:100%; padding:13px 16px; border-radius:var(--r-md);
                                  border:1px solid var(--cream-300); background:var(--paper);
                                  font-family:var(--font-body); font-size:14px; color:var(--ink-900);
                                  box-sizing:border-box; outline:none; transition:border-color .15s;"
                           onfocus="this.style.borderColor='var(--brand-orange)'"
                           onblur="this.style.borderColor='var(--cream-300)'">
                    @error('email')
                        <p style="font-size:12px; color:var(--brand-red); margin-top:6px;">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password"
                           style="display:block; font-size:11px; font-family:var(--font-display);
                                  font-weight:700; letter-spacing:0.12em; text-transform:uppercase;
                                  color:var(--ink-700); margin-bottom:8px;">
                        Contraseña
                    </label>
                    <input id="password" type="password" name="password"
                           placeholder="••••••••••"
                           autocomplete="current-password" required
                           style="width:100%; padding:13px 16px; border-radius:var(--r-md);
                                  border:1px solid var(--cream-300); background:var(--paper);
                                  font-family:var(--font-body); font-size:14px; color:var(--ink-900);
                                  box-sizing:border-box; outline:none; transition:border-color .15s;"
                           onfocus="this.style.borderColor='var(--brand-orange)'"
                           onblur="this.style.borderColor='var(--cream-300)'">
                    @error('password')
                        <p style="font-size:12px; color:var(--brand-red); margin-top:6px;">{{ $message }}</p>
                    @enderror
                    <p style="font-size:11px; color:var(--ink-400); margin-top:6px;">
                        Tu sesión expira tras 30 min de inactividad.
                    </p>
                </div>

                <div class="flex items-center justify-between" style="font-size:13px;">
                    <label style="display:flex; align-items:center; gap:8px; color:var(--ink-700); cursor:pointer;">
                        <input type="checkbox" name="remember"
                               style="accent-color:var(--brand-orange-deep);">
                        Recuérdame en este equipo
                    </label>
                    @if (Route::has('password.request'))
                        <a href="{{ route('password.request') }}"
                           style="color:var(--brand-orange-deep); font-weight:600; text-decoration:none;">
                            ¿Olvidaste tu contraseña?
                        </a>
                    @endif
                </div>

                <button type="submit" class="cm-btn cm-btn-primary"
                        style="width:100%; padding:15px; font-size:15px; border-radius:var(--r-md);">
                    Iniciar sesión &nbsp;→
                </button>
            </form>

            <div style="display:flex; align-items:center; gap:14px; margin:28px 0;
                        color:var(--ink-400); font-size:11px; font-family:var(--font-display);
                        font-weight:700; letter-spacing:0.15em;">
                <div style="flex:1; height:1px; background:var(--cream-200);"></div>
                <span>O ENTRA DE OTRA FORMA</span>
                <div style="flex:1; height:1px; background:var(--cream-200);"></div>
            </div>

            <a href="{{ route('migrante.login') }}"
               style="display:flex; align-items:center; gap:14px; padding:16px 18px;
                      background:var(--brand-orange-soft); border-radius:var(--r-md);
                      border:1px solid var(--brand-orange-line); text-decoration:none; color:var(--ink-900);">
                <div style="width:38px; height:38px; border-radius:var(--r-sm); background:var(--paper);
                            border:1px solid var(--brand-orange-line); display:flex; align-items:center;
                            justify-content:center; flex-shrink:0;">
                    <svg style="width:20px;height:20px;color:var(--brand-orange-deep);"
                         fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                    </svg>
                </div>
                <div style="flex:1;">
                    <div style="font-family:var(--font-display); font-weight:700; font-size:14px;">
                        ¿Eres migrante del albergue?
                    </div>
                    <div style="font-size:12px; color:var(--ink-500); margin-top:2px;">
                        Ingresa con tu llave de acceso
                    </div>
                </div>
                <span style="color:var(--brand-orange-deep); font-weight:700;">→</span>
            </a>

        </div>

        <p style="font-size:11px; color:var(--ink-400); margin-top:32px; font-family:monospace; letter-spacing:0.03em;">
            © {{ date('Y') }} · Casa Monarca A.B.P.
        </p>
    </div>

    {{-- ── Columna derecha: panel de marca (solo desktop) ── --}}
    <div style="background: var(--ink-900); color: var(--cream-50); position:relative; overflow:hidden;
                padding: 48px 56px; display:flex; flex-direction:column; justify-content:space-between;"
         class="hidden lg:flex">

        <div style="position:absolute; right:-120px; top:-60px; width:600px; height:600px; border-radius:50%;
                    background:radial-gradient(circle at 30% 30%, oklch(72% 0.18 50) 0%, oklch(52% 0.20 30) 50%, transparent 70%);
                    opacity:0.35; filter:blur(20px); pointer-events:none;"></div>
        <div style="position:absolute; left:-40px; bottom:-40px; width:240px; height:240px;
                    border-radius:50%; background:oklch(58% 0.20 25 / 0.2);
                    filter:blur(40px); pointer-events:none;"></div>

        <div style="position:relative; display:flex; align-items:center; gap:8px;
                    font-size:12px; color:var(--brand-orange);">
            <span style="width:6px; height:6px; border-radius:999px; background:var(--brand-orange); display:inline-block;"></span>
            Operación activa · Albergue Apodaca
        </div>

        <div style="position:relative;">
            <div class="cm-eyebrow" style="color:var(--brand-orange); margin-bottom:18px;">Nuestra misión</div>
            <p style="font-family:var(--font-display); font-weight:800; font-size:2rem;
                      line-height:1.15; letter-spacing:-0.02em; margin:0; color:var(--cream-50);">
                "Acompañar a la persona migrante en su tránsito, desde el principio de la dignidad humana."
            </p>
            <div style="margin-top:24px; font-size:13px; opacity:0.6; color:var(--cream-200);">
                Casa Monarca · Ayuda Humanitaria al Migrante, A.B.P.
            </div>
        </div>

        <div style="position:relative; display:flex; gap:24px; font-size:12px; opacity:0.5; color:var(--cream-200);">
            <span>Monterrey · N.L.</span>
            <span>·</span>
            <span>Desde 2014</span>
            <span>·</span>
            <span>5 áreas operativas</span>
        </div>
    </div>

</div>

</body>
</html>
