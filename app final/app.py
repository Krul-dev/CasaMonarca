# correr con 
#$env:DB_USER="root"; $env:DB_PASSWORD="nueva123"; python app.py

import io
import os
import base64
import re
import secrets
import string
import pymysql
import pymysql.cursors
import glob
import zipfile
from functools import wraps
from datetime import datetime, timedelta

from cryptography import x509
from cryptography.x509.oid import NameOID
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa, ec
from cryptography.hazmat.primitives.serialization import pkcs12

from werkzeug.security import generate_password_hash, check_password_hash

from flask import (
    Flask, render_template, request, redirect,
    url_for, session, g, abort, send_file, jsonify
)
from flask_session import Session

# ── CONFIGURACIÓN ─────────────────────────────────────────────
app = Flask(__name__)
app.secret_key = 'demo-secret-key-cambiar-en-produccion'

SESSION_DIR  = os.path.join(os.getcwd(), 'flask_sessions_demo')
CA_DIR       = os.path.join(os.getcwd(), 'ca')
DB_DIR       = os.path.join(os.getcwd(), 'db')
SCHEMA_PATH  = os.path.join(DB_DIR, 'schema_mysql.sql')
DB_HOST      = os.environ.get('DB_HOST', 'localhost')
DB_PORT      = int(os.environ.get('DB_PORT', '3306'))
DB_USER      = os.environ.get('DB_USER', 'root')
DB_PASSWORD  = os.environ.get('DB_PASSWORD', '')
DB_NAME      = os.environ.get('DB_NAME', 'casa_monarca')
CA_CERT_PATH = os.path.join(CA_DIR, 'ca_cert.pem')
CA_KEY_PATH  = os.path.join(CA_DIR, 'ca_key.pem')
CA_PASSWORD  = os.environ.get('CA_PASSWORD', 'demo-ca-pwd-cambiar').encode('utf-8')

for d in (SESSION_DIR, CA_DIR, DB_DIR):
    os.makedirs(d, exist_ok=True)

app.config['SESSION_TYPE'] = 'filesystem'
app.config['SESSION_FILE_DIR'] = SESSION_DIR
app.config['SESSION_PERMANENT'] = False
app.config['SESSION_USE_SIGNER'] = True
app.config['TEMPLATES_AUTO_RELOAD'] = True
Session(app)

# ── SEEDS INICIALES (se vuelcan a la DB la primera vez) ───────
USUARIOS_DEMO = {
    'admin@casamonarca.org':    {'nombre': 'Administrador Principal', 'rol': 'admin',  'password': 'admin123',    'activo': 1},
    'respaldo@casamonarca.org': {'nombre': 'Admin Respaldo',          'rol': 'admin',  'password': 'Respaldo@25', 'activo': 0},
    'coord@casamonarca.org':    {'nombre': 'Coordinadora Demo',       'rol': 'coord',  'password': 'coord123',    'activo': 1},
}

CERTIFICADOS_SEED = [
    {
        'serial': '4A2F88C1E3',
        'usuario': 'Ana García López',
        'rol': 'op',
        'fecha_emision': '2025-04-01',
        'activo': True,
    },
    {
        'serial': '3E1D77B2F9',
        'usuario': 'Luis Martínez',
        'rol': 'coord',
        'fecha_emision': '2025-03-22',
        'activo': False,
    },
    {
        'serial': '7C4A19D0B5',
        'usuario': 'Sofía Ramírez',
        'rol': 'op',
        'fecha_emision': '2025-03-10',
        'activo': True,
    },
]

# ── AUTORIDAD CERTIFICADORA (persistente en disco) ────────────
# Se genera UNA sola vez la primera vez que arranca el servidor.
# A partir de ahí, todos los certificados de voluntarios quedan
# firmados por esta misma CA (antes eran autofirmados).
def inicializar_ca():
    if os.path.exists(CA_CERT_PATH) and os.path.exists(CA_KEY_PATH):
        return
    ca_key = rsa.generate_private_key(public_exponent=65537, key_size=4096)
    ca_name = x509.Name([
        x509.NameAttribute(NameOID.COUNTRY_NAME, 'MX'),
        x509.NameAttribute(NameOID.ORGANIZATION_NAME, 'Casa Monarca'),
        x509.NameAttribute(NameOID.COMMON_NAME, 'CA Casa Monarca'),
    ])
    ca_cert = (
        x509.CertificateBuilder()
        .subject_name(ca_name)
        .issuer_name(ca_name)
        .public_key(ca_key.public_key())
        .serial_number(x509.random_serial_number())
        .not_valid_before(datetime.utcnow() - timedelta(days=1))
        .not_valid_after(datetime.utcnow() + timedelta(days=3650))
        .add_extension(x509.BasicConstraints(ca=True, path_length=None), critical=True)
        .sign(ca_key, hashes.SHA256())
    )
    with open(CA_CERT_PATH, 'wb') as f:
        f.write(ca_cert.public_bytes(serialization.Encoding.PEM))
    with open(CA_KEY_PATH, 'wb') as f:
        f.write(ca_key.private_bytes(
            encoding=serialization.Encoding.PEM,
            format=serialization.PrivateFormat.PKCS8,
            encryption_algorithm=serialization.BestAvailableEncryption(CA_PASSWORD)
        ))

def cargar_ca():
    with open(CA_CERT_PATH, 'rb') as f:
        ca_cert = x509.load_pem_x509_certificate(f.read())
    with open(CA_KEY_PATH, 'rb') as f:
        ca_key = serialization.load_pem_private_key(f.read(), password=CA_PASSWORD)
    return ca_cert, ca_key

# ── CERTIFICADO DEL SERVIDOR (para TLS / mTLS) ────────────────
# Genera un cert de servidor firmado por la CA la primera vez.
# Este es el cert que el navegador ve del lado del servidor.
SERVER_CERT_PATH = os.path.join(CA_DIR, 'server.pem')
SERVER_KEY_PATH  = os.path.join(CA_DIR, 'server.key')

def inicializar_cert_servidor():
    if os.path.exists(SERVER_CERT_PATH) and os.path.exists(SERVER_KEY_PATH):
        return
    ca_cert, ca_key = cargar_ca()
    srv_key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    subject = x509.Name([
        x509.NameAttribute(NameOID.COUNTRY_NAME, 'MX'),
        x509.NameAttribute(NameOID.ORGANIZATION_NAME, 'Casa Monarca'),
        x509.NameAttribute(NameOID.COMMON_NAME, 'localhost'),
    ])
    srv_cert = (
        x509.CertificateBuilder()
        .subject_name(subject)
        .issuer_name(ca_cert.subject)
        .public_key(srv_key.public_key())
        .serial_number(x509.random_serial_number())
        .not_valid_before(datetime.utcnow() - timedelta(days=1))
        .not_valid_after(datetime.utcnow() + timedelta(days=3650))
        .add_extension(x509.SubjectAlternativeName([
            x509.DNSName('localhost'),
            x509.DNSName('127.0.0.1'),
        ]), critical=False)
        .add_extension(x509.BasicConstraints(ca=False, path_length=None), critical=True)
        .sign(ca_key, hashes.SHA256())
    )
    with open(SERVER_CERT_PATH, 'wb') as f:
        f.write(srv_cert.public_bytes(serialization.Encoding.PEM))
    with open(SERVER_KEY_PATH, 'wb') as f:
        f.write(srv_key.private_bytes(
            encoding=serialization.Encoding.PEM,
            format=serialization.PrivateFormat.PKCS8,
            encryption_algorithm=serialization.NoEncryption()
        ))

# ── BASE DE DATOS (MySQL) ─────────────────────────────────────
# ── FIRMAS ECDSA (P-256) ──────────────────────────────────────
def generar_claves_ec():
    privada = ec.generate_private_key(ec.SECP256R1())
    priv_pem = privada.private_bytes(
        serialization.Encoding.PEM,
        serialization.PrivateFormat.PKCS8,
        serialization.NoEncryption()
    ).decode()
    pub_pem = privada.public_key().public_bytes(
        serialization.Encoding.PEM,
        serialization.PublicFormat.SubjectPublicKeyInfo
    ).decode()
    return priv_pem, pub_pem

def _obtener_o_crear_claves_ec(db, usuario):
    row = _exec(db, 'SELECT ec_private_key, ec_public_key FROM usuarios WHERE usuario=%s',
                (usuario,)).fetchone()
    if row and row['ec_private_key']:
        return row['ec_private_key'], row['ec_public_key']
    priv_pem, pub_pem = generar_claves_ec()
    _exec(db, 'UPDATE usuarios SET ec_private_key=%s, ec_public_key=%s WHERE usuario=%s',
          (priv_pem, pub_pem, usuario))
    db.commit()
    return priv_pem, pub_pem

def firmar_mensaje(priv_pem: str, mensaje: str) -> str:
    privada = serialization.load_pem_private_key(priv_pem.encode(), password=None)
    sig = privada.sign(mensaje.encode('utf-8'), ec.ECDSA(hashes.SHA256()))
    return base64.b64encode(sig).decode()

def verificar_firma(pub_pem: str, mensaje: str, firma_b64: str) -> bool:
    try:
        publica = serialization.load_pem_public_key(pub_pem.encode())
        publica.verify(base64.b64decode(firma_b64),
                       mensaje.encode('utf-8'), ec.ECDSA(hashes.SHA256()))
        return True
    except Exception:
        return False

def obtener_db():
    db = getattr(g, '_db', None)
    if db is None:
        g._db = pymysql.connect(
            host=DB_HOST, port=DB_PORT,
            user=DB_USER, password=DB_PASSWORD,
            database=DB_NAME,
            cursorclass=pymysql.cursors.DictCursor,
            autocommit=False
        )
    return g._db

@app.teardown_appcontext
def cerrar_db(_exc):
    db = getattr(g, '_db', None)
    if db is not None:
        db.close()

def _exec(db, sql, params=None):
    cur = db.cursor()
    cur.execute(sql, params or ())
    return cur

