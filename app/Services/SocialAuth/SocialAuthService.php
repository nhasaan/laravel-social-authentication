<?php

namespace App\Services\SocialAuth;

use App\Models\SocialProvider;
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

use function Psy\debug;

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

    public function getAuthUrl(string $provider, string $redirectUri): array
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


    public function handleCallback(string $provider, string $code, string $state, string $redirect_uri, ?string $user_msisdn = null, ?string $otp = null): array
    {
        try {
            // Validate state to prevent CSRF
            $prefix = config('social-auth.cache.prefix');
            $stateKey = "{$prefix}{$provider}:{$state}";

            if (!Cache::has($stateKey)) {
                throw new InvalidStateException('Invalid state parameter');
            }

            // Get cached token data if exists
            $tokenCacheKey = "{$prefix}tokens:{$provider}:{$state}";
            $cachedTokenData = Cache::get($tokenCacheKey);

            $userProfile = null;
            $tokens = null;

            if ($cachedTokenData) {
                // Use cached tokens and profile
                $tokens = $cachedTokenData['tokens'];
                $userProfile = $cachedTokenData['profile'];
            } else {
                // Exchange code for tokens (first time only)
                $providerInstance = $this->getProvider($provider);
                $tokens = $providerInstance->exchangeCodeForTokens($code, $redirect_uri);

                if (empty($tokens) || !isset($tokens['access_token'])) {
                    throw new TokenExchangeException('Failed to exchange code for tokens');
                }

                // Get user profile
                $userProfile = $providerInstance->getUserProfile($tokens['access_token']);

                // Cache token data for subsequent requests in this flow
                Cache::put($tokenCacheKey, [
                    'tokens' => $tokens,
                    'profile' => $userProfile
                ], now()->addMinutes(30));
            }

            if (empty($userProfile) || !isset($userProfile['id'])) {
                throw new UserInfoFetchException('Failed to get user profile');
            }

            // Try to find existing provider record
            $providerRecord = SocialProvider::where('provider', $provider)
                ->where('provider_user_id', $userProfile['id'])
                ->first();

            // If provider record exists, use its msisdn
            if ($providerRecord) {
                // Authenticate with IAM using the stored msisdn
                $iamTokens = $this->authenticateWithIAM($userProfile, $providerRecord->msisdn);

                // Remove state from cache
                $this->clearCacheKeys($stateKey, $tokenCacheKey);

                return [
                    'status' => 'success',
                    'iam_tokens' => $iamTokens,
                    'user_profile' => [
                        'provider_id' => $userProfile['id'],
                        'email' => $userProfile['email'] ?? null,
                        'name' => $userProfile['name'] ?? null,
                        'picture' => $userProfile['picture'] ?? null,
                        'msisdn' => $providerRecord->msisdn,
                    ],
                ];
            }

            // If both msisdn and OTP are provided, verify OTP
            if ($user_msisdn && $otp) {

                $msisdn = $this->formatPhoneNumber($user_msisdn);

                // Verify OTP
                $otpVerified = $this->verifyOtp($msisdn, $otp);

                if (!$otpVerified) {
                    throw new IAMServiceException('Invalid OTP');
                }

                // OTP verified, create provider record
                $providerRecord = SocialProvider::create([
                    'msisdn' => $msisdn,
                    'provider' => $provider,
                    'provider_user_id' => $userProfile['id'],
                    'email' => $userProfile['email'] ?? null,
                ]);

                // Authenticate with IAM
                $iamTokens = $this->authenticateWithIAM($userProfile, $msisdn);


                // Remove state from cache
                $this->clearCacheKeys($stateKey, $tokenCacheKey);

                return [
                    'status' => 'success',
                    'iam_tokens' => $iamTokens,
                    'user_profile' => [
                        'provider_id' => $userProfile['id'],
                        'email' => $userProfile['email'] ?? null,
                        'name' => $userProfile['name'] ?? null,
                        'picture' => $userProfile['picture'] ?? null,
                        'msisdn' => $msisdn,
                    ],
                ];
            }

            // If no msisdn provided, request it
            return [
                'status' => 'msisdn_required',
                'message' => 'Please provide your mobile number',
                'user_profile' => [
                    'provider_id' => $userProfile['id'],
                    'email' => $userProfile['email'] ?? null,
                    'name' => $userProfile['name'] ?? null,
                    'picture' => $userProfile['picture'] ?? null,
                ],
                // Store these values in the response for the next request
                'code' => $code,
                'state' => $state,
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

    // Send OTP via IAM service
    public function sendOtp(string $user_msisdn): array
    {
        $msisdn = $this->formatPhoneNumber($user_msisdn);
        $response = Http::withHeaders([
            'X-Client-Uid' => $this->iamClientUid,
            'X-Client-Key' => $this->iamClientKey,
            'X-Client-Secret' => $this->iamClientSecret,
            'lang' => $this->iamLang,
            'Host' => $this->iamHost,
            'Content-Type' => 'application/json'
        ])->post("{$this->iamServiceBaseUrl}/auth/otp", [
            'msisdn' => $msisdn,
            'expires_in_seconds' => 300
        ]);

        if ($response->failed()) {
            throw new IAMServiceException('Failed to send OTP: ' . $response->body());
        }

        return $response->json();
    }

    // Verify OTP via IAM service
    protected function verifyOtp(string $msisdn, string $otp): bool
    {
        try {
            $response = Http::withHeaders([
                'X-Client-Uid' => $this->iamClientUid,
                'X-Client-Key' => $this->iamClientKey,
                'X-Client-Secret' => $this->iamClientSecret,
                'lang' => $this->iamLang,
                'Host' => $this->iamHost,
                'Content-Type' => 'application/json'
            ])->post("{$this->iamServiceBaseUrl}/auth/token", [
                'grant_type' => 'otp',
                'msisdn' => $msisdn,
                'otp' => $otp,
                'provider' => 'users'
            ]);

            return !$response->failed();
        } catch (\Exception $e) {
            Log::error('Error verifying OTP', [
                'msisdn' => $msisdn,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    protected function authenticateWithIAM(array $userProfile, string $msisdn): array
    {
        try {
            if (empty($msisdn)) {
                throw new IAMServiceException('MSISDN is required for IAM authentication');
            }

            // Prepare payload for IAM
            $payload = [
                'msisdn' => $msisdn,
                'claims' => [
                    'role' => 'user',
                    'platform' => 'web',
                    'device_id' => request()->header('User-Agent') ?? 'web',
                    'version' => '1.0',
                    'ip' => request()->ip(),
                ],
                'social_profile' => $userProfile,
            ];

            // Call IAM service
            $response = Http::withHeaders([
                'X-Client-Uid' => $this->iamClientUid,
                'X-Client-Key' => $this->iamClientKey,
                'X-Client-Secret' => $this->iamClientSecret,
                'lang' => $this->iamLang,
                'Host' => $this->iamHost,
                'Content-Type' => 'application/json'
            ])->post("{$this->iamServiceBaseUrl}/auth/issue-auth-token", $payload);

            if ($response->failed()) {
                $errorBody = $response->body();
                $errorJson = $response->json() ?? [];
                Log::error('IAM service authentication failed', [
                    'status' => $response->status(),
                    'body' => $errorBody,
                    'json' => $errorJson,
                    'headers' => $response->headers()
                ]);
                throw new IAMServiceException('IAM service authentication failed: ' . $response->body());
            }

            return $response->json();
        } catch (IAMServiceException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new IAMServiceException('Error communicating with IAM service: ' . $e->getMessage());
        }
    }

    private function clearCacheKeys(string ...$keys): void
    {
        foreach ($keys as $key) {
            Cache::forget($key);
        }
    }

    // Helper method to format phone numbers
    private function formatPhoneNumber(string $phone): ?string
    {
        // Remove non-numeric characters
        $digits = preg_replace('/\D/', '', $phone);

        // Check if it's a valid Bangladesh number
        if (preg_match('/^(?:88)?01\d{9}$/', $digits)) {
            // Ensure it has 88 prefix
            if (strlen($digits) === 11) {
                return '88' . $digits;
            } else if (strlen($digits) === 13 && substr($digits, 0, 2) === '88') {
                return $digits;
            }
        }

        return null; // Return null if not a valid number
    }
}
