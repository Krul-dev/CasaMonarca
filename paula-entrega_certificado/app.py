"""
Correr:
    python app.py
Abrir en navegador:
    http://localhost:5000
"""
import io
import re
import secrets
import string
from functools import wraps
from datetime import datetime


from flask import (
    Flask, render_template, request, redirect,
    url_for, session, g, abort, send_file, jsonify
)
from flask_session import Session

# ── CONFIGURACIÓN ─────────────────────────────────────────────
app = Flask(__name__)
app.secret_key = 'demo-secret-key-cambiar-en-produccion'

app.config['SESSION_TYPE']      = 'filesystem'
app.config['SESSION_FILE_DIR']  = '/tmp/flask_sessions_demo'
app.config['SESSION_PERMANENT'] = False
Session(app)

# ── BASE DE DATOS EN MEMORIA (reemplaza MySQL) ─────────────────
# Cuando tengas MySQL, elimina este bloque y conecta el pool real.

USUARIOS_DEMO = {
    'admin': {'nombre': 'Administrador',   'rol': 'admin', 'password': 'admin123'},
    'coord': {'nombre': 'Coordinadora Demo','rol': 'coord', 'password': 'coord123'},
}

CERTIFICADOS = [
    {
        'id': 1,
        'serial': '4A2F88C1E3',
        'usuario': 'Ana García López',
        'rol': 'op',
        'fecha_emision': '2025-04-01',
        'activo': True,
    },
    {
        'id': 2,
        'serial': '3E1D77B2F9',
        'usuario': 'Luis Martínez',
        'rol': 'coord',
        'fecha_emision': '2025-03-22',
        'activo': False,
    },
    {
        'id': 3,
        'serial': '7C4A19D0B5',
        'usuario': 'Sofía Ramírez',
        'rol': 'op',
        'fecha_emision': '2025-03-10',
        'activo': True,
    },
]

proximo_id = 4   # contador para nuevos registros


# ── HELPERS ───────────────────────────────────────────────────
def generar_contrasena_temporal(longitud=12):
    chars = string.ascii_letters + string.digits + '!@#$'
    return ''.join(secrets.choice(chars) for _ in range(longitud))

def sanitizar_nombre(nombre):
    return re.sub(r'[^a-zA-ZáéíóúÁÉÍÓÚñÑüÜ0-9 \-]', '', nombre).strip()

def generar_serial_demo():
    return secrets.token_hex(5).upper()

def generar_p12_demo(nombre, rol, password):
    """
    Devuelve bytes ficticios que simulan un .p12.
    En producción aquí va la llamada real a cryptography/pkcs12.
    """
    contenido = f"DEMO_P12|nombre={nombre}|rol={rol}|pwd={password}|ts={datetime.utcnow().isoformat()}"
    return contenido.encode('utf-8')


# ── DECORADOR DE AUTENTICACIÓN ────────────────────────────────
def verificar_certificado(f):
    @wraps(f)
    def decorado(*args, **kwargs):
        if 'rol' not in session:
            return redirect(url_for('login'))
        g.rol    = session['rol']
        g.nombre = session.get('nombre', 'Usuario')
        return f(*args, **kwargs)
    return decorado


# ── RUTAS DE AUTENTICACIÓN ────────────────────────────────────
@app.route('/')
def index():
    return redirect(url_for('panel_admin'))

@app.route('/login', methods=['GET', 'POST'])
def login():
    error = None
    if request.method == 'POST':
        usuario = request.form.get('usuario', '').strip()
        pwd     = request.form.get('password', '')
        usuario_data = USUARIOS_DEMO.get(usuario)
        if usuario_data and usuario_data['password'] == pwd:
            session['rol']    = usuario_data['rol']
            session['nombre'] = usuario_data['nombre']
            return redirect(url_for('panel_admin'))
        error = 'Usuario o contraseña incorrectos'
    return render_template('login.html', error=error)

@app.route('/logout')
def logout():
    session.clear()
    return redirect(url_for('login'))


# ── PANEL PRINCIPAL ───────────────────────────────────────────
@app.route('/admin')
@verificar_certificado
def panel_admin():
    if g.rol != 'admin':
        abort(403)
    return render_template('admin.html', nombre=g.nombre, rol=g.rol)


