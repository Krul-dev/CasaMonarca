# Casa Monarca — Project Context

> Paste this file at the start of a new Claude conversation to resume work.

---

## Project Summary

Laravel 13 web app for **Casa Monarca** (NGO shelter for migrants in Monterrey, MX).  
Built for the Tec de Monterrey course MA2006B — "Digital Signatures and Personal Data Protection".  
**Reto:** Identity manager with digital certificates (PKI), lifecycle management, and migrant case tracking.

---

## Tech Stack

| Layer | Tech | Version |
|-------|------|---------|
| Language | PHP | ^8.3 |
| Framework | Laravel | ^13.0 |
| Auth | Laravel Breeze (customized) | ^2.4 |
| Frontend | Tailwind CSS + Alpine.js | Tailwind 3, Alpine 3 |
| Build | Vite + laravel-vite-plugin | Vite 8 |
| Database (dev) | SQLite | — |
| Database (prod) | MySQL | 8.x (Docker) |
| Crypto | PHP `openssl_*` built-in | RSA-2048 + SHA-256 |
| Testing | Pest PHP | ^4.4 |
| Containerization | Docker + compose.yaml | — |

---

## Roles & Permissions

| ID | Name | `nivel_acceso` | Permissions | Notes |
|----|------|----------------|-------------|-------|
| 1 | Administrador | 1 | CRUD completo | Gate: `puede-eliminar` |
| 2 | Coordinador | 2 | CRU | Gets RSA-2048 cert on approval |
| 3 | Operativo | 3 | CR | Can work cases |
| 4 | Usuario | 4 | C | Register migrant data only |
| 5 | Migrante | 5 | R (own) | Gets auto-generated password on approval |
| 6 | Voluntario | 4 | C | New role (migration `2026_05_12_000001`) — needs `php artisan migrate` |

**Gates** (`app/Providers/AppServiceProvider.php`):
```php
Gate::define('puede-eliminar', fn($user) => $user->role_id === 1);
Gate::define('puede-actualizar', fn($user) => $user->role_id <= 2);
Gate::define('puede-crear', fn($user) => $user->role?->nivel_acceso <= 4);
```

**Middleware** `CheckStatus`: blocks any user whose `status !== 'alta'` — logs them out immediately.

---

## Areas (seeded)

| ID | Name |
|----|------|
| 1 | Humanitario |
| 2 | Psicosocial |
| 3 | Legal |
| 4 | Comunicación |
| 5 | Administración |
| 6 | Tecnologías de Información |

---

## Database Schema (all tables)

### `roles` — user roles
```
id, name (string), nivel_acceso (int), timestamps
```

### `areas` — organizational areas
```
id, nombre (string, unique), timestamps
```

### `users` — all system users (internal + migrants)
```
id, name, email (unique), password
area_id (FK→areas, nullable), role_id (FK→roles)
status (enum: pendiente|alta|baja|revocacion, default: pendiente)
approved_by (unsignedBigInt, nullable)
remember_token, timestamps
```

### `migrante_perfiles` — migrant profiles
```
id, user_id (FK→users nullable, nullOnDelete)
fecha_atencion (date), nombre, primer_apellido, segundo_apellido (nullable)
telefono (nullable), genero, pais_origen, departamento_estado (nullable)
estado_civil, fecha_nacimiento (date), rango_edad, grupo_poblacion
motivo_salida (nullable), num_acompanantes (int, default 0)
integrantes_grupo (text, nullable), documentacion (nullable)
necesidades_especiales (text, nullable), destino_final (nullable)
status (enum: pendiente|activo|cerrado, default: pendiente)
registrado_por (unsignedBigInt, nullable), timestamps
```

### `certificados` — PKI digital certificates
```
id, user_id (FK→users nullable, nullOnDelete)
emitido_por (unsignedBigInt, nullable)
public_key (text), fingerprint (string 64, unique)
algoritmo (string 20, default: RSA-2048)
emitido_at, vence_at, revocado_at (nullable)
status (enum: activo|revocado|vencido, default: activo), timestamps
```

### `expedientes` — case files
```
id, folio (string 20, unique, nullable)
migrante_perfil_id (FK→migrante_perfiles nullable, nullOnDelete)
colaborador_id (FK→users nullable, nullOnDelete)
area_id (FK→areas)
status (enum: sin_asignar|en_proceso|terminado, default: sin_asignar)
notas (text, nullable)
resuelto_por (FK→users nullable, nullOnDelete)
resuelto_at (timestamp, nullable), timestamps
```

