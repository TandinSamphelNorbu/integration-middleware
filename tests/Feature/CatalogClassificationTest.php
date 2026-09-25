<?php

namespace Tests\Feature;

use App\Http\Middleware\LogApiRequest;
use App\Models\User;
use App\Repositories\CatalogRepository;
use App\Services\SubscriberService;
use Illuminate\Support\Facades\Cache;
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
}
