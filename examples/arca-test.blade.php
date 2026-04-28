<!-- Blade para renderizar pruebas, ubicar en: resources/views/arca-test.blade.php -->
@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Pruebas ARCA en Homologación</h1>

    <!-- Ping -->
    <div class="card mb-3">
        <div class="card-header">Ping (Verificación conectividad)</div>
        <div class="card-body">
            <pre>{{ json_encode($ping, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        </div>
    </div>

    <!-- TRA -->
    <div class="card mb-3">
        <div class="card-header">TRA (Ticket de Requerimiento)</div>
        <div class="card-body">
            <p>Primeros 500 caracteres:</p>
            <pre>{{ substr($tra, 0, 500) }}...</pre>
        </div>
    </div>

    <!-- TA -->
    <div class="card mb-3">
        <div class="card-header">TA (Ticket de Acceso)</div>
        <div class="card-body">
            @if (isset($ta['error']))
                <p class="text-danger">{{ $ta['error'] }}</p>
            @else
                <p><strong>Token:</strong> {{ substr($ta['token'], 0, 20) }}...</p>
                <p><strong>Sign:</strong> {{ substr($ta['sign'], 0, 20) }}...</p>
                <p><strong>Expira:</strong> {{ $ta['expires_at'] }}</p>
            @endif
        </div>
    </div>

    <!-- Tipos de comprobantes -->
    <div class="card mb-3">
        <div class="card-header">Tipos de Comprobantes</div>
        <div class="card-body">
            @if (is_array($types))
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($types as $type)
                            <tr>
                                <td>{{ $type['id'] }}</td>
                                <td>{{ $type['name'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="text-danger">ERROR: {{ $types['error'] ?? 'desconocido' }}</p>
            @endif
        </div>
    </div>

    <!-- Último comprobante -->
    <div class="card mb-3">
        <div class="card-header">Último Comprobante Autorizado (PtoVta 1, Tipo 6)</div>
        <div class="card-body">
            @if (isset($lastNumber['error']))
                <p class="text-danger">{{ $lastNumber['error'] }}</p>
            @else
                <p><strong>Número:</strong> {{ $lastNumber['cbte_nro'] }}</p>
            @endif
        </div>
    </div>
</div>
@endsection