def inicializar_db():
    """Crea tablas y siembra USUARIOS_DEMO + CERTIFICADOS_SEED la
    primera vez que arranca la app."""
    con = pymysql.connect(
        host=DB_HOST, port=DB_PORT,
        user=DB_USER, password=DB_PASSWORD,
        database=DB_NAME,
        cursorclass=pymysql.cursors.DictCursor,
        autocommit=False
    )
    with open(SCHEMA_PATH, 'r', encoding='utf-8') as f:
        schema = f.read()
    cur = con.cursor()
    for stmt in schema.split(';'):
        stmt = stmt.strip()
        if stmt:
            cur.execute(stmt)
    cur.close()

    # Migración: garantizar que usuarios.rol incluya 'voluntario'.
    try:
        cur = con.cursor()
        cur.execute(
            "ALTER TABLE usuarios MODIFY rol "
            "ENUM('admin','coord','op','voluntario') NOT NULL"
        )
        con.commit()
        cur.close()
    except Exception as e:
        print(f'[migración usuarios.rol ENUM] {e}')

    # Migración: log_certificados.resultado — incluir todos los valores actuales.
    try:
        cur = con.cursor()
        cur.execute(
            "ALTER TABLE log_certificados MODIFY resultado "
            "ENUM('login_exitoso','login_fallido','pwd_incorrecta',"
            "     'cert_revocado','cert_expirado','cert_emitido',"
            "     'cert_revocado_admin','rol_modificado','pwd_cambiada',"
            "     'acceso_denegado','migrante_registrado','migrante_actualizado',"
            "     'migrante_solicitud_eliminacion','migrante_eliminado',"
            "     'pwd_reset_solicitado','pwd_reset_aprobado','pwd_reset_rechazado') NOT NULL"
        )
        con.commit()
        cur.close()
    except Exception as e:
        print(f'[migración log resultado ENUM] {e}')

    # Migración: agregar campo activo a usuarios si no existe.
    try:
        cur = con.cursor()
        cur.execute(
            "ALTER TABLE usuarios ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1"
        )
        con.commit()
        cur.close()
    except Exception as e:
        print(f'[migración usuarios.activo] {e}')

    # Migración: crear tabla solicitudes_pwd si no existe (ya está en schema, esto es por seguridad).
    try:
        cur = con.cursor()
        cur.execute(
            "CREATE TABLE IF NOT EXISTS solicitudes_pwd ("
            "  id            INT AUTO_INCREMENT PRIMARY KEY,"
            "  usuario       VARCHAR(120) NOT NULL,"
            "  estado        ENUM('pendiente','aprobada','rechazada') DEFAULT 'pendiente',"
            "  solicitado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,"
            "  resuelto_por  VARCHAR(64),"
            "  resuelto_en   DATETIME,"
            "  INDEX idx_spwd_usuario (usuario),"
            "  INDEX idx_spwd_estado  (estado)"
            ") ENGINE=InnoDB"
        )
        con.commit()
        cur.close()
    except Exception as e:
        print(f'[migración solicitudes_pwd] {e}')

    # Migración: nuevos campos del cuestionario de migrantes.
    nuevas_cols_migrantes = [
        ("fecha_atencion",     "DATE"),
        ("genero",             "VARCHAR(30)"),
        ("departamento_estado","VARCHAR(100)"),
        ("estado_civil",       "VARCHAR(30)"),
        ("grupo_poblacion",    "VARCHAR(80)"),
        ("telefono_contacto",  "VARCHAR(32)"),
    ]
    for col, tipo in nuevas_cols_migrantes:
        try:
            cur = con.cursor()
            cur.execute(f"ALTER TABLE migrantes ADD COLUMN {col} {tipo}")
            con.commit()
            cur.close()
        except Exception as e:
            print(f'[migración migrantes.{col}] {e}')

    # Migración: eliminar columnas obsoletas de migrantes (reemplazadas por el nuevo formulario).
    cols_obsoletas_migrantes = [
        'tipo_documento', 'num_documento', 'nacionalidad', 'estado_migratorio',
        'fecha_ingreso', 'fecha_egreso', 'tiene_pasaporte', 'tiene_visa',
        'tiene_identificacion', 'estado_salud', 'obs_medicas',
        'contacto_emergencia', 'telefono_emergencia', 'observaciones',
    ]
    for col in cols_obsoletas_migrantes:
        try:
            cur = con.cursor()
            cur.execute(f"ALTER TABLE migrantes DROP COLUMN {col}")
            con.commit()
            cur.close()
        except Exception as e:
            pass  # columna ya eliminada o no existe

    # Migración: corregir tipos de columnas nuevas de migrantes (por si quedaron mal definidas).
    cols_tipos_migrantes = [
        ('genero',             'VARCHAR(30)'),
        ('departamento_estado','VARCHAR(100)'),
        ('estado_civil',       'VARCHAR(30)'),
        ('grupo_poblacion',    'VARCHAR(80)'),
        ('telefono_contacto',  'VARCHAR(32)'),
        ('fecha_atencion',     'DATE'),
    ]
    for col, tipo in cols_tipos_migrantes:
        try:
            cur = con.cursor()
            cur.execute(f"ALTER TABLE migrantes MODIFY COLUMN {col} {tipo}")
            con.commit()
            cur.close()
        except Exception as e:
            print(f'[migración tipo {col}] {e}')

    # Migración: eliminar tabla dependientes_migrante (ya no se usa).
    try:
        cur = con.cursor()
        cur.execute("DROP TABLE IF EXISTS dependientes_migrante")
        con.commit()
        cur.close()
    except Exception as e:
        pass

    # Migración: tabla solicitudes_arco_rect para Derechos ARCO.
    try:
        cur = con.cursor()
        cur.execute(
            "CREATE TABLE IF NOT EXISTS solicitudes_arco_rect ("
            "  id              INT AUTO_INCREMENT PRIMARY KEY,"
            "  migrante_id     INT NOT NULL,"
            "  solicitado_por  VARCHAR(64) NOT NULL,"
            "  cambios_json    TEXT NOT NULL,"
            "  estado          ENUM('pendiente','aprobada','rechazada') DEFAULT 'pendiente',"
            "  motivo_rechazo  TEXT,"
            "  fecha_solicitud DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,"
            "  resuelto_por    VARCHAR(64),"
            "  resuelto_en     DATETIME,"
            "  FOREIGN KEY (migrante_id) REFERENCES migrantes(id) ON DELETE CASCADE,"
            "  INDEX idx_rect_estado (estado)"
            ") ENGINE=InnoDB"
        )
        con.commit()
        cur.close()
    except Exception as e:
        print(f'[migración solicitudes_arco_rect] {e}')

    # Migración: ampliar ENUM de log para eventos ARCO.
    try:
        cur = con.cursor()
        cur.execute(
            "ALTER TABLE log_certificados MODIFY resultado "
            "ENUM('login_exitoso','login_fallido','pwd_incorrecta',"
            "     'cert_revocado','cert_expirado','cert_emitido',"
            "     'cert_revocado_admin','rol_modificado','pwd_cambiada',"
            "     'acceso_denegado','migrante_registrado','migrante_actualizado',"
            "     'migrante_solicitud_eliminacion','migrante_eliminado',"
            "     'pwd_reset_solicitado','pwd_reset_aprobado','pwd_reset_rechazado',"
            "     'arco_acceso','arco_rect_solicitada','arco_rect_aprobada',"
            "     'arco_rect_rechazada','arco_cancel_solicitada',"
            "     'arco_cancel_op_solicitada','arco_cancel_coord_firmada',"
            "     'arco_cancel_coord_rechazada') NOT NULL"
        )
        con.commit()
        cur.close()
    except Exception as e:
        print(f'[migración log ENUM ARCO] {e}')

    # Migración: claves EC para coordinadores.
    for col in ('ec_private_key', 'ec_public_key'):
        try:
            cur = con.cursor()
            cur.execute(f"ALTER TABLE usuarios ADD COLUMN {col} TEXT")
            con.commit()
            cur.close()
        except Exception:
            pass

    # Migración: tabla solicitudes_cancelacion_op (op → coord).
    try:
        cur = con.cursor()
        cur.execute(
            "CREATE TABLE IF NOT EXISTS solicitudes_cancelacion_op ("
            "  id              INT AUTO_INCREMENT PRIMARY KEY,"
            "  migrante_id     INT NOT NULL,"
            "  solicitado_por  VARCHAR(120) NOT NULL,"
            "  motivo          TEXT,"
            "  estado          ENUM('pendiente','aprobada','rechazada') DEFAULT 'pendiente',"
            "  resuelto_por    VARCHAR(120),"
            "  resuelto_en     DATETIME,"
            "  fecha_solicitud DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,"
            "  FOREIGN KEY (migrante_id) REFERENCES migrantes(id) ON DELETE CASCADE"
            ") ENGINE=InnoDB"
        )
        con.commit()
        cur.close()
    except Exception as e:
        print(f'[migración solicitudes_cancelacion_op] {e}')

    # Migración: campos EC en solicitudes_eliminacion.
    for col in ('solicitante_op VARCHAR(120)', 'firma_coord TEXT',
                'coord_pubkey TEXT', 'mensaje_firmado TEXT'):
        try:
            cur = con.cursor()
            cur.execute(f"ALTER TABLE solicitudes_eliminacion ADD COLUMN {col}")
            con.commit()
            cur.close()
        except Exception:
            pass

    # Generar claves EC para coordinadores existentes sin clave.
    try:
        cur = con.cursor()
        cur.execute(
            "SELECT usuario FROM usuarios WHERE rol='coord'"
            " AND (ec_private_key IS NULL OR ec_private_key='')"
        )
        coords_sin_clave = cur.fetchall()
        cur.close()
        for row in coords_sin_clave:
            priv_pem, pub_pem = generar_claves_ec()
            cur = con.cursor()
            cur.execute(
                "UPDATE usuarios SET ec_private_key=%s, ec_public_key=%s WHERE usuario=%s",
                (priv_pem, pub_pem, row['usuario'])
            )
            con.commit()
            cur.close()
    except Exception as e:
        print(f'[migración claves EC coord] {e}')

    # Sembrar solo si la tabla está vacía
    cur = con.cursor()
    cur.execute('SELECT COUNT(*) c FROM usuarios')
    if cur.fetchone()['c'] == 0:
        for login_, u in USUARIOS_DEMO.items():
            cur.execute(
                'INSERT INTO usuarios (usuario, nombre, rol, password_hash, debe_cambiar_pwd, activo)'
                ' VALUES (%s,%s,%s,%s,0,%s)',
                (login_, u['nombre'], u['rol'], generate_password_hash(u['password']), u.get('activo', 1))
            )
        for c in CERTIFICADOS_SEED:
            estado = 'vigente' if c['activo'] else 'revocado'
            cur.execute(
                'INSERT INTO certificados (serial, usuario, rol, fecha_emision, estado)'
                ' VALUES (%s,%s,%s,%s,%s)',
                (c['serial'], c['usuario'], c['rol'], c['fecha_emision'], estado)
            )
            if not c['activo']:
                cur.execute(
                    'INSERT IGNORE INTO certificados_revocados (serial, usuario, rol, motivo)'
                    ' VALUES (%s,%s,%s,%s)',
                    (c['serial'], c['usuario'], c['rol'], 'Seed inicial')
                )
        con.commit()
    cur.close()
    con.close()

# ── HELPERS ───────────────────────────────────────────────────
def generar_contrasena_temporal(longitud=12):
    chars = string.ascii_letters + string.digits
    return ''.join(secrets.choice(chars) for _ in range(longitud))

def sanitizar_nombre(nombre):
    return re.sub(r'[^a-zA-ZáéíóúÁÉÍÓÚñÑüÜ0-9 \-]', '', nombre).strip()

def validar_email(email):
    """Valida formato básico de email"""
    import re
    patron = r'^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$'
    return re.match(patron, email) is not None

def extraer_email_de_cert(cert):
    """Extrae el email del certificado X.509"""
    try:
        # Intenta obtener del campo SubjectAlternativeName
        san_ext = cert.extensions.get_extension_for_oid(
            x509.oid.ExtensionOID.SUBJECT_ALTERNATIVE_NAME
        )
        for name in san_ext.value:
            if isinstance(name, x509.RFC822Name):
                return name.value
    except x509.ExtensionNotFound:
        pass
    
    # Fallback: buscar en Subject
    try:
        email_attrs = cert.subject.get_attributes_for_oid(NameOID.EMAIL_ADDRESS)
        if email_attrs:
            return email_attrs[0].value
    except Exception:
        pass
    
    return None
    
def leer_password_admin():
    with open("admin_bootstrap.txt", "r") as f:
        for linea in f:
            if "Password:" in linea:
                return linea.split("Password:")[1].strip()
    return None


