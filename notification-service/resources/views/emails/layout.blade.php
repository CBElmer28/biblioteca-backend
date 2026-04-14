<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: Georgia, 'Times New Roman', serif;
            background: #f0ece4;
            color: #2c2522;
            line-height: 1.7;
        }
        .wrapper   { max-width:620px; margin:0 auto; background:#fff;
                     border-radius:3px; overflow:hidden;
                     box-shadow:0 2px 10px rgba(0,0,0,.10); }
        .header    { background:#1c2b3a; padding:32px 40px; text-align:center; }
        .logo      { font-size:22px; font-weight:bold; color:#f0ece4;
                     letter-spacing:3px; text-transform:uppercase; }
        .logo-sub  { color:#a8996e; font-size:11px; letter-spacing:4px;
                     margin-top:4px; }
        .accent-bar{ height:3px;
                     background:linear-gradient(90deg,#a8996e,#d4c08a,#a8996e); }
        .body      { padding:36px 40px; }
        h2         { font-size:19px; color:#1c2b3a; margin-bottom:16px;
                     font-weight:normal; border-bottom:1px solid #ede8df;
                     padding-bottom:10px; }
        p          { margin-bottom:13px; font-size:14px; color:#3d3530; }

        /* Caja de datos destacados */
        .data-box  { background:#f9f6f0; border-left:4px solid #a8996e;
                     padding:18px 22px; margin:20px 0; border-radius:2px; }
        .data-row  { display:flex; justify-content:space-between;
                     padding:5px 0; border-bottom:1px solid #ede8df;
                     font-size:13px; }
        .data-row:last-child { border-bottom:none; }
        .data-label{ color:#888; }
        .data-value{ font-weight:bold; color:#1c2b3a; }

        /* Alertas */
        .alert     { padding:14px 18px; margin:18px 0; border-radius:2px;
                     font-size:13px; }
        .alert-warn{ background:#fff8ec; border-left:4px solid #e0a820; }
        .alert-danger{background:#fff2f2; border-left:4px solid #c0392b; }
        .alert-ok  { background:#f0fff4; border-left:4px solid #27ae60; }
        .alert-info{ background:#eef4fb; border-left:4px solid #2980b9; }

        /* Pasos de progreso */
        .steps     { list-style:none; margin:18px 0; }
        .steps li  { padding:7px 0 7px 28px; position:relative;
                     font-size:13px; color:#888;
                     border-bottom:1px solid #f0ece4; }
        .steps li::before { content:'○'; position:absolute; left:0;
                            color:#a8996e; font-size:16px; }
        .steps li.done { color:#1c2b3a; font-weight:bold; }
        .steps li.done::before { content:'●'; color:#27ae60; }
        .steps li.active{ color:#1c2b3a; }
        .steps li.active::before{ content:'●'; color:#a8996e; }

        /* Tabla de desglose */
        .breakdown { width:100%; border-collapse:collapse; margin:16px 0;
                     font-size:13px; }
        .breakdown th { background:#1c2b3a; color:#f0ece4; padding:9px 12px;
                        text-align:left; font-weight:normal; font-size:11px;
                        text-transform:uppercase; letter-spacing:1px; }
        .breakdown td { padding:8px 12px; border-bottom:1px solid #ede8df; }
        .breakdown .total-row td { font-weight:bold; color:#1c2b3a;
                                   border-top:2px solid #1c2b3a;
                                   border-bottom:none; }

        /* Botón CTA */
        .btn       { display:inline-block; background:#1c2b3a; color:#f0ece4 !important;
                     text-decoration:none; padding:12px 28px; border-radius:2px;
                     font-size:13px; letter-spacing:1px;
                     text-transform:uppercase; margin:16px 0; }
        hr         { border:none; border-top:1px solid #ede8df; margin:22px 0; }

        .footer    { background:#f0ece4; padding:22px 40px; text-align:center;
                     font-size:11px; color:#999;
                     border-top:1px solid #e0dbd2; }
        .footer a  { color:#a8996e; text-decoration:none; }

        @media(max-width:640px){
            .body,.header,.footer{ padding:24px 20px; }
        }
    </style>
</head>
<body>
<table width="100%" cellpadding="0" cellspacing="0">
<tr><td align="center" style="padding:20px 12px;">
<div class="wrapper">

    <div class="header">
        <div class="logo">📚 Biblioteca Clásica</div>
        <div class="logo-sub">sistema de gestión bibliotecaria</div>
    </div>
    <div class="accent-bar"></div>

    <div class="body">
        @yield('content')
    </div>

    <div class="footer">
        <p>
            <a href="{{ config('app.url') }}">Biblioteca Clásica</a>
            &nbsp;·&nbsp; Av. de la Cultura 123, Lima, Perú
        </p>
        <p style="margin-top:6px;">
            Soporte: <a href="mailto:soporte@biblioteca-clasica.pe">soporte@biblioteca-clasica.pe</a>
        </p>
        <p style="margin-top:10px; font-size:10px;">
            Este correo fue generado automáticamente. Por favor no respondas directamente.
        </p>
    </div>

</div>
</td></tr>
</table>
</body>
</html>