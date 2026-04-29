# Laravel ARCA SDK (Laravel 10+)

Libreria base para integrar servicios AFIP/ARCA en aplicaciones Laravel 10, 11 y 12.

## Requisitos

- PHP 8.1 o superior
- Laravel 10 o superior
- Extensiones PHP: `soap`, `openssl`, `simplexml`

## Instalacion

```bash
composer require mause/laravel-arca
```

### Instalacion local (sin Packagist)

Si estas desarrollando esta libreria en local, instalala desde tu proyecto Laravel consumidor usando un repositorio de tipo `path`.

Ejemplo en el `composer.json` del proyecto Laravel:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../ArcaWS-ia",
            "options": {
                "symlink": true
            }
        }
    ],
    "require": {
        "mause/laravel-arca": "dev-main"
    }
}
```

Luego ejecutar:

```bash
composer update mause/laravel-arca
php artisan vendor:publish --tag=arca-config
```

Notas:
- Ajusta `url` a la ruta real donde tengas la libreria local.
- `symlink: true` permite ver cambios de la libreria al instante en el proyecto Laravel.
- Si no usas rama `main`, cambia `dev-main` por la rama correspondiente o usa `@dev`.

## Publicar configuracion

```bash
php artisan vendor:publish --tag=arca-config
```

## Variables de entorno

```dotenv
ARCA_MODE=homologation
ARCA_WSAA_HOMO_URL=https://wsaahomo.afip.gov.ar/ws/services/LoginCms
ARCA_WSAA_PROD_URL=https://wsaa.afip.gov.ar/ws/services/LoginCms
ARCA_CERT_PATH_PATTERN=storage/app/public/%s/cert.crt
ARCA_KEY_PATH_PATTERN=storage/app/public/%s/key.key
ARCA_KEY_PASSPHRASE=
```

En esquema multiempresa, `%s` se reemplaza por el CUIT emisor. Ejemplo:
- `storage/app/public/30712345678/cert.crt`
- `storage/app/public/30712345678/key.key`

## Uso rapido

### Verificar instalacion

```php
use Mause\\LaravelArca\\Facades\\Arca;

$data = Arca::ping();
```

### WSAA - Autenticacion

```php
use Mause\\LaravelArca\\Facades\\ArcaWsaa;

$companyCuit = '30712345678';

// Generar TRA
$tra = ArcaWsaa::generateTra('wsfe');

// Firmar TRA (CMS/PKCS#7)
$cms = ArcaWsaa::signTra($tra, $companyCuit);

// Solicitar Ticket de Acceso
$ta = ArcaWsaa::requestTa($companyCuit, 'wsfe');
// Retorna: ['token' => '...', 'sign' => '...', 'expires_at' => '...']
```

### WSFEv1 - Facturación Electrónica

```php
use Mause\\LaravelArca\\Facades\\ArcaWsfev1;

$companyCuit = '30712345678';

// Obtener tipos de comprobantes
$types = ArcaWsfev1::getInvoiceTypes($companyCuit);

// Obtener último número autorizado
$lastNumber = ArcaWsfev1::getLastAuthorizedNumber($companyCuit, 1, 6);

// Solicitar CAE
$invoice = [...]; // Array con datos del comprobante
$cae = ArcaWsfev1::requestCae($companyCuit, $invoice);
```

### WS Padrones - Consulta de CUIT, CUIL, DNI

```php
use Mause\\LaravelArca\\Facades\\ArcaWsPadron;

$companyCuit = '30712345678';

// Consulta automática (detecta DNI, CUIL o CUIT automáticamente)
$result = ArcaWsPadron::consultarPadron($companyCuit, '12345678');
// Retorna:
// {
//   'type' => 'dni'|'cuil'|'cuit',
//   'data' => [...datos de la persona...],
//   'error' => null|'error message'
// }

// Consulta específica por DNI
$dataDni = ArcaWsPadron::consultarPorDni($companyCuit, '12345678');

// Consulta específica por CUIT (persona jurídica)
$dataCuit = ArcaWsPadron::consultarPersona($companyCuit, '30112345678', 'cuit');

