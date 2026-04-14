@extends('emails.layout')
@section('content')
    <h2>Bienvenido/a, {{ $user_name ?? 'lector' }} 👋</h2>

    <p>Tu cuenta en la <strong>Biblioteca Clásica</strong> ha sido creada exitosamente. Ya puedes acceder al catálogo, solicitar préstamos y consultar tu historial.</p>

    <div class="data-box">
        <div class="data-row">
            <span class="data-label">Correo de acceso</span>
            <span class="data-value">{{ $user_email ?? $email ?? '' }}</span>
        </div>
        <div class="data-row">
            <span class="data-label">Rol asignado</span>
            <span class="data-value">Lector</span>
        </div>
        <div class="data-row">
            <span class="data-label">Préstamos simultáneos</span>
            <span class="data-value">Hasta 3 libros</span>
        </div>
    </div>

    <p>Recuerda que los préstamos físicos tienen un plazo de <strong>14 días</strong> y pueden renovarse hasta <strong>2 veces</strong>.</p>
    <hr>
    <p style="font-size:12px;color:#999;">Si no creaste esta cuenta, ignora este mensaje o contacta a la biblioteca.</p>
@endsection