<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(title: 'QLNS API', version: '1.0.0', description: 'Tài liệu API hệ thống Quản lý Nhân sự')]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'JWT',
)]
abstract class Controller
{
    //
}
