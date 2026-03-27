"""
Casa Monarca — Sistema de Gestion Segura de Identidades
MA2006B | Steffany Mishell Lara Muy | A00838589

App completa en Flask + MySQL + bcrypt.
Migrado de la version original en Streamlit.

Si no tienes MySQL, cambia USA_MYSQL = False para SQLite fallback.
"""

from flask import (
    Flask, render_template, request, redirect,
    url_for, session, flash, g,
)
import bcrypt
import uuid
from datetime import datetime
from functools import wraps

# ─── SWITCH: MySQL vs SQLite fallback ─────────────────
USA_MYSQL = False  # ← Cambia a True cuando tengas MySQL

if USA_MYSQL:
    import mysql.connector
else:
    import sqlite3
    from pathlib import Path

# ─────────────────────────────────────────────
# CONFIG
# ─────────────────────────────────────────────
app = Flask(__name__)
app.secret_key = "casa_monarca_secreto_2026"

MYSQL_CONFIG = {
    "host":     "localhost",
    "user":     "root",
    "password": "",               # tu password de MySQL
    "database": "casa_monarca",
}

SQLITE_PATH = "datos/casa_monarca.db"

ROLES = ["Admin", "Coordinador", "Operativo", "Externo"]

PERMISOS = {
    "Admin":       {"ver", "registrar", "subir", "editar",
                    "solicitar_baja", "aprobar_baja", "gestionar_usuarios"},
    "Coordinador": {"ver", "registrar", "subir", "editar", "solicitar_baja"},
    "Operativo":   {"ver", "registrar", "subir"},
    "Externo":     set(),
}

USUARIOS_POR_DEFECTO = [
    ("admin",  "Admin123!",  "Admin",       "Carlos Mendoza"),
    ("coord1", "Coord123!",  "Coordinador", "Laura Vega"),
    ("op1",    "Oper123!",   "Operativo",   "Miguel Torres"),
    ("ext1",   "Ext1234!",   "Externo",     "Ana Garcia"),
]

EXPEDIENTES_POR_DEFECTO = [
    ("EXP-001", "Juan Lopez Ramirez",    "Honduras",    "Activo"),
    ("EXP-002", "Maria Perez Gomez",     "Guatemala",   "Activo"),
    ("EXP-003", "Pedro Hernandez Silva", "El Salvador", "Activo"),
]


# ─────────────────────────────────────────────
# CAPA DE BASE DE DATOS
# ─────────────────────────────────────────────
def obtener_conexion():
    if USA_MYSQL:
        return mysql.connector.connect(**MYSQL_CONFIG)
    else:
        import os
        os.makedirs("datos", exist_ok=True)
        conn = sqlite3.connect(SQLITE_PATH)
        conn.row_factory = sqlite3.Row
        return conn


def ejecutar(query, params=(), fetch=False, fetchone=False):
    conn = obtener_conexion()
    if USA_MYSQL:
        cur = conn.cursor(dictionary=True)
        query = query.replace("?", "%s")
    else:
        cur = conn.cursor()

    cur.execute(query, params)

    resultado = None
    if fetchone:
        row = cur.fetchone()
        resultado = dict(row) if row and not USA_MYSQL else row
    elif fetch:
        rows = cur.fetchall()
        resultado = [dict(r) if not USA_MYSQL else r for r in rows]
    else:
        conn.commit()

    cur.close()
    conn.close()
    return resultado


