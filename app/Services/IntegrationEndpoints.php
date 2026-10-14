<?php

namespace App\Services;

use Illuminate\Contracts\Auth\Factory;
use RuntimeException;

class IntegrationEndpoints
{
    public function __construct(private Factory $auth) {}

    public function crmUrl(): string
    {
        return $this->url('crm_6d');
    }

    public function cbsUrl(): string
    {
        return $this->url('cbs');
    }

    public function crmTokenCacheKey(): string
    {
        return $this->isTestUser() ? 'crm_6d_access_token:test' : 'crm_6d_access_token';
    }

    private function isTestUser(): bool
    {
        $user = $this->auth->guard()->user();

        return $user !== null && in_array($user->name, config('services.integration.test_users', []), true);
    }

    private function url(string $service): string
    {
        $isTestUser = $this->isTestUser();
        $url = config('services.'.$service.'.'.($isTestUser ? 'test_url' : 'url'));

        if (! is_string($url) || trim($url) === '') {
            throw new RuntimeException(($service === 'crm_6d' ? 'CRM' : 'CBS').' '.($isTestUser ? 'test' : 'production').' URL is not configured.');
        }

        return trim($url);
    }
}
