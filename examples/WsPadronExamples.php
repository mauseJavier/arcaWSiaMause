<?php

/**
 * Ejemplos de uso de WsPadron - Consulta de Padrones AFIP
 * 
 * Este archivo contiene ejemplos de los diferentes casos de uso del módulo WsPadron
 * para consultar información de CUIT, CUIL y DNI en los padrones de AFIP.
 * 
 * REQUISITO: El certificado de la empresa debe tener acceso al servicio 'padron'
 * en AFIP. Sin esto, los servicios retornarán errores de autenticación.
 */

namespace Mause\LaravelArca\Examples;

use Mause\LaravelArca\Facades\ArcaWsPadron;

/**
 * CASO 1: Consulta automática detectando el tipo de identificador
 * 
 * El método consultarPadron() detecta automáticamente si pasas:
 * - DNI (8 dígitos)
 * - CUIL (11 dígitos que comienzan con 27 o 28)
 * - CUIT (11 dígitos)
 */
function ejemplo_consulta_automatica()
{
    $companyCuit = '30712345678'; // CUIT de la empresa consultante
    
    // Consulta con DNI (8 dígitos)
    echo "=== Consulta por DNI ===\n";
    $resultDni = ArcaWsPadron::consultarPadron($companyCuit, '12345678');
    
    if ($resultDni && !$resultDni['error']) {
        echo "Tipo detectado: {$resultDni['type']}\n"; // "dni"
        echo "CUIT: {$resultDni['data']['cuit']}\n";
        echo "CUIL: {$resultDni['data']['cuil']}\n";
        echo "Nombre: {$resultDni['data']['nombre']} {$resultDni['data']['apellido']}\n";
        echo "Documento: {$resultDni['data']['tipoDocumento']} {$resultDni['data']['numeroDocumento']}\n";
    } else {
        echo "Error: " . ($resultDni['error'] ?? 'Desconocido') . "\n";
    }
    
    echo "\n";
    
    // Consulta con CUIL (11 dígitos que comienzan con 27)
    echo "=== Consulta por CUIL ===\n";
    $resultCuil = ArcaWsPadron::consultarPadron($companyCuit, '27123456789');
    
    if ($resultCuil && !$resultCuil['error']) {
        echo "Tipo detectado: {$resultCuil['type']}\n"; // "cuil"
        echo "CUIL: {$resultCuil['data']['cuil']}\n";
        echo "Nombre: {$resultCuil['data']['nombre']} {$resultCuil['data']['apellido']}\n";
        echo "Estado: {$resultCuil['data']['estado']}\n";
    } else {
        echo "Error: " . ($resultCuil['error'] ?? 'Desconocido') . "\n";
    }
    
    echo "\n";
    
    // Consulta con CUIT (11 dígitos normales)
    echo "=== Consulta por CUIT ===\n";
    $resultCuit = ArcaWsPadron::consultarPadron($companyCuit, '30998765432');
    
    if ($resultCuit && !$resultCuit['error']) {
        echo "Tipo detectado: {$resultCuit['type']}\n"; // "cuit"
        echo "CUIT: {$resultCuit['data']['cuit']}\n";
        echo "Razón Social: {$resultCuit['data']['razonSocial']}\n";
        echo "Tipo Personería: {$resultCuit['data']['tipoPersoneria']}\n";
        echo "Estado: {$resultCuit['data']['estado']}\n";
    } else {
        echo "Error: " . ($resultCuit['error'] ?? 'Desconocido') . "\n";
    }
}

/**
 * CASO 2: Consulta específica por DNI
 * 
 * Retorna CUIT, CUIL y datos personales asociados al DNI.
 */
function ejemplo_consulta_dni()
{
    $companyCuit = '30712345678';
    $numeroDni = '12345678';
    
    echo "=== Consultando DNI: $numeroDni ===\n";
    
    $result = ArcaWsPadron::consultarPorDni($companyCuit, $numeroDni);
    
    if ($result && !$result['error']) {
        $data = $result['data'];
        
        echo "Tipo: {$result['type']}\n";
        echo "CUIT: {$data['cuit']}\n";
        echo "CUIL: {$data['cuil']}\n";
        echo "Nombre: {$data['nombre']} {$data['apellido']}\n";
        echo "Tipo Documento: {$data['tipoDocumento']}\n";
        echo "Número Documento: {$data['numeroDocumento']}\n";
        echo "Estado: {$data['estado']}\n";
        echo "Monotributo: " . ($data['monotributo'] ? 'Sí' : 'No') . "\n";
        echo "Empleador: " . ($data['empleador'] ? 'Sí' : 'No') . "\n";
        
    } else {
        echo "Error: " . ($result['error'] ?? 'Desconocido') . "\n";
    }
}

