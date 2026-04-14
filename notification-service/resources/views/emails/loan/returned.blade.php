@extends('emails.layout')
@section('content')
    <h2>Devolución confirmada ✅</h2>

    <p>Hola <strong>{{ $user_name ?? '' }}</strong>, hemos registrado la devolución del siguiente libro.</p>

    <div class="data-box">
        <div class="data-row">
            <span class="data-label">Libro</span>
            <span class="data-value">{{ $book_title ?? '—' }}</span>
        </div>
        <div class="data-row">
            <span class="data-label">Días de retraso</span>
            <span class="data-value">{{ ($days_overdue ?? 0) > 0 ? ($days_overdue . ' día(s)') : 'Ninguno ✓' }}</span>
        </div>
        <div class="data-row">
            <span class="data-label">Estado del ejemplar</span>
            <span class="data-value">{{ ($had_damage ?? false) ? '⚠ Daño detectado' : 'Sin daños ✓' }}</span>
        </div>
    </div>

    @if(($days_overdue ?? 0) > 0 || ($had_damage ?? false))
    <div class="alert alert-warn">
        Se ha generado una multa por este préstamo. Consulta el detalle en tu perfil o acércate a la biblioteca.
    </div>
    @else
    <div class="alert alert-ok">
        Devolución sin incidencias. ¡Gracias por cuidar nuestros libros!
    </div>
    @endif
@endsection