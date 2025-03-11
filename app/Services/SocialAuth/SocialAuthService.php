<?php

namespace App\Services\SocialAuth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Services\SocialAuth\Providers\SocialAuthProviderInterface;
use App\Services\SocialAuth\Exceptions\InvalidStateException;
use App\Services\SocialAuth\Exceptions\TokenExchangeException;
use App\Services\SocialAuth\Exceptions\UserInfoFetchException;
use App\Services\SocialAuth\Exceptions\IAMServiceException;
use Illuminate\Support\Facades\Config;

class SocialAuthService
{
    protected $providers = [];
    protected $iamServiceBaseUrl;
    protected $iamClientUid;
    protected $iamClientKey;
    protected $iamClientSecret;
    protected $iamLang;
    protected $iamHost;


    public function __construct()
    {
        $this->iamServiceBaseUrl = Config::get('social-auth.iam.base_url');
        $this->iamClientUid = Config::get('social-auth.iam.client_uid');
        $this->iamClientKey = Config::get('social-auth.iam.client_key');
        $this->iamClientSecret = Config::get('social-auth.iam.client_secret');
        $this->iamLang = Config::get('social-auth.iam.lang', 'bn');
        $this->iamHost = Config::get('social-auth.iam.host');

        // Debug logging to see what configuration we're loading
        Log::debug('SocialAuthService configuration', [
            'iam_base_url' => $this->iamServiceBaseUrl,
            'iam_client_uid' => $this->iamClientUid,
            'all_config' => Config::get('social-auth'),
        ]);
    }

    public function registerProvider(string $name, SocialAuthProviderInterface $provider): self
    {
        $this->providers[$name] = $provider;
        return $this;
    }

    public function getProvider(string $name): SocialAuthProviderInterface
    {
        if (!isset($this->providers[$name])) {
            throw new \InvalidArgumentException("Social auth provider '{$name}' is not registered");
        }

        return $this->providers[$name];
    }

    public function getAuthUrl(string $provider, string $redirectUri, string $msisdn): array
    {
        try {
            $providerInstance = $this->getProvider($provider);

            // Generate state token
            $state = Str::random(40);

            // Store state with MSISDN if provided
            $prefix = Config::get('social-auth.cache.prefix', 'social_auth_state:');
            $stateKey = "{$prefix}{$provider}:{$state}";

            // Store state data with MSISDN
            $stateData = [
                'msisdn' => $msisdn,
                'created_at' => now()->timestamp
            ];

            $ttl = Config::get('social-auth.cache.ttl', 600);
            Cache::put($stateKey, $stateData, now()->addSeconds($ttl));

            // Get auth URL from provider
            $url = $providerInstance->getAuthorizationUrl($redirectUri, $state);

            return [
                'url' => $url,
                'state' => $state
            ];
        } catch (\Exception $e) {
            Log::error('Error generating auth URL', [
                'provider' => $provider,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }


    public function handleCallback(string $provider, string $code, string $state, string $redirect_uri): array
    {
        try {
            // Validate state to prevent CSRF
            $prefix = config('social-auth.cache.prefix');
            $stateKey = "{$prefix}{$provider}:{$state}";

            // Get state data from cache
            $stateData = Cache::get($stateKey);

            if (!$stateData) {
                throw new InvalidStateException('Invalid state parameter');
            }

            // Extract MSISDN from state data
            $msisdn = is_array($stateData) && isset($stateData['msisdn']) ? $stateData['msisdn'] : null;

            if (empty($msisdn)) {
                throw new InvalidStateException('MSISDN not found in state data');
            }

            // Remove state from cache
            Cache::forget($stateKey);

            // Get provider instance
            $providerInstance = $this->getProvider($provider);

            // Exchange code for tokens
            $tokens = $providerInstance->exchangeCodeForTokens($code, $redirect_uri);

            if (empty($tokens) || !isset($tokens['access_token'])) {
                throw new TokenExchangeException('Failed to exchange code for tokens');
            }

            // Get user profile from provider
            $userProfile = $providerInstance->getUserProfile($tokens['access_token']);

            if (empty($userProfile) || !isset($userProfile['id'])) {
                throw new UserInfoFetchException('Failed to get user profile');
            }

            // Send user profile to IAM service
            $iamTokens = $this->authenticateWithIAM($provider, $userProfile, $msisdn);

            return [
                'iam_tokens' => $iamTokens,
                'provider_tokens' => $tokens,
                'user_profile' => $userProfile
            ];
        } catch (InvalidStateException $e) {
            Log::warning('Invalid state parameter', [
                'provider' => $provider,
                'error' => $e->getMessage()
            ]);
            throw $e;
        } catch (TokenExchangeException $e) {
            Log::error('Token exchange failed', [
                'provider' => $provider,
                'error' => $e->getMessage()
            ]);
            throw $e;
        } catch (UserInfoFetchException $e) {
            Log::error('User profile fetch failed', [
                'provider' => $provider,
                'error' => $e->getMessage()
            ]);
            throw $e;
        } catch (IAMServiceException $e) {
            Log::error('IAM service authentication failed', [
                'provider' => $provider,
                'error' => $e->getMessage()
            ]);
            throw $e;
        } catch (\Exception $e) {
            Log::error('Unexpected error in social auth callback', [
                'provider' => $provider,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    protected function authenticateWithIAM(string $provider, array $userProfile, string $msisdn): array
    {
        try {
            if (empty($this->iamServiceBaseUrl)) {
                throw new IAMServiceException('IAM service base URL is not configured');
            }
            if (empty($msisdn)) {
                throw new IAMServiceException('MSISDN is required for IAM authentication');
            }
            $url = "{$this->iamServiceBaseUrl}/auth/issue-auth-token";
            Log::debug('IAM service URL', ['url' => $url]);

            // Prepare the payload for IAM
            $payload = [
                'msisdn' => $msisdn,
                'claims' => [
                    'role' => 'user',
                    'platform' => 'web',
                    'device_id' => request()->header('User-Agent') ?? 'web',
                    'version' => '1.0',
                    'ip' => request()->ip(),
                    'provider' => $provider,
                    'provider_id' => $userProfile['id'],
                    'email' => $userProfile['email'] ?? null,
                    'name' => $userProfile['name'] ?? null,
                    'picture' => $userProfile['picture'] ?? null
                ],
                'scopes' => 'read:profile,read:user'
            ];

            // Make the request with required headers
            $response = Http::withHeaders([
                'X-Client-Uid' => $this->iamClientUid,
                'X-Client-Key' => $this->iamClientKey,
                'X-Client-Secret' => $this->iamClientSecret,
                'lang' => $this->iamLang,
                'Host' => $this->iamHost,
                'Content-Type' => 'application/json'
            ])->post($url, $payload);

            if ($response->failed()) {
                throw new IAMServiceException('IAM service authentication failed: ' . $response->body());
            }

            return $response->json();
        } catch (IAMServiceException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new IAMServiceException('Error communicating with IAM service: ' . $e->getMessage());
        }
    }
}
