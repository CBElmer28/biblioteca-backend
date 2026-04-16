<?php

// =============================================================================
// config/database.php — Plantilla Maestra de Conexiones DB
// Proyecto: Librería Clásica — Microservicios Laravel
//
// INSTRUCCIONES DE USO:
//   1. Pegar este array `connections` dentro del return [] de tu database.php
//   2. Cambiar DB_SCHEMA en el .env de cada servicio:
//      identity-service  → DB_SCHEMA=identity
//      catalog-service   → DB_SCHEMA=catalog
//      order-service     → DB_SCHEMA=orders
//      payment-service   → DB_SCHEMA=payments
// =============================================================================

return [

    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [

        // =====================================================================
        // CONEXIÓN PRINCIPAL — PgBouncer (Puerto 6543, Transaction Mode)
        // Usar para: Queries, Eloquent ORM, Jobs, todo el flujo normal.
        //
        // IMPORTANTE sobre PgBouncer en Transaction Mode:
        //   - PDO::ATTR_EMULATE_PREPARES = true  → evita "prepared statement
        //     already exists" porque PgBouncer no mantiene estado de sesión.
        //   - PDO::ATTR_PERSISTENT = false        → sin conexiones persistentes;
        //     PgBouncer ya hace pooling por nosotros.
        //   - sslmode=require                     → Supabase exige TLS.
        // =====================================================================
        'pgsql' => [
            'driver'         => 'pgsql',
            'url'            => env('DATABASE_URL'),
            'host'           => env('DB_HOST', '127.0.0.1'),
            'port'           => env('DB_PORT', '6543'),          // PgBouncer
            'database'       => env('DB_DATABASE', 'postgres'),
            'username'       => env('DB_USERNAME', 'postgres'),
            'password'       => env('DB_PASSWORD', ''),
            'charset'        => 'utf8',
            'prefix'         => '',
            'prefix_indexes' => true,

            // Esquema lógico del microservicio (ej: "identity", "catalog"…)
            // Laravel lo usará como search_path de PostgreSQL
            'search_path'    => env('DB_SCHEMA', 'public'),

            'sslmode'        => env('DB_SSLMODE', 'require'),

            'options' => [
                // CRÍTICO para PgBouncer Transaction Mode:
                // Emular prepares en el lado del cliente PHP, no en el servidor
                \PDO::ATTR_EMULATE_PREPARES => true,

                // Sin conexiones persistentes; PgBouncer gestiona el pool
                \PDO::ATTR_PERSISTENT => false,

                // Timeout de conexión (en segundos)
                \PDO::ATTR_TIMEOUT => 10,
            ],
        ],

        // =====================================================================
        // CONEXIÓN DIRECTA — PostgreSQL (Puerto 5432, sin PgBouncer)
        // Usar EXCLUSIVAMENTE para: php artisan migrate
        //
        // POR QUÉ una conexión separada para migraciones:
        //   Las migraciones de Laravel usan advisory locks y transacciones
        //   de larga duración que son INCOMPATIBLES con el Transaction Mode
        //   de PgBouncer. Siempre correr migraciones con:
        //   php artisan migrate --database=pgsql_direct
        // =====================================================================
        'pgsql_direct' => [
            'driver'         => 'pgsql',
            'host'           => env('DB_HOST', '127.0.0.1'),
            'port'           => '5432',                           // Directo a PostgreSQL
            'database'       => env('DB_DATABASE', 'postgres'),
            'username'       => env('DB_USERNAME', 'postgres'),
            'password'       => env('DB_PASSWORD', ''),
            'charset'        => 'utf8',
            'prefix'         => '',
            'prefix_indexes' => true,

            // Mismo esquema que la conexión principal
            'search_path'    => env('DB_SCHEMA', 'public'),

            'sslmode'        => env('DB_SSLMODE', 'require'),

            'options' => [
                // En conexión directa SÍ podemos usar prepares nativos de PG
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::ATTR_PERSISTENT       => false,
            ],
        ],

        // =====================================================================
        // SQLite — Solo para tests unitarios en CI/CD (sin DB real)
        // Usar con: php artisan test --env=testing
        // =====================================================================
        'sqlite' => [
            'driver'                  => 'sqlite',
            'url'                     => env('DATABASE_URL'),
            'database'                => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix'                  => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
        ],

    ],

    // =========================================================================
    // Configuración de Redis (usado como broker de colas y caché)
    // =========================================================================
    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),  // Requiere ext-redis

        'default' => [
            'host'     => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD', null),
            'port'     => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'read_timeout' => -1,
        ],

        // Canal separado para colas de jobs (evita colisión con caché)
        'cache' => [
            'host'     => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD', null),
            'port'     => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],
    ],

    // Migraciones: usar siempre la conexión directa (ver pgsql_direct arriba)
    'migrations' => [
        'table'     => 'migrations',
        'update_date_on_publish' => true,
    ],

];