def leer_certificado_usb(password: str, email: str):
    """
    Detecta un certificado en USB simulada y valida que corresponda al email del usuario.
    Args:
        password: contraseña para desbloquear el .p12
        email: email del usuario que intenta entrar
    Returns:
        certificate (objeto x509) o None si no se encontró/validó
    """
    rutas = glob.glob("usb_simulada/*.p12")
    if not rutas:
        return None

    for ruta in rutas:
        try:
            with open(ruta, "rb") as f:
                data = f.read()
            private_key, certificate, _ = pkcs12.load_key_and_certificates(
                data, password.encode("utf-8")
            )
            if certificate:
                cert_email = extraer_email_de_cert(certificate)
                if cert_email and cert_email.lower() == email.lower():
                    return certificate
        except Exception as e:
            print(f"[USB] Error al leer {ruta}: {e}")
            continue

    return None
       
def generar_p12_real(email, nombre, rol, password):
    """
    Genera certificado P12 con email incluido.
    
    Args:
        email: Email del usuario (ej: juan.perez@casamonarca.org)
        nombre: Nombre completo (ej: Juan Pérez)
        rol: Rol del usuario (admin/coord/op)
        password: Contraseña para proteger el .p12
    
    Returns:
        (p12_bytes, serial_hex)
    """
    # Cargar la CA para firmar
    ca_cert, ca_key = cargar_ca()

    private_key = rsa.generate_private_key(
        public_exponent=65537,
        key_size=2048,
    )

    # Subject con email
    subject = x509.Name([
        x509.NameAttribute(NameOID.COUNTRY_NAME, "MX"),
        x509.NameAttribute(NameOID.ORGANIZATION_NAME, "Casa Monarca"),
        x509.NameAttribute(NameOID.ORGANIZATIONAL_UNIT_NAME, rol),
        x509.NameAttribute(NameOID.COMMON_NAME, nombre),
        x509.NameAttribute(NameOID.EMAIL_ADDRESS, email),  # ← NUEVO
    ])

    # Crear certificado
    cert = (
        x509.CertificateBuilder()
        .subject_name(subject)
        .issuer_name(ca_cert.subject)
        .public_key(private_key.public_key())
        .serial_number(x509.random_serial_number())
        .not_valid_before(datetime.utcnow() - timedelta(minutes=1))
        .not_valid_after(datetime.utcnow() + timedelta(days=365))
        .add_extension(
            x509.BasicConstraints(ca=False, path_length=None),
            critical=True
        )
        .add_extension(
            # ← NUEVO: Email en SubjectAlternativeName
            x509.SubjectAlternativeName([
                x509.RFC822Name(email)
            ]),
            critical=False
        )
        .sign(ca_key, hashes.SHA256())
    )

    p12_bytes = pkcs12.serialize_key_and_certificates(
        name=nombre.encode("utf-8"),
        key=private_key,
        cert=cert,
        cas=[ca_cert],
        encryption_algorithm=serialization.BestAvailableEncryption(
            password.encode("utf-8")
        )
    )

    return p12_bytes, format(cert.serial_number, 'X').upper(), cert

def badge_rol(rol):
    if rol == 'admin':
        return 'Administrador'
    if rol == 'coord':
        return 'Coordinador'
    if rol == 'voluntario':
        return 'Voluntario'
    return 'Operador'

def url_panel(rol):
    """Devuelve la URL del panel correcto según el rol."""
    if rol == 'admin':
        return url_for('panel_admin')
    if rol == 'voluntario':
        return url_for('panel_voluntario')
    return url_for('panel_usuario')

def log_evento(resultado, usuario=None, rol=None, serial=None, detalle=None):
    """Registra evento en la tabla de auditoría.
    Pedido explícito del módulo de Santiago (sección 3.7 del reporte)."""
    try:
        db = obtener_db()
        _exec(db,
            'INSERT INTO log_certificados'
            ' (serial, usuario, rol, ip_origen, resultado, detalle)'
            ' VALUES (%s,%s,%s,%s,%s,%s)',
            (serial, usuario, rol, request.remote_addr, resultado, detalle)
        )
        db.commit()
    except Exception as e:
        print(f'[log_evento] error: {e}')

def refrescar_expirados():
    """Marca como 'expirado' todo cert cuya fecha ya pasó."""
    db = obtener_db()
    ahora = datetime.utcnow().strftime('%Y-%m-%d %H:%M:%S')
    _exec(db,
        "UPDATE certificados SET estado='expirado'"
        " WHERE estado='vigente' AND fecha_expiracion IS NOT NULL"
        " AND fecha_expiracion < %s",
        (ahora,)
    )
    db.commit()

# ── CONFIGURACIÓN DE mTLS ─────────────────────────────────────
# MTLS_ENABLED=1 → el servidor exige cert de cliente (modo producción / demo).
# MTLS_ENABLED=0 → arranca en HTTP plano (modo debug). Default: 1.
MTLS_ENABLED = os.environ.get('MTLS_ENABLED', '1') == '1'

# Rutas públicas que NO requieren cert de cliente
# (ninguna en este diseño: el cert se pide para TODO el sitio)
RUTAS_SIN_MTLS = set()

@app.before_request
def verificar_cert_cliente():
    """
    Middleware mTLS (corregido):
    Antes de CUALQUIER ruta, el servidor ya NO exige que el navegador
    presente un certificado de cliente. Esto permite que usuarios normales
    entren solo con usuario/contraseña.

    La validación de certificados emitidos por la CA de Casa Monarca
    se mantiene en el flujo USB para roles admin/coord.
    """
    # Si mTLS está desactivado, no hacer nada
    if not MTLS_ENABLED:
        return None

    # 🔴 Cambio: ya no exigimos certificado de cliente en el handshake TLS
    # Se deja pasar a todas las rutas, y la validación fuerte se hace
    # en login_usb para roles altos.
    return None

# ── DECORADOR DE AUTENTICACIÓN ────────────────────────────────
def verificar_certificado(f):
    @wraps(f)
    def decorado(*args, **kwargs):
        if 'rol' not in session:
            return redirect(url_for('login'))
        g.rol = session['rol']
        g.usuario = session.get('usuario', '')
        g.nombre = session.get('nombre', 'Usuario')
        return f(*args, **kwargs)
    return decorado

# ── RUTAS DE AUTENTICACIÓN ────────────────────────────────────
@app.route('/')
def index():
    if 'rol' not in session:
        return redirect(url_for('login'))
    return redirect(url_panel(session['rol']))

@app.route('/login', methods=['GET', 'POST'])
@app.route('/login', methods=['GET', 'POST'])
def login():
    # POST: verificar credenciales
    email = request.form.get('usuario', '').strip().lower()
    password = request.form.get('password', '')
    if not email or not password:
        return render_template('login.html',
                               error='Email y contraseña son requeridos')

    # Validar formato de email
    if not validar_email(email):
        return render_template('login.html',
                               error='Formato de email inválido')

    db = obtener_db()
    usuario = _exec(db,
        'SELECT * FROM usuarios WHERE usuario=%s', (email,)
    ).fetchone()

    # Verificar credenciales
    if not usuario or not check_password_hash(usuario['password_hash'], password):
        log_evento('login_fallido', usuario=email,
                   detalle='Credenciales incorrectas')
        return render_template('login.html',
                               error='Email o contraseña incorrectos')

    # Verificar que la cuenta esté activa
    if not usuario.get('activo', 1):
        log_evento('acceso_denegado', usuario=email,
                   detalle='Intento de acceso a cuenta inactiva')
        return render_template('login.html',
                               error='Tu cuenta está inactiva. Contacta al administrador.')

    # Si el rol es admin o coord, redirigir a login_usb
    if usuario['rol'] in ('admin', 'coord'):
        session.clear()
        session['pending_user'] = usuario['usuario']
        session['nombre'] = usuario['nombre']
        session['rol'] = usuario['rol']
        return redirect(url_for('login_usb'))

    # Validación 1: Estado del certificado en BD
    refrescar_expirados()

    cert = _exec(db,
        'SELECT estado, serial FROM certificados WHERE usuario=%s OR usuario=%s'
        ' ORDER BY id DESC LIMIT 1',
        (email, usuario['nombre'])
    ).fetchone()

    if cert and cert['estado'] == 'revocado':
        log_evento('cert_revocado', usuario=email, rol=usuario['rol'],
                   serial=cert['serial'])
        return render_template('login.html',
                               error='Tu certificado fue revocado. Contacta al administrador.')

    if cert and cert['estado'] == 'expirado':
        log_evento('cert_expirado', usuario=email, rol=usuario['rol'],
                   serial=cert['serial'])
        return render_template('login.html',
                               error='Tu certificado expiró. Solicita uno nuevo.')

    # 🔴 Validación mTLS desactivada
    # Ya no se compara contra g.cert_email

    # Login exitoso - Crear sesión
    session.clear()
    session['usuario'] = usuario['usuario']  # email
    session['nombre'] = usuario['nombre']
    session['rol'] = usuario['rol']

    log_evento('login_exitoso', usuario=email, rol=usuario['rol'],
               serial=cert['serial'] if cert else None)

    if usuario['debe_cambiar_pwd']:
        return redirect(url_for('cambiar_pwd'))

    return redirect(url_panel(usuario['rol']))
    
@app.route('/login_usb', methods=['GET', 'POST'])
def login_usb():
    # Mostrar formulario para subir el .p12
    if request.method == 'GET':
        return render_template('login_usb.html')

    # Procesar el archivo .p12
    archivo = request.files.get('certificado_usb')
    if not archivo:
        log_evento('acceso_denegado', usuario=session.get('pending_user'),
                   detalle='No se seleccionó archivo USB')
        return render_template('login_usb.html',
                               error='Debes seleccionar tu archivo .p12 de la USB')

    p12_password = request.form.get('p12_password', '')
    email = session.get('pending_user')

    try:
        data = archivo.read()
        private_key, certificate, _ = pkcs12.load_key_and_certificates(
            data, p12_password.encode("utf-8")
        )
    except Exception as e:
        log_evento('acceso_denegado', usuario=email,
                   detalle=f'Error al abrir certificado USB: {str(e)}')
        return render_template('login_usb.html',
                               error='No se pudo abrir el certificado USB')

    cert_email = extraer_email_de_cert(certificate)
    if not cert_email or cert_email.lower() != email.lower():
        log_evento('acceso_denegado', usuario=email,
                   detalle=f'Cert USB email={cert_email}, login email={email}')
        return render_template('login_usb.html',
                               error='El certificado USB no corresponde a este usuario')

    # Validación en BD
    db = obtener_db()
    refrescar_expirados()
    cert = _exec(db,
        'SELECT estado, serial FROM certificados WHERE usuario=%s OR usuario=%s '
        'ORDER BY id DESC LIMIT 1',
        (email, session.get('nombre'))
    ).fetchone()

    if cert and cert['estado'] == 'revocado':
        log_evento('cert_revocado', usuario=email, rol=session['rol'],
                   serial=cert['serial'])
        return render_template('login_usb.html',
                               error='Tu certificado fue revocado. Contacta al administrador.')
    if cert and cert['estado'] == 'expirado':
        log_evento('cert_expirado', usuario=email, rol=session['rol'],
                   serial=cert['serial'])
        return render_template('login_usb.html',
                               error='Tu certificado expiró. Solicita uno nuevo.')

    # Login exitoso: completar sesión
    session['usuario'] = email
    log_evento('login_exitoso', usuario=email, rol=session['rol'],
               serial=cert['serial'] if cert else None)

    db = obtener_db()
    usuario = _exec(db, 'SELECT * FROM usuarios WHERE usuario=%s', (email,)).fetchone()
    if usuario and usuario['debe_cambiar_pwd']:
        return redirect(url_for('cambiar_pwd'))

    return redirect(url_panel(session['rol']))

