<?php

return [
    'secret' => env('JWT_SECRET'),
    'algo' => 'HS256',
    'access_ttl_minutes' => (int) env('JWT_ACCESS_TTL', 15),
    'refresh_ttl_days' => (int) env('JWT_REFRESH_TTL_DAYS', 7),
];
