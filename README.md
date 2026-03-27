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