@app.route('/cambiar_pwd', methods=['GET', 'POST'])
@verificar_certificado
def cambiar_pwd():
    error = None
    if request.method == 'POST':
        nueva  = request.form.get('nueva', '')
        confir = request.form.get('confirmacion', '')
        if len(nueva) < 8:
            error = 'La contraseña debe tener al menos 8 caracteres.'
        elif nueva != confir:
            error = 'Las contraseñas no coinciden.'
        else:
            db = obtener_db()
            _exec(db,
                'UPDATE usuarios SET password_hash=%s, debe_cambiar_pwd=0'
                ' WHERE usuario=%s',
                (generate_password_hash(nueva), g.usuario)
            )
            db.commit()
            log_evento('pwd_cambiada', usuario=g.usuario, rol=g.rol)
            return redirect(url_panel(g.rol))
    return render_template('cambiar_pwd.html', error=error, nombre=g.nombre)

@app.route('/logout')
def logout():
    session.clear()
    return redirect(url_for('login'))

# ── PANEL PRINCIPAL ───────────────────────────────────────────
@app.route('/admin')
@verificar_certificado
def panel_admin():
    print(">>> Renderizando panel_admin")
    if g.rol != 'admin':
        abort(403)
    return render_template('admin.html', nombre=g.nombre, rol=g.rol, usuario=g.usuario)

# ── API: MÉTRICAS ─────────────────────────────────────────────
@app.route('/admin/api/metricas')
@verificar_certificado
def api_metricas():
    if g.rol != 'admin':
        abort(403)
    refrescar_expirados()
    db = obtener_db()
    total     = _exec(db, 'SELECT COUNT(*) c FROM certificados').fetchone()['c']
    vigentes  = _exec(db, "SELECT COUNT(*) c FROM certificados WHERE estado='vigente'").fetchone()['c']
    revocados = _exec(db, "SELECT COUNT(*) c FROM certificados WHERE estado='revocado'").fetchone()['c']
    expirados = _exec(db, "SELECT COUNT(*) c FROM certificados WHERE estado='expirado'").fetchone()['c']
    # 'activos' se mantiene como alias para no romper el front existente.
    return jsonify(total=total, activos=vigentes, vigentes=vigentes,
                   revocados=revocados, expirados=expirados)

# ── API: LISTA DE CERTIFICADOS ────────────────────────────────
@app.route('/admin/api/certificados')
@verificar_certificado
def api_certificados():
    if g.rol != 'admin':
        abort(403)

    refrescar_expirados()
    db = obtener_db()
    rows = _exec(db,
        'SELECT id, serial, usuario, rol, fecha_emision, estado'
        ' FROM certificados ORDER BY id DESC'
    ).fetchall()

    certificados_ui = []
    for c in rows:
        certificados_ui.append({
            'id': c['id'],
            'nombre': c['usuario'],
            'usuario': c['usuario'],
            'rol': c['rol'],
            'serial': c['serial'],
            'fecha': c['fecha_emision'],
            'fecha_emision': c['fecha_emision'],
            'estado': c['estado'],
            'activo': c['estado'] == 'vigente',
        })

    return jsonify(certificados_ui)

# ── API: LOG DE AUDITORÍA ─────────────────────────────────────
@app.route('/admin/api/log')
@verificar_certificado
def api_log():
    if g.rol != 'admin':
        abort(403)
    db = obtener_db()
    try:
        rows = _exec(db,
            'SELECT fecha, usuario, rol, ip_origen, resultado, detalle'
            ' FROM log_certificados ORDER BY id DESC LIMIT 50'
        ).fetchall()
        
        # Devolver exactamente como está en la BD
        return jsonify([dict(r) for r in rows])
    
        
    except Exception as e:
        print(f"[ERROR api_log] {e}")
        return jsonify({'error': str(e)}), 500

@app.route('/admin/api/eliminados')
@verificar_certificado
def api_eliminados():
    if g.rol != 'admin':
        abort(403)
    db = obtener_db()
    rows = _exec(db,
        "SELECT fecha, usuario, detalle FROM log_certificados"
        " WHERE resultado = 'migrante_eliminado'"
        "   AND fecha >= NOW() - INTERVAL 30 DAY"
        " ORDER BY id DESC"
    ).fetchall()
    return jsonify([dict(r) for r in rows])


# ── GESTIÓN DE USUARIOS ───────────────────────────────────────

@app.route('/admin/api/usuarios')
@verificar_certificado
def admin_api_usuarios():
    if g.rol != 'admin':
        abort(403)
    db = obtener_db()
    rows = _exec(db,
        'SELECT usuario, nombre, rol, activo, debe_cambiar_pwd, creado'
        ' FROM usuarios ORDER BY rol, nombre'
    ).fetchall()
    result = []
    for r in rows:
        d = dict(r)
        if d.get('creado') and hasattr(d['creado'], 'isoformat'):
            d['creado'] = d['creado'].isoformat()
        result.append(d)
    return jsonify(result)


@app.route('/admin/api/usuarios/reset_pwd', methods=['POST'])
@verificar_certificado
def admin_reset_pwd():
    if g.rol != 'admin':
        abort(403)
    usuario = request.form.get('usuario', '').strip()
    if not usuario:
        return jsonify(error='Usuario requerido'), 400
    db = obtener_db()
    user = _exec(db, 'SELECT nombre FROM usuarios WHERE usuario=%s', (usuario,)).fetchone()
    if not user:
        return jsonify(error='Usuario no encontrado'), 404
    pwd_temp = generar_contrasena_temporal()
    _exec(db,
        'UPDATE usuarios SET password_hash=%s, debe_cambiar_pwd=1 WHERE usuario=%s',
        (generate_password_hash(pwd_temp), usuario)
    )
    db.commit()
    log_evento('pwd_cambiada', usuario=usuario, rol=g.rol,
               detalle=f'Reset forzado por {g.usuario}')
    return jsonify(ok=True, pwd_temporal=pwd_temp)


@app.route('/admin/api/usuarios/toggle_activo', methods=['POST'])
@verificar_certificado
def admin_toggle_activo():
    if g.rol != 'admin':
        abort(403)
    usuario = request.form.get('usuario', '').strip()
    if not usuario:
        return jsonify(error='Usuario requerido'), 400
    if usuario == g.usuario:
        return jsonify(error='No puedes desactivar tu propia cuenta'), 400
    db = obtener_db()
    user = _exec(db, 'SELECT activo FROM usuarios WHERE usuario=%s', (usuario,)).fetchone()
    if not user:
        return jsonify(error='Usuario no encontrado'), 404
    nuevo = 0 if user['activo'] else 1
    _exec(db, 'UPDATE usuarios SET activo=%s WHERE usuario=%s', (nuevo, usuario))
    db.commit()
    accion = 'activada' if nuevo else 'desactivada'
    log_evento('rol_modificado', usuario=usuario, rol=g.rol,
               detalle=f'Cuenta {accion} por {g.usuario}')
    return jsonify(ok=True, activo=nuevo)


@app.route('/admin/api/usuarios/eliminar', methods=['POST'])
@verificar_certificado
def admin_eliminar_usuario():
    if g.rol != 'admin':
        abort(403)
    usuario = request.form.get('usuario', '').strip()
    if not usuario:
        return jsonify(error='Usuario requerido'), 400
    if usuario == g.usuario:
        return jsonify(error='No puedes eliminar tu propia cuenta'), 400
    db = obtener_db()
    user = _exec(db, 'SELECT activo, nombre, rol FROM usuarios WHERE usuario=%s',
                 (usuario,)).fetchone()
    if not user:
        return jsonify(error='Usuario no encontrado'), 404
    if user['activo']:
        return jsonify(error='Solo se pueden eliminar cuentas desactivadas'), 400
    try:
        _exec(db, 'DELETE FROM solicitudes_pwd WHERE usuario=%s', (usuario,))
        _exec(db, 'DELETE FROM usuarios WHERE usuario=%s', (usuario,))
        db.commit()
    except Exception as e:
        db.rollback()
        return jsonify(error=f'Error al eliminar usuario: {str(e)}'), 500
    log_evento('rol_modificado', usuario=usuario, rol=user['rol'],
               detalle=f'Usuario eliminado permanentemente por {g.usuario}')
    return jsonify(ok=True)


@app.route('/admin/api/usuarios/editar', methods=['POST'])
@verificar_certificado
def admin_editar_usuario():
    if g.rol != 'admin':
        abort(403)
    usuario = request.form.get('usuario', '').strip()
    nombre  = request.form.get('nombre', '').strip()
    rol     = request.form.get('rol', '').strip()
    if not usuario or not nombre or not rol:
        return jsonify(error='Datos incompletos'), 400
    if rol not in ('admin', 'coord', 'op', 'voluntario'):
        return jsonify(error='Rol no válido'), 400
    db = obtener_db()
    user = _exec(db, 'SELECT id FROM usuarios WHERE usuario=%s', (usuario,)).fetchone()
    if not user:
        return jsonify(error='Usuario no encontrado'), 404
    _exec(db, 'UPDATE usuarios SET nombre=%s, rol=%s WHERE usuario=%s',
          (nombre, rol, usuario))
    db.commit()
    log_evento('rol_modificado', usuario=usuario, rol=g.rol,
               detalle=f'Perfil editado por {g.usuario}: nombre="{nombre}", rol="{rol}"')
    return jsonify(ok=True)


@app.route('/solicitar_reset_pwd', methods=['POST'])
def solicitar_reset_pwd():
    usuario = request.form.get('usuario', '').strip().lower()
    if not usuario:
        return jsonify(error='Ingresa tu email de acceso'), 400
    db = obtener_db()
    user = _exec(db, 'SELECT id FROM usuarios WHERE usuario=%s', (usuario,)).fetchone()
    if user:
        existe = _exec(db,
            "SELECT id FROM solicitudes_pwd WHERE usuario=%s AND estado='pendiente'",
            (usuario,)
        ).fetchone()
        if not existe:
            _exec(db, 'INSERT INTO solicitudes_pwd (usuario) VALUES (%s)', (usuario,))
            db.commit()
            log_evento('pwd_reset_solicitado', usuario=usuario,
                       detalle='Solicitud desde pantalla de login')
    return jsonify(ok=True)


@app.route('/admin/api/solicitudes_pwd')
@verificar_certificado
def admin_solicitudes_pwd():
    if g.rol != 'admin':
        abort(403)
    db = obtener_db()
    rows = _exec(db,
        "SELECT id, usuario, estado, solicitado_en, resuelto_por, resuelto_en"
        " FROM solicitudes_pwd ORDER BY solicitado_en DESC LIMIT 100"
    ).fetchall()
    result = []
    for r in rows:
        d = dict(r)
        for k in ('solicitado_en', 'resuelto_en'):
            if d.get(k) and hasattr(d[k], 'isoformat'):
                d[k] = d[k].isoformat()
        result.append(d)
    return jsonify(result)


