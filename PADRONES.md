# Consulta de Padrones AFIP/ARCA

Guía para integrar consultas de padrones de inscripción y datos de CUIT/CUIL/DNI.

## Servicios Web AFIP Disponibles

### 1. **WS Padron de Inscripción (WSPADRON)**
Consulta datos de personas jurídicas (empresas, sociedades, asociaciones).

**Endpoint:**
- Homologación: `https://padsehomo.afip.gov.ar/ws/padron/v1/persona`
- Producción: `https://padse.afip.gov.ar/ws/padron/v1/persona`

**Método:** `POST` (SOAP)

**Parámetros:**
```xml
<request>
    <token>TOKEN_DE_ACCESO</token>
    <sign>SIGN</sign>
    <cuitRepresentante>CUIT_EMPRESA</cuitRepresentante>
    <cuitPersonaConsultada>CUIT_A_CONSULTAR</cuitPersonaConsultada>
</request>
```

**Datos retornados:**
- `cuit`: CUIT de la empresa
- `razonSocial`: Nombre o razón social
- `tipoPersoneria`: Tipo (Empresa individual, SRL, SA, etc.)
- `estado`: Estado del registro
- `domicilio`: Dirección registrada
- `inscripcionesIVA`: Información de inscripción en IVA
- `domicilioFiscal`: Domicilio fiscal

**Códigos de error comunes:**
- `-1`: Error interno
- `-2`: CUIT inválido
- `-3`: Persona no encontrada
- `-4`: Error de autenticación

---

### 2. **WS Padron A13 (WSPRA13 / Persona Física)**
Consulta datos de personas físicas y monotributistas.

**Endpoint:**
- Homologación: `https://padsehomo.afip.gov.ar/ws/padron/a13/persona`
- Producción: `https://padse.afip.gov.ar/ws/padron/a13/persona`

**Método:** `POST` (SOAP)

**Parámetros:**
```xml
<request>
    <token>TOKEN_DE_ACCESO</token>
    <sign>SIGN</sign>
    <cuitRepresentante>CUIT_EMPRESA</cuitRepresentante>
    <cuitPersonaConsultada>CUIT_PERSONA_FISICA</cuitPersonaConsultada>
</request>
```

**Datos retornados:**
- `idPersona`: ID interno AFIP
- `cuit`: CUIT de la persona
- `cuil`: CUIL de la persona
- `nombre`: Nombre completo
- `apellido`: Apellido
- `tipoDocumento`: Tipo de documento (DNI, Pasaporte, etc.)
- `numeroDocumento`: Número de DNI/Pasaporte
- `estado`: Estado del registro (Activo/Inactivo)
- `domicilio`: Domicilio registrado
- `impuestos`: Impuestos a los que está inscripto
- `monotributo`: Si está inscripto en Monotributo
- `empleador`: Si es empleador

---

### 3. **WS Consulta de Datos por DNI**
Servicio para obtener CUIT/CUIL a partir del DNI (cuando es disponible).

**Endpoint:**
- Homologación: `https://padsehomo.afip.gov.ar/ws/padron/personafisica`
- Producción: `https://padse.afip.gov.ar/ws/padron/personafisica`

**Parámetros:**
```xml
<request>
    <token>TOKEN_DE_ACCESO</token>
    <sign>SIGN</sign>
    <cuitRepresentante>CUIT_EMPRESA</cuitRepresentante>
    <numeroDni>DNI_A_CONSULTAR</numeroDni>
</request>
```

**Datos retornados:**
- `cuit`: CUIT asociado al DNI
- `cuil`: CUIL asociado al DNI
- `nombre`: Nombre
- `apellido`: Apellido

---

### 4. **WS Padron de Inscripción General**
Consulta general para ambos tipos de personas (jurídicas y físicas).

**Endpoint:**
- Homologación: `https://padsehomo.afip.gov.ar/ws/padron/v2/persona`
- Producción: `https://padse.afip.gov.ar/ws/padron/v2/persona`

**Método:** `POST` (SOAP)

