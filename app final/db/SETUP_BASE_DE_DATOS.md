# Configuración de la Base de Datos — Casa Monarca

Este documento explica cómo recrear la base de datos MySQL del proyecto desde cero.

---

## Requisitos previos

| Herramienta | Versión mínima |
|---|---|
| MySQL / MariaDB | 8.0 / 10.5 |
| Python | 3.9+ |
| pip packages | `pymysql`, `flask`, `flask-session`, `cryptography`, `werkzeug` |

Instala las dependencias de Python:

```bash
pip install flask flask-session pymysql cryptography werkzeug
```

---

## Variables de entorno

La aplicación lee la configuración de la base de datos desde variables de entorno. Defínelas antes de correr el servidor:

```powershell
# PowerShell
$env:DB_HOST     = "localhost"
$env:DB_PORT     = "3306"
$env:DB_USER     = "root"
$env:DB_PASSWORD = "tu_contraseña"
$env:DB_NAME     = "casa_monarca"
```

```bash
# Bash / Linux / Mac
export DB_HOST=localhost
export DB_PORT=3306
export DB_USER=root
export DB_PASSWORD=tu_contraseña
export DB_NAME=casa_monarca
```

---

## Opción A — Dejar que la app cree la base automáticamente (recomendado)

Al iniciar el servidor por primera vez, `app.py` ejecuta `inicializar_db()` que:

1. Crea todas las tablas a partir de `db/schema_mysql.sql`.
2. Aplica todas las migraciones necesarias.
3. Siembra los usuarios demo iniciales.

Solo necesitas crear la base de datos vacía en MySQL:

```sql
CREATE DATABASE IF NOT EXISTS casa_monarca
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
```

Luego corre el servidor:

```powershell
# PowerShell
$env:DB_USER="root"; $env:DB_PASSWORD="tu_contraseña"; python "app.py"
```

---

## Opción B — Crear la base manualmente con SQL

Si prefieres crear el esquema completo (con todas las migraciones ya aplicadas) sin correr la app, ejecuta el siguiente script en orden en tu cliente MySQL.

### 1. Crear la base de datos

```sql
CREATE DATABASE IF NOT EXISTS casa_monarca
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE casa_monarca;
```

### 2. Tabla `usuarios`

```sql
CREATE TABLE IF NOT EXISTS usuarios (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    usuario          VARCHAR(64)  NOT NULL UNIQUE,
    nombre           VARCHAR(120) NOT NULL,
    rol              ENUM('admin','coord','op','voluntario') NOT NULL,
    password_hash    VARCHAR(255) NOT NULL,
    debe_cambiar_pwd TINYINT(1)   NOT NULL DEFAULT 1,
    correo           VARCHAR(120),
    telefono         VARCHAR(32),
    curp             VARCHAR(32),
    fecha_nacimiento VARCHAR(20),
    genero           VARCHAR(30),
    area             VARCHAR(80),
    observaciones    TEXT,
    serial_cert      VARCHAR(80),
    activo           TINYINT(1)   NOT NULL DEFAULT 1,
    ec_private_key   TEXT,
    ec_public_key    TEXT,
    creado           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
```

### 3. Tabla `certificados`

```sql
CREATE TABLE IF NOT EXISTS certificados (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    serial           VARCHAR(80)  NOT NULL UNIQUE,
    usuario          VARCHAR(120) NOT NULL,
    nombre           VARCHAR(120),
    rol              ENUM('admin','coord','op') NOT NULL,
    fecha_emision    DATETIME     NOT NULL,
    fecha_expiracion DATETIME,
    estado           ENUM('vigente','revocado','expirado') NOT NULL DEFAULT 'vigente',
    emitido_por      VARCHAR(64),
    INDEX idx_cert_usuario (usuario),
    INDEX idx_cert_estado  (estado)
) ENGINE=InnoDB;
```

### 4. Tabla `certificados_revocados`

```sql
CREATE TABLE IF NOT EXISTS certificados_revocados (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    serial       VARCHAR(80) NOT NULL UNIQUE,
    usuario      VARCHAR(64),
    rol          VARCHAR(20),
    revocado_por VARCHAR(64),
    motivo       TEXT,
    fecha        DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
```

### 5. Tabla `log_certificados` (bitácora de auditoría)

```sql
CREATE TABLE IF NOT EXISTS log_certificados (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    serial    VARCHAR(80),
    usuario   VARCHAR(64),
    rol       VARCHAR(20),
    ip_origen VARCHAR(45),
    resultado ENUM(
        'login_exitoso','login_fallido','pwd_incorrecta',
        'cert_revocado','cert_expirado','cert_emitido',
        'cert_revocado_admin','rol_modificado','pwd_cambiada',
        'acceso_denegado','migrante_registrado','migrante_actualizado',
        'migrante_solicitud_eliminacion','migrante_eliminado',
        'pwd_reset_solicitado','pwd_reset_aprobado','pwd_reset_rechazado',
        'arco_acceso','arco_rect_solicitada','arco_rect_aprobada',
        'arco_rect_rechazada','arco_cancel_solicitada',
        'arco_cancel_op_solicitada','arco_cancel_coord_firmada',
        'arco_cancel_coord_rechazada'
    ) NOT NULL,
    detalle   TEXT,
    fecha     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_log_fecha   (fecha),
    INDEX idx_log_usuario (usuario)
) ENGINE=InnoDB;
```