def inicializar_bd():
    conn = obtener_conexion()
    cur = conn.cursor()

    if USA_MYSQL:
        temp = mysql.connector.connect(
            host=MYSQL_CONFIG["host"],
            user=MYSQL_CONFIG["user"],
            password=MYSQL_CONFIG["password"],
        )
        tc = temp.cursor()
        tc.execute(f"CREATE DATABASE IF NOT EXISTS {MYSQL_CONFIG['database']}")
        tc.close(); temp.close()
        conn = mysql.connector.connect(**MYSQL_CONFIG)
        cur = conn.cursor()

    t = "VARCHAR(500)" if USA_MYSQL else "TEXT"
    tid = "VARCHAR(36)" if USA_MYSQL else "TEXT"
    ai = "AUTO_INCREMENT" if USA_MYSQL else "AUTOINCREMENT"

    cur.execute(f"""CREATE TABLE IF NOT EXISTS usuarios (
        id {tid} PRIMARY KEY, nombre_usuario {t} UNIQUE NOT NULL,
        hash_contrasena {t} NOT NULL, rol {t} NOT NULL,
        nombre {t} NOT NULL, activo INTEGER DEFAULT 1, creado_en {t} NOT NULL
    )""")

    cur.execute(f"""CREATE TABLE IF NOT EXISTS log_auditoria (
        id INTEGER PRIMARY KEY {ai}, fecha {t} NOT NULL,
        actor {t} NOT NULL, accion {t} NOT NULL, detalle {t} DEFAULT ''
    )""")

    cur.execute(f"""CREATE TABLE IF NOT EXISTS expedientes (
        id {tid} PRIMARY KEY, nombre {t} NOT NULL,
        pais {t} NOT NULL, estatus {t} DEFAULT 'Activo'
    )""")

    cur.execute(f"""CREATE TABLE IF NOT EXISTS solicitudes_eliminacion (
        id {tid} PRIMARY KEY, id_expediente {tid} NOT NULL,
        etiqueta {t}, solicitado_por {t} NOT NULL,
        nombre_solicitante {t}, motivo {t},
        estatus {t} DEFAULT 'Pendiente',
        solicitado_en {t}, resuelto_por {t}, resuelto_en {t}
    )""")

    # Seed usuarios
    ph = "%s" if USA_MYSQL else "?"
    cur.execute("SELECT COUNT(*) FROM usuarios")
    cnt = cur.fetchone()[0] if not USA_MYSQL else cur.fetchone()[0]
    if cnt == 0:
        for usr, pw, rol, nombre in USUARIOS_POR_DEFECTO:
            h = bcrypt.hashpw(pw.encode(), bcrypt.gensalt()).decode()
            cur.execute(
                f"INSERT INTO usuarios (id,nombre_usuario,hash_contrasena,rol,nombre,activo,creado_en)"
                f" VALUES ({ph},{ph},{ph},{ph},{ph},1,{ph})",
                (str(uuid.uuid4()), usr, h, rol, nombre, datetime.now().isoformat()),
            )

    # Seed expedientes
    cur.execute("SELECT COUNT(*) FROM expedientes")
    cnt2 = cur.fetchone()[0] if not USA_MYSQL else cur.fetchone()[0]
    if cnt2 == 0:
        for eid, nom, pais, est in EXPEDIENTES_POR_DEFECTO:
            cur.execute(
                f"INSERT INTO expedientes (id,nombre,pais,estatus) VALUES ({ph},{ph},{ph},{ph})",
                (eid, nom, pais, est),
            )

    conn.commit(); cur.close(); conn.close()


# ─────────────────────────────────────────────
# HELPERS
# ─────────────────────────────────────────────
def autenticar(nombre_usuario, contrasena):
    u = ejecutar("SELECT * FROM usuarios WHERE nombre_usuario=? AND activo=1",
                 (nombre_usuario,), fetchone=True)
    if u and bcrypt.checkpw(contrasena.encode(), u["hash_contrasena"].encode()):
        return u
    return None


def registrar_auditoria(actor, accion, detalle=""):
    ejecutar("INSERT INTO log_auditoria (fecha,actor,accion,detalle) VALUES (?,?,?,?)",
             (datetime.now().strftime("%Y-%m-%d %H:%M:%S"), actor, accion, detalle))


def tiene_permiso(accion):
    rol = session.get("rol", "Externo")
    return accion in PERMISOS.get(rol, set())


def login_requerido(f):
    @wraps(f)
    def decorada(*args, **kwargs):
        if "nombre_usuario" not in session:
            flash("Inicia sesion primero.", "warning")
            return redirect(url_for("login"))
        return f(*args, **kwargs)
    return decorada


def permiso_requerido(accion):
    def dec(f):
        @wraps(f)
        def decorada(*args, **kwargs):
            if not tiene_permiso(accion):
                flash("No tienes permisos para esta seccion.", "danger")
                return redirect(url_for("dashboard"))
            return f(*args, **kwargs)
        return decorada
    return dec


@app.context_processor
def inyectar_helpers():
    return dict(tiene_permiso=tiene_permiso, PERMISOS=PERMISOS)


