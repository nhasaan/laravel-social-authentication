<?php

namespace App\Services\SocialAuth\Providers;

use Illuminate\Support\Facades\Http;
use App\Services\SocialAuth\Exceptions\TokenExchangeException;
use App\Services\SocialAuth\Exceptions\UserInfoFetchException;

class FacebookAuthProvider implements SocialAuthProviderInterface
{
    protected $clientId;
    protected $clientSecret;

    public function __construct()
    {
        $this->clientId = config('social-auth.providers.facebook.client_id');
        $this->clientSecret = config('social-auth.providers.facebook.client_secret');
    }

    public function getAuthorizationUrl(string $redirectUri, string $state): string
    {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'response_type' => 'code',
            'scope' => 'email,public_profile',
        ];

        return 'https://www.facebook.com/v16.0/dialog/oauth?' . http_build_query($params);
    }

    public function exchangeCodeForTokens(string $code, string $redirectUri): array
    {
        $response = Http::get('https://graph.facebook.com/v16.0/oauth/access_token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);

        if ($response->failed()) {
            throw new TokenExchangeException('Facebook token exchange failed: ' . $response->body());
        }

        return $response->json();
    }

    public function getUserProfile(string $accessToken): array
    {
        $response = Http::get('https://graph.facebook.com/v16.0/me', [
            'access_token' => $accessToken,
            'fields' => 'id,name,email,picture.width(200).height(200)',
        ]);

        if ($response->failed()) {
            throw new UserInfoFetchException('Facebook user info fetch failed: ' . $response->body());
        }

        $profile = $response->json();

        return [
            'id' => $profile['id'],
            'email' => $profile['email'] ?? null,
            'name' => $profile['name'] ?? null,
            'picture' => isset($profile['picture']['data']['url']) ? $profile['picture']['data']['url'] : null,
            'raw' => $profile,
        ];
    }
}
