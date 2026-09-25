<?php

namespace Tests\Feature;

use App\Services\CrmAuthService;
use App\Services\SubscriberService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CrmConnectionLoggingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'services.crm_6d.url' => 'https://crm.example.test']);
        Http::preventStrayRequests();
    }

    public static function operations(): array
    {
        return [
            ['Authentication', '/ticl/api/v1/authentication'],
            ['QueryPlan', '/ticl/api/v1/passthrough/12345678*'],
            ['FetchHLR', '/ticl/api/v1/passthrough?*'],
        ];
    }

    private function callOperation(string $operation): mixed
    {
        if ($operation === 'Authentication') {
            return app(CrmAuthService::class)->getAccessToken();
        }
        Cache::put('crm_6d_access_token', 'secret-token', 300);

        return $operation === 'QueryPlan'
            ? app(SubscriberService::class)->getPrimaryOfferingId('12345678')
            : app(SubscriberService::class)->has4G('12345678');
    }

    #[DataProvider('operations')]
    public function test_connection_failure_logs_safe_context_and_throws_safe_message(string $operation, string $path): void
    {
        Http::fake(['https://crm.example.test'.$path => Http::failedConnection('Connection failed with secret-token and 12345678')]);
        Log::shouldReceive('error')->once()->with('CRM integration failed.', \Mockery::on(fn (array $context): bool => $context['integration'] === 'CRM'
            && $context['operation'] === $operation
            && $context['status_code'] === null
            && $context['service_id'] === ($operation === 'Authentication' ? null : '12345678')
            && ! str_contains(json_encode($context), 'secret-token')
        ));

        try {
            $this->callOperation($operation);
            $this->fail('Expected a CRM connection failure.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Unable to connect to CRM', $exception->getMessage());
            $this->assertStringNotContainsString('secret-token', $exception->getMessage());
            $this->assertStringNotContainsString('12345678', $exception->getMessage());
            $this->assertInstanceOf(ConnectionException::class, $exception->getPrevious());
        }

        Http::assertSentCount(1);
    }

    #[DataProvider('operations')]
    public function test_http_failure_logs_status_without_response_body(string $operation, string $path): void
    {
        Http::fake(['https://crm.example.test'.$path => Http::response(['secret' => 'not-for-logs'], 503)]);
        Log::shouldReceive('error')->once()->with('CRM integration failed.', \Mockery::on(fn (array $context): bool => $context['operation'] === $operation
            && $context['status_code'] === 503
            && ! str_contains(json_encode($context), 'not-for-logs')
        ));

        try {
            $this->callOperation($operation);
            $this->fail('Expected a failed CRM response.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HTTP status: 503', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_recovered_unauthorized_response_does_not_log_a_failure(): void
    {
        Cache::put('crm_6d_access_token', 'expired-token', 300);
        Http::fake([
            'https://crm.example.test/ticl/api/v1/authentication' => Http::response(['status' => '200', 'accesToken' => 'fresh-token']),
            'https://crm.example.test/ticl/api/v1/passthrough/12345678*' => Http::sequence()
                ->push([], 401)
                ->push(['result_code' => '0', 'subscriptions' => [['is_base_plan' => 1, 'external_plan_id' => 'plan-1']]]),
        ]);
        Log::shouldReceive('error')->never();

        $this->assertSame('plan-1', app(SubscriberService::class)->getPrimaryOfferingId('12345678'));
        Http::assertSentCount(3);
    }
}
