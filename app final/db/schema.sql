-- ══════════════════════════════════════════════════════════════
-- Casa Monarca · Schema SQLite
-- Todas las tablas que la bitácora del 10 y 14 de abril pidieron:
--   · usuarios (login + hash de contraseña + flag de primer acceso)
--   · certificados (emitidos, con estado separado del rol)
--   · certificados_revocados (CRL local)
--   · log_certificados (auditoría: intentos exitosos y fallidos)
-- Para migrar a MySQL usar schema_mysql.sql
-- ══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS usuarios (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario            TEXT NOT NULL UNIQUE,
    nombre             TEXT NOT NULL,
    rol                TEXT NOT NULL CHECK (rol IN ('admin','coord','op')),
    password_hash      TEXT NOT NULL,
    debe_cambiar_pwd   INTEGER NOT NULL DEFAULT 1,   -- 1 = forzar cambio al primer login
    correo             TEXT,
    telefono           TEXT,
    curp               TEXT,
    fecha_nacimiento   TEXT,
    genero             TEXT,
    area               TEXT,
    observaciones      TEXT,
    serial_cert        TEXT,                         -- FK lógica al último cert emitido
    activo             INTEGER NOT NULL DEFAULT 1,
    intentos_fallidos  INTEGER NOT NULL DEFAULT 0,
    bloqueado          INTEGER NOT NULL DEFAULT 0,
    creado             TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS certificados (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    serial             TEXT NOT NULL UNIQUE,
    usuario            TEXT NOT NULL,                -- nombre humano (compat. con seed original)
    nombre             TEXT,                         -- opcional, alias redundante para otros usos
    rol                TEXT NOT NULL CHECK (rol IN ('admin','coord','op')),
    fecha_emision      TEXT NOT NULL,
    fecha_expiracion   TEXT,                         -- opcional: los seeds viejos no la traían
    -- Estado del certificado EN CRIPTO (independiente del rol del usuario).
    -- La bitácora del 14 de abril lo exige explícitamente.
    estado             TEXT NOT NULL DEFAULT 'vigente'
                       CHECK (estado IN ('vigente','revocado','expirado')),
    emitido_por        TEXT                         -- usuario admin que lo emitió
);

CREATE INDEX IF NOT EXISTS idx_cert_usuario ON certificados(usuario);
CREATE INDEX IF NOT EXISTS idx_cert_estado  ON certificados(estado);

CREATE TABLE IF NOT EXISTS certificados_revocados (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    serial        TEXT NOT NULL UNIQUE,
    usuario       TEXT,
    rol           TEXT,
    revocado_por  TEXT,
    motivo        TEXT,
    fecha         TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS log_certificados (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    serial     TEXT,
    usuario    TEXT,
    rol        TEXT,
    ip_origen  TEXT,
    resultado  TEXT NOT NULL
               CHECK (resultado IN
                 ('login_exitoso','login_fallido','pwd_incorrecta',
                  'cert_revocado','cert_expirado','cert_emitido',
                  'cert_revocado_admin','rol_modificado','pwd_cambiada',
                  'acceso_denegado')),
    detalle    TEXT,
    fecha      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_log_fecha   ON log_certificados(fecha);
CREATE INDEX IF NOT EXISTS idx_log_usuario ON log_certificados(usuario);
