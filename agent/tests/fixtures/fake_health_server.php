<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path === '/error') {
    http_response_code(500);
    echo 'custom application error page';
    return;
}

if ($path === '/slow') {
    sleep(2);
    http_response_code(200);
    echo 'eventually healthy';
    return;
}

if ($path === '/redirect') {
    header('Location: /ok', true, 302);
    return;
}

http_response_code(200);
echo 'customer-homepage-marker';
