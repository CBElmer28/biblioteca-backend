@extends('emails.layout')
@section('content')
    <h2>⚠ Tu préstamo ha vencido</h2>

    <p>Hola <strong>{{ $user_name ?? '' }}</strong>, el plazo de devolución ha expirado.</p>

    <div class="data-box">
        <div class="data-row">
            <span class="data-label">Libro</span>
            <span class="data-value">{{ $book_title ?? '—' }}</span>
        </div>
        <div class="data-row">
            <span class="data-label">Venció el</span>
            <span class="data-value" style="color:#c0392b;">
                {{ isset($due_at) ? \Carbon\Carbon::parse($due_at)->format('d/m/Y') : '—' }}
            </span>
        </div>
        <div class="data-row">
            <span class="data-label">Días de retraso</span>
            <span class="data-value" style="color:#c0392b;">{{ $days_overdue ?? 0 }} día(s)</span>
        </div>
    </div>

    <div class="alert alert-danger">
        Se está acumulando una multa de <strong>S/ 1.00 por día</strong> de retraso.
        Acumulado estimado: <strong>S/ {{ number_format(($days_overdue ?? 0) * 1.00, 2) }}</strong>.
    </div>

    <p>Por favor, devuelve el ejemplar a la brevedad posible para detener el cómputo de la multa.</p>

    <ul class="steps">
        <li class="done">Préstamo registrado</li>
        <li class="done">Vencimiento superado</li>
        <li class="active">⚠ Acumulando multa ← estás aquí</li>
        <li>Devolución y liquidación</li>
    </ul>
@endsection