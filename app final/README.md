README.txt
===============================================================================
PROYECTO: Casa Monarca - Sistema de certificados, firmas digitales y control de acceso
===============================================================================

1. NOMBRE DEL PROYECTO
----------------------
Casa Monarca - Sistema de certificados, firmas digitales y control de acceso
Reto: "Firmas solidarias: Tecnología criptográfica para los derechos humanos".

Este proyecto fue desarrollado como solución académica para apoyar procesos de una
organización que atiende a personas migrantes, cuidando la seguridad de datos,
la trazabilidad de operaciones y el cumplimiento de flujos relacionados con
integridad, autenticidad, no repudio y Derechos ARCO.

2. DESARROLLADORES Y CONTACTO
-----------------------------
Equipo 3:

- A00839082 - Paola Michelle Martínez Galeazzi
- A00838589 - Steffany Mishell Lara Muy
- A01424357 - Patricio Mourra Cossio
- A00838606 - Santiago Dueñas Sánchez
- A00840131 - María Paula Recinos Ríos
- A01198777 - Andrés Alarcón Navarro

Contacto del proyecto:
- Responsable / representante: Santiago Dueñas Sánchez
- Correo de contacto: [agregar correo institucional del representante]
- Institución: Tecnológico de Monterrey

Nota: antes de entregar, sustituir el campo de correo por el correo real que el
equipo decida usar como contacto oficial.

3. DESCRIPCIÓN BREVE DEL PROYECTO
---------------------------------
La aplicación es un sistema web desarrollado en Python con Flask para administrar
usuarios, certificados digitales, registros de migrantes, solicitudes de
eliminación/cancelación, solicitudes de rectificación y eventos de auditoría.

El sistema implementa autenticación por usuario y contraseña, validación de
certificados .p12 para roles de mayor privilegio, emisión y revocación de
certificados, firmas digitales ECDSA P-256 para operaciones sensibles y control
de acceso por rol. También permite consultar datos, generar reportes en Excel y
mantener bitácoras de actividad.

4. CARACTERÍSTICAS PRINCIPALES
------------------------------
- Aplicación web con Flask.
- Base de datos MySQL/MariaDB para usuarios, certificados, migrantes, solicitudes
  y logs.
- Roles de usuario:
  * admin: administración general, usuarios, certificados, reportes y solicitudes.
  * coord: aprobación de registros, solicitudes y operaciones firmadas.
  * op: captura/validación operativa y solicitudes ARCO.
  * voluntario: captura inicial de información.
- Autenticación con contraseña protegida mediante hash.
- Bloqueo de cuentas por múltiples intentos fallidos.
- Certificados X.509 en formato .p12 emitidos por una CA interna del proyecto.
- Validación de certificados por archivo .p12 para usuarios admin y coord.
- Revocación y expiración de certificados.
- Firma digital con ECDSA P-256 para operaciones sensibles.
- Verificación de firmas digitales desde el panel administrativo.
- Registro y aprobación de datos de migrantes mediante flujo por etapas.
- Módulo de Derechos ARCO:
  * Acceso
  * Rectificación
  * Cancelación / eliminación
- Bitácora de auditoría de accesos, cambios, solicitudes y eventos relevantes.
- Descarga de reportes en Excel.
- Paneles HTML/CSS para administración y operación.

5. REQUISITOS DE INSTALACIÓN
----------------------------
Software recomendado:

- Python 3.9 o superior
- pip
- MySQL 8.0 o MariaDB 10.5 o superior
- Navegador web actualizado
- Git, si se va a clonar el repositorio

Dependencias principales de Python:

- Flask
- Flask-Session
- pymysql
- cryptography
- openpyxl

Estas dependencias están declaradas en:

app final/requirements.txt

6. INSTALACIÓN
--------------
Opción A: si se descarga el ZIP del proyecto

1. Descomprimir el archivo del proyecto.
2. Entrar a la carpeta principal.
3. Abrir una terminal dentro de la carpeta:

   app final

4. Crear un entorno virtual:

   En Windows PowerShell:
   python -m venv .venv
   .\.venv\Scripts\activate

   En macOS/Linux:
   python3 -m venv .venv
   source .venv/bin/activate