# ─────────────────────────────────────────────
# RUTAS
# ─────────────────────────────────────────────
@app.route("/")
def index():
    return redirect(url_for("dashboard") if "nombre_usuario" in session else url_for("login"))


# ── LOGIN ──
@app.route("/login", methods=["GET", "POST"])
def login():
    if "nombre_usuario" in session:
        return redirect(url_for("dashboard"))
    if request.method == "POST":
        usr = request.form.get("nombre_usuario", "").strip()
        pw  = request.form.get("contrasena", "").strip()
        if not usr or not pw:
            flash("Completa todos los campos.", "danger")
            return render_template("login.html")
        usuario = autenticar(usr, pw)
        if usuario:
            session["nombre_usuario"] = usuario["nombre_usuario"]
            session["nombre"]         = usuario["nombre"]
            session["rol"]            = usuario["rol"]
            registrar_auditoria(usr, "INICIO_SESION", f"Rol: {usuario['rol']}")
            return redirect(url_for("dashboard"))
        registrar_auditoria(usr, "INICIO_SESION_FALLIDO", "Credenciales incorrectas")
        flash("Usuario o contrasena incorrectos.", "danger")
    return render_template("login.html")


# ── DASHBOARD ──
@app.route("/dashboard")
@login_requerido
def dashboard():
    permisos_etiquetas = {
        "ver": "Ver expedientes", "registrar": "Registrar migrante",
        "subir": "Subir documentos", "editar": "Editar expediente",
        "solicitar_baja": "Solicitar eliminacion",
        "aprobar_baja": "Aprobar eliminaciones",
        "gestionar_usuarios": "Gestionar usuarios",
    }
    log = ejecutar("SELECT * FROM log_auditoria ORDER BY id DESC LIMIT 8", fetch=True) or []
    return render_template("dashboard.html",
        nombre=session["nombre"], rol=session["rol"],
        fecha=datetime.now().strftime("%d/%m/%Y %H:%M"),
        permisos_etiquetas=permisos_etiquetas,
        permisos_rol=PERMISOS.get(session["rol"], set()),
        log=log)


# ── EXPEDIENTES ──
@app.route("/expedientes")
@login_requerido
@permiso_requerido("ver")
def expedientes():
    exps = ejecutar("SELECT * FROM expedientes", fetch=True) or []
    return render_template("expedientes.html", expedientes=exps)


# ── SOLICITAR ELIMINACION (doble control) ──
@app.route("/solicitar-eliminacion", methods=["GET", "POST"])
@login_requerido
@permiso_requerido("solicitar_baja")
def solicitar_eliminacion():
    activos = ejecutar("SELECT * FROM expedientes WHERE estatus='Activo'", fetch=True) or []

    if request.method == "POST":
        id_exp = request.form.get("id_expediente", "")
        motivo = request.form.get("motivo", "").strip()
        if not motivo:
            flash("El motivo es obligatorio.", "danger")
            return render_template("solicitar_eliminacion.html", activos=activos)

        # Verificar si ya existe solicitud pendiente
        ya = ejecutar("SELECT 1 FROM solicitudes_eliminacion WHERE id_expediente=? AND estatus='Pendiente'",
                      (id_exp,), fetchone=True)
        if ya:
            flash("Ya existe una solicitud pendiente para ese expediente.", "warning")
            return render_template("solicitar_eliminacion.html", activos=activos)

        exp = ejecutar("SELECT * FROM expedientes WHERE id=?", (id_exp,), fetchone=True)
        etiqueta = f"{exp['id']} - {exp['nombre']}" if exp else id_exp

        ejecutar(
            "INSERT INTO solicitudes_eliminacion "
            "(id,id_expediente,etiqueta,solicitado_por,nombre_solicitante,motivo,estatus,solicitado_en,resuelto_por,resuelto_en)"
            " VALUES (?,?,?,?,?,?,?,?,?,?)",
            (str(uuid.uuid4()), id_exp, etiqueta, session["nombre_usuario"],
             session["nombre"], motivo, "Pendiente", datetime.now().isoformat(), None, None),
        )
        registrar_auditoria(session["nombre_usuario"], "SOLICITAR_ELIMINACION",
                            f"Expediente {id_exp} - motivo: {motivo[:60]}")
        flash("Solicitud enviada. Un administrador debe aprobarla.", "success")
        return redirect(url_for("solicitar_eliminacion"))

    return render_template("solicitar_eliminacion.html", activos=activos)