**Parámetros:**
```xml
<request>
    <token>TOKEN_DE_ACCESO</token>
    <sign>SIGN</sign>
    <cuitRepresentante>CUIT_EMPRESA</cuitRepresentante>
    <cuitPersonaConsultada>CUIT_O_CUIL_A_CONSULTAR</cuitPersonaConsultada>
</request>
```

**Datos retornados:** Combinación de ambos servicios según el tipo de persona.

---

## Módulo Sugerido: `WsPadron.php`

Para integrar estos servicios a tu librería Laravel ARCA, deberías crear un módulo similar a `Wsfev1.php`:

```php
<?php

namespace Mause\LaravelArca\Modules;

use Mause\LaravelArca\Contracts\ArcaClientInterface;

class WsPadron
{
    public function __construct(private ArcaClientInterface $client) {}

    /**
     * Consultar datos de persona jurídica (empresa).
     *
     * @param string|int $companyCuit CUIT de la empresa emisora
     * @param string|int $cuitConsultada CUIT a consultar
     * @return array|null
     */
    public function consultarPersonaJuridica(string|int $companyCuit, string|int $cuitConsultada): ?array
    {
        $ta = $this->client->getTA($companyCuit, 'padron');
        
        if (!$ta) {
            return null;
        }

        $soapClient = $this->client->createSoapClient(
            'https://padsehomo.afip.gov.ar/ws/padron/v1/persona'
        );

        try {
            $request = [
                'token' => $ta['token'],
                'sign' => $ta['sign'],
                'cuitRepresentante' => $companyCuit,
                'cuitPersonaConsultada' => $cuitConsultada,
            ];

            $response = $soapClient->consultarPersonaJuridica($request);
            
            return $this->parseResponse($response);
        } catch (\Exception $e) {
            \Log::error('Error en consultarPersonaJuridica', [
                'exception' => $e->getMessage(),
                'cuit' => $cuitConsultada,
            ]);
            return null;
        }
    }

    /**
     * Consultar datos de persona física (padrón A13).
     *
     * @param string|int $companyCuit CUIT de la empresa emisora
     * @param string|int $cuitPersona CUIT de la persona física
     * @return array|null
     */
    public function consultarPersonaFisica(string|int $companyCuit, string|int $cuitPersona): ?array
    {
        $ta = $this->client->getTA($companyCuit, 'padron');
        
        if (!$ta) {
            return null;
        }

        $soapClient = $this->client->createSoapClient(
            'https://padsehomo.afip.gov.ar/ws/padron/a13/persona'
        );

        try {
            $request = [
                'token' => $ta['token'],
                'sign' => $ta['sign'],
                'cuitRepresentante' => $companyCuit,
                'cuitPersonaConsultada' => $cuitPersona,
            ];

            $response = $soapClient->consultarPersonaFisica($request);
            
            return $this->parseResponse($response);
        } catch (\Exception $e) {
            \Log::error('Error en consultarPersonaFisica', [
                'exception' => $e->getMessage(),
                'cuit' => $cuitPersona,
            ]);
            return null;
        }
    }

    /**
     * Consultar CUIT/CUIL por DNI.
     *
     * @param string|int $companyCuit CUIT de la empresa emisora
     * @param string $numeroDni DNI a consultar
     * @return array|null
     */
    public function consultarPorDni(string|int $companyCuit, string $numeroDni): ?array
    {
        $ta = $this->client->getTA($companyCuit, 'padron');
        
        if (!$ta) {
            return null;
        }

        $soapClient = $this->client->createSoapClient(
            'https://padsehomo.afip.gov.ar/ws/padron/personafisica'
        );

        try {
            $request = [
                'token' => $ta['token'],
                'sign' => $ta['sign'],
                'cuitRepresentante' => $companyCuit,
                'numeroDni' => $numeroDni,
            ];

            $response = $soapClient->consultarPorDni($request);
            
            return $this->parseResponse($response);
        } catch (\Exception $e) {
            \Log::error('Error en consultarPorDni', [
                'exception' => $e->getMessage(),
                'dni' => $numeroDni,
            ]);
            return null;
        }
    }

    /**
     * Consultar cualquier persona (jurídica o física).
     *
     * @param string|int $companyCuit CUIT de la empresa emisora
     * @param string|int $cuitConsultada CUIT o CUIL a consultar
     * @return array|null
     */
    public function consultarPersona(string|int $companyCuit, string|int $cuitConsultada): ?array
    {
        $ta = $this->client->getTA($companyCuit, 'padron');
        
        if (!$ta) {
            return null;
        }

        $soapClient = $this->client->createSoapClient(
            'https://padsehomo.afip.gov.ar/ws/padron/v2/persona'
        );

        try {
            $request = [
                'token' => $ta['token'],
                'sign' => $ta['sign'],
                'cuitRepresentante' => $companyCuit,
                'cuitPersonaConsultada' => $cuitConsultada,
            ];

            $response = $soapClient->consultarPersona($request);
            
            return $this->parseResponse($response);
        } catch (\Exception $e) {
            \Log::error('Error en consultarPersona', [
                'exception' => $e->getMessage(),
                'cuit' => $cuitConsultada,
            ]);
            return null;
        }
    }

    /**
     * Parsear respuesta SOAP a array.
     */
    private function parseResponse($response): ?array
    {
        // Implementar según respuesta SOAP específica
        if (is_object($response)) {
            return (array) $response;
        }
        return $response;
    }
}
```

