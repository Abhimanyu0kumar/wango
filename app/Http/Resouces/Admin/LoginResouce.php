<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoginResource extends JsonResource
{
    protected $token;

    public function __construct($resource, $token = null)
    {
        parent::__construct($resource);
        $this->token = $token;
    }

    public function toArray(Request $request): array
    {
        return [
            'user' => [
                'id'    => $this->id,
                'name'  => $this->profile?->name,
                'email' => $this->email,
                'roles' => $this->roles?->pluck('name') ?? [],
                'permissions' => $this->getAllPermissions()->pluck('name') ?? [],
            ],

            'auth' => [
                'token' => $this->token,
                'type'  => 'Bearer',
            ],

            'meta' => [
                'login_at' => now()->toDateTimeString(),
            ],
        ];
    }
}
