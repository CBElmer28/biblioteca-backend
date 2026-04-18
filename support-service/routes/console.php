<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

Artisan::command('glpi:list-assets', function () {
    $this->info('🔍 Consultando el directorio raíz de la API de GLPI...');
    
    // Usamos la URL correcta que ya configuramos en tu .env (http://glpi:80/api.php/v1)
    $url = rtrim(config('glpi.url'), '/'); 
    $appToken = config('glpi.app_token');
    $userToken = config('glpi.user_token');

    // 1. Iniciar Sesión
    $response = Http::withHeaders([
        'App-Token' => $appToken, 
        'Authorization' => "user_token {$userToken}"
    ])->get("{$url}/initSession");

    if ($response->failed()) {
        $this->error("❌ Error al iniciar sesión en GLPI: " . $response->body());
        return;
    }

    $session = $response->json('session_token');

    // 2. Obtener el diccionario maestro (Todos los endpoints)
    $endpoints = Http::withHeaders([
        'App-Token' => $appToken, 
        'Session-Token' => $session
    ])->get("{$url}/")->json();

    if (!is_array($endpoints)) {
        $this->error('❌ La API no devolvió una lista válida.');
        return;
    }

    // 3. Buscar cualquier activo que contenga la palabra "libro" (sin importar mayúsculas)
    $resultados = array_filter(array_keys($endpoints), fn($key) => stripos($key, 'libro') !== false);

    $this->newLine();
    if (empty($resultados)) {
        $this->error('⚠️ GLPI no tiene NINGÚN endpoint que contenga la palabra "libro".');
        $this->line('💡 Tip: ¿Le pusiste otro nombre en GLPI (ej. "AssetLibrary", "Publicacion")?');
        
        // Guardamos todo el listado por si quieres revisar manualmente
        file_put_contents(storage_path('logs/glpi_endpoints_full.json'), json_encode(array_keys($endpoints), JSON_PRETTY_PRINT));
        $this->info('📄 He guardado la lista completa de activos en storage/logs/glpi_endpoints_full.json');
    } else {
        $this->info('✅ ¡Encontrados! Estos son los nombres exactos que debes poner en tu .env:');
        foreach ($resultados as $res) {
            $this->line("   👉 <fg=green>{$res}</>");
        }
    }
    $this->newLine();
});