# ── APROBAR ELIMINACIONES ──
@app.route("/aprobar-eliminaciones")
@login_requerido
@permiso_requerido("aprobar_baja")
def aprobar_eliminaciones():
    todas = ejecutar("SELECT * FROM solicitudes_eliminacion ORDER BY solicitado_en DESC", fetch=True) or []
    pendientes = [s for s in todas if s["estatus"] == "Pendiente" and s["solicitado_por"] != session["nombre_usuario"]]
    propias    = [s for s in todas if s["estatus"] == "Pendiente" and s["solicitado_por"] == session["nombre_usuario"]]
    resueltas  = [s for s in todas if s["estatus"] != "Pendiente"]
    return render_template("aprobar_eliminaciones.html",
        pendientes=pendientes, propias=propias, resueltas=resueltas)


@app.route("/aprobar/<sol_id>")
@login_requerido
@permiso_requerido("aprobar_baja")
def aprobar(sol_id):
    sol = ejecutar("SELECT * FROM solicitudes_eliminacion WHERE id=?", (sol_id,), fetchone=True)
    if not sol or sol["estatus"] != "Pendiente":
        flash("Solicitud no encontrada.", "danger")
        return redirect(url_for("aprobar_eliminaciones"))
    if sol["solicitado_por"] == session["nombre_usuario"]:
        flash("No puedes aprobar tus propias solicitudes.", "danger")
        return redirect(url_for("aprobar_eliminaciones"))

    ejecutar("UPDATE expedientes SET estatus='Eliminado' WHERE id=?", (sol["id_expediente"],))
    ejecutar("UPDATE solicitudes_eliminacion SET estatus='Aprobada', resuelto_por=?, resuelto_en=? WHERE id=?",
             (session["nombre_usuario"], datetime.now().isoformat(), sol_id))
    registrar_auditoria(session["nombre_usuario"], "APROBAR_ELIMINACION",
                        f"Expediente {sol['id_expediente']} eliminado. Solicitado por @{sol['solicitado_por']}")
    flash("Solicitud aprobada. Expediente eliminado.", "success")
    return redirect(url_for("aprobar_eliminaciones"))


@app.route("/rechazar/<sol_id>")
@login_requerido
@permiso_requerido("aprobar_baja")
def rechazar(sol_id):
    sol = ejecutar("SELECT * FROM solicitudes_eliminacion WHERE id=?", (sol_id,), fetchone=True)
    if not sol or sol["estatus"] != "Pendiente":
        flash("Solicitud no encontrada.", "danger")
        return redirect(url_for("aprobar_eliminaciones"))

    ejecutar("UPDATE solicitudes_eliminacion SET estatus='Rechazada', resuelto_por=?, resuelto_en=? WHERE id=?",
             (session["nombre_usuario"], datetime.now().isoformat(), sol_id))
    registrar_auditoria(session["nombre_usuario"], "RECHAZAR_ELIMINACION",
                        f"Solicitud de @{sol['solicitado_por']} rechazada")
    flash("Solicitud rechazada.", "warning")
    return redirect(url_for("aprobar_eliminaciones"))


# ── GESTION DE USUARIOS ──
@app.route("/usuarios")
@login_requerido
@permiso_requerido("gestionar_usuarios")
def gestion_usuarios():
    usuarios = ejecutar("SELECT * FROM usuarios", fetch=True) or []
    return render_template("usuarios.html", usuarios=usuarios, roles=ROLES)


