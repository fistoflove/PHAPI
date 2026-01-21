<?php

declare(strict_types=1);

namespace PHAPI\Examples\MultiFile\Controllers;

use PHAPI\HTTP\Response;

final class UserController
{
    public function index(): Response
    {
        return Response::json([
            'users' => [
                ['id' => 1, 'name' => 'Ada'],
                ['id' => 2, 'name' => 'Linus'],
            ],
        ]);
    }
}