### 6. Tabla `migrantes`

```sql
CREATE TABLE IF NOT EXISTS migrantes (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    folio               VARCHAR(20)  NOT NULL UNIQUE,
    nombre              VARCHAR(120) NOT NULL,
    fecha_nacimiento    DATE,
    pais_origen         VARCHAR(80),
    fecha_atencion      DATE,
    genero              VARCHAR(30),
    departamento_estado VARCHAR(100),
    estado_civil        VARCHAR(30),
    grupo_poblacion     VARCHAR(80),
    telefono_contacto   VARCHAR(32),
    registrado_por      VARCHAR(64),
    creado              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_mig_nombre (nombre),
    INDEX idx_mig_folio  (folio)
) ENGINE=InnoDB;
```

### 7. Tabla `solicitudes_pwd`

```sql
CREATE TABLE IF NOT EXISTS solicitudes_pwd (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    usuario       VARCHAR(120) NOT NULL,
    estado        ENUM('pendiente','aprobada','rechazada') DEFAULT 'pendiente',
    solicitado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resuelto_por  VARCHAR(64),
    resuelto_en   DATETIME,
    INDEX idx_spwd_usuario (usuario),
    INDEX idx_spwd_estado  (estado)
) ENGINE=InnoDB;
```

### 8. Tabla `solicitudes_eliminacion`

```sql
CREATE TABLE IF NOT EXISTS solicitudes_eliminacion (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    migrante_id      INT NOT NULL,
    solicitado_por   VARCHAR(64)  NOT NULL,
    motivo           TEXT,
    estado           ENUM('pendiente','aprobada','rechazada') DEFAULT 'pendiente',
    resuelto_por     VARCHAR(64),
    fecha_solicitud  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_resolucion DATETIME,
    solicitante_op   VARCHAR(120),
    firma_coord      TEXT,
    coord_pubkey     TEXT,
    mensaje_firmado  TEXT,
    FOREIGN KEY (migrante_id) REFERENCES migrantes(id) ON DELETE CASCADE,
    INDEX idx_sol_estado (estado)
) ENGINE=InnoDB;
```

### 9. Tabla `solicitudes_arco_rect`

```sql
CREATE TABLE IF NOT EXISTS solicitudes_arco_rect (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    migrante_id     INT NOT NULL,
    solicitado_por  VARCHAR(64)  NOT NULL,
    cambios_json    TEXT NOT NULL,
    estado          ENUM('pendiente','aprobada','rechazada') DEFAULT 'pendiente',
    motivo_rechazo  TEXT,
    fecha_solicitud DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resuelto_por    VARCHAR(64),
    resuelto_en     DATETIME,
    FOREIGN KEY (migrante_id) REFERENCES migrantes(id) ON DELETE CASCADE,
    INDEX idx_rect_estado (estado)
) ENGINE=InnoDB;
```

### 10. Tabla `solicitudes_cancelacion_op`

```sql
CREATE TABLE IF NOT EXISTS solicitudes_cancelacion_op (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    migrante_id     INT NOT NULL,
    solicitado_por  VARCHAR(120) NOT NULL,
    motivo          TEXT,
    estado          ENUM('pendiente','aprobada','rechazada') DEFAULT 'pendiente',
    resuelto_por    VARCHAR(120),
    resuelto_en     DATETIME,
    fecha_solicitud DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (migrante_id) REFERENCES migrantes(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

---

## Usuarios demo (datos iniciales)

La aplicación siembra estos usuarios automáticamente si la tabla `usuarios` está vacía al arrancar. Si creaste la base manualmente y quieres los mismos datos, insértalos con contraseñas hasheadas por Werkzeug (la app lo hace sola en la Opción A).

| Email | Nombre | Rol | Contraseña | Activo |
|---|---|---|---|---|
| admin@casamonarca.org | Administrador Principal | admin | admin123 | Sí |
| respaldo@casamonarca.org | Admin Respaldo | admin | Respaldo@25 | No |
| coord@casamonarca.org | Coordinadora Demo | coord | coord123 | Sí |

---

## Resumen de tablas

| Tabla | Descripción |
|---|---|
| `usuarios` | Cuentas del sistema (admin, coord, op, voluntario) |
| `certificados` | Certificados X.509 emitidos |
| `certificados_revocados` | Lista de revocación (CRL local) |
| `log_certificados` | Bitácora de auditoría de todos los eventos |
| `migrantes` | Registros de personas atendidas |
| `solicitudes_pwd` | Solicitudes de restablecimiento de contraseña |
| `solicitudes_eliminacion` | Solicitudes de eliminación de registros de migrantes |
| `solicitudes_arco_rect` | Solicitudes de rectificación (Derechos ARCO) |
| `solicitudes_cancelacion_op` | Solicitudes de cancelación iniciadas por operativos |
