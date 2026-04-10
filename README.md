# evidencias_reto

En este repositorio se estarán almacenando los entregables de las evidencias a realizar. Incluyendo archivos de documentación y ejecución.

## Diagrama de Gantt
Para visualizar el diagrama de Gantt, favor de descargar el archivo *gantt.json*. Favor de cargar el archivo anteriormente descargado en el [visualizador de Gantt](https://carleslc.me/Gantt/) para más detalle. 

## Diagrama de Contenedores

```mermaid
flowchart TB
    staff[Personal]

    subgraph browser["Navegador del usuario"]
        web["Cliente web React\nservido en /"]
    end

    subgraph edge["Capa de dominio"]
        dns["casamonarca.muchosnumeros.online"]
    end

    subgraph staging["VPS de staging"]
        apache["Apache"]
        php["API Laravel\nexpuesta en /api"]
        mysql[("MySQL")]
        sessions[("Almacen de sesiones")]
    end

    subgraph git["Control de codigo fuente"]
        ghweb["Repositorio GitHub\nAccess-Control-CM-Web"]
        ghapi["Repositorio GitHub\nAccess-Control-CM-API"]
    end

    staff --> web
    web --> dns
    dns --> apache
    apache --> web
    apache --> php
    php --> mysql
    php --> sessions

    ghweb -. script de despliegue frontend .-> apache
    ghapi -. script de despliegue API / git pull .-> php
```

## Modelo conceptual de confianza y documentos
```mermaid
classDiagram
    class Usuario {
        +id: int
        +nombre: string
        +email: string
        +autenticar()
    }

    class Rol {
        +id: int
        +nombre: string
        +nivelPermiso: int
    }

    class Documento {
        +id: int
        +contenido: string
        +fechaCreacion: Date
        +estado: string
    }

    class FirmaDigital {
        +id: int
        +firma: string
        +firmar()
        +verificar()
    }

    class CertificadoDigital {
        +id: int
        +clavePublica: string
        +fechaExpiracion: Date
        +emitir()
        +revocar()
    }

    class GestorIdentidad {
        +validarAcceso()
        +asignarRol()
    }

    class ValidadorDocumentos {
        +validarDocumento()
        +verificarFirma()
    }

    class CryptoService {
        +generarHash()
        +generarHMAC()
        +encriptar()
        +desencriptar()
    }

    class BaseDeDatos {
        +guardar()
        +consultar()
    }

    Usuario --> Rol
    Usuario --> CertificadoDigital
    GestorIdentidad --> Usuario
    Documento --> FirmaDigital
    ValidadorDocumentos --> Documento
    ValidadorDocumentos --> FirmaDigital
    ValidadorDocumentos --> CertificadoDigital
    CryptoService --> FirmaDigital
    Documento --> BaseDeDatos
    Usuario --> BaseDeDatos
```

## Vista de Despliegue
```mermaid
flowchart TB
    subgraph local["Maquinas de desarrollo"]
        fe["Construir frontend en local"]
        be["Desarrollar API en local"]
        vault["Vault de Obsidian\ndiagramas y notas locales"]
    end

    subgraph github["GitHub"]
        webrepo["Access-Control-CM-Web"]
        apirepo["Access-Control-CM-API"]
    end

    subgraph vps["VPS de staging"]
        public["public_html\nfrontend estatico"]
        app["Checkout de Laravel"]
        httpd["Apache + PHP-FPM"]
        db[("MySQL")]
        mirror["Espejo de staging en VPS"]
    end

    subgraph target["Modelo de hosting objetivo"]
        hg["Restricciones de hosting compartido estilo HostGator"]
    end

    fe --> webrepo
    be --> apirepo
    vault --> fe
    vault --> be

    webrepo -->|despliegue de artefacto local| public
    apirepo -->|despliegue con pull remoto| app

    public --> httpd
    app --> httpd
    app --> db
    mirror -.->|supuestos de runtime| hg
```

## Secuencia de Inicio de Sesion
```mermaid
sequenceDiagram
    actor User as Usuario
    participant Browser as Cliente web React
    participant Apache as Apache / borde de mismo origen
    participant API as API Laravel
    participant DB as MySQL

    User->>Browser: Enviar correo y contrasena
    Browser->>Apache: GET /api/csrf-token
    Apache->>API: Reenviar solicitud
    API-->>Browser: Respuesta con token CSRF

    Browser->>Apache: POST /api/login\ncredenciales + X-CSRF-TOKEN
    Apache->>API: Reenviar solicitud
    API->>DB: Verificar credenciales del usuario
    API->>DB: Crear o actualizar sesion
    API-->>Browser: 200 OK + payload de usuario + cookie de sesion
    Browser-->>User: Mostrar mensaje de acceso exitoso
```
## Secuencia de Inicio de Sesion e.Firma
```mermaid
sequenceDiagram
    autonumber
    actor User as Usuario
    participant Browser as Cliente web React
    participant Apache as Apache / borde de mismo origen
    participant API as API Laravel
    participant DB as MySQL

    Note over User, Apache: El navegador presenta el Certificado del Cliente (mTLS)
    
    User->>Browser: Seleccionar Certificado e ingresar contraseña
    Browser->>Apache: GET /api/csrf-token + Certificado Digital
    
    Note right of Apache: Apache valida que el certificado sea vigente y confiable
    
    Apache->>API: Reenviar solicitud (incluye datos del certificado)
    API-->>Browser: Respuesta con token CSRF

    Browser->>Apache: POST /api/login (Credenciales + Firma con Certificado) + X-CSRF-TOKEN
    Apache->>API: Reenviar solicitud
    
    API->>API: Validar firma digital del mensaje
    API->>DB: Verificar credenciales y vinculación de certificado
    API->>DB: Crear o actualizar sesion
    
    API-->>Browser: 200 OK + payload de usuario + cookie de sesion
    Browser-->>User: Mostrar mensaje de acceso exitoso
```

## Secuencia de Registro de Usuario
```mermaid
sequenceDiagram
    autonumber
    actor Admin as Usuario Admin
    participant Browser as Cliente web React
    participant Apache as Apache / borde de mismo origen
    participant API as API Laravel
    participant DB as MySQL
    participant Mail as Servidor de Correo

    Note over Admin, Mail: Fase 1: Alta de Correo y Verificación

    Admin->>Browser: Ingresa correo electrónico
    Browser->>Apache: GET /api/csrf-token
    Apache->>API: Reenviar solicitud
    API-->>Browser: Respuesta con token CSRF

    Browser->>Apache: POST /api/register-admin\n{correo} + X-CSRF-TOKEN
    Apache->>API: Reenviar solicitud
    API->>DB: Verificar disponibilidad y crear registro previo
    API->>Mail: Enviar correo con enlace de verificación + token único
    API-->>Browser: 201 Created (Instrucción de revisar correo)
    Browser-->>Admin: Mostrar "Verifica tu bandeja de entrada"

    Note over Admin, Mail: Fase 2: Configuración de Seguridad (Password y Token)

    Admin->>Browser: Clic en enlace de correo (token de verificación)
    Browser->>Apache: GET /setup-password?verify_token=...
    Apache-->>Browser: Carga formulario de credenciales y 2FA
    
    Browser->>Apache: GET /api/csrf-token
    Apache->>API: Reenviar solicitud
    API-->>Browser: Respuesta con nuevo token CSRF

    Admin->>Browser: Ingresa Password, Confirmación y Token (OTP)
    Browser->>Apache: POST /api/complete-setup\n{pass, pass_conf, otp} + X-CSRF-TOKEN
    Apache->>API: Reenviar solicitud

    API->>API: Validar tokens, contraseñas y código OTP
    API->>DB: Actualizar Admin (Hash de Pass, Email Verificado, 2FA Activo)
    API->>DB: Crear sesión inicial
    
    API-->>Browser: 200 OK + payload de admin + cookie de sesion
    Browser-->>Admin: Mostrar mensaje de configuración exitosa y acceso al sistema

Note over Admin, API: Fase 3: Generación de Certificado Digital
    
    Admin->>Browser: Solicitar generación de Certificado
    Browser->>Browser: Generar par de llaves (Pública/Privada) localmente
    Browser->>Apache: POST /api/issue-certificate {CSR + Public Key}
    Apache->>API: Reenviar solicitud
    API->>API: Firmar clave con la CA (Autoridad Certificadora) interna
    API->>DB: Almacenar certificado público vinculado al Admin
    API-->>Browser: Enviar Certificado Firmado (.crt / .pem)
    Browser-->>Admin: Descarga de archivo de identidad (eFirma)
```
