"""
emitir_cert_admin.py — Script de bootstrap con email

Problema del huevo y la gallina:
  Con mTLS activo, NADIE puede entrar al panel sin un cert de cliente.
  Pero el cert se emite DESDE el panel. ¿Cómo emites el primer cert?

Solución:
  Este script se corre UNA vez desde la terminal (no desde el navegador).
  Genera el .p12 del admin inicial y lo guarda en ./admin_bootstrap.p12.
  A partir de ahí, el admin ya puede entrar al panel y emitir certs
  para todos los demás voluntarios.

Uso:
    python emitir_cert_admin.py

Salida:
    admin_bootstrap.p12   (el .p12 del admin)
    admin_bootstrap.txt   (la contraseña temporal del .p12)
"""
import os
import base64
from datetime import datetime, timedelta

# Reutilizamos las funciones de app.py
import app

ADMIN_EMAIL = 'admin@casamonarca.org'
ADMIN_NOMBRE = 'Administrador'
ADMIN_ROL = 'admin'

def main():
    app.inicializar_ca()
    app.inicializar_db()

    # Buscar si el admin ya tiene un cert vigente
    import pymysql
    import pymysql.cursors
    con = pymysql.connect(
        host=app.DB_HOST, port=app.DB_PORT,
        user=app.DB_USER, password=app.DB_PASSWORD,
        database=app.DB_NAME,
        cursorclass=pymysql.cursors.DictCursor,
        autocommit=False
    )
    cur = con.cursor()
    cur.execute(
        "SELECT serial FROM certificados WHERE usuario=%s AND estado='vigente'"
        " ORDER BY id DESC LIMIT 1",
        (ADMIN_EMAIL,)
    )
    row = cur.fetchone()

    if row:
        print(f'\n[!] El admin ya tiene un cert vigente (serial {row["serial"]}).')
        print('    Si lo perdiste, revocalo primero desde MySQL:')
        print(f'    UPDATE certificados SET estado=\'revocado\''
              f' WHERE serial=\'{row["serial"]}\';')
        cur.close()
        con.close()
        return

    # Generar cert + p12
    pwd_temporal = app.generar_contrasena_temporal()
    p12_bytes, serial = app.generar_p12_real(ADMIN_EMAIL, ADMIN_NOMBRE, ADMIN_ROL, pwd_temporal)

    fecha = datetime.now().strftime('%Y-%m-%d')
    fecha_exp = (datetime.utcnow() + timedelta(days=365)).strftime('%Y-%m-%d %H:%M:%S')

    cur.execute(
        'INSERT INTO certificados'
        ' (serial, usuario, nombre, rol, fecha_emision, fecha_expiracion, estado, emitido_por)'
        ' VALUES (%s,%s,%s,%s,%s,%s,%s,%s)',
        (serial, ADMIN_EMAIL, ADMIN_NOMBRE, ADMIN_ROL, fecha, fecha_exp, 'vigente', 'bootstrap')
    )
    cur.execute(
        'UPDATE usuarios SET serial_cert=%s WHERE usuario=%s',
        (serial, ADMIN_EMAIL)
    )
    con.commit()
    cur.close()
    con.close()

    # Guardar el .p12 y la contraseña
    with open('admin_bootstrap.p12', 'wb') as f:
        f.write(p12_bytes)
    
    with open('admin_bootstrap.txt', 'w', encoding='utf-8') as f:
        f.write(f'Email:         {ADMIN_EMAIL}\n'
                f'Password:      admin123\n'
                f'Serial cert:   {serial}\n'
                f'Password .p12: {pwd_temporal}\n\n'
                f'Pasos:\n'
                f'  1. Importar admin_bootstrap.p12 en Chrome/Firefox\n'
                f'     (Configuracion > Privacidad > Gestionar certificados > Importar)\n'
                f'  2. Usar la password del .p12 que aparece arriba.\n'
                f'  3. Importar ca/ca_cert.pem como CA de confianza.\n'
                f'  4. Abrir https://localhost:5001 y seleccionar el cert cuando Chrome pregunte.\n'
                f'  5. Login con:\n'
                f'     Email:    {ADMIN_EMAIL}\n'
                f'     Password: admin123\n')

    print('\n[OK] Certificado de admin emitido.')
    print(f'  * Archivo:  admin_bootstrap.p12')
    print(f'  * Email:    {ADMIN_EMAIL}')
    print(f'  * Password: {pwd_temporal}')
    print(f'  * Serial:   {serial}')
    print(f'  * Instrucciones: admin_bootstrap.txt')
    print('\nSiguiente paso: importar admin_bootstrap.p12 en tu navegador.')

if __name__ == '__main__':
    main()