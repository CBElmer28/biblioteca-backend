@extends('emails.layout')
@section('content')
    <h2>⏰ Tu préstamo vence mañana</h2>

    <p>Hola <strong>{{ $user_name ?? '' }}</strong>, este es un recordatorio amistoso.</p>

    <div class="data-box">
        <div class="data-row">
            <span class="data-label">Libro</span>
            <span class="data-value">{{ $book_title ?? '—' }}</span>
        </div>
        @if(!empty($copy_code))
        <div class="data-row">
            <span class="data-label">Ejemplar</span>
            <span class="data-value">{{ $copy_code }}</span>
        </div>
        @endif
        <div class="data-row">
            <span class="data-label">Vence el</span>
            <span class="data-value" style="color:#e0a820;">
                {{ isset($due_at) ? \Carbon\Carbon::parse($due_at)->format('d/m/Y H:i') : '—' }}
            </span>
        </div>
    </div>

    <div class="alert alert-warn">
        <strong>¿No puedes devolver a tiempo?</strong> Puedes solicitar una renovación antes del vencimiento, sujeta a disponibilidad.
    </div>

    <ul class="steps">
        <li class="done">Préstamo registrado</li>
        <li class="active">Vence mañana ← estás aquí</li>
        <li>Devolución</li>
    </ul>
@endsection