@app.route('/admin/api/solicitudes_pwd/<int:sid>/resolver', methods=['POST'])
@verificar_certificado
def admin_resolver_reset_pwd(sid):
    if g.rol != 'admin':
        abort(403)
    aprobar = request.form.get('accion') == 'aprobar'
    db = obtener_db()
    sol = _exec(db,
        "SELECT usuario, estado FROM solicitudes_pwd WHERE id=%s", (sid,)
    ).fetchone()
    if not sol:
        return jsonify(error='Solicitud no encontrada'), 404
    if sol['estado'] != 'pendiente':
        return jsonify(error='Esta solicitud ya fue resuelta'), 400

    pwd_temp = None
    if aprobar:
        pwd_temp = generar_contrasena_temporal()
        _exec(db,
            'UPDATE usuarios SET password_hash=%s, debe_cambiar_pwd=1 WHERE usuario=%s',
            (generate_password_hash(pwd_temp), sol['usuario'])
        )
        resultado_log = 'pwd_reset_aprobado'
    else:
        resultado_log = 'pwd_reset_rechazado'

    _exec(db,
        'UPDATE solicitudes_pwd SET estado=%s, resuelto_por=%s, resuelto_en=NOW() WHERE id=%s',
        ('aprobada' if aprobar else 'rechazada', g.usuario, sid)
    )
    db.commit()
    log_evento(resultado_log, usuario=sol['usuario'], rol=g.rol,
               detalle=f'{"Aprobado" if aprobar else "Rechazado"} por {g.usuario}')

    resp = dict(ok=True)
    if pwd_temp:
        resp['pwd_temporal'] = pwd_temp
    return jsonify(**resp)


# ── EMITIR NUEVO CERTIFICADO ──────────────────────────────────
@app.route('/admin/nuevo_voluntario', methods=['POST'])
@verificar_certificado
def nuevo_voluntario():
    if g.rol != 'admin':
        abort(403)

    try:
        nombre = sanitizar_nombre(request.form.get('nombre', ''))
        email  = request.form.get('correo', '').strip().lower()
        rol    = request.form.get('rol', '')

        if not nombre:
            return jsonify(error='El nombre no puede estar vacío'), 400
        if not email:
            return jsonify(error='El email es obligatorio'), 400
        if rol not in ('op', 'coord', 'admin', 'voluntario'):
            return jsonify(error='Rol inválido'), 400

        correo   = request.form.get('correo', '').strip() or None
        telefono = request.form.get('telefono', '').strip() or None
        curp     = request.form.get('curp', '').strip() or None
        fnac     = request.form.get('fecha_nac', '').strip() or None
        genero   = request.form.get('genero', '').strip() or None
        area     = request.form.get('area', '').strip() or None
        observ   = request.form.get('observaciones', '').strip() or None

        db = obtener_db()
        existe = _exec(db, 'SELECT id FROM usuarios WHERE usuario=%s', (email,)).fetchone()
        if existe:
            return jsonify(error=f'El email {email} ya está registrado'), 400

        pwd_temporal = generar_contrasena_temporal()

        # Voluntario y operador: solo cuenta con contraseña temporal, sin certificado
        if rol in ('voluntario', 'op'):
            _exec(db,
                'INSERT INTO usuarios (usuario, nombre, rol, password_hash,'
                ' debe_cambiar_pwd, correo, telefono, curp, fecha_nacimiento,'
                ' genero, area, observaciones)'
                ' VALUES (%s,%s,%s,%s,1,%s,%s,%s,%s,%s,%s,%s)',
                (email, nombre, rol, generate_password_hash(pwd_temporal),
                 correo, telefono, curp, fnac, genero, area, observ)
            )
            db.commit()
            session['pwd_login']        = pwd_temporal
            session['p12_nombre']       = f"{rol}_{nombre.lower().replace(' ', '_')}"
            session['p12_display']      = nombre    
            session['p12_email']        = email
            session['p12_rol']          = rol
            session['p12_serial']       = ''
            session['p12_pwd_mostrada'] = False
            log_evento('rol_modificado', usuario=email, rol=rol,
                       detalle=f'Alta voluntario por {g.usuario}')
            return jsonify(ok=True, nombre=nombre, email=email, rol=rol,
                           redirect_url=url_for('entregar_certificado'))

    except Exception as e:
        import traceback
        traceback.print_exc()
        return jsonify(error=f'Error al crear usuario: {str(e)}'), 500

    # Roles con certificado (admin, coord únicamente)
    pwd_p12 = pwd_temporal
    if rol in ('admin', 'coord'):
        pwd_p12 = generar_contrasena_temporal()

    try:
        p12_bytes, serial, cert = generar_p12_real(email, nombre, rol, pwd_p12)
    except Exception as e:
        return jsonify(error=f'Error al generar el certificado .p12: {str(e)}'), 500

    # Generar bytes del .crt en memoria (sin escribir a disco)
    crt_bytes = cert.public_bytes(serialization.Encoding.PEM)

    # Generar README en memoria
    nombre_p12 = f"{rol}_{nombre.lower().replace(' ', '_')}"
    readme_texto = f"""Instrucciones para instalar certificado de Casa Monarca

1. Ingresa con tu email y contraseña (contraseña temporal si es la primera vez)
   en la pantalla de login de Casa Monarca.

2. Selecciona el archivo {nombre_p12}.p12 en tu navegador con esta contraseña: {pwd_p12}

⚠️ No copies estos archivos fuera del USB.
⚠️ La contraseña es personal y solo se muestra aquí.
"""

    fecha = datetime.now().strftime('%Y-%m-%d')
    fecha_exp = (datetime.utcnow() + timedelta(days=365)).strftime('%Y-%m-%d %H:%M:%S')

    _exec(db,
        'INSERT INTO certificados'
        ' (serial, usuario, nombre, rol, fecha_emision, fecha_expiracion, estado, emitido_por)'
        ' VALUES (%s,%s,%s,%s,%s,%s,%s,%s)',
        (serial, email, nombre, rol, fecha, fecha_exp, 'vigente', g.usuario)
    )

    existente = _exec(db, 'SELECT id FROM usuarios WHERE usuario=%s', (email,)).fetchone()
    if existente is None:
        _exec(db,
            'INSERT INTO usuarios (usuario, nombre, rol, password_hash,'
            ' debe_cambiar_pwd, correo, telefono, curp, fecha_nacimiento,'
            ' genero, area, observaciones, serial_cert)'
            ' VALUES (%s,%s,%s,%s,1,%s,%s,%s,%s,%s,%s,%s,%s)',
            (email, nombre, rol,
             generate_password_hash(pwd_temporal),
             email, telefono, curp, fnac, genero, area, observ, serial)
        )
    else:
        _exec(db,
            'UPDATE usuarios SET nombre=%s, rol=%s, password_hash=%s,'
            ' debe_cambiar_pwd=1, serial_cert=%s WHERE usuario=%s',
            (nombre, rol, generate_password_hash(pwd_temporal),
             serial, email)
        )
    db.commit()

    log_evento('cert_emitido', usuario=email, rol=rol, serial=serial,
               detalle=f'Emitido por {g.usuario} para {nombre}')

    # Guardar en sesión el nombre correcto del .p12
    nombre_seguro = f"{rol}_{nombre.lower().replace(' ', '_')}"
    session['pwd_login'] = pwd_temporal
    session['p12_b64'] = base64.b64encode(p12_bytes).decode('utf-8')
    session['crt_b64'] = base64.b64encode(crt_bytes).decode('utf-8')
    session['readme_txt'] = readme_texto
    session['p12_nombre'] = nombre_seguro
    session['p12_display'] = nombre    
    session['p12_email'] = email
    session['p12_rol'] = rol
    session['p12_serial'] = serial
    session['p12_pwd_mostrada'] = False

    return jsonify(
        ok=True,
        nombre=nombre,
        email=email,
        rol=rol,
        serial=serial,
        redirect_url=url_for('entregar_certificado')
    )

# ── PANTALLA DE ENTREGA ───────────────────────────────────────
@app.route('/admin/entregar_certificado')
@verificar_certificado
def entregar_certificado():
    print(">>> Renderizando entregar_certificado")
    if g.rol != 'admin':
        abort(403)

    nombre = session.get('p12_display')
    rol = session.get('p12_rol')
    serial = session.get('p12_serial')

    if not nombre:
        return redirect(url_for('panel_admin'))

    pwd_login = None
    if not session.get('p12_pwd_mostrada', False):
        pwd_login = session.get('pwd_login')
        session['p12_pwd_mostrada'] = True

    return render_template(
        'entregar_cert.html',
        nombre=nombre,
        rol=rol,
        rol_legible=badge_rol(rol),
        serial=serial,
        pwd_login=pwd_login
    )

# ── DESCARGA REAL DEL .P12 ────────────────────────────────────
@app.route('/admin/descargar_p12')
@verificar_certificado
def descargar_p12():
    if g.rol != 'admin':
        abort(403)

    p12_b64      = session.get('p12_b64')
    crt_b64      = session.get('crt_b64')
    readme_txt   = session.get('readme_txt', '')
    nombre_seguro = session.get('p12_nombre', 'certificado')
    email        = session.get('p12_email', 'usuario@casamonarca.org')

    if not p12_b64:
        abort(404, description='No hay certificado disponible para descargar.')

    try:
        p12_bytes = base64.b64decode(p12_b64)
    except Exception:
        abort(500, description='No se pudo reconstruir el archivo .p12.')

    # Construir el ZIP completamente en memoria
    zip_buffer = io.BytesIO()
    with zipfile.ZipFile(zip_buffer, 'w', zipfile.ZIP_DEFLATED) as zf:
        zf.writestr(f"{nombre_seguro}.p12", p12_bytes)
        if crt_b64:
            zf.writestr(f"{email}.crt", base64.b64decode(crt_b64))
        if readme_txt:
            zf.writestr(f"README_{email}.txt", readme_txt.encode('utf-8'))
    zip_buffer.seek(0)

    return send_file(
        zip_buffer,
        mimetype='application/zip',
        as_attachment=True,
        download_name=f"{nombre_seguro}_usb.zip"
    )

# ── REVOCAR CERTIFICADO ───────────────────────────────────────
@app.route('/admin/revocar/<int:cert_id>', methods=['POST'])
@verificar_certificado
def revocar_certificado(cert_id):
    if g.rol != 'admin':
        abort(403)

    db = obtener_db()
    cert = _exec(db,
        'SELECT * FROM certificados WHERE id=%s', (cert_id,)
    ).fetchone()
    if cert is None:
        return jsonify(error='Certificado no encontrado'), 404
    if cert['estado'] == 'revocado':
        return jsonify(error='Certificado ya estaba revocado'), 400

    motivo = request.form.get('motivo', 'Revocado desde panel admin')
    _exec(db, "UPDATE certificados SET estado='revocado' WHERE id=%s", (cert_id,))
    _exec(db,
        'INSERT IGNORE INTO certificados_revocados'
        ' (serial, usuario, rol, revocado_por, motivo) VALUES (%s,%s,%s,%s,%s)',
        (cert['serial'], cert['usuario'], cert['rol'], g.usuario, motivo)
    )
    _exec(db, 'UPDATE usuarios SET activo=0 WHERE usuario=%s', (cert['usuario'],))
    db.commit()

    log_evento('cert_revocado_admin', usuario=cert['usuario'], rol=cert['rol'],
               serial=cert['serial'], detalle=motivo)
    return jsonify(ok=True)