/**
 * CASO 3: Consulta de persona jurídica (CUIT)
 * 
 * Retorna razón social, tipo de personería, domicilio y datos de inscripción.
 */
function ejemplo_consulta_cuit()
{
    $companyCuit = '30712345678'; // Empresa consultante
    $cuitAConsultar = '30998765432'; // CUIT a consultar
    
    echo "=== Consultando CUIT: $cuitAConsultar (Empresa) ===\n";
    
    $result = ArcaWsPadron::consultarPersona(
        $companyCuit,
        $cuitAConsultar,
        'cuit'
    );
    
    if ($result && !$result['error']) {
        $data = $result['data'];
        
        echo "Tipo: {$result['type']}\n";
        echo "CUIT: {$data['cuit']}\n";
        echo "Razón Social: {$data['razonSocial']}\n";
        echo "Tipo Personería: {$data['tipoPersoneria']}\n";
        echo "Estado: {$data['estado']}\n";
        echo "Domicilio: " . json_encode($data['domicilio']) . "\n";
        echo "Domicilio Fiscal: " . json_encode($data['domicilioFiscal']) . "\n";
        
    } else {
        echo "Error: " . ($result['error'] ?? 'Desconocido') . "\n";
    }
}

/**
 * CASO 4: Consulta de persona física (CUIL)
 * 
 * Retorna datos personales, impuestos a los que está inscripto.
 */
function ejemplo_consulta_cuil()
{
    $companyCuit = '30712345678'; // Empresa consultante
    $cuilAConsultar = '27123456789'; // CUIL a consultar
    
    echo "=== Consultando CUIL: $cuilAConsultar (Persona Física) ===\n";
    
    $result = ArcaWsPadron::consultarPersona(
        $companyCuit,
        $cuilAConsultar,
        'cuil'
    );
    
    if ($result && !$result['error']) {
        $data = $result['data'];
        
        echo "Tipo: {$result['type']}\n";
        echo "CUIL: {$data['cuil']}\n";
        echo "Nombre: {$data['nombre']} {$data['apellido']}\n";
        echo "DNI: {$data['numeroDocumento']}\n";
        echo "Estado: {$data['estado']}\n";
        echo "Impuestos: " . json_encode($data['impuestos']) . "\n";
        echo "Monotributo: " . ($data['monotributo'] ? 'Sí' : 'No') . "\n";
        
    } else {
        echo "Error: " . ($result['error'] ?? 'Desconocido') . "\n";
    }
}

/**
 * CASO 5: Normalización de identificadores
 * 
 * La librería acepta identificadores con diferentes formatos,
 * normalizando automáticamente.
 */
function ejemplo_normalizacion()
{
    $companyCuit = '30712345678';
    
    echo "=== Normalización de identificadores ===\n";
    
    // Todos estos son equivalentes para DNI:
    $formatos_dni = [
        '12345678',      // Sin guiones
        '12-345-678',    // Con guiones
        12345678,        // Número entero
    ];
    
    foreach ($formatos_dni as $dni) {
        echo "Consultando DNI: " . var_export($dni, true) . "\n";
        $result = ArcaWsPadron::consultarPadron($companyCuit, $dni);
        echo "Tipo detectado: " . ($result ? $result['type'] : 'error') . "\n";
    }
    
    echo "\n";
    
    // Todos estos son equivalentes para CUIT:
    $formatos_cuit = [
        '30712345678',      // Sin guiones
        '30-712345678-9',   // Con guiones
        30712345678,        // Número entero
    ];
    
    foreach ($formatos_cuit as $cuit) {
        echo "Consultando CUIT: " . var_export($cuit, true) . "\n";
        $result = ArcaWsPadron::consultarPadron($companyCuit, $cuit);
        echo "Tipo detectado: " . ($result ? $result['type'] : 'error') . "\n";
    }
}

/**
 * CASO 6: Manejo de errores
 * 
 * Diferentes escenarios de error y cómo tratarlos.
 */
function ejemplo_manejo_errores()
{
    $companyCuit = '30712345678';
    
    echo "=== Manejo de Errores ===\n";
    
    // Escenario 1: Identificador no encontrado
    echo "\nEscenario 1: DNI no encontrado\n";
    $result = ArcaWsPadron::consultarPadron($companyCuit, '99999999');
    
    if ($result === null) {
        echo "Error crítico: No se ejecutó la consulta\n";
    } elseif ($result['error']) {
        echo "Error en consulta: {$result['error']}\n";
        echo "Tipo buscado: {$result['type']}\n";
    } else {
        echo "Datos encontrados\n";
    }
    
    // Escenario 2: Certificado sin permisos
    echo "\nEscenario 2: Certificado sin acceso a 'padron'\n";
    echo "Si el certificado no tiene acceso al servicio, obtendrás:\n";
    echo "Error: 'No se pudo obtener Ticket de Acceso'\n";
    
    // Escenario 3: CUIT consultante inválido
    echo "\nEscenario 3: CUIT consultante inválido\n";
    $result = ArcaWsPadron::consultarPadron('invalid', '12345678');
    if ($result && $result['error']) {
        echo "Error capturado: {$result['error']}\n";
    }
}

