"""
generar_admin_p12.py
────────────────────
Corre este script UNA SOLA VEZ para generar el .p12 del administrador
y registrar su serial en la base de datos.

Uso:
    python generar_admin_p12.py

Requisitos:
    - Las variables de entorno DB_HOST, DB_PORT, DB_USER, DB_PASSWORD,
      DB_NAME y CA_PASSWORD deben estar configuradas (igual que en Railway).
    - Los archivos ca/ca_cert.pem y ca/ca_key.pem deben existir.

Resultado:
    - Archivo admin.p12 en el directorio actual (guárdalo en lugar seguro).
    - Serial registrado en la tabla certificados de la DB.
    - Se imprime la contraseña del .p12 en pantalla (anótala).
"""

import os
import sys
import secrets
import string
import pymysql
import pymysql.cursors
from datetime import datetime, timedelta

from cryptography import x509
from cryptography.x509.oid import NameOID
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import ec
from cryptography.hazmat.primitives.serialization import pkcs12

# ── Configuración (mismas variables que app.py) ───────────────
CA_DIR       = os.path.join(os.getcwd(), 'ca')
CA_CERT_PATH = os.path.join(CA_DIR, 'ca_cert.pem')
CA_KEY_PATH  = os.path.join(CA_DIR, 'ca_key.pem')
CA_PASSWORD  = os.environ.get('CA_PASSWORD', 'demo-ca-pwd-cambiar').encode('utf-8')

DB_HOST     = os.environ.get('DB_HOST', 'localhost')
DB_PORT     = int(os.environ.get('DB_PORT', '3306'))
DB_USER     = os.environ.get('DB_USER', 'root')
DB_PASSWORD = os.environ.get('DB_PASSWORD', 'nueva123')
DB_NAME     = os.environ.get('DB_NAME', 'casa_monarca')

# ── Datos del admin ───────────────────────────────────────────
# ADMIN_EMAIL  = 'admin@casamonarca.org'
# ADMIN_NOMBRE = 'Administrador Principal'
# ADMIN_ROL    = 'admin'
ADMIN_EMAIL  = 'respaldo@casamonarca.org'
ADMIN_NOMBRE = 'Admin Respaldo'
ADMIN_ROL    = 'admin'

def cargar_ca():
    with open(CA_CERT_PATH, 'rb') as f:
        ca_cert = x509.load_pem_x509_certificate(f.read())
    with open(CA_KEY_PATH, 'rb') as f:
        ca_key = serialization.load_pem_private_key(f.read(), password=CA_PASSWORD)
    return ca_cert, ca_key


def generar_password(longitud=16):
    chars = string.ascii_letters + string.digits + '!@#$%'
    return ''.join(secrets.choice(chars) for _ in range(longitud))


def generar_p12_admin(password: str):
    ca_cert, ca_key = cargar_ca()

    # EC P-256 — mismo algoritmo que usa app.py ahora
    private_key = ec.generate_private_key(ec.SECP256R1())

    subject = x509.Name([
        x509.NameAttribute(NameOID.COUNTRY_NAME, 'MX'),
        x509.NameAttribute(NameOID.ORGANIZATION_NAME, 'Casa Monarca'),
        x509.NameAttribute(NameOID.ORGANIZATIONAL_UNIT_NAME, ADMIN_ROL),
        x509.NameAttribute(NameOID.COMMON_NAME, ADMIN_NOMBRE),
        x509.NameAttribute(NameOID.EMAIL_ADDRESS, ADMIN_EMAIL),
    ])

    cert = (
        x509.CertificateBuilder()
        .subject_name(subject)
        .issuer_name(ca_cert.subject)
        .public_key(private_key.public_key())
        .serial_number(x509.random_serial_number())
        .not_valid_before(datetime.utcnow() - timedelta(minutes=1))
        .not_valid_after(datetime.utcnow() + timedelta(days=365))
        .add_extension(x509.BasicConstraints(ca=False, path_length=None), critical=True)
        .add_extension(
            x509.SubjectAlternativeName([x509.RFC822Name(ADMIN_EMAIL)]),
            critical=False
        )
        .sign(ca_key, hashes.SHA256())
    )

    p12_bytes = pkcs12.serialize_key_and_certificates(
        name=ADMIN_NOMBRE.encode('utf-8'),
        key=private_key,
        cert=cert,
        cas=[ca_cert],
        encryption_algorithm=serialization.BestAvailableEncryption(password.encode('utf-8'))
    )

    serial_hex = format(cert.serial_number, 'X').upper()
    return p12_bytes, serial_hex


