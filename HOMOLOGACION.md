# Guía de Pruebas en Homologación

## Prerrequisitos

Esta guia ya esta preparada para que una IA o integrador humano consuma la libreria en un proyecto Laravel multiempresa.

1. **Certificados generados** en WSASS (homologación):
   - `arca.key` (clave privada)
   - `arca.crt` (certificado PEM)
   - Ubicar en `storage/app/arca/`

2. **Configuración en `.env`**:
   ```dotenv
   ARCA_MODE=homologation
   ARCA_WSAA_HOMO_URL=https://wsaahomo.afip.gov.ar/ws/services/LoginCms
    ARCA_CERT_PATH_PATTERN=storage/app/public/%s/cert.crt
    ARCA_KEY_PATH_PATTERN=storage/app/public/%s/key.key
   ARCA_KEY_PASSPHRASE=  # Si tu clave tiene frase secreta, úsala aquí
   ```

    Para cada empresa emisora, la librería buscará:
    - `storage/app/public/{cuit}/cert.crt`
    - `storage/app/public/{cuit}/key.key`

3. **Instalar en proyecto Laravel 10+**:

     Opcion A - desde Packagist:
     ```bash
     composer require mause/laravel-arca
     php artisan vendor:publish --tag=arca-config
     ```

     Opcion B - libreria local (recomendada durante desarrollo):
     - En el `composer.json` del proyecto Laravel agregar:
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

     - Luego ejecutar:
     ```bash
     composer update mause/laravel-arca
     php artisan vendor:publish --tag=arca-config
     ```

4. **Regla de integracion multiempresa**:
     - Siempre pasar el CUIT emisor en cada llamada a WSAA y WSFEv1.
     - No usar un certificado global si el sistema maneja varias empresas.
     - La libreria resolvera automaticamente:
         - `storage/app/public/{cuit}/cert.crt`
         - `storage/app/public/{cuit}/key.key`

## Pruebas paso a paso

### Contrato de uso resumido

```php
ArcaWsaa::createCertificateRequest(string|int $companyCuit, array $distinguishedName, ?string $passphrase = null, int $privateKeyBits = 2048)
ArcaWsaa::requestTa(string|int $companyCuit, string $wsn = 'wsfe')
ArcaWsfev1::getInvoiceTypes(string|int $companyCuit)
ArcaWsfev1::getLastAuthorizedNumber(string|int $companyCuit, int $ptoVta, int $cbteType)
ArcaWsfev1::requestCae(string|int $companyCuit, array $invoice)
```

### Generar CSR por empresa

Si la empresa todavía no tiene credenciales, podés generar la clave privada y el CSR localmente.

Datos mínimos requeridos además del CUIT:

- `organizationName`: razón social o nombre de la empresa
- `commonName`: alias o nombre del sistema

La librería completa automáticamente `serialNumber` con el formato `CUIT {cuit}`.

```php
use Mause\LaravelArca\Facades\ArcaWsaa;

$companyCuit = '30712345678';

$result = ArcaWsaa::createCertificateRequest(
    companyCuit: $companyCuit,
    distinguishedName: [
        'organizationName' => 'Empresa Demo SA',
        'commonName' => 'SistemaFacturacion',
        'countryName' => 'AR',
    ]
);
```

Resultado esperado:

- `storage/app/public/{cuit}/key.key`
- `storage/app/public/{cuit}/request.csr`

Luego debés subir `request.csr` a WSASS para homologación o al Administrador de Certificados Digitales para producción, y guardar el certificado emitido en:

- `storage/app/public/{cuit}/cert.crt`

### 1. Verificar instalación y conectividad

**Ruta o Tinker**:
```php
use Mause\LaravelArca\Facades\Arca;

$ping = Arca::ping();
dd($ping);
```

**Esperado**:
```php
[
  'package' => 'mause/laravel-arca',
  'mode' => 'homologation',
  'wsaa_homologacion' => 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms',
  'wsaa_produccion' => 'https://wsaa.afip.gov.ar/ws/services/LoginCms',
]
```

### 2. Generar TRA

