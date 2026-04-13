<?php

// =============================================================================
// config/permission.php — Override de Spatie para usar esquema "identity"
// Solo se sobreescriben las claves relevantes; el resto hereda del paquete.
// =============================================================================

return [

    'models' => [
        'permission' => Spatie\Permission\Models\Permission::class,
        'role'       => Spatie\Permission\Models\Role::class,
    ],

    'table_names' => [
        // Todas las tablas del paquete dentro del esquema "identity"
        // El search_path de PostgreSQL ya las enruta correctamente.
        'roles'                 => 'roles',
        'permissions'           => 'permissions',
        'model_has_permissions' => 'model_has_permissions',
        'model_has_roles'       => 'model_has_roles',
        'role_has_permissions'  => 'role_has_permissions',
    ],

    'column_names' => [
        'role_pivot_key'       => null,
        'permission_pivot_key' => null,
        'model_morph_key'      => 'model_id',
        'team_foreign_key'     => 'team_id',
    ],

    // Guardado en caché de permisos (evita N+1 en cada request)
    'cache' => [
        'expiration_time'  => \DateInterval::createFromDateString('24 hours'),
        'key'              => 'spatie.permission.cache',
        'store'            => 'redis',  // Usar Redis como caché de permisos
    ],

    // Sin teams por ahora (puede activarse en fases futuras)
    'teams'                   => false,
    'display_permission_in_exception' => true,
    'display_role_in_exception'       => true,
    'enable_wildcard_permission'      => false,
];