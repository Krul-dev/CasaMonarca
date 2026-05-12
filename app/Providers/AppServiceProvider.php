<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Nivel 1 — Admin: CRUD completo
        Gate::define('puede-eliminar', fn($user) => $user->role_id === 1);

        // Nivel 1–2 — Admin + Coordinador: CRU
        Gate::define('puede-actualizar', fn($user) => $user->role_id <= 2);

        // Nivel 1–4 — Todos menos Migrante: pueden crear registros (incluye Voluntario)
        Gate::define('puede-crear', fn($user) => $user->role?->nivel_acceso <= 4);
    }
}