**Código**:
```php
use Mause\LaravelArca\Facades\ArcaWsaa;

$companyCuit = '30712345678';

$tra = ArcaWsaa::generateTra('wsfe');
echo $tra;
```

**Esperado**: XML válido con structure TRA (contiene CUIT, timestamps, etc.).

### 3. Firmar TRA (CMS)

**Código**:
```php
use Mause\LaravelArca\Facades\ArcaWsaa;

$companyCuit = '30712345678';
$tra = ArcaWsaa::generateTra('wsfe');
$cms = ArcaWsaa::signTra($tra, $companyCuit);
echo strlen($cms) > 100 ? 'OK: CMS firmado' : 'ERROR';
```

**Esperado**: CMS con bloc de inicio `-----BEGIN PKCS7-----` y fin `-----END PKCS7-----`.

### 4. Solicitar TA (Ticket de Acceso)

**Código**:
```php
use Mause\LaravelArca\Facades\ArcaWsaa;

$companyCuit = '30712345678';

try {
    $ta = ArcaWsaa::requestTa($companyCuit, 'wsfe');
    if ($ta) {
        echo 'Token: ' . $ta['token'] . PHP_EOL;
        echo 'Sign: ' . $ta['sign'] . PHP_EOL;
        echo 'Expira: ' . $ta['expires_at'] . PHP_EOL;
    } else {
        echo 'ERROR: No TA recibido';
    }
} catch (\Exception $e) {
    echo 'ERROR: ' . $e->getMessage();
}
```

**Esperado**: Token y Sign no vacíos, expires_at válido (12h desde ahora).

**Troubleshooting común**:
- _"Certificado o clave privada no encontrados"_: Verificar rutas en config `arca.php`.
- _"Firma CMS falló"_: Revisar permisos de lectura en `arca.key` y `arca.crt`.
- _"TLS dh key too small"_: Problema cliente OpenSSL, intentar con `curl -v` desde CLI primero.

### 5. Obtener tipos de comprobantes

**Código**:
```php
use Mause\LaravelArca\Facades\ArcaWsfev1;

$companyCuit = '30712345678';

$types = ArcaWsfev1::getInvoiceTypes($companyCuit);
if ($types) {
    foreach ($types as $t) {
        echo $t['id'] . ' - ' . $t['name'] . PHP_EOL;
    }
} else {
    echo 'ERROR obteniendo tipos';
}
```

**Esperado**:
```
1 - Factura A
6 - Factura B
11 - Factura C
...
```

### 6. Obtener último número de comprobante autorizado

**Código**:
```php
use Mause\LaravelArca\Facades\ArcaWsfev1;

$companyCuit = '30712345678';

$result = ArcaWsfev1::getLastAuthorizedNumber(
    companyCuit: $companyCuit,
    ptoVta: 1,      // Punto de venta
    cbteType: 6     // Tipo comprobante (6 = Factura B)
);

if ($result && !$result['error']) {
    echo 'Último comprobante: ' . $result['cbte_nro'];
} else {
    echo 'ERROR: ' . ($result['error'] ?? 'desconocido');
}
```

**Esperado**: Número entero > 0.

### 7. Solicitar CAE (Comprobante Autorizado Electrónico)

**Código**:
```php
use Mause\LaravelArca\Facades\ArcaWsfev1;

$companyCuit = '30712345678';

$invoice = [
    'FeCabReq' => [
        'CantReg' => 1,
        'CbteTipo' => 6,        // Factura B
        'PtoVta' => 1,
    ],
    'FeDetReq' => [
        'FECAEDetRequest' => [
            'Concepto' => 2,     // 2 = Servicios
            'DocTipo' => 96,     // 96 = DNI
            'DocNro' => 12345678,
            'CbteDesde' => 100,
            'CbteHasta' => 100,
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

$result = ArcaWsfev1::requestCae($companyCuit, $invoice);
if ($result && !$result['error']) {
    echo 'CAE: ' . $result['cae'] . PHP_EOL;
    echo 'Vencimiento: ' . $result['expiration'];
} else {
    echo 'ERROR: ' . ($result['error'] ?? 'desconocido');
}
```