/**
 * CASO 7: Caso de uso real - Validación de cliente
 * 
 * Validar datos de un cliente antes de procesar un pedido.
 */
function ejemplo_validacion_cliente($datosCliente)
{
    $companyCuit = '30712345678';
    $identificador = $datosCliente['dni'] ?? $datosCliente['cuit'];
    
    echo "=== Validación de Cliente ===\n";
    
    if (!$identificador) {
        echo "Error: Debes proporcionar DNI o CUIT\n";
        return false;
    }
    
    // Consultar en padrón
    $result = ArcaWsPadron::consultarPadron($companyCuit, $identificador);
    
    if (!$result || $result['error']) {
        echo "Cliente no encontrado en AFIP\n";
        echo "Error: " . ($result['error'] ?? 'Desconocido') . "\n";
        return false;
    }
    
    $data = $result['data'];
    
    // Validar que el nombre coincida
    if (isset($datosCliente['nombre'])) {
        $nombreAfip = $data['nombre'] . ' ' . $data['apellido'];
        
        if (strtoupper($datosCliente['nombre']) !== strtoupper($nombreAfip)) {
            echo "Advertencia: El nombre no coincide exactamente\n";
            echo "Nombre en forma: {$datosCliente['nombre']}\n";
            echo "Nombre en AFIP: $nombreAfip\n";
        }
    }
    
    echo "✓ Cliente validado correctamente\n";
    echo "CUIT: {$data['cuit']}\n";
    echo "Nombre: {$data['nombre']} {$data['apellido']}\n";
    echo "Estado: {$data['estado']}\n";
    
    return $data;
}

/**
 * CASO 8: Caso de uso real - Verificación de monotributista
 * 
 * Determinar si una persona está inscripta en Monotributo.
 */
function ejemplo_verificar_monotributista($numeroDni)
{
    $companyCuit = '30712345678';
    
    echo "=== Verificar si es Monotributista ===\n";
    echo "DNI: $numeroDni\n\n";
    
    $result = ArcaWsPadron::consultarPorDni($companyCuit, $numeroDni);
    
    if (!$result || $result['error']) {
        echo "Error: " . ($result['error'] ?? 'Desconocido') . "\n";
        return null;
    }
    
    $data = $result['data'];
    
    echo "Nombre: {$data['nombre']} {$data['apellido']}\n";
    echo "Estado: {$data['estado']}\n";
    
    if ($data['monotributo']) {
        echo "✓ Es MONOTRIBUTISTA\n";
        echo "CUIT: {$data['cuit']}\n";
        echo "CUIL: {$data['cuil']}\n";
        return 'monotributista';
    } else {
        echo "✗ No es monotributista\n";
        echo "CUIL: {$data['cuil']}\n";
        return 'nomonotributista';
    }
}

/**
 * Ejecutar todos los ejemplos
 */
function ejecutar_todos_ejemplos()
{
    echo "################################\n";
    echo "# EJEMPLOS DE WsPadron\n";
    echo "################################\n\n";
    
    // Ejemplo 1
    echo "EJEMPLO 1: Consulta Automática\n";
    echo "================================\n";
    ejemplo_consulta_automatica();
    
    echo "\n\n";
    
    // Ejemplo 2
    echo "EJEMPLO 2: Consulta por DNI\n";
    echo "============================\n";
    ejemplo_consulta_dni();
    
    echo "\n\n";
    
    // Ejemplo 3
    echo "EJEMPLO 3: Consulta por CUIT\n";
    echo "=============================\n";
    ejemplo_consulta_cuit();
    
    echo "\n\n";
    
    // Ejemplo 4
    echo "EJEMPLO 4: Consulta por CUIL\n";
    echo "=============================\n";
    ejemplo_consulta_cuil();
    
    echo "\n\n";
    
    // Ejemplo 5
    echo "EJEMPLO 5: Normalización\n";
    echo "=========================\n";
    ejemplo_normalizacion();
    
    echo "\n\n";
    
    // Ejemplo 6
    echo "EJEMPLO 6: Manejo de Errores\n";
    echo "=============================\n";
    ejemplo_manejo_errores();
}

// Descomentar para ejecutar:
// ejecutar_todos_ejemplos();
