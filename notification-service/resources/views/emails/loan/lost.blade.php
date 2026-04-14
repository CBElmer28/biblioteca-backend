@extends('emails.layout')
@section('content')
    <h2>📋 Aviso de pérdida de ejemplar</h2>

    <p>Hola <strong>{{ $user_name ?? '' }}</strong>, hemos registrado el reporte de pérdida del siguiente ejemplar.</p>

    <div class="data-box">
        <div class="data-row">
            <span class="data-label">Libro</span>
            <span class="data-value">{{ $book_title ?? '—' }}</span>
        </div>
        @if(!empty($copy_code))
        <div class="data-row">
            <span class="data-label">Código de ejemplar</span>
            <span class="data-value">{{ $copy_code }}</span>
        </div>
        @endif
    </div>

    <div class="alert alert-danger">
        Se ha generado una multa por pérdida equivalente al <strong>doble del costo de adquisición</strong> del ejemplar. Acércate a la biblioteca para regularizar tu situación.
    </div>

    <p>Hasta regularizar esta multa no podrás solicitar nuevos préstamos.</p>
@endsection