5. Instalar dependencias:

   pip install -r requirements.txt

6. Crear la base de datos vacía en MySQL:

   CREATE DATABASE IF NOT EXISTS casa_monarca
     CHARACTER SET utf8mb4
     COLLATE utf8mb4_unicode_ci;

7. Configurar variables de entorno.

   En Windows PowerShell:

   $env:DB_HOST="localhost"
   $env:DB_PORT="3306"
   $env:DB_USER="root"
   $env:DB_PASSWORD="tu_contraseña"
   $env:DB_NAME="casa_monarca"
   $env:CA_PASSWORD="demo-ca-pwd-cambiar"

   En macOS/Linux:

   export DB_HOST=localhost
   export DB_PORT=3306
   export DB_USER=root
   export DB_PASSWORD=tu_contraseña
   export DB_NAME=casa_monarca
   export CA_PASSWORD=demo-ca-pwd-cambiar

8. Ejecutar la aplicación:

   python app.py

9. Abrir en el navegador:

   http://localhost:5001

Opción B: si se clona desde GitHub

1. Clonar el repositorio:

   git clone [URL_DEL_REPOSITORIO]
   cd reto-casa-monarca-main
   cd "app final"

2. Continuar desde el paso 4 de la Opción A.

7. CONFIGURACIÓN
----------------
La aplicación usa variables de entorno para conectar con la base de datos y
configurar componentes criptográficos.

Variables principales:

- DB_HOST: servidor de base de datos. Ejemplo: localhost
- DB_PORT: puerto de MySQL. Ejemplo: 3306
- DB_USER: usuario de MySQL. Ejemplo: root
- DB_PASSWORD: contraseña del usuario de MySQL
- DB_NAME: nombre de la base de datos. Ejemplo: casa_monarca
- CA_PASSWORD: contraseña usada para proteger la llave privada de la CA
- PORT: puerto de ejecución de Flask. Por defecto: 5001
- MTLS_ENABLED: variable histórica del proyecto. En la versión actual la
  validación fuerte para admin/coord se realiza mediante carga del archivo .p12
  en la ruta /login_usb.

Archivos importantes de configuración:

- app final/app.py
  Archivo principal de la aplicación.

- app final/db/schema_mysql.sql
  Script base para crear tablas en MySQL/MariaDB.

- app final/db/SETUP_BASE_DE_DATOS.md
  Guía detallada para recrear la base de datos.

- app final/requirements.txt
  Dependencias de Python.

Advertencia de seguridad:
No se deben subir a repositorios públicos archivos reales como ca_key.pem,
certificados .p12, bases de datos con información sensible ni carpetas de sesión.
Los archivos incluidos en este proyecto son de demostración académica.

8. USO BÁSICO
-------------
1. Iniciar la aplicación:

   python app.py

2. Abrir el navegador en:

   http://localhost:5001

3. Iniciar sesión con un usuario registrado.

Usuarios demo definidos en el código:

- admin@casamonarca.org
- coord@casamonarca.org
- respaldo@casamonarca.org

Las contraseñas demo están definidas en la sección USUARIOS_DEMO de app.py.
Antes de una entrega formal se recomienda cambiarlas y no publicar contraseñas
en el repositorio.

4. Flujo de acceso:

- El usuario escribe correo y contraseña.
- Si el rol es admin o coord, el sistema solicita además un archivo .p12.
- El sistema valida que el certificado corresponda al usuario y que no esté
  revocado o expirado.
- Si el usuario debe cambiar contraseña, se redirige a la pantalla de cambio.
- Después del acceso, el usuario entra al panel correspondiente a su rol.

5. Flujo general de operación:

- Un voluntario u operador captura información inicial de una persona migrante.
- El operador valida o rechaza solicitudes pendientes.
- El coordinador aprueba, rechaza o firma operaciones sensibles.
- El administrador supervisa usuarios, certificados, auditoría, reportes y
  solicitudes críticas.
- Las operaciones sensibles se registran en bitácora.

6. Ejemplos de uso:

