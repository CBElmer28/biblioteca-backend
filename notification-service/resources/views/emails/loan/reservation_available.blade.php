@extends('emails.layout')
@section('content')
    <h2>📬 Tu libro reservado está disponible</h2>

    <p>Hola <strong>{{ $user_name ?? '' }}</strong>, el libro que reservaste ya está disponible para retirar.</p>

    <div class="data-box">
        <div class="data-row">
            <span class="data-label">Libro</span>
            <span class="data-value">{{ $book_title ?? '—' }}</span>
        </div>
        <div class="data-row">
            <span class="data-label">Disponible hasta</span>
            <span class="data-value" style="color:#e0a820;">
                {{ isset($expires_at) ? \Carbon\Carbon::parse($expires_at)->format('d/m/Y H:i') : '—' }}
            </span>
        </div>
    </div>

    <div class="alert alert-warn">
        Tienes <strong>48 horas</strong> para acercarte a la biblioteca y retirar el libro. Pasado ese plazo, la reserva expirará y el libro pasará al siguiente lector en cola.
    </div>
@endsection