<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Casa Monarca') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700|archivo:700,800,900&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body style="background: var(--cream-50); color: var(--ink-900);" class="font-sans antialiased">
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0">
            <div class="mb-6">
                <a href="/">
                    <img src="{{ asset('images/logo-casa-monarca.png') }}"
                         alt="Casa Monarca"
                         class="h-14 w-auto">
                </a>
            </div>

            <div style="background: var(--paper); border: 1px solid var(--cream-200);
                        box-shadow: var(--shadow-md); border-radius: var(--r-lg);"
                 class="w-full sm:max-w-md px-8 py-7 overflow-hidden">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