- Crear un usuario desde el panel de administrador.
- Emitir o descargar un certificado .p12 para un usuario.
- Revocar un certificado para impedir su uso posterior.
- Registrar una persona migrante.
- Solicitar rectificación de datos.
- Solicitar cancelación/eliminación con firma digital.
- Verificar una firma digital desde el panel administrativo.
- Descargar reportes en Excel de migrantes, eliminaciones o logs.

9. ESTRUCTURA DEL PROYECTO
--------------------------
Estructura principal:

reto-casa-monarca-main/
|
|-- README.md
|
|-- app final/
    |
    |-- app.py
    |   Archivo principal de Flask. Contiene configuración, rutas, autenticación,
    |   control por roles, generación de certificados, firmas digitales,
    |   operaciones ARCO, reportes y lógica de base de datos.
    |
    |-- requirements.txt
    |   Lista de dependencias del proyecto.
    |
    |-- generar_admin_p12.py
    |   Script para generar un certificado .p12 de administrador y registrarlo
    |   en la base de datos.
    |
    |-- db/
    |   |
    |   |-- schema_mysql.sql
    |   |   Script de creación de tablas para MySQL/MariaDB.
    |   |
    |   |-- schema.sql
    |   |   Esquema SQLite histórico/de referencia.
    |   |
    |   |-- SETUP_BASE_DE_DATOS.md
    |       Guía de configuración de base de datos.
    |
    |-- templates/
    |   Plantillas HTML de login, panel de usuario, panel admin, cambio de
    |   contraseña, carga de certificado y vistas principales.
    |
    |-- static/
    |   Archivos estáticos usados por Flask, como CSS e imágenes.
    |
    |-- css/
    |   Estilos CSS auxiliares.
    |
    |-- img/
    |   Imágenes del proyecto.
    |
    |-- ca/
    |   Certificados y llaves de la CA de demostración.
    |   En producción no debe publicarse la llave privada.
    |
    |-- usb_simulada/
    |   Carpeta de demostración para certificados .p12 simulando uso por USB.
    |
    |-- flask_sessions_demo/
        Carpeta de sesiones locales de Flask. No debe subirse con datos reales.

10. CONTRIBUCIONES
------------------
Para colaborar en el proyecto:

1. Clonar o descargar el repositorio.
2. Leer primero:
   - README.txt
   - app final/README.md
   - app final/db/SETUP_BASE_DE_DATOS.md
   - app final/app.py
   - app final/db/schema_mysql.sql
3. Crear una rama de trabajo:

   git checkout -b nombre-de-la-mejora

4. No modificar directamente archivos sensibles como:
   - ca/ca_key.pem
   - certificados .p12 reales
   - bases de datos reales
   - archivos de sesión
   - contraseñas o llaves privadas

5. Antes de cambiar código, identificar qué módulo se va a tocar:
   - Autenticación
   - Usuarios
   - Certificados
   - Migrantes
   - Derechos ARCO
   - Firmas digitales
   - Reportes
   - Base de datos

6. Probar localmente antes de integrar cambios.

7. Documentar cualquier cambio relevante en la sección Changelog.

11. PRUEBAS BÁSICAS
-------------------
Pruebas manuales sugeridas para verificar que el sistema funciona:

Prueba 1: Arranque de la aplicación
- Ejecutar python app.py.
- Confirmar que no existan errores de conexión a MySQL.
- Abrir http://localhost:5001.
- Verificar que aparezca la pantalla de login.

Prueba 2: Login con credenciales inválidas
- Intentar entrar con contraseña incorrecta.
- Confirmar que el sistema rechaza el acceso.
- Revisar que se registre el evento de login fallido.

Prueba 3: Login de administrador o coordinador
- Entrar con correo y contraseña.
- Confirmar que el sistema solicite el archivo .p12.
- Cargar el .p12 correcto con su contraseña.
- Confirmar que se abra el panel correspondiente.

Prueba 4: Bloqueo de cuenta
- Realizar varios intentos fallidos.
- Confirmar que la cuenta se bloquee al superar el límite definido.
- Verificar que un administrador pueda desbloquearla.