def registrar_en_db(serial: str):
    con = pymysql.connect(
        host=DB_HOST, port=DB_PORT,
        user=DB_USER, password=DB_PASSWORD,
        database=DB_NAME,
        cursorclass=pymysql.cursors.DictCursor,
        autocommit=False
    )
    cur = con.cursor()

    # Verificar si ya existe un serial activo para este admin
    cur.execute(
        "SELECT id FROM certificados WHERE usuario=%s AND estado='vigente'",
        (ADMIN_EMAIL,)
    )
    existente = cur.fetchone()
    if existente:
        print('\n⚠  Ya existe un certificado vigente para este admin en la DB.')
        respuesta = input('   ¿Deseas revocar el anterior y crear uno nuevo? (s/N): ').strip().lower()
        if respuesta != 's':
            print('Operación cancelada.')
            con.close()
            sys.exit(0)
        cur.execute(
            "UPDATE certificados SET estado='revocado' WHERE usuario=%s AND estado='vigente'",
            (ADMIN_EMAIL,)
        )
        con.commit()
        print('   Certificado anterior revocado.')

    fecha     = datetime.now().strftime('%Y-%m-%d')
    fecha_exp = (datetime.now() + timedelta(days=365)).strftime('%Y-%m-%d %H:%M:%S')

    cur.execute(
        'INSERT INTO certificados'
        ' (serial, usuario, nombre, rol, fecha_emision, fecha_expiracion, estado, emitido_por)'
        ' VALUES (%s,%s,%s,%s,%s,%s,%s,%s)',
        (serial, ADMIN_EMAIL, ADMIN_NOMBRE, ADMIN_ROL, fecha, fecha_exp, 'vigente', 'bootstrap')
    )

    # Actualizar serial_cert en tabla usuarios
    cur.execute(
        'UPDATE usuarios SET serial_cert=%s WHERE usuario=%s',
        (serial, ADMIN_EMAIL)
    )

    con.commit()
    cur.close()
    con.close()


def main():
    print('\n══════════════════════════════════════════')
    print('  Generador de credenciales — Admin')
    print('══════════════════════════════════════════\n')

    # Verificar que existan los archivos de CA
    if not os.path.exists(CA_CERT_PATH) or not os.path.exists(CA_KEY_PATH):
        print('✖  No se encontraron los archivos de CA.')
        print(f'   Esperados en: {CA_DIR}')
        sys.exit(1)

    pwd = generar_password()
    print(f'  Email:      {ADMIN_EMAIL}')
    print(f'  Nombre:     {ADMIN_NOMBRE}')
    print(f'  Validez:    365 días\n')

    print('  Generando certificado EC P-256...')
    p12_bytes, serial = generar_p12_admin(pwd)
    print(f'  Serial:     {serial}')

    print('  Registrando serial en la base de datos...')
    registrar_en_db(serial)

    # Guardar el .p12
    nombre_archivo = 'admin.p12'
    with open(nombre_archivo, 'wb') as f:
        f.write(p12_bytes)

    print('\n══════════════════════════════════════════')
    print('  ✔  Listo')
    print('══════════════════════════════════════════')
    print(f'\n  Archivo:    {nombre_archivo}')
    print(f'  Contraseña: {pwd}')
    print('\n  ⚠  Guarda esta contraseña en un lugar seguro.')
    print('     No se puede recuperar si la pierdes.\n')


if __name__ == '__main__':
    main()
