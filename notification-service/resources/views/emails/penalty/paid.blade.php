@extends('emails.layout')
@section('content')
    <h2>✅ Multa saldada completamente</h2>

    <p>Hola <strong>{{ $user_name ?? '' }}</strong>, tu multa ha sido liquidada en su totalidad.</p>

    <div class="data-box">
        <div class="data-row">
            <span class="data-label">Libro</span>
            <span class="data-value">{{ $book_title ?? '—' }}</span>
        </div>
        <div class="data-row">
            <span class="data-label">Total pagado</span>
            <span class="data-value" style="color:#27ae60;">
                S/ {{ number_format($total_paid ?? 0, 2) }}
            </span>
        </div>
    </div>

    <div class="alert alert-ok">
        Tu cuenta está al día. Ya puedes solicitar nuevos préstamos.
    </div>
@endsection