@extends('emails.layout')
@section('content')
    <h2>Multa condonada</h2>

    <p>Hola <strong>{{ $user_name ?? '' }}</strong>, la administración de la biblioteca ha decidido condonar la multa asociada a <strong>{{ $book_title ?? 'tu préstamo' }}</strong>.</p>

    <div class="data-box">
        <div class="data-row">
            <span class="data-label">Monto condonado</span>
            <span class="data-value" style="color:#27ae60;">
                S/ {{ number_format($amount ?? 0, 2) }}
            </span>
        </div>
        <div class="data-row">
            <span class="data-label">Autorizado por</span>
            <span class="data-value">{{ $waived_by ?? '—' }}</span>
        </div>
        @if(!empty($reason))
        <div class="data-row">
            <span class="data-label">Motivo</span>
            <span class="data-value">{{ $reason }}</span>
        </div>
        @endif
    </div>

    <div class="alert alert-ok">
        Tu cuenta está regularizada. Ya puedes solicitar nuevos préstamos.
    </div>
@endsection