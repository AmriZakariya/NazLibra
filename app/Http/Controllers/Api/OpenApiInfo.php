<?php

namespace App\Http\Controllers\Api;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'NazLibra POS API',
    description: 'Offline-first REST API for NazLibra POS mobile application. All protected routes require Bearer token + X-Tenant-Slug header.',
    contact: new OA\Contact(email: 'support@omnevo.net'),
)]
#[OA\Server(url: '/', description: 'Current server')]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'JWT',
)]
class OpenApiInfo {}