# ── ELIMINAR CERTIFICADO ──────────────────────────────────────
@app.route('/admin/certificados/<int:cert_id>/eliminar', methods=['POST'])
@verificar_certificado
def eliminar_certificado(cert_id):
    if g.rol != 'admin':
        abort(403)
    db = obtener_db()
    cert = _exec(db, 'SELECT * FROM certificados WHERE id=%s', (cert_id,)).fetchone()
    if cert is None:
        return jsonify(error='Certificado no encontrado'), 404
    try:
        if cert['estado'] == 'vigente':
            _exec(db, 'UPDATE usuarios SET activo=0 WHERE usuario=%s', (cert['usuario'],))
            _exec(db,
                'INSERT IGNORE INTO certificados_revocados'
                ' (serial, usuario, rol, revocado_por, motivo) VALUES (%s,%s,%s,%s,%s)',
                (cert['serial'], cert['usuario'], cert['rol'], g.usuario, 'Eliminado desde panel admin')
            )
        _exec(db, 'DELETE FROM certificados WHERE id=%s', (cert_id,))
        db.commit()
    except Exception as e:
        db.rollback()
        return jsonify(error=f'Error al eliminar: {e}'), 500
    log_evento('cert_revocado_admin', usuario=cert['usuario'], rol=cert['rol'],
               serial=cert['serial'], detalle=f'Certificado eliminado por {g.usuario}')
    return jsonify(ok=True)

# ── PANEL DE COORDINADORES Y OPERADORES ───────────────────────
@app.route('/panel')
@verificar_certificado
def panel_usuario():
    if g.rol == 'admin':
        return redirect(url_for('panel_admin'))
    if g.rol == 'voluntario':
        return redirect(url_for('panel_voluntario'))
    return render_template('panel_usuario.html',
                           nombre=g.nombre, rol=g.rol, usuario=g.usuario,
                           rol_legible=badge_rol(g.rol))

# ── PANEL DE VOLUNTARIOS ──────────────────────────────────────
@app.route('/voluntario')
@verificar_certificado
def panel_voluntario():
    if g.rol != 'voluntario':
        abort(403)
    return render_template('voluntario.html',
                           nombre=g.nombre, rol=g.rol, usuario=g.usuario,
                           rol_legible='Voluntario')

@app.route('/panel/api/certificados')
@verificar_certificado
def panel_api_certificados():
    """Lista de certificados (solo lectura, sin botón de revocar).
    Coord y op pueden VER pero no modificar."""
    refrescar_expirados()
    db = obtener_db()
    rows = _exec(db,
        'SELECT id, serial, usuario, rol, fecha_emision, estado'
        ' FROM certificados ORDER BY id DESC'
    ).fetchall()
    lista = []
    for c in rows:
        lista.append({
            'id': c['id'],
            'nombre': c['usuario'],
            'rol': c['rol'],
            'serial': c['serial'],
            'fecha': c['fecha_emision'],
            'estado': c['estado'],
        })
    return jsonify(lista)

@app.route('/panel/api/mi_certificado')
@verificar_certificado
def panel_api_mi_certificado():
    """Devuelve los datos del cert más reciente del usuario logueado."""
    refrescar_expirados()
    db = obtener_db()
    # Buscar por nombre (como está guardado en la tabla certificados)
    cert = _exec(db,
        'SELECT serial, usuario, rol, fecha_emision, fecha_expiracion, estado'
        ' FROM certificados WHERE usuario=%s ORDER BY id DESC LIMIT 1',
        (g.nombre,)
    ).fetchone()
    if not cert:
        cert = _exec(db,
            'SELECT serial, usuario, rol, fecha_emision, fecha_expiracion, estado'
            ' FROM certificados WHERE usuario=%s ORDER BY id DESC LIMIT 1',
            (g.usuario,)
        ).fetchone()
    if not cert:
        return jsonify(error='Sin certificado asociado')
    return jsonify({
        'serial': cert['serial'],
        'nombre': cert['usuario'],
        'rol': cert['rol'],
        'fecha_emision': cert['fecha_emision'],
        'fecha_expiracion': cert['fecha_expiracion'] or '',
        'estado': cert['estado'],
    })

# ── DIAGNÓSTICO DB ────────────────────────────────────────────
@app.route('/api/debug/columnas_migrantes')
@verificar_certificado
def debug_columnas():
    if g.rol != 'admin':
        abort(403)
    db = obtener_db()
    rows = _exec(db, 'DESCRIBE migrantes').fetchall()
    return jsonify([dict(r) for r in rows])

# ── REGISTROS DE MIGRANTES ────────────────────────────────────
@app.route('/api/migrantes')
@verificar_certificado
def api_migrantes_lista():
    db = obtener_db()
    rows = _exec(db,
        'SELECT id, folio, nombre, fecha_nacimiento, pais_origen,'
        ' fecha_atencion, genero, departamento_estado, estado_civil,'
        ' grupo_poblacion, telefono_contacto, registrado_por, creado, actualizado'
        ' FROM migrantes ORDER BY id DESC'
    ).fetchall()

    def _fstr(v):
        return v.isoformat()[:10] if hasattr(v, 'isoformat') else (str(v)[:10] if v else None)

    lista = []
    for r in rows:
        m = dict(r)
        m['fecha_nacimiento'] = _fstr(m.get('fecha_nacimiento'))
        m['fecha_atencion']   = _fstr(m.get('fecha_atencion'))
        lista.append(m)
    return jsonify(lista)


@app.route('/api/migrantes', methods=['POST'])
@verificar_certificado
def api_migrantes_crear():
    if g.rol not in ('admin', 'coord', 'op', 'voluntario'):
        abort(403)

    nombre_pila      = request.form.get('nombre_pila', '').strip()
    primer_apellido  = request.form.get('primer_apellido', '').strip()
    segundo_apellido = request.form.get('segundo_apellido', '').strip()
    fecha_atencion   = request.form.get('fecha_atencion', '').strip() or None
    telefono_contacto = request.form.get('telefono_contacto', '').strip() or None
    genero           = request.form.get('genero', '').strip() or None
    pais_origen      = request.form.get('pais_origen', '').strip()
    departamento_estado = request.form.get('departamento_estado', '').strip() or None
    estado_civil     = request.form.get('estado_civil', '').strip() or None
    fecha_nac        = request.form.get('fecha_nacimiento', '').strip() or None
    grupo_poblacion  = request.form.get('grupo_poblacion', '').strip() or None

    faltantes = [f for f, v in [
        ('nombre_pila', nombre_pila),
        ('primer_apellido', primer_apellido),
        ('pais_origen', pais_origen),
        ('fecha_nacimiento', fecha_nac),
        ('genero', genero),
        ('grupo_poblacion', grupo_poblacion),
    ] if not v]
    if faltantes:
        return jsonify(error=f'Campos obligatorios faltantes: {", ".join(faltantes)}'), 400

    partes = [nombre_pila, primer_apellido]
    if segundo_apellido:
        partes.append(segundo_apellido)
    nombre = ' '.join(partes)

    db = obtener_db()
    try:
        cur = _exec(db,
            'INSERT INTO migrantes'
            ' (folio, nombre, fecha_nacimiento, pais_origen,'
            '  fecha_atencion, genero, departamento_estado, estado_civil,'
            '  grupo_poblacion, telefono_contacto, registrado_por)'
            ' VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)',
            (
                'TEMP', nombre, fecha_nac, pais_origen,
                fecha_atencion, genero, departamento_estado, estado_civil,
                grupo_poblacion, telefono_contacto, g.usuario,
            )
        )
    except Exception as e:
        return jsonify(error=f'Error al guardar en base de datos: {e}'), 500

    nuevo_id = cur.lastrowid
    folio = f'MIG-{datetime.now().strftime("%Y%m")}-{nuevo_id:04d}'
    _exec(db, 'UPDATE migrantes SET folio=%s WHERE id=%s', (folio, nuevo_id))
    db.commit()

    log_evento('migrante_registrado', usuario=g.usuario, rol=g.rol,
               detalle=f'Nuevo migrante: {nombre} · País: {pais_origen} · Folio: {folio}')
    return jsonify(ok=True, id=nuevo_id, folio=folio)


@app.route('/api/migrantes/<int:mid>', methods=['PUT'])
@verificar_certificado
def api_migrantes_editar(mid):
    if g.rol not in ('admin', 'coord'):
        abort(403)

    nombre_pila      = request.form.get('nombre_pila', '').strip()
    primer_apellido  = request.form.get('primer_apellido', '').strip()
    segundo_apellido = request.form.get('segundo_apellido', '').strip()
    fecha_atencion   = request.form.get('fecha_atencion', '').strip() or None
    telefono_contacto = request.form.get('telefono_contacto', '').strip() or None
    genero           = request.form.get('genero', '').strip() or None
    pais_origen      = request.form.get('pais_origen', '').strip()
    departamento_estado = request.form.get('departamento_estado', '').strip() or None
    estado_civil     = request.form.get('estado_civil', '').strip() or None
    fecha_nac        = request.form.get('fecha_nacimiento', '').strip() or None
    grupo_poblacion  = request.form.get('grupo_poblacion', '').strip() or None

    faltantes = [f for f, v in [
        ('nombre_pila', nombre_pila),
        ('primer_apellido', primer_apellido),
        ('pais_origen', pais_origen),
        ('fecha_nacimiento', fecha_nac),
        ('genero', genero),
        ('grupo_poblacion', grupo_poblacion),
    ] if not v]
    if faltantes:
        return jsonify(error=f'Campos obligatorios faltantes: {", ".join(faltantes)}'), 400

    partes = [nombre_pila, primer_apellido]
    if segundo_apellido:
        partes.append(segundo_apellido)
    nombre = ' '.join(partes)

    db = obtener_db()
    mig = _exec(db,
        'SELECT folio, nombre, fecha_nacimiento, pais_origen,'
        ' fecha_atencion, genero, departamento_estado, estado_civil, grupo_poblacion,'
        ' telefono_contacto FROM migrantes WHERE id=%s', (mid,)
    ).fetchone()
    if not mig:
        return jsonify(error='Migrante no encontrado'), 404

    try:
        _exec(db,
            'UPDATE migrantes SET'
            '  nombre=%s, fecha_nacimiento=%s, pais_origen=%s,'
            '  fecha_atencion=%s, genero=%s, departamento_estado=%s,'
            '  estado_civil=%s, grupo_poblacion=%s, telefono_contacto=%s'
            ' WHERE id=%s',
            (
                nombre, fecha_nac, pais_origen,
                fecha_atencion, genero, departamento_estado,
                estado_civil, grupo_poblacion, telefono_contacto, mid,
            )
        )
    except Exception as e:
        return jsonify(error=f'Error al actualizar en base de datos: {e}'), 500
    db.commit()

    _s = lambda v: v.isoformat()[:10] if hasattr(v, 'isoformat') else (str(v) if v else '')
    comparar = [
        ('nombre',           _s(mig['nombre']),            nombre),
        ('fecha_nacimiento', _s(mig['fecha_nacimiento']),  fecha_nac or ''),
        ('pais_origen',      _s(mig['pais_origen']),       pais_origen),
        ('genero',           _s(mig.get('genero') or ''),  genero or ''),
        ('grupo_poblacion',  _s(mig.get('grupo_poblacion') or ''), grupo_poblacion or ''),
    ]
    cambios = [f'{k}: "{vo}" → "{vn}"' for k, vo, vn in comparar if vo != vn]
    detalle = (f'Folio: {mig["folio"]} · '
               + (' | '.join(cambios) if cambios else 'sin cambios'))
    log_evento('migrante_actualizado', usuario=g.usuario, rol=g.rol, detalle=detalle)
    return jsonify(ok=True)


