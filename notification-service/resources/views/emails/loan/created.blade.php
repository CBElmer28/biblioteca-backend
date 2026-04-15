@extends('emails.layout')
@section('content')
    <h2>Préstamo registrado ✅</h2>

    <p>Hola <strong>{{ $user_name ?? '' }}</strong>, tu préstamo ha sido registrado correctamente.</p>

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
        <div class="data-row">
            <span class="data-label">Tipo</span>
            <span class="data-value">{{ ($is_digital ?? false) ? 'E-book (digital)' : 'Libro físico' }}</span>
        </div>
        <div class="data-row">
            <span class="data-label">Fecha de préstamo</span>
            <span class="data-value">
                {{ isset($loaned_at) ? \Carbon\Carbon::parse($loaned_at)->format('d/m/Y H:i') : '—' }}
            </span>
        </div>
        <div class="data-row">
            <span class="data-label">Fecha de devolución</span>
            <span class="data-value" style="color:#c0392b;">
                {{ isset($due_at) ? \Carbon\Carbon::parse($due_at)->format('d/m/Y') : '—' }}
            </span>
        </div>
    </div>

    @if(!($is_digital ?? false))
    <div class="alert alert-info">
        La devolución fuera de plazo genera una multa de <strong>S/ 1.00 por día</strong> de retraso.
    </div>
    @else
    <div class="alert alert-ok">
        Los e-books no generan multas por retraso. El acceso expira automáticamente al vencimiento.
    </div>
    @endif
@endsection