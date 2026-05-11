<?php

declare(strict_types=1);

namespace App\OpenApi;

use OpenApi\Attributes as OAT;

#[OAT\Info(
    version: '0.1.0',
    title: 'API Server'
)]
class Info
{
}