// Consulta específica por CUIL (persona física)
$dataCuil = ArcaWsPadron::consultarPersona($companyCuit, '27123456789', 'cuil');
```

#### Detección automática de tipo

El módulo WsPadron detecta automáticamente el tipo de identificador:
- **DNI**: 8 dígitos
- **CUIL**: 11 dígitos que comienzan con 27 o 28
- **CUIT**: 11 dígitos restantes

```php
// Estos se procesan automáticamente con el padrón correcto
ArcaWsPadron::consultarPadron($companyCuit, '12345678');    // → padrón DNI
ArcaWsPadron::consultarPadron($companyCuit, '27123456789');  // → padrón CUIL
ArcaWsPadron::consultarPadron($companyCuit, '30112345678');  // → padrón CUIT
```

#### Datos retornados

**Para DNI/CUIL (Persona Física)**:
- `cuit`, `cuil`, `nombre`, `apellido`
- `tipoDocumento`, `numeroDocumento`
- `estado`, `domicilio`
- `monotributo`, `empleador`

**Para CUIT (Persona Jurídica)**:
- `cuit`, `razonSocial`
- `tipoPersoneria`, `estado`
- `domicilio`, `domicilioFiscal`
- `inscripcionesIva`

**IMPORTANTE**: Requiere acceso al servicio `padron` en tu certificado AFIP.

---


### WSFEv1 - Facturacion electronica

```php
use Mause\\LaravelArca\\Facades\\ArcaWsfev1;

$companyCuit = '30712345678';

// Obtener tipos de comprobantes
$types = ArcaWsfev1::getInvoiceTypes($companyCuit);

// Obtener ultimo numero de comprobante
$result = ArcaWsfev1::getLastAuthorizedNumber(
    companyCuit: $companyCuit,
    ptoVta: 1,      // Punto de venta
    cbteType: 6     // Tipo (6 = Factura B)
);

// Solicitar CAE
$invoice = [
    'FeCabReq' => [...],
    'FeDetReq' => [...]
];
$cae = ArcaWsfev1::requestCae($companyCuit, $invoice);
// Retorna: ['cae' => '...', 'expiration' => '...', 'error' => null]
```

## Guia de homologacion

Ver [HOMOLOGACION.md](HOMOLOGACION.md) para pruebas paso a paso en ambiente de testing.

## Pruebas con Docker

Ver [DOCKER_TESTING.md](DOCKER_TESTING.md) para levantar un Laravel consumidor en contenedor,
instalar esta librería y ejecutar pruebas de WSAA/WSFEv1.

## Guia para IA

Esta seccion deja el contrato de uso explicito para que una IA pueda integrar esta libreria dentro de otro proyecto Laravel sin asumir firmas o rutas incorrectas.

### Supuestos de integracion

- La libreria es multiempresa.
- El identificador del emisor es el CUIT de la empresa.
- Los certificados se resuelven por patron de ruta usando el CUIT emisor.
- El `Auth.Cuit` que se envia a ARCA debe ser el CUIT emisor, no el del cliente.
- El cliente del comprobante se informa dentro de `FeDetReq` con `DocTipo` y `DocNro`.

### Rutas esperadas de certificados

Por defecto:

- `storage/app/public/{cuit}/cert.crt`
- `storage/app/public/{cuit}/key.key`

Configuracion asociada:

```dotenv
ARCA_CERT_PATH_PATTERN=storage/app/public/%s/cert.crt
ARCA_KEY_PATH_PATTERN=storage/app/public/%s/key.key
```

### Firmas publicas actuales

```php
Arca::ping(): array

ArcaWsaa::generateTra(string $wsn = 'wsfe'): string
ArcaWsaa::createCertificateRequest(string|int $companyCuit, array $distinguishedName, ?string $passphrase = null, int $privateKeyBits = 2048): array
ArcaWsaa::signTra(string $tra, string|int|null $companyCuit = null): ?string
ArcaWsaa::requestTa(string|int $companyCuit, string $wsn = 'wsfe'): ?array

