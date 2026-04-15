@extends('emails.layout')
@section('content')
    <h2>Multa generada en tu cuenta</h2>

    <p>Hola <strong>{{ $user_name ?? '' }}</strong>, se ha registrado una multa asociada al préstamo de <strong>{{ $book_title ?? 'un libro' }}</strong>.</p>

    <div class="data-box">
        <div class="data-row">
            <span class="data-label">Tipo de multa</span>
            <span class="data-value">
                @php
                    $labels = ['overdue' => 'Retraso', 'damage' => 'Daño', 'loss' => 'Pérdida', 'combined' => 'Retraso y daño'];
                @endphp
                {{ $labels[$type ?? ''] ?? ($type ?? '—') }}
            </span>
        </div>
        <div class="data-row">
            <span class="data-label">Monto total</span>
            <span class="data-value" style="color:#c0392b;">
                S/ {{ number_format($total_amount ?? 0, 2) }}
            </span>
        </div>
    </div>

    @php $breakdown = $breakdown ?? []; @endphp
    @if(!empty($breakdown))
    <table class="breakdown">
        <thead>
            <tr><th>Concepto</th><th style="text-align:right">Monto</th></tr>
        </thead>
        <tbody>
            @if(($breakdown['days_overdue'] ?? 0) > 0)
            <tr>
                <td>Retraso ({{ $breakdown['days_overdue'] }} día(s) × S/ 1.00)</td>
                <td style="text-align:right">S/ {{ number_format($breakdown['overdue_amount'] ?? 0, 2) }}</td>
            </tr>
            @endif
            @if(($breakdown['damage_amount'] ?? 0) > 0)
            <tr>
                <td>Daño al ejemplar</td>
                <td style="text-align:right">S/ {{ number_format($breakdown['damage_amount'], 2) }}</td>
            </tr>
            @endif
            @if(($breakdown['loss_amount'] ?? 0) > 0)
            <tr>
                <td>Reposición por pérdida</td>
                <td style="text-align:right">S/ {{ number_format($breakdown['loss_amount'], 2) }}</td>
            </tr>
            @endif
            <tr class="total-row">
                <td>Total</td>
                <td style="text-align:right">S/ {{ number_format($total_amount ?? 0, 2) }}</td>
            </tr>
        </tbody>
    </table>
    @endif

    <p>Acércate a la biblioteca para regularizar el pago. No podrás solicitar nuevos préstamos mientras tengas multas pendientes.</p>
@endsection