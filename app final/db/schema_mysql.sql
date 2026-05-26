-- ══════════════════════════════════════════════════════════════
-- Casa Monarca · Schema MySQL (para migración al servidor HostGator)
-- Equivalente a schema.sql (SQLite) pero adaptado a MySQL/MariaDB.
-- La bitácora del 10 de abril lo pide:
--   "preparar desde ahora un conjunto de instrucciones o scripts
--    que permitan replicar el entorno de manera clara y ordenada"
-- ══════════════════════════════════════════════════════════════

CREATE DATABASE IF NOT EXISTS casa_monarca
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE casa_monarca;

CREATE TABLE IF NOT EXISTS usuarios (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    usuario           VARCHAR(64)  NOT NULL UNIQUE,
    nombre            VARCHAR(120) NOT NULL,
    rol               ENUM('admin','coord','op','voluntario') NOT NULL,
    password_hash     VARCHAR(255) NOT NULL,
    debe_cambiar_pwd  TINYINT(1)   NOT NULL DEFAULT 1,
    correo            VARCHAR(120),
    telefono          VARCHAR(32),
    curp              VARCHAR(32),
    fecha_nacimiento  VARCHAR(20),
    genero            VARCHAR(30),
    area              VARCHAR(80),
    observaciones     TEXT,
    serial_cert       VARCHAR(80),
    activo            TINYINT(1)   NOT NULL DEFAULT 1,
    creado            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS certificados (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    serial            VARCHAR(80)  NOT NULL UNIQUE,
    usuario           VARCHAR(120) NOT NULL,
    nombre            VARCHAR(120),
    rol               ENUM('admin','coord','op') NOT NULL,
    fecha_emision     DATETIME     NOT NULL,
    fecha_expiracion  DATETIME,
    estado            ENUM('vigente','revocado','expirado') NOT NULL DEFAULT 'vigente',
    emitido_por       VARCHAR(64),
    INDEX idx_cert_usuario (usuario),
    INDEX idx_cert_estado  (estado)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS certificados_revocados (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    serial        VARCHAR(80)  NOT NULL UNIQUE,
    usuario       VARCHAR(64),
    rol           VARCHAR(20),
    revocado_por  VARCHAR(64),
    motivo        TEXT,
    fecha         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS log_certificados (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    serial     VARCHAR(80),
    usuario    VARCHAR(64),
    rol        VARCHAR(20),
    ip_origen  VARCHAR(45),
    resultado  ENUM('login_exitoso','login_fallido','pwd_incorrecta',
                    'cert_revocado','cert_expirado','cert_emitido',
                    'cert_revocado_admin','rol_modificado','pwd_cambiada',
                    'acceso_denegado','migrante_registrado','migrante_actualizado',
                    'migrante_solicitud_eliminacion','migrante_eliminado') NOT NULL,
    detalle    TEXT,
    fecha      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_log_fecha   (fecha),
    INDEX idx_log_usuario (usuario)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS migrantes (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    folio                VARCHAR(20) NOT NULL UNIQUE,
    nombre               VARCHAR(120) NOT NULL,
    fecha_nacimiento     DATE,
    tipo_documento       VARCHAR(50),
    num_documento        VARCHAR(80),
    nacionalidad         VARCHAR(80),
    pais_origen          VARCHAR(80),
    estado_migratorio    ENUM('en_transito','solicitante_asilo','deportado','repatriado','otro') DEFAULT 'en_transito',
    fecha_ingreso        DATE NOT NULL,
    fecha_egreso         DATE,
    tiene_pasaporte      TINYINT(1) DEFAULT 0,
    tiene_visa           TINYINT(1) DEFAULT 0,
    tiene_identificacion TINYINT(1) DEFAULT 0,
    estado_salud         ENUM('bueno','regular','requiere_atencion') DEFAULT 'bueno',
    obs_medicas          TEXT,
    contacto_emergencia  VARCHAR(120),
    telefono_emergencia  VARCHAR(32),
    observaciones        TEXT,
    registrado_por       VARCHAR(64),
    creado               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_mig_nombre (nombre),
    INDEX idx_mig_folio  (folio)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dependientes_migrante (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    migrante_id   INT NOT NULL,
    nombre        VARCHAR(120) NOT NULL,
    num_documento VARCHAR(80),
    FOREIGN KEY (migrante_id) REFERENCES migrantes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS solicitudes_pwd (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    usuario       VARCHAR(120) NOT NULL,
    estado        ENUM('pendiente','aprobada','rechazada') DEFAULT 'pendiente',
    solicitado_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resuelto_por  VARCHAR(64),
    resuelto_en   DATETIME,
    INDEX idx_spwd_usuario (usuario),
    INDEX idx_spwd_estado  (estado)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS solicitudes_eliminacion (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    migrante_id      INT NOT NULL,
    solicitado_por   VARCHAR(64) NOT NULL,
    motivo           TEXT,
    estado           ENUM('pendiente','aprobada','rechazada') DEFAULT 'pendiente',
    resuelto_por     VARCHAR(64),
    fecha_solicitud  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_resolucion DATETIME,
    FOREIGN KEY (migrante_id) REFERENCES migrantes(id) ON DELETE CASCADE,
    INDEX idx_sol_estado (estado)
) ENGINE=InnoDB;