ArcaWsfev1::getInvoiceTypes(string|int $companyCuit): ?array
ArcaWsfev1::getLastAuthorizedNumber(string|int $companyCuit, int $ptoVta, int $cbteType): ?array
ArcaWsfev1::requestCae(string|int $companyCuit, array $invoice): ?array
```

### Flujo correcto para una IA integradora

1. Obtener el CUIT emisor desde el modelo de empresa o tenant.
2. Si todavía no existen credenciales, generar `key.key` y `request.csr` con `ArcaWsaa::createCertificateRequest(...)`.
3. Subir `request.csr` al portal oficial de ARCA y descargar `cert.crt`.
4. Verificar que existan `cert.crt` y `key.key` en la carpeta de ese CUIT.
5. Solicitar TA con `ArcaWsaa::requestTa($empresa->cuit, 'wsfe')`.
6. Consultar tipos o ultimo comprobante con el mismo CUIT emisor.
7. Construir `FeCabReq` y `FeDetReq`.
8. Solicitar CAE con `ArcaWsfev1::requestCae($empresa->cuit, $invoice)`.

### Datos mínimos para generar CSR

Además del CUIT emisor, esta librería necesita como mínimo:

- `organizationName`: razón social o nombre de la empresa
- `commonName`: alias o nombre del sistema que usará el certificado

Opcionales recomendados:

- `countryName`: por defecto `AR`
- `stateOrProvinceName`
- `localityName`
- `organizationalUnitName`
- `emailAddress`

La librería completa automáticamente:

- `serialNumber`: `CUIT {cuit}`

### Ejemplo de generación de CSR

```php
use Mause\LaravelArca\Facades\ArcaWsaa;

$csr = ArcaWsaa::createCertificateRequest(
    companyCuit: $empresa->cuit,
    distinguishedName: [
        'organizationName' => $empresa->razon_social,
        'commonName' => 'SistemaFacturacion',
        'countryName' => 'AR',
        'stateOrProvinceName' => 'Buenos Aires',
        'localityName' => 'La Plata',
        'emailAddress' => 'admin@empresa.com',
    ],
    passphrase: null,
    privateKeyBits: 2048,
);
```

El método guarda automáticamente:

- `storage/app/public/{cuit}/key.key`
- `storage/app/public/{cuit}/request.csr`

Después tenés que subir `request.csr` a WSASS o al Administrador de Certificados Digitales para obtener `cert.crt`.

### Ejemplo minimo de integracion

```php
use Mause\LaravelArca\Facades\ArcaWsaa;
use Mause\LaravelArca\Facades\ArcaWsfev1;

$companyCuit = $empresa->cuit;

$ta = ArcaWsaa::requestTa($companyCuit, 'wsfe');

$last = ArcaWsfev1::getLastAuthorizedNumber($companyCuit, 1, 6);
$nextNumber = (($last['cbte_nro'] ?? 0) + 1);

$invoice = [
    'FeCabReq' => [
        'CantReg' => 1,
        'CbteTipo' => 6,
        'PtoVta' => 1,
    ],
    'FeDetReq' => [
        'FECAEDetRequest' => [
            'Concepto' => 2,
            'DocTipo' => 96,
            'DocNro' => 12345678,
            'CbteDesde' => $nextNumber,
            'CbteHasta' => $nextNumber,
            'CbteFch' => date('Ymd'),
            'ImpTotal' => 100.00,
            'ImpTotConc' => 0,
            'ImpNeto' => 82.64,
            'ImpOpEx' => 0,
            'ImpIVA' => 17.36,
            'ImpTrib' => 0,
            'MonId' => 'PES',
            'MonCotiz' => 1,
        ],
    ],
];

$response = ArcaWsfev1::requestCae($companyCuit, $invoice);
```

### Errores de implementacion a evitar

- No pasar el CUIT emisor y asumir un certificado global.
- Enviar el CUIT del cliente en `Auth.Cuit`.
- Pedir CAE sin antes consultar el ultimo comprobante autorizado.
- Usar certificados de homologacion en modo produccion.
- Guardar token/sign fuera de un cache compartido cuando hay multiples instancias.

## Estructura

- `src/Services/ArcaClient.php` - Cliente base de configuracion
- `src/Modules/Wsaa.php` - Modulo WSAA (autenticacion)
- `src/Modules/Wsfev1.php` - Modulo WSFEv1 (facturacion)
- `src/Facades/Arca.php` - Facade general
- `src/Facades/ArcaWsaa.php` - Facade WSAA
- `src/Facades/ArcaWsfev1.php` - Facade WSFEv1
- `config/arca.php` - Configuracion del paquete

## Ejemplos

Ver carpeta `examples/`:
- `ArcaTestController.php` - Controlador de pruebas
- `arca-test.blade.php` - Vista para debugging

## Siguientes pasos sugeridos

- Implementar generacion de CAEA (Codigo de Autorizacion Electronico Anticipado).
- Agregar soporte para WSMTXCA (facturacion con detalle de items).
- Agregar soporte para WSFEXv1 (facturacion de exportacion).
- Implementar persistencia de CAE en base de datos.
- Agregar tests automatizados con Orchestra Testbench.
- Generar PDF/XML firmado de comprobante.

## License

MIT License