**Esperado**: CAE válido (8 dígitos) y fecha de vencimiento.

---

## Consulta de Padrones (CUIT, CUIL, DNI)

El módulo WsPadron permite consultar datos de personas jurídicas (empresas) y físicas de los registros de AFIP.

**Importante**: Antes de usar estos servicios, solicita acceso al servicio `padron` en tu certificado SOAP a través del portal de AFIP.

### Contrato WsPadron

```php
ArcaWsPadron::consultarPadron(string|int $companyCuit, string|int $identifier)
ArcaWsPadron::consultarPorDni(string|int $companyCuit, string|int $numeroDni)
ArcaWsPadron::consultarPersona(string|int $companyCuit, string|int $cuitOCuil, string $personType = 'cuit')
```

### 8. Consulta automática (detecta tipo automáticamente)

El método `consultarPadron` detecta automáticamente si pasas DNI, CUIL o CUIT:
- **DNI**: 8 dígitos → consulta WS personafisica
- **CUIL**: 11 dígitos que comienzan con 27 o 28 → consulta WS padron/a13
- **CUIT**: 11 dígitos (por defecto) → consulta WS padron/v1

**Código**:
```php
use Mause\LaravelArca\Facades\ArcaWsPadron;

$companyCuit = '30712345678';

// Consulta con DNI (8 dígitos)
$resultDni = ArcaWsPadron::consultarPadron($companyCuit, '12345678');
if ($resultDni && !$resultDni['error']) {
    echo 'Tipo detectado: ' . $resultDni['type'] . PHP_EOL; // "dni"
    echo 'CUIT: ' . $resultDni['data']['cuit'] . PHP_EOL;
    echo 'CUIL: ' . $resultDni['data']['cuil'] . PHP_EOL;
    echo 'Nombre: ' . $resultDni['data']['nombre'] . PHP_EOL;
}

// Consulta con CUIL (11 dígitos que comienzan con 27 o 28)
$resultCuil = ArcaWsPadron::consultarPadron($companyCuit, '27123456789');
if ($resultCuil && !$resultCuil['error']) {
    echo 'Tipo detectado: ' . $resultCuil['type'] . PHP_EOL; // "cuil"
}

// Consulta con CUIT (11 dígitos normales)
$resultCuit = ArcaWsPadron::consultarPadron($companyCuit, '30712345678');
if ($resultCuit && !$resultCuit['error']) {
    echo 'Tipo detectado: ' . $resultCuit['type'] . PHP_EOL; // "cuit"
    echo 'Razón Social: ' . $resultCuit['data']['razonSocial'] . PHP_EOL;
}
```

**Esperado**:
```php
[
    'type' => 'dni|cuil|cuit',
    'data' => [
        'cuit' => '20123456780',
        'cuil' => '27123456780',
        'nombre' => 'Juan',
        'apellido' => 'Pérez',
        'numeroDocumento' => '12345678',
        'estado' => 'Activo',
        ...
    ],
    'error' => null
]
```

### 9. Consulta específica por DNI

**Código**:
```php
use Mause\LaravelArca\Facades\ArcaWsPadron;

$companyCuit = '30712345678';

$result = ArcaWsPadron::consultarPorDni($companyCuit, '12345678');

if ($result && !$result['error']) {
    $data = $result['data'];
    echo 'CUIT: ' . $data['cuit'] . PHP_EOL;
    echo 'CUIL: ' . $data['cuil'] . PHP_EOL;
    echo 'Nombre: ' . $data['nombre'] . ' ' . $data['apellido'] . PHP_EOL;
    echo 'Documento: ' . $data['tipoDocumento'] . ' ' . $data['numeroDocumento'] . PHP_EOL;
} else {
    echo 'ERROR: ' . ($result['error'] ?? 'desconocido');
}
```

**Datos retornados**:
- `cuit`: CUIT de la persona física
- `cuil`: CUIL de la persona física
- `nombre`: Nombre
- `apellido`: Apellido
- `tipoDocumento`: Tipo de documento (DNI, Pasaporte, etc.)
- `numeroDocumento`: Número del documento
- `estado`: Activo/Inactivo
- `domicilio`: Información del domicilio
- `monotributo`: Si está inscripto en Monotributo
- `empleador`: Si es empleador

