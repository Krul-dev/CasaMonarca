# TODO — Instar (Casa Monarca)

Este documento lista los pendientes identificados al cierre de la versión
v1.1.0 (junio 2026), agrupados por prioridad. Las fechas y responsables
deben asignarse al iniciar cada etapa.

---

## Prioridad alta — bloqueantes para producción

- [ ] **Autenticación multifactor (TOTP)** para Administrador y Coordinador
      antes de cualquier despliegue público. Mientras no exista, la seguridad
      de las cuentas con privilegios elevados depende únicamente de la
      contraseña.
- [ ] **Hardening de configuración de producción:** HTTPS estricto, HSTS,
      Content Security Policy (CSP), cookies con `Secure` y `HttpOnly`,
      rotación periódica de secretos.
- [ ] **Pruebas de penetración** sobre los endpoints de firma
      (`/firmas/{doc}/challenge` y `/firmas/{doc}/store`) y de verificación
      de integridad (`/admin/archivos/doc/{doc}/verificar`).
- [ ] **Pruebas de carga** sobre la vista `/admin/archivos` para volúmenes
      reales de documentos. Definir paginación obligatoria y procesamiento
      asíncrono si los tiempos de respuesta exceden 2 segundos.
- [ ] **Procedimiento operativo de pérdida de llave PEM** documentado en la
      carpeta `docs/`. Debe incluir el flujo de revocación, la emisión de un
      nuevo certificado y la comunicación con la persona afectada.
- [ ] **Política de respaldo cifrado** de la base de datos y de los archivos
      del storage privado.

---

## Prioridad media — siguientes versiones (v1.2.x)

- [ ] **Notificaciones por correo electrónico** a la persona migrante cuando:
  - su documento es firmado por un Coordinador,
  - su solicitud ARCO cambia de estado,
  - su acceso al portal es habilitado.
- [ ] **Exportación del log de auditoría** (`actividad_log`) en formato PDF
      firmado por el Administrador, para presentar como evidencia formal ante
      organismos reguladores.
- [ ] **Procedimiento formal del Derecho ARCO de Oposición** a usos
      específicos, con flujo dedicado análogo al de Rectificación.
- [ ] **Verificación externa de documentos** (Sub-problema 4 del reto):
      endpoint público que permita a un tercero (autoridad migratoria, aliado,
      donante) confirmar la autenticidad de un documento emitido por Casa
      Monarca sin intervención del personal interno.
- [ ] **Sistema de firma de correos electrónicos** (Sub-problema 2 del reto),
      para garantizar autenticidad en comunicaciones salientes con
      contrapartes.
- [ ] **Cifrado de base de datos en reposo** (Sub-problema 6 del reto).
- [ ] **Respaldos automáticos cifrados** con rotación (Sub-problema 7).
- [ ] **Rotación periódica de APP_KEY** con procedimiento de re-sellado de
      documentos de identidad para mantener validez de la verificación
      HMAC-SHA256.
- [ ] **Paginación obligatoria y procesamiento asíncrono** en
      `/admin/archivos` (workers con cola para verificación de integridad).
- [ ] **Adopción del estándar X.509 RFC 5280** completo para certificados
      internos con lista de revocación (CRL) exportable.

---

## Prioridad baja — visión a largo plazo

- [ ] **Capa de registro distribuido (blockchain)** que persista únicamente
      hashes, fingerprints y referencias pseudónimas — nunca datos
      personales — para alcanzar inmutabilidad criptográfica de la bitácora
      más allá del nivel operacional actual.
- [ ] **Integración con Autoridad Certificadora externa** para validez
      interinstitucional de los certificados emitidos.
- [ ] **Migración del cliente de firma a hardware-backed keys** (Web
      Authentication API con autenticadores físicos tipo YubiKey) cuando la
      base de coordinadores justifique la complejidad operativa.
- [ ] **Adaptación multi-tenant** del sistema para que pueda ser utilizado
      por otras organizaciones humanitarias con necesidades análogas, sobre
      la base ya construida para Casa Monarca.
- [ ] **Dashboard de métricas operativas** para la dirección de Casa Monarca:
      tiempo medio de aprobación, número de acciones por semana, tiempo medio
      de respuesta a solicitudes ARCO, integridad de archivos por área.
- [ ] **Aplicación móvil** para personas migrantes (PWA o nativa) con soporte
      offline para los formularios de entrevista inicial.

---

## Deuda técnica acumulada

- [ ] **Cobertura de pruebas:** ampliar tests automatizados en flujos de
      auditoría y revocación. Actualmente cubrimos los 22 casos principales
      (TC-01 a TC-22) pero hay caminos secundarios sin cobertura.
- [ ] **Refactor de `ArchivosMigrantesController`:** la lógica de
      verificación profunda crece en complejidad y debería extraerse a un
      servicio dedicado (`IntegrityCheckService`).
- [ ] **Internacionalización del backend:** los mensajes de error en español
      están hardcodeados. Mover a archivos de traducción (`lang/`).
- [ ] **Documentación de la API interna** entre controladores y servicios
      (generación automática con phpDocumentor).
- [ ] **Migraciones consolidadas:** existen migraciones de fix que podrían
      consolidarse al iniciar un nuevo ciclo (no urgente).
- [ ] **Eliminación de dependencias no utilizadas** tras la migración de
      MySQL a SQLite en desarrollo (revisar `composer.json`).

---

## Notas para quien continúe el proyecto

1. **No tocar `actividad_log` ni `certificados`** sin entender la semántica de
   inmutabilidad: estas tablas son la columna vertebral de la auditoría y
   cualquier cambio destructivo es irreversible.
2. **La APP_KEY es crítica.** Si se cambia sin re-sellar, todos los
   documentos de identidad existentes quedarán marcados como inválidos en la
   verificación de integridad.
3. **Antes de tocar firma digital o sellado**, leer el Anexo B del Reporte
   Técnico v1.1.0 (`docs/01_Reporte_Tecnico_v1_1_0.docx`) — contiene el
   pseudocódigo de los tres flujos criptográficos y las decisiones de diseño.
4. **Los tests TC-16 a TC-22 son la red de seguridad** de la versión 1.1.0.
   Cualquier cambio en los controladores `Firma`, `DocumentoIdentidad`,
   `Rectificacion` o `ArchivosMigrantes` debe mantenerlos pasando.
