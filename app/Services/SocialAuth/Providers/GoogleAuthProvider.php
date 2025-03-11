<?php

namespace App\Services\SocialAuth\Providers;

use Illuminate\Support\Facades\Http;
use App\Services\SocialAuth\Exceptions\TokenExchangeException;
use App\Services\SocialAuth\Exceptions\UserInfoFetchException;

class GoogleAuthProvider implements SocialAuthProviderInterface
{
    protected $clientId;
    protected $clientSecret;
    protected $oAuthBaseUrl = 'https://accounts.google.com/o/oauth2/v2/auth?';
    protected $tokenExchangeBaseUrl = 'https://oauth2.googleapis.com/token';
    protected $userInfoBaseUrl = 'https://www.googleapis.com/oauth2/v3/userinfo';

    public function __construct()
    {
        $this->clientId = config('social-auth.providers.google.client_id');
        $this->clientSecret = config('social-auth.providers.google.client_secret');
        $this->oAuthBaseUrl = config('social-auth.providers.google.oauth_base_url');
        $this->tokenExchangeBaseUrl = config('social-auth.providers.google.token_base_url');
        $this->userInfoBaseUrl = config('social-auth.providers.google.user_info_base_url');
    }

    public function getAuthorizationUrl(string $redirectUri, string $state): string
    {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'email profile openid',
            'state' => $state,
            'prompt' => 'select_account',
            'access_type' => 'offline',
        ];

        return $this->oAuthBaseUrl . http_build_query($params);
    }

    public function exchangeCodeForTokens(string $code, string $redirectUri): array
    {
        $response = Http::post($this->tokenExchangeBaseUrl, [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ]);

        if ($response->failed()) {
            throw new TokenExchangeException('Google token exchange failed: ' . $response->body());
        }

        return $response->json();
    }

    public function getUserProfile(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->get($this->userInfoBaseUrl);

        if ($response->failed()) {
            throw new UserInfoFetchException('Google user info fetch failed: ' . $response->body());
        }

        $profile = $response->json();

        return [
            'id' => $profile['sub'],
            'email' => $profile['email'] ?? null,
            'email_verified' => $profile['email_verified'] ?? false,
            'name' => $profile['name'] ?? null,
            'given_name' => $profile['given_name'] ?? null,
            'family_name' => $profile['family_name'] ?? null,
            'picture' => $profile['picture'] ?? null,
            'locale' => $profile['locale'] ?? null,
            'raw' => $profile,
        ];
    }
}