@app.route('/api/migrantes/<int:mid>', methods=['DELETE'])
@verificar_certificado
def api_migrantes_eliminar(mid):
    if g.rol != 'admin':
        abort(403)
    db = obtener_db()
    mig = _exec(db, 'SELECT nombre, folio FROM migrantes WHERE id=%s', (mid,)).fetchone()
    if not mig:
        return jsonify(error='Migrante no encontrado'), 404
    _exec(db, 'DELETE FROM migrantes WHERE id=%s', (mid,))
    db.commit()
    log_evento('migrante_eliminado', usuario=g.usuario, rol=g.rol,
               detalle=f'Eliminación directa: {mig["nombre"]} · Folio: {mig["folio"]}')
    return jsonify(ok=True)


@app.route('/api/migrantes/<int:mid>/solicitar_eliminacion', methods=['POST'])
@verificar_certificado
def api_solicitar_eliminacion(mid):
    if g.rol != 'coord':
        abort(403)

    db = obtener_db()
    existe = _exec(db, 'SELECT id FROM migrantes WHERE id=%s', (mid,)).fetchone()
    if not existe:
        return jsonify(error='Migrante no encontrado'), 404

    pendiente = _exec(db,
        "SELECT id FROM solicitudes_eliminacion"
        " WHERE migrante_id=%s AND estado='pendiente'", (mid,)
    ).fetchone()
    if pendiente:
        return jsonify(error='Ya existe una solicitud pendiente para este registro'), 400

    mig = _exec(db, 'SELECT nombre, folio FROM migrantes WHERE id=%s', (mid,)).fetchone()
    motivo = request.form.get('motivo', '').strip() or None
    _exec(db,
        'INSERT INTO solicitudes_eliminacion (migrante_id, solicitado_por, motivo)'
        ' VALUES (%s,%s,%s)',
        (mid, g.usuario, motivo)
    )
    db.commit()

    log_evento('migrante_solicitud_eliminacion', usuario=g.usuario, rol=g.rol,
               detalle=f'Solicitud eliminación: {mig["nombre"] if mig else mid} · Motivo: {motivo or "sin motivo"}')
    return jsonify(ok=True)


@app.route('/admin/api/solicitudes')
@verificar_certificado
def api_solicitudes_lista():
    if g.rol != 'admin':
        abort(403)
    db = obtener_db()
    rows = _exec(db,
        'SELECT s.id, s.migrante_id, m.folio, m.nombre AS migrante_nombre,'
        '  s.solicitado_por, s.motivo, s.estado, s.fecha_solicitud,'
        '  s.solicitante_op, s.firma_coord'
        ' FROM solicitudes_eliminacion s'
        ' JOIN migrantes m ON m.id = s.migrante_id'
        " WHERE s.estado='pendiente'"
        ' ORDER BY s.fecha_solicitud ASC'
    ).fetchall()
    return jsonify([dict(r) for r in rows])


@app.route('/admin/api/solicitudes/<int:sid>/resolver', methods=['POST'])
@verificar_certificado
def api_resolver_solicitud(sid):
    if g.rol != 'admin':
        abort(403)

    aprobar = request.form.get('aprobar', '0') == '1'
    db = obtener_db()
    sol = _exec(db, 'SELECT * FROM solicitudes_eliminacion WHERE id=%s', (sid,)).fetchone()
    if not sol:
        return jsonify(error='Solicitud no encontrada'), 404
    if sol['estado'] != 'pendiente':
        return jsonify(error='La solicitud ya fue resuelta'), 400

    nuevo_estado = 'aprobada' if aprobar else 'rechazada'
    ahora = datetime.utcnow().strftime('%Y-%m-%d %H:%M:%S')
    _exec(db,
        'UPDATE solicitudes_eliminacion'
        ' SET estado=%s, resuelto_por=%s, fecha_resolucion=%s WHERE id=%s',
        (nuevo_estado, g.usuario, ahora, sid)
    )
    if aprobar:
        mig = _exec(db, 'SELECT nombre, folio FROM migrantes WHERE id=%s',
                    (sol['migrante_id'],)).fetchone()
        _exec(db, 'DELETE FROM migrantes WHERE id=%s', (sol['migrante_id'],))
        db.commit()
        if mig:
            log_evento('migrante_eliminado', usuario=g.usuario, rol=g.rol,
                       detalle=f'Eliminación aprobada (sol. #{sid}): {mig["nombre"]} · Folio: {mig["folio"]}')
    else:
        db.commit()
    return jsonify(ok=True, accion=nuevo_estado)


# ── DERECHOS ARCO ─────────────────────────────────────────────
# Whitelist de campos editables por rectificación (previene inyección SQL)
CAMPOS_RECT = {
    'nombre', 'fecha_nacimiento', 'pais_origen', 'departamento_estado',
    'estado_civil', 'genero', 'grupo_poblacion', 'telefono_contacto', 'fecha_atencion'
}

@app.route('/api/arco/buscar')
@verificar_certificado
def arco_buscar():
    if g.rol not in ('admin', 'coord', 'op'):
        abort(403)
    q = request.args.get('q', '').strip()
    if len(q) < 2:
        return jsonify([])
    db = obtener_db()
    rows = _exec(db,
        'SELECT id, folio, nombre, pais_origen, fecha_atencion, genero, grupo_poblacion'
        ' FROM migrantes WHERE nombre LIKE %s OR folio LIKE %s'
        ' ORDER BY nombre LIMIT 20',
        (f'%{q}%', f'%{q}%')
    ).fetchall()
    def _fstr(v):
        return v.isoformat()[:10] if hasattr(v, 'isoformat') else (str(v) if v else None)
    return jsonify([{**dict(r), 'fecha_atencion': _fstr(r['fecha_atencion'])} for r in rows])


@app.route('/api/arco/acceso/<int:mid>')
@verificar_certificado
def arco_acceso(mid):
    if g.rol not in ('admin', 'coord', 'op'):
        abort(403)
    db = obtener_db()
    mig = _exec(db,
        'SELECT id, folio, nombre, fecha_nacimiento, pais_origen,'
        ' fecha_atencion, genero, departamento_estado, estado_civil,'
        ' grupo_poblacion, telefono_contacto, registrado_por, creado'
        ' FROM migrantes WHERE id=%s', (mid,)
    ).fetchone()
    if not mig:
        return jsonify(error='Migrante no encontrado'), 404
    def _fstr(v):
        return v.isoformat()[:10] if hasattr(v, 'isoformat') else (str(v) if v else None)
    m = dict(mig)
    for k in ('fecha_nacimiento', 'fecha_atencion'):
        m[k] = _fstr(m.get(k))
    if m.get('creado'):
        m['creado'] = m['creado'].isoformat()[:10]
    log_evento('arco_acceso', usuario=g.usuario, rol=g.rol,
               detalle=f'Acceso ARCO id={mid} folio={m["folio"]}')
    return jsonify(m)


@app.route('/api/arco/rectificacion', methods=['POST'])
@verificar_certificado
def arco_rectificacion():
    import json as _json
    if g.rol not in ('op', 'coord'):
        abort(403)
    migrante_id = request.form.get('migrante_id', '').strip()
    cambios_raw = request.form.get('cambios_json', '{}')
    if not migrante_id:
        return jsonify(error='Migrante requerido'), 400
    try:
        cambios = _json.loads(cambios_raw)
    except Exception:
        return jsonify(error='Datos inválidos'), 400
    cambios = {k: v for k, v in cambios.items() if k in CAMPOS_RECT}
    if not cambios:
        return jsonify(error='No hay cambios válidos'), 400
    db = obtener_db()
    mig = _exec(db, 'SELECT folio, nombre FROM migrantes WHERE id=%s', (migrante_id,)).fetchone()
    if not mig:
        return jsonify(error='Migrante no encontrado'), 404

    # Coordinador: aplica los cambios de inmediato
    if g.rol == 'coord':
        sets = ', '.join(f'{k}=%s' for k in cambios)
        vals = list(cambios.values()) + [migrante_id]
        _exec(db, f'UPDATE migrantes SET {sets} WHERE id=%s', vals)
        db.commit()
        log_evento('arco_rect_aprobada', usuario=g.usuario, rol=g.rol,
                   detalle=f'Rectificación inmediata ARCO folio={mig["folio"]}')
        return jsonify(ok=True, aplicado=True)

    # Operativo: crea solicitud pendiente para el coordinador
    pendiente = _exec(db,
        "SELECT id FROM solicitudes_arco_rect WHERE migrante_id=%s AND estado='pendiente'",
        (migrante_id,)
    ).fetchone()
    if pendiente:
        return jsonify(error='Ya existe una solicitud pendiente para este registro'), 400
    _exec(db,
        'INSERT INTO solicitudes_arco_rect (migrante_id, solicitado_por, cambios_json)'
        ' VALUES (%s,%s,%s)',
        (migrante_id, g.usuario, _json.dumps(cambios))
    )
    db.commit()
    log_evento('arco_rect_solicitada', usuario=g.usuario, rol=g.rol,
               detalle=f'Rectificación ARCO folio={mig["folio"]}')
    return jsonify(ok=True, aplicado=False)


@app.route('/api/arco/solicitudes_rect')
@verificar_certificado
def arco_solicitudes_rect():
    if g.rol not in ('admin', 'coord', 'op'):
        abort(403)
    db = obtener_db()
    if g.rol == 'op':
        rows = _exec(db,
            'SELECT r.id, r.migrante_id, m.folio, m.nombre AS migrante_nombre,'
            '  r.solicitado_por, r.cambios_json, r.estado, r.motivo_rechazo,'
            '  r.fecha_solicitud'
            ' FROM solicitudes_arco_rect r'
            ' JOIN migrantes m ON m.id=r.migrante_id'
            ' WHERE r.solicitado_por=%s ORDER BY r.fecha_solicitud DESC LIMIT 50',
            (g.usuario,)
        ).fetchall()
    else:
        rows = _exec(db,
            'SELECT r.id, r.migrante_id, m.folio, m.nombre AS migrante_nombre,'
            '  r.solicitado_por, r.cambios_json, r.estado, r.motivo_rechazo,'
            '  r.fecha_solicitud'
            ' FROM solicitudes_arco_rect r'
            ' JOIN migrantes m ON m.id=r.migrante_id'
            " WHERE r.estado='pendiente' ORDER BY r.fecha_solicitud ASC"
        ).fetchall()
    result = []
    for r in rows:
        d = dict(r)
        if d.get('fecha_solicitud') and hasattr(d['fecha_solicitud'], 'isoformat'):
            d['fecha_solicitud'] = d['fecha_solicitud'].isoformat()
        result.append(d)
    return jsonify(result)


