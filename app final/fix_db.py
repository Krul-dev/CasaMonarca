"""
Script de corrección de base de datos.
Corre una sola vez para arreglar la columna genero (ENUM → VARCHAR)
y asegurar que todas las columnas del nuevo formulario tengan el tipo correcto.

Uso:
    python fix_db.py
    (o con contraseña: $env:DB_PASSWORD="nueva123"; python fix_db.py)
"""
import os
import pymysql

con = pymysql.connect(
    host=os.environ.get('DB_HOST', 'localhost'),
    port=int(os.environ.get('DB_PORT', '3306')),
    user=os.environ.get('DB_USER', 'root'),
    password=os.environ.get('DB_PASSWORD', ''),
    database=os.environ.get('DB_NAME', 'casa_monarca'),
    cursorclass=pymysql.cursors.DictCursor,
)

arreglos = [
    ("genero",             "VARCHAR(30)"),
    ("departamento_estado","VARCHAR(100)"),
    ("estado_civil",       "VARCHAR(30)"),
    ("grupo_poblacion",    "VARCHAR(80)"),
    ("telefono_contacto",  "VARCHAR(32)"),
    ("fecha_atencion",     "DATE"),
]

cur = con.cursor()
for col, tipo in arreglos:
    try:
        cur.execute(f"ALTER TABLE migrantes MODIFY COLUMN `{col}` {tipo}")
        con.commit()
        print(f"  OK  {col} → {tipo}")
    except Exception as e:
        print(f"  --  {col}: {e}")

# Verificar resultado
cur.execute("DESCRIBE migrantes")
rows = cur.fetchall()
print("\nEsquema actual de migrantes:")
for r in rows:
    print(f"  {r['Field']:25s}  {r['Type']}")

cur.close()
con.close()
print("\nListo.")