Prueba 5: Gestión de certificados
- Crear o emitir un certificado.
- Confirmar que se registre en la base de datos.
- Revocar el certificado.
- Intentar usarlo nuevamente y verificar que sea rechazado.

Prueba 6: Registro de migrante
- Crear una solicitud de registro.
- Validarla como operador.
- Aprobarla como coordinador.
- Confirmar que aparezca en la tabla de migrantes.

Prueba 7: Firma digital
- Solicitar una eliminación/cancelación desde un rol permitido.
- Confirmar que se genere mensaje firmado y firma ECDSA.
- Verificar la firma desde el panel administrativo.

Prueba 8: Derechos ARCO
- Buscar un migrante.
- Probar acceso a datos.
- Solicitar rectificación.
- Solicitar cancelación.
- Confirmar que el flujo respete permisos por rol.

Prueba 9: Reportes
- Descargar reportes de migrantes, eliminaciones y logs.
- Confirmar que los archivos Excel se generen correctamente.

12. LICENCIA DE USO
-------------------
Uso académico y demostrativo para el reto Casa Monarca / Tecnológico de
Monterrey.

Licencia formal: pendiente por definir.

Este proyecto no debe usarse en producción sin una revisión de seguridad,
protección de datos, manejo de secretos, despliegue seguro con HTTPS y pruebas
formales.

13. TODO - PENDIENTES POR HACER
-------------------------------
- Completar el correo oficial de contacto del equipo.
- Cambiar credenciales demo antes de cualquier entrega pública.
- Eliminar del repositorio archivos sensibles o generados localmente:
  certificados privados, llaves de CA, bases de datos reales y sesiones.
- Agregar un archivo .gitignore más estricto para ca/, *.p12, *.db y sesiones.
- Agregar pruebas automatizadas para rutas críticas.
- Documentar con capturas el flujo completo por rol.
- Preparar base de datos demo limpia para la presentación.
- Revisar textos para audiencia no técnica.
- Incluir explícitamente la relación con ODS 16: Paz, justicia e instituciones
  sólidas.
- Validar que los reportes descargables no expongan información innecesaria.
- Revisar configuración de despliegue para servidor real.
- Definir licencia final del repositorio.

14. CHANGELOG
-------------
Versión 0.1 - Prototipo inicial
- Se crea estructura inicial del proyecto.
- Se agregan pantallas básicas de login e interfaz.

Versión 0.2 - Base de datos y usuarios
- Se agregan tablas de usuarios, certificados y logs.
- Se implementa autenticación con contraseña protegida por hash.
- Se agregan usuarios demo.

Versión 0.3 - Certificados digitales
- Se agrega generación de CA interna.
- Se implementa emisión de certificados .p12.
- Se agregan estados de certificado: vigente, revocado y expirado.
- Se implementa revocación de certificados.

Versión 0.4 - Roles y control de acceso
- Se separan permisos por admin, coordinador, operador y voluntario.
- Se agregan paneles diferenciados por rol.
- Se refuerza el principio de mínimo privilegio.

Versión 0.5 - Migrantes, solicitudes y auditoría
- Se agrega módulo de registro de migrantes.
- Se implementan flujos de validación y aprobación.
- Se agregan logs de auditoría para eventos relevantes.
- Se agregan reportes descargables en Excel.

Versión 0.6 - Firmas digitales y Derechos ARCO
- Se implementan firmas ECDSA P-256.
- Se guardan mensajes firmados, firmas y llaves públicas.
- Se agrega verificación de firma.
- Se implementan flujos de acceso, rectificación y cancelación.

Versión 1.0 - Versión de demostración académica
- Se integra el flujo completo del sistema.
- Se prepara la aplicación para demostración por roles.
- Se documenta instalación, configuración, pruebas y mantenimiento.

15. NOTAS FINALES
-----------------
Este README.txt está diseñado para entregar una versión resumida y práctica del
proyecto. Para documentación más técnica, consultar los archivos internos del
repositorio, especialmente app.py, schema_mysql.sql y SETUP_BASE_DE_DATOS.md.
