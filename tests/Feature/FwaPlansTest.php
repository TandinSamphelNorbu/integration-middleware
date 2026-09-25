<?php

namespace Tests\Feature;

use App\Http\Middleware\LogApiRequest;
use App\Models\User;
use App\Repositories\IllOfferingRepository;
use App\Services\CbsFwaUsageService;
use App\Services\FwaPlanResolver;
use App\Services\FwaPlanService;
use App\Services\PostpaidFwaPlanService;
use App\Services\SubscriberService;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class FwaPlansTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32)), 'session.driver' => 'array']);
        $this->withoutMiddleware(LogApiRequest::class);
    }

    public function test_unified_endpoint_dispatches_prepaid_and_preserves_its_payload(): void
    {
        $customer = ['success' => true, 'subscription' => 'Prepaid', 'BasePlan' => 'Prepaid'];
        $result = $this->prepaidPayload();
        $this->mock(SubscriberService::class)->shouldReceive('getIllCustomerData')->once()->with('12345678')->andReturn($customer);
        $this->mock(FwaPlanService::class)->shouldReceive('getPlans')->once()->with('12345678')->andReturn($result);
        $this->mock(PostpaidFwaPlanService::class)->shouldNotReceive('getPlans');

        $this->actingAs(User::factory()->make(), 'sanctum')
            ->getJson('/api/v1/fwa/plans?service_id=12345678')
            ->assertOk()->assertExactJson(['success' => true, 'service_id' => '12345678', ...$result]);
    }

    public function test_unified_endpoint_passes_exact_customer_to_postpaid_and_preserves_its_payload(): void
    {
        $customer = ['success' => true, 'status' => '0', 'subscription' => 'Postpaid', 'BasePlan' => '5G Unlimited'];
        $result = [
            'success' => true, 'subscription' => 'Postpaid', 'BasePlan' => '5G Unlimited',
            'bandwidth' => '5GHome 1477_Postpaid', 'subscriptions' => [['planId' => '1801771352']],
            'currentBasePlan' => ['Id' => 1801771352], 'basePlanOfferings' => false,
            'addOnOfferings' => [['Id' => 1304191889]], 'usage' => [['remainingAmount' => '164.64 GB']],
        ];
        $this->mock(SubscriberService::class)->shouldReceive('getIllCustomerData')->once()->with('12345678')->andReturn($customer);
        $this->mock(FwaPlanService::class)->shouldNotReceive('getPlans');
        $this->mock(PostpaidFwaPlanService::class)->shouldReceive('getPlans')->once()
            ->withArgs(fn (string $serviceId, array $suppliedCustomer): bool => $serviceId === '12345678' && $suppliedCustomer === $customer)
            ->andReturn($result);

        $this->actingAs(User::factory()->make(), 'sanctum')
            ->getJson('/api/v1/fwa/plans?service_id=12345678')
            ->assertOk()->assertExactJson(['success' => true, 'service_id' => '12345678', ...$result]);
    }

    public static function rejectedCustomers(): array
    {
        return [
            'hybrid' => [true, 'Hybrid', 'Unsupported subscription type for FWA.'],
            'missing subscription' => [true, null, 'Unsupported subscription type for FWA.'],
            'unknown subscription' => [true, 'Other', 'Unsupported subscription type for FWA.'],
            'failed lookup' => [false, 'Postpaid', 'Unable to retrieve FWA customer details.'],
        ];
    }

    #[DataProvider('rejectedCustomers')]
    public function test_resolver_rejects_customer_without_dispatching(bool $success, ?string $subscription, string $message): void
    {
        $this->mock(SubscriberService::class)->shouldReceive('getIllCustomerData')->once()->with('12345678')
            ->andReturn(['success' => $success, 'subscription' => $subscription, 'BasePlan' => null]);
        $this->mock(FwaPlanService::class)->shouldNotReceive('getPlans');
        $this->mock(PostpaidFwaPlanService::class)->shouldNotReceive('getPlans');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);
        app(FwaPlanResolver::class)->getPlans('12345678');
    }

    public function test_unified_endpoint_returns_400_for_resolver_failure(): void
    {
        $this->mock(SubscriberService::class)->shouldReceive('getIllCustomerData')->once()->with('12345678')
            ->andReturn(['success' => false, 'subscription' => null, 'BasePlan' => null]);
        $this->mock(FwaPlanService::class)->shouldNotReceive('getPlans');
        $this->mock(PostpaidFwaPlanService::class)->shouldNotReceive('getPlans');

        $this->actingAs(User::factory()->make(), 'sanctum')->getJson('/api/v1/fwa/plans?service_id=12345678')
            ->assertStatus(400)->assertExactJson(['success' => false, 'message' => 'Unable to retrieve FWA customer details.']);
    }

    public function test_unified_endpoint_requires_service_id_before_customer_lookup(): void
    {
        $this->mock(SubscriberService::class)->shouldNotReceive('getIllCustomerData');

        $this->actingAs(User::factory()->make(), 'sanctum')->getJson('/api/v1/fwa/plans')
            ->assertUnprocessable()->assertJsonValidationErrors(['service_id']);
    }

    public static function customerSources(): array
    {
        return ['supplied customer' => [true], 'direct legacy call' => [false]];
    }

    #[DataProvider('customerSources')]
    public function test_postpaid_service_only_fetches_customer_when_not_supplied(bool $supplied): void
    {
        $customer = ['success' => true, 'subscription' => 'Postpaid', 'BasePlan' => '5G Unlimited'];
        $subscriber = $this->mock(SubscriberService::class);
        if ($supplied) {
            $subscriber->shouldNotReceive('getIllCustomerData');
        } else {
            $subscriber->shouldReceive('getIllCustomerData')->once()->with('12345678')->andReturn($customer);
        }
        $subscriber->shouldReceive('getIllService')->once()->with('12345678')
            ->andReturn(['bandwidth' => 'No Active Bandwidth', 'subscriptions' => []]);
        $repository = $this->mock(IllOfferingRepository::class);
        $repository->shouldReceive('getCurrentPlan')->once()->with('No Active Bandwidth')->andReturnNull();
        $repository->shouldNotReceive('getAlternativeBasePlans');
        $repository->shouldNotReceive('getBoosterPlans');
        $this->mock(CbsFwaUsageService::class)->shouldReceive('getUsage')->once()->with('12345678')->andReturn([]);

        $service = app(PostpaidFwaPlanService::class);
        $result = $supplied ? $service->getPlans('12345678', $customer) : $service->getPlans('12345678');

        $this->assertSame([
            'success' => true, 'subscription' => 'Postpaid', 'BasePlan' => '5G Unlimited',
            'bandwidth' => 'No Active Bandwidth', 'subscriptions' => [], 'currentBasePlan' => null,
            'basePlanOfferings' => false, 'addOnOfferings' => false, 'usage' => [],
        ], $result);
    }

    public function test_dashboard_fwa_uses_resolver_for_prepaid(): void
    {
        $result = $this->prepaidPayload();
        $this->mock(FwaPlanResolver::class)->shouldReceive('getPlans')->once()->with('12345678')->andReturn($result);
        $this->mock(SubscriberService::class)->shouldNotReceive('getIllCustomerData');
        $this->mock(FwaPlanService::class)->shouldNotReceive('getPlans');
        $this->mock(PostpaidFwaPlanService::class)->shouldNotReceive('getPlans');

        $this->actingAs(User::factory()->make())->getJson('/dashboard/fwa?service_id=12345678')
            ->assertOk()->assertExactJson(['success' => true, 'service_id' => '12345678', ...$result]);
    }

    public function test_dashboard_fwa_uses_resolver_for_postpaid(): void
    {
        $result = [
            'success' => true, 'subscription' => 'Postpaid', 'BasePlan' => '5G Unlimited',
            'bandwidth' => '5GHome 1477_Postpaid', 'subscriptions' => [], 'currentBasePlan' => null,
            'basePlanOfferings' => false, 'addOnOfferings' => [], 'usage' => [],
        ];
        $this->mock(FwaPlanResolver::class)->shouldReceive('getPlans')->once()->with('12345678')->andReturn($result);
        $this->mock(FwaPlanService::class)->shouldNotReceive('getPlans');

        $this->actingAs(User::factory()->make())->getJson('/dashboard/fwa?service_id=12345678')
            ->assertOk()->assertExactJson(['service_id' => '12345678', ...$result]);
    }

    public function test_dashboard_fwa_requires_service_id_before_resolving(): void
    {
        $this->mock(FwaPlanResolver::class)->shouldNotReceive('getPlans');

        $this->actingAs(User::factory()->make())->getJson('/dashboard/fwa')
            ->assertUnprocessable()->assertJsonValidationErrors(['service_id']);
    }

    private function prepaidPayload(): array
    {
        return [
            'primary_offering_id' => 702186264, 'plan_type' => '5G', 'GST' => '5%',
            'plans' => [['plan_name' => '5G plan', 'amount' => 100, 'data_cap' => '10 GB', 'max_speed' => '15 Mbps', 'gstAmount' => 5, 'totalAmount' => 105]],
            'prepaidILLPlans' => [100, 50],
        ];
    }
}