@app.route('/api/arco/solicitudes_rect/<int:sid>/resolver', methods=['POST'])
@verificar_certificado
def arco_resolver_rect(sid):
    import json as _json
    if g.rol not in ('coord', 'admin'):
        abort(403)
    aprobar = request.form.get('accion') == 'aprobar'
    motivo  = request.form.get('motivo', '').strip() or None
    db = obtener_db()
    sol = _exec(db,
        'SELECT r.*, m.folio FROM solicitudes_arco_rect r'
        ' JOIN migrantes m ON m.id=r.migrante_id WHERE r.id=%s', (sid,)
    ).fetchone()
    if not sol:
        return jsonify(error='Solicitud no encontrada'), 404
    if sol['estado'] != 'pendiente':
        return jsonify(error='La solicitud ya fue resuelta'), 400
    ahora = datetime.utcnow().strftime('%Y-%m-%d %H:%M:%S')
    if aprobar:
        try:
            cambios = _json.loads(sol['cambios_json'] or '{}')
        except Exception:
            cambios = {}
        cambios = {k: v for k, v in cambios.items() if k in CAMPOS_RECT}
        if cambios:
            sets = ', '.join(f'{k}=%s' for k in cambios)
            vals = list(cambios.values()) + [sol['migrante_id']]
            _exec(db, f'UPDATE migrantes SET {sets} WHERE id=%s', vals)
    _exec(db,
        'UPDATE solicitudes_arco_rect'
        ' SET estado=%s, resuelto_por=%s, resuelto_en=%s, motivo_rechazo=%s'
        ' WHERE id=%s',
        ('aprobada' if aprobar else 'rechazada', g.usuario, ahora, motivo, sid)
    )
    db.commit()
    log_evento('arco_rect_aprobada' if aprobar else 'arco_rect_rechazada',
               usuario=g.usuario, rol=g.rol,
               detalle=f'Folio {sol["folio"]}: {"aprobada" if aprobar else "rechazada"}')
    return jsonify(ok=True, accion='aprobada' if aprobar else 'rechazada')


# Operativo → Coordinador: solicitar cancelación
@app.route('/api/arco/cancelacion_op/<int:mid>', methods=['POST'])
@verificar_certificado
def arco_cancelacion_op(mid):
    if g.rol != 'op':
        abort(403)
    db = obtener_db()
    mig = _exec(db, 'SELECT nombre, folio FROM migrantes WHERE id=%s', (mid,)).fetchone()
    if not mig:
        return jsonify(error='Migrante no encontrado'), 404
    pendiente = _exec(db,
        "SELECT id FROM solicitudes_cancelacion_op WHERE migrante_id=%s AND estado='pendiente'",
        (mid,)
    ).fetchone()
    if pendiente:
        return jsonify(error='Ya existe una solicitud de cancelación pendiente para este registro'), 400
    motivo = request.form.get('motivo', '').strip() or None
    _exec(db,
        'INSERT INTO solicitudes_cancelacion_op (migrante_id, solicitado_por, motivo)'
        ' VALUES (%s,%s,%s)',
        (mid, g.usuario, motivo)
    )
    db.commit()
    log_evento('arco_cancel_op_solicitada', usuario=g.usuario, rol=g.rol,
               detalle=f'Op solicita cancelación: {mig["nombre"]} · Folio: {mig["folio"]}')
    return jsonify(ok=True)


# Coordinador: ver solicitudes de cancelación enviadas por operativos
@app.route('/api/arco/solicitudes_cancelacion_op')
@verificar_certificado
def arco_solicitudes_cancelacion_op():
    if g.rol != 'coord':
        abort(403)
    db = obtener_db()
    rows = _exec(db,
        'SELECT s.id, s.migrante_id, m.folio, m.nombre AS migrante_nombre,'
        '  s.solicitado_por, s.motivo, s.estado, s.fecha_solicitud'
        ' FROM solicitudes_cancelacion_op s'
        ' JOIN migrantes m ON m.id = s.migrante_id'
        " WHERE s.estado='pendiente'"
        ' ORDER BY s.fecha_solicitud ASC'
    ).fetchall()
    result = []
    for r in rows:
        d = dict(r)
        if d.get('fecha_solicitud') and hasattr(d['fecha_solicitud'], 'isoformat'):
            d['fecha_solicitud'] = d['fecha_solicitud'].isoformat()
        result.append(d)
    return jsonify(result)


# Op: ver mis solicitudes de cancelación (historial)
@app.route('/api/arco/mis_solicitudes_cancelacion')
@verificar_certificado
def arco_mis_solicitudes_cancelacion():
    if g.rol != 'op':
        abort(403)
    db = obtener_db()
    rows = _exec(db,
        'SELECT s.id, s.migrante_id, m.folio, m.nombre AS migrante_nombre,'
        '  s.motivo, s.estado, s.fecha_solicitud, s.resuelto_por, s.resuelto_en'
        ' FROM solicitudes_cancelacion_op s'
        ' JOIN migrantes m ON m.id = s.migrante_id'
        ' WHERE s.solicitado_por=%s ORDER BY s.fecha_solicitud DESC LIMIT 50',
        (g.usuario,)
    ).fetchall()
    result = []
    for r in rows:
        d = dict(r)
        for f in ('fecha_solicitud', 'resuelto_en'):
            if d.get(f) and hasattr(d[f], 'isoformat'):
                d[f] = d[f].isoformat()
        result.append(d)
    return jsonify(result)


# Coordinador → Admin: aprobar (firma EC + escala) o rechazar cancelación de op
@app.route('/api/arco/solicitudes_cancelacion_op/<int:sid>/resolver', methods=['POST'])
@verificar_certificado
def arco_resolver_cancelacion_op(sid):
    if g.rol != 'coord':
        abort(403)
    aprobar = request.form.get('accion') == 'aprobar'
    db = obtener_db()
    sol = _exec(db,
        'SELECT s.*, m.folio, m.nombre AS migrante_nombre'
        ' FROM solicitudes_cancelacion_op s'
        ' JOIN migrantes m ON m.id=s.migrante_id WHERE s.id=%s', (sid,)
    ).fetchone()
    if not sol:
        return jsonify(error='Solicitud no encontrada'), 404
    if sol['estado'] != 'pendiente':
        return jsonify(error='La solicitud ya fue resuelta'), 400

    ahora = datetime.utcnow().strftime('%Y-%m-%d %H:%M:%S')
    nuevo_estado = 'aprobada' if aprobar else 'rechazada'
    _exec(db,
        'UPDATE solicitudes_cancelacion_op'
        ' SET estado=%s, resuelto_por=%s, resuelto_en=%s WHERE id=%s',
        (nuevo_estado, g.usuario, ahora, sid)
    )

    if aprobar:
        # Genera firma EC del coordinador
        priv_pem, pub_pem = _obtener_o_crear_claves_ec(db, g.usuario)
        mensaje = (f"CANCELACION|{sol['migrante_id']}|{sol['folio']}"
                   f"|{sol['solicitado_por']}|{g.usuario}|{ahora}|{sol['motivo'] or ''}")
        firma = firmar_mensaje(priv_pem, mensaje)
        # Verifica que la firma sea válida antes de guardar
        if not verificar_firma(pub_pem, mensaje, firma):
            db.rollback()
            return jsonify(error='Error al generar firma digital'), 500
        # Escala a solicitudes_eliminacion para admin
        _exec(db,
            'INSERT INTO solicitudes_eliminacion'
            ' (migrante_id, solicitado_por, motivo, solicitante_op, firma_coord, coord_pubkey, mensaje_firmado)'
            ' VALUES (%s,%s,%s,%s,%s,%s,%s)',
            (sol['migrante_id'], g.usuario, sol['motivo'],
             sol['solicitado_por'], firma, pub_pem, mensaje)
        )
        db.commit()
        log_evento('arco_cancel_coord_firmada', usuario=g.usuario, rol=g.rol,
                   detalle=f'Cancelación firmada y escalada al admin: {sol["migrante_nombre"]} · Folio: {sol["folio"]}')
    else:
        db.commit()
        log_evento('arco_cancel_coord_rechazada', usuario=g.usuario, rol=g.rol,
                   detalle=f'Cancelación rechazada por coord: {sol["migrante_nombre"]}')

    return jsonify(ok=True, accion=nuevo_estado)


# Admin: verificar firma EC de una solicitud de eliminación
@app.route('/api/arco/verificar_firma/<int:sol_id>')
@verificar_certificado
def arco_verificar_firma(sol_id):
    if g.rol != 'admin':
        abort(403)
    db = obtener_db()
    sol = _exec(db,
        'SELECT firma_coord, coord_pubkey, mensaje_firmado, solicitado_por, solicitante_op'
        ' FROM solicitudes_eliminacion WHERE id=%s', (sol_id,)
    ).fetchone()
    if not sol:
        return jsonify(error='Solicitud no encontrada'), 404
    if not sol['firma_coord']:
        return jsonify(firmada=False, mensaje='Esta solicitud no tiene firma digital')
    valida = verificar_firma(sol['coord_pubkey'], sol['mensaje_firmado'], sol['firma_coord'])
    return jsonify(
        firmada=True,
        valida=valida,
        coord=sol['solicitado_por'],
        solicitante_op=sol['solicitante_op'],
        mensaje=sol['mensaje_firmado'],
        pubkey=sol['coord_pubkey'],
    )


# Coordinador: cancelación directa propia (sin escalado de op) — mantiene flujo existente
@app.route('/api/arco/cancelacion/<int:mid>', methods=['POST'])
@verificar_certificado
def arco_cancelacion(mid):
    if g.rol != 'coord':
        abort(403)
    db = obtener_db()
    mig = _exec(db, 'SELECT nombre, folio FROM migrantes WHERE id=%s', (mid,)).fetchone()
    if not mig:
        return jsonify(error='Migrante no encontrado'), 404
    pendiente = _exec(db,
        "SELECT id FROM solicitudes_eliminacion WHERE migrante_id=%s AND estado='pendiente'",
        (mid,)
    ).fetchone()
    if pendiente:
        return jsonify(error='Ya existe una solicitud de cancelación pendiente'), 400
    motivo = request.form.get('motivo', '').strip() or None
    priv_pem, pub_pem = _obtener_o_crear_claves_ec(db, g.usuario)
    ahora = datetime.utcnow().strftime('%Y-%m-%d %H:%M:%S')
    mensaje = (f"CANCELACION|{mid}|{mig['folio']}"
               f"||{g.usuario}|{ahora}|{motivo or ''}")
    firma = firmar_mensaje(priv_pem, mensaje)
    _exec(db,
        'INSERT INTO solicitudes_eliminacion'
        ' (migrante_id, solicitado_por, motivo, firma_coord, coord_pubkey, mensaje_firmado)'
        ' VALUES (%s,%s,%s,%s,%s,%s)',
        (mid, g.usuario, motivo, firma, pub_pem, mensaje)
    )
    db.commit()
    log_evento('arco_cancel_coord_firmada', usuario=g.usuario, rol=g.rol,
               detalle=f'Cancelación directa firmada: {mig["nombre"]} · Folio: {mig["folio"]}')
    return jsonify(ok=True)


# ── ARRANQUE ──────────────────────────────────────────────────
inicializar_ca()
inicializar_cert_servidor()
inicializar_db()

if __name__ == '__main__':
    import ssl

    print('\n── Casa Monarca Demo ─────────────────────────────')
    if MTLS_ENABLED:
        print('   URL:      https://localhost:5001')
        print('   Modo:     TLS ACTIVO — solo cert de servidor (sin cliente)')
    else:
        print('   URL:      http://localhost:5001')
        print('   Modo:     DEBUG — TLS desactivado')
    print('   Usuario:  admin    Contraseña: admin123')
    print('   Usuario:  coord    Contraseña: coord123')
    print('─────────────────────────────────────────────────\n')

    if MTLS_ENABLED:
        ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        ctx.load_cert_chain(SERVER_CERT_PATH, SERVER_KEY_PATH)
        ctx.load_verify_locations(CA_CERT_PATH)

        # 🔴 Cambio: ya no exigimos certificado de cliente
        ctx.verify_mode = ssl.CERT_NONE

        app.run(
            debug=False,
            port=5001,
            ssl_context=ctx,
            host='127.0.0.1',
            threaded=True
        )
    else:
        app.run(debug=True, port=5001)
        