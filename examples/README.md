# Ejemplos de Uso - Laravel ARCA

Este directorio contiene ejemplos de cómo integrar y usar la librería Laravel ARCA en una aplicación real.

## Archivos

### `ArcaTestController.php`
Controlador de ejemplo que muestra cómo usar los servicios de facturación (WSFEv1).

### `arca-test.blade.php`
Vista Blade de ejemplo para probar la librería desde una ruta HTTP.

### `PadronConsultaController.php` ✨ NUEVO
Controlador de ejemplo para consultar padrones (CUIT, CUIL, DNI).

**Métodos:**
- `consultar()` - Consulta automática detectando el tipo
- `consultarDni()` - Consulta específica por DNI
- `consultarEmpresa()` - Consulta específica por CUIT
- `consultarPersonaFisica()` - Consulta específica por CUIL

**Uso en rutas:**
```php
Route::post('/padron/consultar/{identifier}', [PadronConsultaController::class, 'consultar']);
Route::post('/padron/dni/{numeroDni}', [PadronConsultaController::class, 'consultarDni']);
Route::post('/padron/cuit/{cuit}', [PadronConsultaController::class, 'consultarEmpresa']);
Route::post('/padron/cuil/{cuil}', [PadronConsultaController::class, 'consultarPersonaFisica']);
```

### `WsPadronExamples.php` ✨ NUEVO
Archivo con 8 ejemplos completos de uso del módulo WsPadron:

1. **Consulta automática** - Detecta DNI, CUIT o CUIL automáticamente
2. **Consulta por DNI** - Buscar CUIT/CUIL a partir de DNI
3. **Consulta por CUIT** - Datos de persona jurídica (empresa)
4. **Consulta por CUIL** - Datos de persona física
5. **Normalización** - Aceptar diferentes formatos de identificadores
6. **Manejo de errores** - Cómo tratar errores comunes
7. **Validación de cliente** - Caso real: validar cliente antes de procesar
8. **Verificar monotributista** - Caso real: determinar si es monotributista

**Para usar:**
```php
// En Tinker o ruta
require_once 'examples/WsPadronExamples.php';
\Mause\LaravelArca\Examples\ejemplo_consulta_automatica();
```

### `WsPadronTest.php` ✨ NUEVO
Tests unitarios de ejemplo para el módulo WsPadron.

**Pruebas incluidas:**
- Detección automática de DNI, CUIL, CUIT
- Normalización de identificadores (con/sin guiones)
- Estructura de respuestas
- Manejo de errores
- Validación de datos retornados

**Para ejecutar:**
```bash
# Dentro del proyecto Laravel
php artisan tinker
> require_once 'examples/WsPadronTest.php';
```

O con PHPUnit:
```bash
# Si la clase está en tests/
php artisan test tests/WsPadronTest.php
```

### `ptos_prod.php`
Script de ejemplo para consultar puntos de venta en produccion (WSFEv1).

**Uso:**
```bash
docker compose run --rm arca-dev php /workspace/examples/ptos_prod.php
```

### `factura_c_prod.php`
Script de ejemplo para emitir una Factura C en produccion.

**Uso:**
```bash
docker compose run --rm arca-dev php /workspace/examples/factura_c_prod.php
```

### `padron_prod.php`
Script de ejemplo para consultar padron en produccion con un CUIT representante.

**Uso:**
```bash
docker compose run --rm arca-dev php /workspace/examples/padron_prod.php
```

---

## Flujo Típico de Integración

### 1. Configuración Inicial

```php
// .env
ARCA_MODE=homologation
ARCA_CERT_PATH_PATTERN=storage/app/public/%s/cert.crt
ARCA_KEY_PATH_PATTERN=storage/app/public/%s/key.key
```

### 2. Usar en Controlador

```php
use Mause\LaravelArca\Facades\ArcaWsPadron;

class ClienteController extends Controller
{
    public function store(Request $request)
    {
        $companyCuit = auth()->user()->company_cuit;
        
        // Validar cliente en AFIP
        $result = ArcaWsPadron::consultarPadron($companyCuit, $request->identificador);
        
        if ($result['error']) {
            return response()->json(['error' => $result['error']], 400);
        }
        
        // Guardar cliente
        Cliente::create([
            'nombre' => $result['data']['nombre'],
            'apellido' => $result['data']['apellido'],
            'documento' => $result['data']['numeroDocumento'],
            'cuit' => $result['data']['cuit'],
        ]);
    }
}
```

### 3. Usar en Rutas

```php
// routes/api.php
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/padron/consultar/{id}', [PadronConsultaController::class, 'consultar']);
});
```

---

## Detección Automática de Tipo

| Entrada | Tipo Detectado | Endpoint |
|---------|---|---|
| `12345678` (8 dígitos) | `dni` | `padron/personafisica?wsdl` |
| `27123456789` (11, inicia 27) | `cuil` | `padron/a13/persona?wsdl` |
| `28123456789` (11, inicia 28) | `cuil` | `padron/a13/persona?wsdl` |
| `30112345678` (11, otro) | `cuit` | `padron/v1/persona?wsdl` |

---

## Respuesta Automática

```php
[
    'type' => 'dni' | 'cuil' | 'cuit',
    'data' => [
        // Para DNI/CUIL:
        'cuit' => '20123456780',
        'cuil' => '27123456789',
        'nombre' => 'Juan',
        'apellido' => 'Pérez',
        'numeroDocumento' => '12345678',
        'estado' => 'Activo',
        
        // Para CUIT (empresa):
        'razonSocial' => 'Empresa SA',
        'tipoPersoneria' => 'SRL',
        'domicilio' => [...],
    ],
    'error' => null | 'Mensaje de error'
]
```

---

## Troubleshooting

### Error: "No se pudo obtener Ticket de Acceso"
**Causa**: El certificado no tiene acceso al servicio `padron`  
**Solución**: Solicitar acceso en https://www.afip.gov.ar/

### Error: "Persona no encontrada"
**Causa**: El CUIT/CUIL/DNI no existe o es inválido  
**Solución**: Verificar el identificador ingresado

### No retorna campo DNI
**Causa**: Certificado sin permisos para retornar datos personales  
**Solución**: Solicitar ampliación de permisos en AFIP

### Timeout en consulta
**Causa**: Servidor AFIP no responde o límite de consultas excedido  
**Solución**: Reintentar después de unos segundos. AFIP limita a ~10 req/s

---

## Referencia Rápida

```php
use Mause\LaravelArca\Facades\ArcaWsPadron;

$cuit = auth()->user()->company_cuit;

// Detección automática
$res = ArcaWsPadron::consultarPadron($cuit, $id);

// Específico DNI
$res = ArcaWsPadron::consultarPorDni($cuit, $dni);

// Específico CUIT
$res = ArcaWsPadron::consultarPersona($cuit, $cuit_a_consultar, 'cuit');

// Específico CUIL
$res = ArcaWsPadron::consultarPersona($cuit, $cuil_a_consultar, 'cuil');

// Verificar resultado
if ($res && !$res['error']) {
    $datos = $res['data'];
    // ...
}
```

---

## Documentación Completa

- [HOMOLOGACION.md](../HOMOLOGACION.md) - Guía de pruebas (incluye secciones 8-11)
- [README.md](../README.md) - Referencia rápida
- [PADRONES.md](../PADRONES.md) - Documentación técnica de servicios
