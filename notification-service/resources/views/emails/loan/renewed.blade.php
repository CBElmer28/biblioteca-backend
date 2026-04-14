@extends('emails.layout')
@section('content')
    <h2>Préstamo renovado ✅</h2>

    <p>Hola <strong>{{ $user_name ?? '' }}</strong>, tu préstamo ha sido renovado correctamente.</p>

    <div class="data-box">
        <div class="data-row">
            <span class="data-label">Libro</span>
            <span class="data-value">{{ $book_title ?? '—' }}</span>
        </div>
        <div class="data-row">
            <span class="data-label">Nueva fecha de devolución</span>
            <span class="data-value" style="color:#27ae60;">
                {{ isset($new_due_at) ? \Carbon\Carbon::parse($new_due_at)->format('d/m/Y') : '—' }}
            </span>
        </div>
        <div class="data-row">
            <span class="data-label">Renovaciones restantes</span>
            <span class="data-value">{{ $renewals_left ?? 0 }}</span>
        </div>
    </div>

    @if(($renewals_left ?? 0) === 0)
    <div class="alert alert-warn">
        Has alcanzado el límite de renovaciones. La próxima devolución es definitiva.
    </div>
    @endif
@endsection