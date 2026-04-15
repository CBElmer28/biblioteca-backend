<?php

return [
    'inventory' => [
        'url' => env('INVENTORY_SERVICE_URL', 'http://inventory-service:8000'),
    ],
    'penalty' => [
        'url' => env('PENALTY_SERVICE_URL', 'http://penalty-service:8000'),
    ],
];