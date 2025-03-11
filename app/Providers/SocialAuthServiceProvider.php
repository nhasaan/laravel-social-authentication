<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\SocialAuth\SocialAuthService;
use App\Services\SocialAuth\Providers\GoogleAuthProvider;

class SocialAuthServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Check if config file exists and load it if needed
        if (!config()->has('social-auth')) {
            config()->set('social-auth', require __DIR__ . '/../../config/social-auth.php');
        }

        $this->app->singleton(SocialAuthService::class, function ($app) {
            $service = new SocialAuthService();

            // Register Google provider
            if ($app['config']->get('social-auth.providers.google.enabled')) {
                $service->registerProvider('google', new GoogleAuthProvider());
            }

            return $service;
        });
    }

    public function boot()
    {
        //
    }
}