### `documentos` — case documents
```
id, expediente_id (FK→expedientes cascadeOnDelete)
subido_por (FK→users nullable, nullOnDelete)
nombre, tipo (string 50), ruta_storage, hash_sha256 (string 64), timestamps
```

### `firmas` — cryptographic document signatures
```
id, documento_id (FK→documentos cascadeOnDelete)
firmado_por (FK→users nullable, nullOnDelete)
certificado_id (FK→certificados nullable, nullOnDelete)
firma_b64 (text), firmado_at (timestamp), timestamps
```

### `solicitudes` — migrant service requests
```
id, migrante_perfil_id (FK cascadeOnDelete)
user_id (FK→users nullable, nullOnDelete)
area_id (FK→areas), expediente_id (FK nullable, nullOnDelete)
tipo (string 50), descripcion (text)
status (enum: pendiente|en_proceso|completada|rechazada, default: pendiente)
atendida_por (FK→users nullable, nullOnDelete), timestamps
```

### `postulaciones` — staff volunteering for cases
```
id, solicitud_id (FK cascadeOnDelete), user_id (FK cascadeOnDelete)
nota (text, nullable), timestamps
UNIQUE(solicitud_id, user_id)
```

### `area_solicitudes` — area membership requests
```
id, user_id (FK cascadeOnDelete), area_id (FK cascadeOnDelete)
nota (text, nullable)
status (enum: pendiente|aprobada|rechazada, default: pendiente)
revisado_por (FK→users nullable, nullOnDelete)
revisado_at (timestamp, nullable), timestamps
```

### `actividad_log` — immutable audit trail
```
id, actor_id (unsignedBigInt, NO FK — snapshot)
actor_nombre (string — snapshot at event time)
accion (string 80), modelo_tipo (string 60, nullable), modelo_id (unsignedBigInt, nullable)
payload (json, nullable), ip (string 45, nullable), timestamps
```

### `documento_acciones_log` — doc edit/delete audit
```
id, documento_id (FK→documentos nullable, nullOnDelete)
expediente_id (FK cascadeOnDelete), user_id (FK cascadeOnDelete)
accion (enum: editado|eliminado), detalle (json, nullable), timestamps
```

---

## Key Routes

### Auth (`routes/auth.php`)
```
GET  /tipo-acceso              → selection screen
GET  /register                 → staff registration
POST /register
GET  /login                    → staff login
POST /login
GET  /acceso                   → migrant login (password-based)
POST /acceso                   throttle:5,1
GET  /registro/migrante        → migrant registration (public)
POST /registro/migrante
GET  /espera-aprobacion        → pending approval page
```

### Admin — User Management (`routes/web.php`)
```
GET    /admin/usuarios                       → index (all users)
GET    /admin/colaboradores                  → colaboradores view (roles 2,3,4)
GET    /admin/migrantes                      → migrantes view (role 5)
GET    /admin/voluntarios                    → voluntarios view (role 6)
GET    /admin/usuarios/{user}                → user detail + credentials form
GET    /admin/aprobaciones                   → pending approvals
PATCH  /admin/usuarios/{user}/update         → update role_id + area_id
PATCH  /admin/usuarios/{user}/credentials    → update email and/or password (NEW)
POST   /usuarios/{user}/approve
POST   /usuarios/{user}/reject
POST   /usuarios/{user}/revoke
POST   /usuarios/{user}/restore
DELETE /usuarios/{user}
GET    /admin/aprobacion-exitosa             → one-time private key display
```

### Area & Case Management
```
GET  /areas                                  → area list
GET  /mi-area                                → request area membership
GET  /admin/sin-area                         → users without area
POST /area-solicitudes/{s}/aprobar
POST /area-solicitudes/{s}/rechazar
GET  /areas/{area}/bandeja                   → area case inbox
POST /solicitudes/{s}/postularse
POST /solicitudes/{s}/aprobar                → creates Expediente
GET  /mis-casos
GET  /casos/{expediente}
POST /casos/{expediente}/nota
POST /casos/{expediente}/documento
POST /casos/{expediente}/resolver
```

### Migrant Portal (`/mi-espacio`)
```
GET  /mi-espacio/                            → migrant dashboard
GET  /mi-espacio/solicitudes
GET  /mi-espacio/solicitudes/nueva
POST /mi-espacio/solicitudes
```

---

## Key Files