### 10. Consulta de persona jurídica (CUIT)

**Código**:
```php
use Mause\LaravelArca\Facades\ArcaWsPadron;

$companyCuit = '30712345678';

$result = ArcaWsPadron::consultarPersona($companyCuit, '30112345678', 'cuit');

if ($result && !$result['error']) {
    $data = $result['data'];
    echo 'CUIT: ' . $data['cuit'] . PHP_EOL;
    echo 'Razón Social: ' . $data['razonSocial'] . PHP_EOL;
    echo 'Tipo Personería: ' . $data['tipoPersoneria'] . PHP_EOL;
    echo 'Estado: ' . $data['estado'] . PHP_EOL;
} else {
    echo 'ERROR: ' . ($result['error'] ?? 'desconocido');
}
```

**Datos retornados**:
- `cuit`: CUIT de la empresa
- `razonSocial`: Nombre/razón social
- `tipoPersoneria`: Tipo (SRL, SA, Empresa Individual, etc.)
- `estado`: Activo/Inactivo
- `domicilio`: Domicilio principal
- `domicilioFiscal`: Domicilio fiscal
- `inscripcionesIva`: Información de inscripción en IVA

### 11. Consulta de persona física (CUIL)

**Código**:
```php
use Mause\LaravelArca\Facades\ArcaWsPadron;

$companyCuit = '30712345678';

$result = ArcaWsPadron::consultarPersona($companyCuit, '27123456789', 'cuil');

if ($result && !$result['error']) {
    $data = $result['data'];
    echo 'Nombre: ' . $data['nombre'] . ' ' . $data['apellido'] . PHP_EOL;
    echo 'CUIL: ' . $data['cuil'] . PHP_EOL;
    echo 'Estado: ' . $data['estado'] . PHP_EOL;
} else {
    echo 'ERROR: ' . ($result['error'] ?? 'desconocido');
}
```

### Validación automática de identificadores

La librería normaliza automáticamente los identificadores:

```php
// Todos estos son equivalentes:
ArcaWsPadron::consultarPadron($companyCuit, '12-345-678');    // Guiones
ArcaWsPadron::consultarPadron($companyCuit, '12345678');       // Sin guiones
ArcaWsPadron::consultarPadron($companyCuit, 12345678);         // Número entero

// Todos válidos para CUIT/CUIL:
ArcaWsPadron::consultarPadron($companyCuit, '30-712345678-9'); 
ArcaWsPadron::consultarPadron($companyCuit, '30712345678');
```

### Manejo de errores

```php
$result = ArcaWsPadron::consultarPadron($companyCuit, $identifier);

if ($result === null) {
    // Error crítico (no se pudo ejecutar la consulta)
    echo 'Error crítico en consulta';
} elseif ($result['error']) {
    // Error específico de AFIP
    echo 'Error AFIP: ' . $result['error'];
    echo 'Tipo de búsqueda: ' . $result['type'];
} else {
    // Consulta exitosa
    $data = $result['data'];
    echo 'Datos encontrados';
}
```

### Troubleshooting

| Problema | Causa | Solución |
|----------|-------|----------|
| "No se pudo obtener Ticket de Acceso" | Certificado no autorizado para `padron` | Solicitar acceso a servicio `padron` en AFIP |
| "Persona no encontrada" | CUIT/CUIL no existe en AFIP | Verificar que el identificador sea válido |
| No retorna DNI en datos | Certificado sin acceso a datos personales | Solicitar ampliación de permisos en AFIP |
| Timeout en consulta | Servidor AFIP no responde | Reintentar, AFIP tiene límites de ~10 req/s |

---

## Siguiente paso

1. Crear un controlador en tu app con los casos anteriores.
2. Extender con validación de respuestas y manejo de observaciones AFIP.
3. Almacenar CAE en base de datos (relación a facturas).
4. Generar PDF/XML del comprobante para envío al cliente.
5. Probar el flujo completo antes de pasar a producción.
