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
            'admin' => [
                'id'    => $this->id,
                'name'  => $this->profile?->name,
                'email' => $this->email,
            ],
            'token' => $this->token,
        ];
    }
}