```
app/
  Http/
    Controllers/
      UserController.php          ← identity lifecycle + credentials update
      CasoController.php          ← cases, documents, PEM verification
      CertificadoController.php   ← certificate listing
      AreaMembresiaController.php ← area assignment workflow
      MigranteSolicitudController.php
      DashboardController.php
      Auth/
        MigranteAuthController.php      ← password-based migrant login
        MigranteRegistrationController.php
        RegisteredUserController.php    ← staff registration
    Middleware/
      CheckStatus.php             ← blocks non-'alta' users on every request
  Models/
    User.php, Role.php, Area.php
    Certificado.php, Expediente.php, Documento.php, Firma.php
    Solicitud.php, Postulacion.php, AreaSolicitud.php
    ActividadLog.php, DocumentoAccionLog.php, MigrantePerfil.php
  Providers/
    AppServiceProvider.php        ← Gate definitions

resources/views/
  layouts/
    navigation.blade.php          ← main nav (3 group links for admin)
    app.blade.php
  admin/
    users/
      index.blade.php             ← all-users listing (legacy)
      group.blade.php             ← shared view for colaboradores/migrantes/voluntarios
      show.blade.php              ← user detail + credentials form (NEW)
      approvals.blade.php
    areas/show.blade.php
    aprobacion-exitosa.blade.php  ← one-time private key display
  staff/
    bandeja.blade.php             ← area case inbox
    caso/show.blade.php
    mis-casos.blade.php
  migrante/
    dashboard.blade.php
  auth/
    tipo-acceso.blade.php, tipo-registro.blade.php
    espera-aprobacion.blade.php

database/
  migrations/                     ← 18 migration files (see schema above)
  seeders/
    RoleSeeder.php                ← 5 base roles
    AreaSeeder.php                ← 6 areas
    AdminUserSeeder.php           ← 2 admin accounts (prod + contingency)
```

---

## Admin Accounts (seeded)

| Purpose | Email | Initial Password |
|---------|-------|-----------------|
| Production | correo@casamonarca.com | casamonarca |
| Contingency | contingencia@casamonarca.com | casamonarca-contingencia |

> ⚠️ Change both before deploying to production.

---

## Cryptographic Flow

1. Admin approves a **Coordinador** → `UserController::approve()` runs:
   ```php
   $resource  = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
   $publicKey = openssl_pkey_get_details($resource)['key'];
   openssl_pkey_export($resource, $privateKeyPem);
   $fingerprint = hash('sha256', $publicKey);
   // Stores: public_key + fingerprint in `certificados`
   // Puts privateKeyPem in session flash → shown ONCE → never stored
   ```

2. Coordinator uses their private key to authorize sensitive document operations:
   ```php
   // CasoController::verificarPem()
   $privKey     = openssl_pkey_get_private($pemInput);
   $fingerprint = hash('sha256', openssl_pkey_get_details($privKey)['key']);
   if ($fingerprint !== $cert->fingerprint) abort(403);
   ```

3. Documents get SHA-256 integrity hash on upload:
   ```php
   $hash = hash_file('sha256', $file->getRealPath());
   ```

---

## Changes Made in Previous Session

1. **New role "Voluntario"** — migration `2026_05_12_000001_add_voluntario_role.php` (run `php artisan migrate`)
2. **Gate `puede-crear`** updated to use `nivel_acceso` instead of `role_id` → Voluntarios included automatically
3. **3 new admin views** — `/admin/colaboradores`, `/admin/migrantes`, `/admin/voluntarios` (shared `group.blade.php`)
4. **New routes** — `GET /admin/colaboradores|migrantes|voluntarios` + `PATCH /admin/usuarios/{user}/credentials`
5. **New controller methods** — `colaboradores()`, `migrantes()`, `voluntarios()`, `updateCredentials()`
6. **`show.blade.php` updated** — admin can now change any user's email and/or password from the detail view
7. **Navigation updated** — "Usuarios" replaced by 3 separate links; Voluntario role included in "Sin área" and "Mis casos" counters

---

## Pending / Known Issues

- Run `php artisan migrate` to activate the Voluntario role
- The `Role` model has a bug: `$fillable = ['nombre', ...]` but the column is actually `name` — use `DB::table()` directly or fix the model
- Document signing UI (`firmas` table) is modeled but frontend not built
- No MFA implemented
- Email driver is in `log` mode (no real emails sent)
- `php artisan db:seed` passwords are in plaintext in `AdminUserSeeder.php` — change before production

---

## Setup Commands

```bash
# First time
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
npm install && npm run build

# Development
composer run dev   # starts php artisan serve + vite + queue + pail concurrently

# Tests
php artisan test
```

Docker alternative:
```bash
docker compose up -d
docker compose exec app php artisan migrate --seed
```
