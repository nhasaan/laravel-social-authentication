<?php

namespace App\Http\Resources\API;

use Illuminate\Http\Resources\Json\JsonResource;

class AuthResource extends JsonResource
{
    public static $wrap = null;
    
    public function toArray($request)
    {
        return [
            'token_type' => $this->resource['token_type'] ?? 'Bearer',
            'access_token' => $this->resource['access_token'] ?? null,
            'refresh_token' => $this->resource['refresh_token'] ?? null,
            'expires_in' => $this->resource['expires_in'] ?? null,
            'user' => [
                'msisdn' => $this->resource['user']['msisdn'] ?? null,
                'name' => $this->resource['user']['name'] ?? null,
                'email' => $this->resource['user']['email'] ?? null,
                'provider' => $this->resource['user']['provider'] ?? null,
                'provider_id' => $this->resource['user']['provider_id'] ?? null,
            ],
        ];
    }
}