<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\SocialAuthUrlRequest;
use App\Http\Requests\API\SocialAuthCallbackRequest;
use App\Services\SocialAuth\SocialAuthService;
use Illuminate\Support\Facades\Log;
use App\Services\SocialAuth\Exceptions\InvalidStateException;
use App\Services\SocialAuth\Exceptions\TokenExchangeException;
use App\Services\SocialAuth\Exceptions\UserInfoFetchException;
use App\Services\SocialAuth\Exceptions\IAMServiceException;

class SocialAuthController extends Controller
{
    protected $socialAuthService;

    public function __construct(SocialAuthService $socialAuthService)
    {
        $this->socialAuthService = $socialAuthService;
    }

    public function getAuthUrl(SocialAuthUrlRequest $request)
    {
        try {
            $provider = $request->input('provider', 'google');
            $msisdn = $request->input('msisdn'); // Get MSISDN from request

            if (empty($msisdn)) {
                return response()->json(['error' => 'MSISDN is required'], 400);
            }

            $redirectUriKey = "social-auth.providers.{$provider}.redirect_uri";
            $redirectUri = config($redirectUriKey);

            // Pass MSISDN to service method
            $result = $this->socialAuthService->getAuthUrl($provider, $redirectUri, $msisdn);

            return response()->json(['data' => $result]);
        } catch (\Exception $e) {
            Log::error('Error generating auth URL', [
                'error' => $e->getMessage(),
                'provider' => $request->input('provider', 'google')
            ]);

            return response()->json(['error' => 'Failed to generate authorization URL: ' . $e->getMessage()], 500);
        }
    }

    public function handleCallback(SocialAuthCallbackRequest $request)
    {
        try {
            $provider = $request->input('provider');
            $code = $request->input('code');
            $state = $request->input('state');
            $redirect_uri_key = "social-auth.providers.{$provider}.redirect_uri";
            $redirect_uri = config($redirect_uri_key);

            $result = $this->socialAuthService->handleCallback($provider, $code, $state, $redirect_uri);

            return response()->json([
                'success' => true,
                'data' => $result
            ]);
        } catch (InvalidStateException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        } catch (TokenExchangeException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        } catch (UserInfoFetchException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        } catch (IAMServiceException $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        } catch (\Exception $e) {
            Log::error('Error in social auth callback', [
                'error' => $e->getMessage(),
                'provider' => $request->input('provider', 'google')
            ]);

            return response()->json(['error' => 'Authentication failed'], 500);
        }
    }
}
