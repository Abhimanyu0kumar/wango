<?php

namespace App\Http\Controllers;

use App\Models\User;

abstract class Controller
{
    protected function getUser(): User
    {
        return auth('api')->user();
    }
}
