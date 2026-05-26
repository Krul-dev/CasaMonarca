# Casa Monarca · Sistema de certificados con mTLS real

Sistema Flask con **autenticación mutua TLS (mTLS)**: el servidor exige
que el navegador presente un certificado X.509 firmado por la CA de Casa
Monarca ANTES de mostrar cualquier página. Aunque alguien conozca la
contraseña, no puede entrar sin el `.p12` instalado.

---

## 1. Setup inicial (una sola vez)

### 1.1 Dependencias

```bash
cd paula-entrega_certificado
pip install flask flask-session cryptography werkzeug
```

### 1.2 Primera inicialización (sin mTLS) — genera CA y DB

```bash
MTLS_ENABLED=0 python app.py
# Ctrl+C en cuanto veas "Running on http://127.0.0.1:5001"
```

Esto crea:
- `ca/ca_cert.pem` + `ca/ca_key.pem` — autoridad certificadora.
- `ca/server.pem` + `ca/server.key` — cert del servidor.
- `db/casa_monarca.db` — base de datos SQLite.

### 1.3 Emitir el cert del admin inicial

```bash
python emitir_cert_admin.py
```

Genera:
- `admin_bootstrap.p12` — cert del admin.
- `admin_bootstrap.txt` — contraseña del `.p12` y pasos.

Lee el `.txt`:
```bash
cat admin_bootstrap.txt
```

### 1.4 Instalar los certificados en tu navegador

**Chrome (Mac):**
1. Abre **Keychain Access**.
2. Arrastra `admin_bootstrap.p12` al llavero "login". Ingresa la pwd del `.txt`.
3. Arrastra `ca/ca_cert.pem` al llavero "System".
4. Doble click en "CA Casa Monarca" → pestaña **Trust** → "Always Trust".

**Firefox:**
1. Settings → Privacy & Security → Certificates → View Certificates.
2. **Your Certificates** → Import → `admin_bootstrap.p12` + pwd.
3. **Authorities** → Import → `ca/ca_cert.pem` → marca "Trust to identify websites".

### 1.5 Arrancar con mTLS

```bash
python app.py
```

Verás:
```
URL:      https://localhost:5001
Modo:     mTLS ACTIVO — requiere cert de cliente
```

### 1.6 Entrar al sistema

1. Abre `https://localhost:5001`.
2. El navegador pregunta qué cert usar → elige **"Administrador"**.
3. Login con `admin` / `admin123`.

---

## 2. Demo para el maestro

### Prueba A — Emitir cert para voluntario y entrar con él

1. Panel admin → **Nuevo voluntario** → nombre "Juanito", rol "admin".
2. Anota la contraseña temporal (solo se ve una vez).
3. Descarga `Juanito.p12`.
4. Instálalo en el navegador (paso 1.4).
5. Cierra sesión, recarga → el navegador pregunta qué cert → elige "Juanito".
6. Login con `juanito` + pwd temporal.
7. Te obliga a cambiar pwd.
8. ✅ Entras como juanito.

### Prueba B — Revocar y verificar el bloqueo

1. Vuelve como admin.
2. **Certificados emitidos** → botón "Revocar" en la fila de Juanito.
3. Cierra sesión.
4. Recarga eligiendo el cert de Juanito.
5. ✅ El servidor responde **403 Certificado revocado** antes del login.

### Prueba C — Sin cert, el sitio no carga

1. En un navegador sin cert instalado, entra a `https://localhost:5001`.
2. ✅ Chrome: **ERR_BAD_SSL_CLIENT_AUTH_CERT**. Ni ves el login.

Esto es la sección 2.4 del reporte: doble verificación (dispositivo + usuario).

---

## 3. Configuración

**Desactivar mTLS temporalmente:**
```bash
MTLS_ENABLED=0 python app.py
```

**Cambiar la pwd de la CA:**
```bash
export CA_PASSWORD="una-pwd-fuerte"
python app.py
```
⚠️ Si la cambias después de generada la CA, hay que regenerar todo.

---

## 4. Troubleshooting

**"Address already in use" en puerto 5001**
macOS AirPlay no usa el 5001, pero por si acaso cambia la línea
`app.run(..., port=5001)` en `app.py`.

**"NOT NULL constraint failed"**
DB vieja. Borra todo y arranca limpio:
```bash
rm -rf db/casa_monarca.db flask_sessions_demo/* ca/*.pem ca/*.key admin_bootstrap.*
MTLS_ENABLED=0 python app.py  # Ctrl+C
python emitir_cert_admin.py
python app.py
```

**"ERR_CERT_AUTHORITY_INVALID"**
No instalaste `ca/ca_cert.pem` como CA de confianza. Ver paso 1.4.

**El navegador no pregunta por certificado**
- Chrome Mac: el `.p12` debe estar en Keychain Access con "Always Trust".
- Firefox: usa su propio almacén, en Settings → Certificates.

**"Certificado revocado" sin haberlo revocado**
El cert que presentaste ya está revocado en DB. Revisa:
```bash
python -c "
import sqlite3
con = sqlite3.connect('db/casa_monarca.db')
for r in con.execute('SELECT serial, usuario, estado FROM certificados'):
    print(r)
"
```

---

## 5. Migrar a MySQL

```bash
mysql -u root -p < db/schema_mysql.sql
```

En `app.py`: cambia `sqlite3.connect(DB_PATH)` por `pymysql.connect(...)` y `?` por `%s`.

---

## 6. Estructura

```
paula-entrega_certificado/
├── app.py                       ← rutas + middleware mTLS
├── emitir_cert_admin.py         ← bootstrap (correr 1 vez)
├── README.md
├── ca/                          ← auto-generado
│   ├── ca_cert.pem              ← instalar en navegadores
│   ├── ca_key.pem
│   ├── server.pem
│   └── server.key
├── db/
│   ├── schema.sql
│   ├── schema_mysql.sql
│   └── casa_monarca.db          ← auto-generado
├── flask_sessions_demo/
├── static/
│   ├── css/admin.css
│   └── img/logo.png
└── templates/
    ├── admin.html
    ├── cambiar_pwd.html
    ├── entregar_cert.html
    └── login.html
```
