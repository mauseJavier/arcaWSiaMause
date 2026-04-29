<?php

namespace App\Http\Controllers;

use Mause\LaravelArca\Facades\ArcaWsaa;
use Mause\LaravelArca\Facades\ArcaWsfev1;

/**
 * Ejemplo de controlador para pruebas en homologación.
 * Ubicar en: app/Http/Controllers/ArcaTestController.php
 */
class ArcaTestController extends Controller
{
    private string $companyCuit = '20358337164';

    /**
     * GET /arca/test - Dashboard de pruebas.
     */
    public function index()
    {
        return view('arca-test', [
            'ping' => $this->testPing(),
            'tra' => $this->testTra(),
            'ta' => $this->testTa(),
            'types' => $this->testInvoiceTypes(),
            'lastNumber' => $this->testLastAuthorizedNumber(),
        ]);
    }

    private function testPing(): ?array
    {
        try {
            return app('arca')->ping();
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function testTra(): ?string
    {
        try {
            return ArcaWsaa::generateTra('wsfe');
        } catch (\Exception $e) {
            return 'ERROR: ' . $e->getMessage();
        }
    }

    private function testTa(): ?array
    {
        try {
            return ArcaWsaa::requestTa($this->companyCuit, 'wsfe');
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function testInvoiceTypes(): ?array
    {
        try {
            return ArcaWsfev1::getInvoiceTypes($this->companyCuit);
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function testLastAuthorizedNumber(): ?array
    {
        try {
            return ArcaWsfev1::getLastAuthorizedNumber($this->companyCuit, 1, 6);
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
