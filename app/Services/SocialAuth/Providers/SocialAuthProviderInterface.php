<?php

namespace App\Services\SocialAuth\Providers;

interface SocialAuthProviderInterface
{
    /**
     * Get the authorization URL for the provider
     */
    public function getAuthorizationUrl(string $redirectUri, string $state): string;
    
    /**
     * Exchange an authorization code for access and/or ID tokens
     */
    public function exchangeCodeForTokens(string $code, string $redirectUri): array;
    
    /**
     * Get the user profile from the provider
     */
    public function getUserProfile(string $accessToken): array;
}