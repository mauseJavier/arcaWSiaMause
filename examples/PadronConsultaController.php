<?php

namespace App\Http\Controllers;

use Mause\LaravelArca\Facades\ArcaWsPadron;

class PadronConsultaController extends Controller
{
    /**
     * Consultar padrón automáticamente detectando el tipo de identificador.
     * 
     * Acepta:
     * - DNI: 8 dígitos
     * - CUIL: 11 dígitos que inician con 27 o 28
     * - CUIT: 11 dígitos restantes
     */
    public function consultar(string $identifier)
    {
        $companyCuit = auth()->user()->company_cuit; // Desde la empresa autenticada

        $result = ArcaWsPadron::consultarPadron($companyCuit, $identifier);

        if (!$result || $result['error']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Error en consulta',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'type' => $result['type'], // 'dni', 'cuit', o 'cuil'
            'data' => $result['data'],
        ]);
    }

    /**
     * Consultar específicamente por DNI.
     */
    public function consultarDni(string $numeroDni)
    {
        $companyCuit = auth()->user()->company_cuit;

        $result = ArcaWsPadron::consultarPorDni($companyCuit, $numeroDni);

        return response()->json($result);
    }

    /**
     * Consultar específicamente persona jurídica (CUIT).
     */
    public function consultarEmpresa(string $cuit)
    {
        $companyCuit = auth()->user()->company_cuit;

        $result = ArcaWsPadron::consultarPersona($companyCuit, $cuit, 'cuit');

        return response()->json($result);
    }

    /**
     * Consultar específicamente persona física (CUIL).
     */
    public function consultarPersonaFisica(string $cuil)
    {
        $companyCuit = auth()->user()->company_cuit;

        $result = ArcaWsPadron::consultarPersona($companyCuit, $cuil, 'cuil');

        return response()->json($result);
    }
}