@app.route("/usuarios/crear", methods=["POST"])
@login_requerido
@permiso_requerido("gestionar_usuarios")
def crear_usuario():
    nombre = request.form.get("nombre", "").strip()
    usr    = request.form.get("nombre_usuario", "").strip()
    pw     = request.form.get("contrasena", "").strip()
    rol    = request.form.get("rol", "Operativo")
    if not all([nombre, usr, pw]):
        flash("Todos los campos son obligatorios.", "danger")
        return redirect(url_for("gestion_usuarios"))
    if ejecutar("SELECT 1 FROM usuarios WHERE nombre_usuario=?", (usr,), fetchone=True):
        flash("Ese nombre de usuario ya existe.", "danger")
        return redirect(url_for("gestion_usuarios"))
    h = bcrypt.hashpw(pw.encode(), bcrypt.gensalt()).decode()
    ejecutar("INSERT INTO usuarios (id,nombre_usuario,hash_contrasena,rol,nombre,activo,creado_en)"
             " VALUES (?,?,?,?,?,1,?)",
             (str(uuid.uuid4()), usr, h, rol, nombre, datetime.now().isoformat()))
    registrar_auditoria(session["nombre_usuario"], "CREAR_USUARIO", f"'{usr}' con rol {rol}")
    flash(f"Usuario '{nombre}' creado.", "success")
    return redirect(url_for("gestion_usuarios"))


@app.route("/usuarios/editar/<usr_id>", methods=["GET", "POST"])
@login_requerido
@permiso_requerido("gestionar_usuarios")
def editar_usuario(usr_id):
    objetivo = ejecutar("SELECT * FROM usuarios WHERE nombre_usuario=?", (usr_id,), fetchone=True)
    if not objetivo:
        flash("Usuario no encontrado.", "danger")
        return redirect(url_for("gestion_usuarios"))

    if request.method == "POST":
        nuevo_nombre = request.form.get("nombre", "").strip()
        nuevo_rol    = request.form.get("rol", objetivo["rol"])
        nuevo_activo = 1 if request.form.get("activo") else 0
        nueva_pw     = request.form.get("contrasena", "").strip()

        if nueva_pw:
            h = bcrypt.hashpw(nueva_pw.encode(), bcrypt.gensalt()).decode()
            ejecutar("UPDATE usuarios SET nombre=?, rol=?, activo=?, hash_contrasena=? WHERE nombre_usuario=?",
                     (nuevo_nombre, nuevo_rol, nuevo_activo, h, usr_id))
        else:
            ejecutar("UPDATE usuarios SET nombre=?, rol=?, activo=? WHERE nombre_usuario=?",
                     (nuevo_nombre, nuevo_rol, nuevo_activo, usr_id))

        registrar_auditoria(session["nombre_usuario"], "EDITAR_USUARIO",
                            f"'{usr_id}': nombre={nuevo_nombre}, rol={nuevo_rol}, activo={nuevo_activo}")
        flash("Usuario actualizado.", "success")
        return redirect(url_for("gestion_usuarios"))

    return render_template("editar_usuario.html", objetivo=objetivo, roles=ROLES)


# ── LOG DE AUDITORIA ──
@app.route("/log")
@login_requerido
@permiso_requerido("gestionar_usuarios")
def log_auditoria():
    registros = ejecutar("SELECT * FROM log_auditoria ORDER BY id DESC LIMIT 50", fetch=True) or []
    return render_template("log.html", registros=registros)


# ── MI PERFIL ──
@app.route("/perfil", methods=["GET", "POST"])
@login_requerido
def perfil():
    if request.method == "POST":
        nuevo_nombre = request.form.get("nombre", "").strip()
        nueva_pw     = request.form.get("contrasena", "").strip()
        if nueva_pw:
            h = bcrypt.hashpw(nueva_pw.encode(), bcrypt.gensalt()).decode()
            ejecutar("UPDATE usuarios SET nombre=?, hash_contrasena=? WHERE nombre_usuario=?",
                     (nuevo_nombre, h, session["nombre_usuario"]))
        else:
            ejecutar("UPDATE usuarios SET nombre=? WHERE nombre_usuario=?",
                     (nuevo_nombre, session["nombre_usuario"]))
        session["nombre"] = nuevo_nombre
        registrar_auditoria(session["nombre_usuario"], "ACTUALIZAR_PERFIL",
                            f"Nombre actualizado a '{nuevo_nombre}'")
        flash("Perfil actualizado.", "success")
        return redirect(url_for("perfil"))
    return render_template("perfil.html")


# ── LOGOUT ──
@app.route("/logout")
def logout():
    if "nombre_usuario" in session:
        registrar_auditoria(session["nombre_usuario"], "CERRAR_SESION", "")
    session.clear()
    flash("Sesion cerrada.", "info")
    return redirect(url_for("login"))


# ─────────────────────────────────────────────
if __name__ == "__main__":
    inicializar_bd()
    app.run(debug=True, port=5000)