# ── API: MÉTRICAS ─────────────────────────────────────────────
@app.route('/admin/api/metricas')
@verificar_certificado
def api_metricas():
    if g.rol != 'admin':
        abort(403)
    total     = len(CERTIFICADOS)
    activos   = sum(1 for c in CERTIFICADOS if c['activo'])
    revocados = total - activos
    return jsonify(total=total, activos=activos, revocados=revocados)


# ── API: LISTA DE CERTIFICADOS ────────────────────────────────
@app.route('/admin/api/certificados')
@verificar_certificado
def api_certificados():
    if g.rol != 'admin':
        abort(403)
    return jsonify(list(reversed(CERTIFICADOS)))


# ── EMITIR NUEVO CERTIFICADO ──────────────────────────────────
@app.route('/admin/nuevo_voluntario', methods=['POST'])
@verificar_certificado
def nuevo_voluntario():
    global proximo_id

    if g.rol != 'admin':
        abort(403)

    nombre = sanitizar_nombre(request.form.get('nombre', ''))
    rol    = request.form.get('rol', '')

    if not nombre:
        return jsonify(error='El nombre no puede estar vacío'), 400
    if rol not in ('op', 'coord', 'admin'):
        return jsonify(error='Rol inválido'), 400

    serial       = generar_serial_demo()
    pwd_temporal = generar_contrasena_temporal()
    p12_bytes    = generar_p12_demo(nombre, rol, pwd_temporal)
    fecha        = datetime.now().strftime('%Y-%m-%d')

    CERTIFICADOS.append({
        'id':            proximo_id,
        'serial':        serial,
        'usuario':       nombre,
        'rol':           rol,
        'fecha_emision': fecha,
        'activo':        True,
    })
    proximo_id += 1

    # Guardar en sesión server-side (one-time)
    session['pwd_temporal'] = pwd_temporal
    session['p12']          = p12_bytes
    session['p12_nombre']   = nombre

    return jsonify(ok=True, nombre=nombre, rol=rol)


# ── PANTALLA DE ENTREGA ───────────────────────────────────────
@app.route('/admin/entregar_certificado/<nombre>')
@verificar_certificado
def entregar_certificado(nombre):
    if g.rol != 'admin':
        abort(403)
    pwd = session.pop('pwd_temporal', None)
    p12 = session.pop('p12', None)
    return render_template('entregar_cert.html', nombre=nombre, pwd_temporal=pwd)


# ── DESCARGA DEL .p12 ─────────────────────────────────────────
@app.route('/admin/descargar_p12')
@verificar_certificado
def descargar_p12():
    if g.rol != 'admin':
        abort(403)

    p12_bytes = session.pop('p12', None)
    nombre    = session.pop('p12_nombre', 'certificado')

    if not p12_bytes:
        abort(404)

    nombre_seguro = re.sub(r'[^a-zA-Z0-9_\-]', '_', nombre)

    return send_file(
        io.BytesIO(p12_bytes),
        mimetype='application/x-pkcs12',
        as_attachment=True,
        download_name=f'{nombre_seguro}.p12',
    )


# ── REVOCAR CERTIFICADO ───────────────────────────────────────
@app.route('/admin/revocar/<int:cert_id>', methods=['POST'])
@verificar_certificado
def revocar_certificado(cert_id):
    if g.rol != 'admin':
        abort(403)

    cert = next((c for c in CERTIFICADOS if c['id'] == cert_id), None)
    if cert is None:
        return jsonify(error='Certificado no encontrado'), 404

    cert['activo'] = False
    return jsonify(ok=True)


# ── ARRANQUE ──────────────────────────────────────────────────
if __name__ == '__main__':
    import os
    os.makedirs('/tmp/flask_sessions_demo', exist_ok=True)
    print('\n── Casa Monarca Demo ─────────────────────────────')
    print('   URL:      http://localhost:5000')
    print('   Usuario:  admin    Contraseña: admin123')
    print('   Usuario:  coord    Contraseña: coord123')
    print('─────────────────────────────────────────────────\n')
    app.run(debug=True, port=5000)