---

## Uso en Façade

Agregar en `Facades/ArcaWsPadron.php`:

```php
<?php

namespace Mause\LaravelArca\Facades;

use Illuminate\Support\Facades\Facade;

class ArcaWsPadron extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'arca-ws-padron';
    }
}
```

---

## Configuración en `.env`

Agregar las URLs de padrón:

```dotenv
ARCA_WPADRON_HOMO_URL=https://padsehomo.afip.gov.ar/ws/padron
ARCA_WPADRON_PROD_URL=https://padse.afip.gov.ar/ws/padron
```

---

## Uso en Proyecto

```php
use Mause\LaravelArca\Facades\ArcaWsPadron;

$companyCuit = '30712345678';

// Consultar empresa
$empresa = ArcaWsPadron::consultarPersonaJuridica($companyCuit, '20123456780');

// Consultar persona física
$persona = ArcaWsPadron::consultarPersonaFisica($companyCuit, '23123456780');

// Consultar por DNI
$datosporDni = ArcaWsPadron::consultarPorDni($companyCuit, '12345678');

// Consultar cualquiera
$datos = ArcaWsPadron::consultarPersona($companyCuit, $cuitOCuil);
```

---

## Notas Importantes

1. **Autenticación WSAA**: Los padrones usan el mismo esquema WSAA. Necesitarás solicitar acceso al servicio `padron` en el certificado AFIP.

2. **Formato CUIT/CUIL**:
   - CUIT: 11 dígitos (formato: XX-XXXXXXXX-X)
   - CUIL: 11 dígitos (formato: XX-XXXXXXXX-X)
   - El sistema AFIP generalmente acepta ambos formatos (con o sin guiones)

3. **Rates y límites**:
   - AFIP limita a aproximadamente 10 consultas por segundo por empresa
   - Implementar cola de consultas para evitar límites

4. **Dato disponible**:
   - El campo `numeroDocumento` (DNI) **NO siempre está disponible** en la respuesta
   - Esto depende del nivel de autorización del certificado SOAP

5. **Período de validez**: Las respuestas están válidas hasta 24 horas. Considerar cachear.

---

## Errores Comunes

| Error | Causa | Solución |
|-------|-------|----------|
| `-2` | CUIT inválido | Validar formato (11 dígitos) |
| `-3` | Persona no encontrada | Verificar que el CUIT existe en AFIP |
| `-4` | Token inválido/expirado | Solicitar nuevo TA a WSAA |
| `SOAP Fault` | Certificado no autorizado | Solicitar acceso a servicio `padron` |
| Timeout | Servidor AFIP no responde | Implementar reintentos con backoff |

---

## Referencias Oficiales

- **Documentación AFIP**: https://www.afip.gov.ar/sitio/
- **Portal de Servicios**: https://servicios.afip.gov.ar/
- **Solicitar certificado**: https://www.afip.gov.ar/certificados/
