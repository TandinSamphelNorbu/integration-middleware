<?php

namespace Tests\Feature;

use App\Http\Middleware\LogApiRequest;
use App\Models\User;
use App\Repositories\CatalogRepository;
use App\Services\CatalogEligibilityService;
use App\Services\SubscriberService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CatalogClassificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        $this->withoutMiddleware(LogApiRequest::class);
    }

    public static function subscriptions(): array
    {
        return [
            'prepaid without type' => ['Prepaid', 2, ''],
            'postpaid without type' => ['Postpaid', 1, ''],
            'postpaid overrides prepaid' => ['Postpaid', 1, '&type=prepaid'],
            'prepaid overrides postpaid' => ['Prepaid', 2, '&type=postpaid'],
            'obsolete invalid type ignored' => ['Postpaid', 1, '&type=invalid'],
        ];
    }

    #[DataProvider('subscriptions')]
    public function test_catalog_uses_crm_subscription_once_and_preserves_response(string $subscription, int $subscriptionType, string $query): void
    {
        $subscriber = $this->mock(SubscriberService::class);
        $subscriber->shouldReceive('getIllCustomerData')->once()->with('12345678')
            ->andReturn(['success' => true, 'subscription' => $subscription, 'BasePlan' => null]);
        $subscriber->shouldReceive('getPrimaryOfferingId')->once()->with('12345678')->andReturn('123');
        $subscriber->shouldReceive('has4G')->once()->with('12345678')->andReturn(true);

        $repository = $this->partialMock(CatalogRepository::class);
        if ($subscription === 'Postpaid') {
            $repository->shouldReceive('isStudentPostpaidNumber')->once()->with('12345678')->andReturn(false);
        } else {
            $repository->shouldNotReceive('isStudentPostpaidNumber');
        }

        $plan = [
            'Id' => 10, 'Name' => 'SIM plan', 'AddOn' => null, 'CategoryId' => 3,
            'Category' => 'Normal', 'DisplayOrder' => 1, 'ShortName' => 'SIM', 'Price' => 100,
            'DataBucket' => '10 GB', 'Validity' => '30 days', 'SubscriptionType' => $subscriptionType,
            'Type' => 1, 'IsStudentPlan' => 0, 'Only4G' => 0, 'ForPO' => null, 'NotForPO' => null, 'NotFor5G' => 0,
        ];
        Cache::put('catalog.base_data', [$plan, [...$plan, 'Id' => 11, 'SubscriptionType' => $subscriptionType === 1 ? 2 : 1]], 300);

        $this->actingAs(User::factory()->make(), 'sanctum')->getJson('/api/v1/catalog?service_id=12345678'.$query)
            ->assertOk()->assertExactJson([
                'poId' => '123',
                'dataPlans' => [[
                    'Id' => 3, 'Category' => 'Normal', 'DisplayOrder' => 1,
                    'Plans' => [[
                        'Id' => 10, 'Name' => 'SIM plan', 'AddOn' => null, 'ShortName' => 'SIM',
                        'Price' => 100, 'DataBucket' => '10 GB', 'Validity' => '30 days',
                    ]],
                ]],
            ]);
    }

    public static function rejectedCustomers(): array
    {
        return [
            'hybrid' => [true, 'Hybrid', 'Unsupported subscription type for mobile catalog.'],
            'missing' => [true, null, 'Unsupported subscription type for mobile catalog.'],
            'unknown' => [true, 'Other', 'Unsupported subscription type for mobile catalog.'],
            'failed' => [false, 'Postpaid', 'Unable to retrieve catalog customer details.'],
        ];
    }

    #[DataProvider('rejectedCustomers')]
    public function test_catalog_rejects_customer_before_other_eligibility_lookups(bool $success, ?string $subscription, string $message): void
    {
        $subscriber = $this->mock(SubscriberService::class);
        $subscriber->shouldReceive('getIllCustomerData')->once()->with('12345678')
            ->andReturn(['success' => $success, 'subscription' => $subscription, 'BasePlan' => null]);
        $subscriber->shouldNotReceive('getPrimaryOfferingId');
        $subscriber->shouldNotReceive('has4G');
        $repository = $this->mock(CatalogRepository::class);
        $repository->shouldNotReceive('isStudentPostpaidNumber');
        $repository->shouldNotReceive('getEligiblePlansFromCache');

        $this->actingAs(User::factory()->make(), 'sanctum')->getJson('/api/v1/catalog?service_id=12345678')
            ->assertStatus(422)->assertExactJson(['success' => false, 'message' => $message]);
    }

    public function test_catalog_requires_service_id_before_customer_lookup(): void
    {
        $this->mock(SubscriberService::class)->shouldNotReceive('getIllCustomerData');

        $this->actingAs(User::factory()->make(), 'sanctum')->getJson('/api/v1/catalog')
            ->assertUnprocessable()->assertJsonValidationErrors(['service_id']);
    }

    public static function integrationFailures(): array
    {
        $cases = [];
        foreach (['Authentication' => 'Unable to retrieve catalog customer details.', 'queryservice' => 'Unable to retrieve catalog customer details.', 'QueryPlan' => 'Unable to retrieve subscriber primary offering.', 'FetchHLR' => 'Unable to retrieve subscriber network details.'] as $operation => $message) {
            foreach (['http', 'connection', 'business'] as $failure) {
                $cases[$operation.' '.$failure] = [$operation, $message, $failure];
            }
        }

        return $cases;
    }

    #[DataProvider('integrationFailures')]
    public function test_catalog_returns_422_and_logs_one_safe_failure(string $operation, string $message, string $failure): void
    {
        config(['app.debug' => true, 'services.crm_6d.url' => 'https://crm.example.test']);
        Cache::put('crm_6d_access_token', 'secret-token', 300);
        if ($operation === 'Authentication') {
            Cache::forget('crm_6d_access_token');
        }
        Http::preventStrayRequests();
        Http::fake(['https://crm.example.test/ticl/api/v1/*' => function ($request) use ($operation, $failure) {
            if ($operation === 'Authentication' || $request->hasHeader('route', $operation)) {
                if ($failure === 'connection') {
                    throw new ConnectionException('Authorization: Bearer secret-token bearer opaque-test-token eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJ0ZXN0In0.test-signature username=integration-user password=private client_secret=synthetic-client-secret https://embedded-user:embedded-password@upstream.internal.test request_headers={Cookie:session=synthetic-session;X-Api-Key:synthetic-api-key;X-Internal-Routing:synthetic-routing-value;Content-Type:application/json} raw sensitive payload https://crm.internal.test:18443/customer http://cbs.internal.test:18080/subscriber 10.20.30.40:19090 [fd00::1234]:19443 port 15432');
                }

                return Http::response(['result_code' => '1', 'message' => 'Authorization: Bearer secret-token bearer opaque-test-token eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJ0ZXN0In0.test-signature username=integration-user password=private client_secret=synthetic-client-secret https://embedded-user:embedded-password@upstream.internal.test request_headers={Cookie:session=synthetic-session;X-Api-Key:synthetic-api-key;X-Internal-Routing:synthetic-routing-value;Content-Type:application/json} raw sensitive payload https://crm.internal.test:18443/customer http://cbs.internal.test:18080/subscriber 10.20.30.40:19090 [fd00::1234]:19443 port 15432'], $failure === 'http' ? 503 : 200);
            }

            return Http::response([
                'result_code' => '0',
                'service_info' => ['basic_details' => [['id' => 'connection_type', 'value' => '2']]],
                'subscriptions' => [['is_base_plan' => 1, 'external_plan_id' => '123']],
            ]);
        }]);
        Log::shouldReceive('error')->once()->with('CRM integration failed.', \Mockery::on(fn (array $context): bool => $context['integration'] === 'CRM'
            && $context['service_id'] === '12345678'
            && $context['operation'] === $operation
            && $context['status_code'] === ($failure === 'connection' ? null : ($failure === 'http' ? 503 : 200))
            && isset($context['message'])
            && ! str_contains(json_encode($context), 'secret-token')
            && ! str_contains(json_encode($context), 'opaque-test-token')
            && ! str_contains(json_encode($context), 'eyJhbGciOiJIUzI1NiJ9')
            && ! str_contains(strtolower(json_encode($context)), 'bearer')
            && ! str_contains(strtolower(json_encode($context)), 'authorization')
            && ! str_contains(json_encode($context), 'integration-user')
            && ! str_contains(json_encode($context), 'synthetic-client-secret')
            && ! str_contains(json_encode($context), 'embedded-user')
            && ! str_contains(json_encode($context), 'embedded-password')
            && ! str_contains(json_encode($context), 'request_headers')
            && ! str_contains(json_encode($context), 'synthetic-session')
            && ! str_contains(json_encode($context), 'synthetic-api-key')
            && ! str_contains(json_encode($context), 'synthetic-routing-value')
            && ! str_contains(strtolower(json_encode($context)), 'content-type')
            && ! str_contains(json_encode($context), 'private')
            && ! str_contains(json_encode($context), 'payload')
            && ! str_contains(json_encode($context), 'crm.internal.test')
            && ! str_contains(json_encode($context), 'cbs.internal.test')
            && ! str_contains(json_encode($context), '10.20.30.40')
            && ! str_contains(json_encode($context), 'fd00::1234')
            && ! str_contains(json_encode($context), '18443')
            && ! str_contains(json_encode($context), '18080')
            && ! str_contains(json_encode($context), '19090')
            && ! str_contains(json_encode($context), '19443')
            && ! str_contains(json_encode($context), '15432')
        ));

        $this->actingAs(User::factory()->make(), 'sanctum')->getJson('/api/v1/catalog?service_id=12345678')
            ->assertUnprocessable()->assertExactJson(['success' => false, 'message' => $message]);
    }

    public function test_catalog_wrapper_preserves_original_exception(): void
    {
        $original = new \RuntimeException('sensitive underlying error');
        $this->mock(SubscriberService::class)->shouldReceive('getIllCustomerData')->once()->andThrow($original);
        request()->attributes->set('request_id', 'catalog-correlation');
        Log::shouldReceive('error')->once()->with('CRM integration failed.', \Mockery::on(fn (array $context): bool => $context['request_id'] === 'catalog-correlation'));

        try {
            app(CatalogEligibilityService::class)->getEligibility('12345678');
            $this->fail('Expected a controlled error.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Unable to retrieve catalog customer details.', $exception->getMessage());
            $this->assertSame($original, $exception->getPrevious());
        }
    }
}
