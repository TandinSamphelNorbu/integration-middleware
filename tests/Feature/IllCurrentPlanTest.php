<?php

namespace Tests\Feature;

use App\Http\Middleware\LogApiRequest;
use App\Models\User;
use App\Services\CrmAuthService;
use App\Services\IllCurrentPlanSelector;
use App\Services\IntegrationException;
use App\Services\SubscriberService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IllCurrentPlanTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'services.crm_6d.url' => 'http://crm.test:8080', 'services.integration.test_users' => [], 'session.driver' => 'array', 'app.key' => 'base64:'.base64_encode(str_repeat('t', 32))]);
        $this->withoutMiddleware(LogApiRequest::class);
    }

    public static function selections(): array
    {
        return [
            'one premium' => [['1 Mbps Premium 2000'], 1, 'Premium', null, 1],
            '13 first' => [['13 Mbps Premium 10465 New', '1 Mbps Premium 2000'], 13, 'Premium', null, 2],
            '1 first' => [['1 Mbps Premium 2000', '14 Mbps Premium 11270 New'], 1, 'Premium', null, 2],
            'base only' => [['ILL Main Offering'], null, null, 'no_matching_bandwidth_plan', 0],
            'gin prefix' => [['GIN 5 Mbps'], 5, 'GIN', null, 1],
            'case sensitive' => [['1 mbps Premium 2000', '5 Mbps Standard'], null, null, 'invalid_selected_plan', 2],
            'substring' => [['2 Mbps StandardPlus'], 2, 'Standard', null, 1],
            'skip false zero' => [['0 Mbps Premium', '5 Mbps Standard'], 5, 'Standard', null, 2],
            'gin precedence' => [['GIN 5 Mbps Standard'], 5, 'GIN', null, 1],
        ];
    }

    #[DataProvider('selections')]
    public function test_selects_legacy_first_match_without_status_or_base_filters(array $names, ?int $bandwidth, ?string $type, ?string $reason, int $count): void
    {
        $rows = array_map(fn (string $name): array => ['plan_name' => $name, 'plan_id' => '00028', 'external_plan_id' => 'wrong', 'subscription_id' => '00099', 'status' => 'Inactive', 'is_base_plan' => 0], $names);
        $result = (new IllCurrentPlanSelector)->select(['result_code' => '999', 'subscriptions' => $rows]);

        $this->assertSame($count, $result['matchingSubscriptionCount']);
        if ($reason !== null) {
            $this->assertSame('unresolved', $result['state']);
            $this->assertSame($reason, $result['reason']);
            $this->assertNull($result['currentBandwidthPlan']);
        } else {
            $this->assertSame('resolved', $result['state']);
            $this->assertSame($bandwidth, $result['currentBandwidthPlan']['bandwidthMbps']);
            $this->assertSame($type, $result['currentBandwidthPlan']['serviceType']);
            $this->assertSame('00028', $result['currentBandwidthPlan']['planId']);
            $this->assertSame('00099', $result['currentBandwidthPlan']['subscriptionId']);
            $this->assertSame($count > 1 ? ['multiple_matching_subscriptions'] : [], $result['diagnostics']);
        }
    }

    public static function invalidResponses(): array
    {
        return [
            'not json' => [null, 'missing_subscriptions'],
            'missing' => [[], 'missing_subscriptions'],
            'empty' => [['subscriptions' => []], 'missing_subscriptions'],
            'object instead of list' => [['subscriptions' => ['bad' => []]], 'missing_subscriptions'],
            'bad row' => [['subscriptions' => [null]], 'invalid_subscription_data'],
            'bad name' => [['subscriptions' => [['plan_name' => 5]]], 'invalid_subscription_data'],
            'missing identifiers' => [['subscriptions' => [['plan_name' => '5 Mbps Premium']]], 'invalid_selected_plan'],
            'wrong identifier types' => [['subscriptions' => [['plan_name' => '5 Mbps Premium', 'plan_id' => [], 'subscription_id' => true]]], 'invalid_selected_plan'],
            'negative' => [['subscriptions' => [['plan_name' => '-5 Mbps Standard', 'plan_id' => '1', 'subscription_id' => '2']]], 'invalid_selected_plan'],
        ];
    }

    #[DataProvider('invalidResponses')]
    public function test_malformed_data_is_unresolved(mixed $response, string $reason): void
    {
        $result = (new IllCurrentPlanSelector)->select($response);

        $this->assertSame('unresolved', $result['state']);
        $this->assertSame($reason, $result['reason']);
        $this->assertNull($result['currentBandwidthPlan']);
    }

    public static function serviceTypes(): array
    {
        return ['standard' => ['Standard'], 'premium' => ['Premium'], 'gin' => ['GIN']];
    }

    #[DataProvider('serviceTypes')]
    public function test_catalogue_only_offers_selected_service_type_with_strict_bandwidth_comparisons(string $serviceType): void
    {
        Cache::put('ill.normal.offerings', collect([
            ['id' => 1, 'bandwidth' => 5, 'service_type' => $serviceType],
            ['id' => 2, 'bandwidth' => 13, 'service_type' => $serviceType],
            ['id' => 3, 'bandwidth' => 14, 'service_type' => $serviceType],
            ['id' => 4, 'bandwidth' => 20, 'service_type' => 'Standard'],
            ['id' => 5, 'bandwidth' => 20, 'service_type' => 'Premium'],
            ['id' => 6, 'bandwidth' => 20, 'service_type' => 'GIN'],
            ['id' => 7, 'bandwidth' => 1, 'service_type' => 'Standard'],
            ['id' => 8, 'bandwidth' => 1, 'service_type' => 'Premium'],
            ['id' => 9, 'bandwidth' => 1, 'service_type' => 'GIN'],
        ]), 100);
        Cache::put('crm_6d_access_token', 'synthetic-token');
        Http::preventStrayRequests();
        Http::fake(['http://crm.test:8080/ticl/api/v1/passthrough/00100035523?filter=*' => Http::response(['result_code' => '0', 'service_info' => ['basic_details' => [['id' => 'connection_type', 'value' => '1'], ['id' => 'rate_plan_name', 'value' => 'ILL Main Offering']]]]), 'http://crm.test:8080/ticl/api/v1/passthrough/00100035523?type=2' => Http::response(['subscriptions' => [
            ['plan_name' => '13 Mbps '.$serviceType.' 10465 New', 'plan_id' => '00028', 'subscription_id' => '00099'],
            ['plan_name' => '1 Mbps Premium 2000', 'plan_id' => '29', 'subscription_id' => '100'],
        ]])]);

        $response = $this->actingAs(User::factory()->make(), 'sanctum')->getJson('/api/v1/ill/catalog?service_id=00100035523')
            ->assertOk()->assertJsonPath('service_id', '00100035523')->assertJsonPath('currentBandwidthPlan.planId', '00028')
            ->assertJsonPath('currentBandwidthPlan.bandwidthMbps', 13)
            ->assertJsonPath('base_plan.id', '109')
            ->assertJsonPath('currentBandwidthPlan.serviceType', $serviceType)
            ->assertJsonPath('bandwidthChangeOfferings.upgrade.'.$serviceType.'.0.id', 3)
            ->assertJsonPath('bandwidthChangeOfferings.downgrade.'.$serviceType.'.0.id', 1)
            ->assertJsonCount(2, 'bandwidthChangeOfferings.upgrade.'.$serviceType)
            ->assertJsonCount(2, 'bandwidthChangeOfferings.downgrade.'.$serviceType)
            ->assertJsonCount(5, 'addons.'.$serviceType);

        foreach (array_diff(['Standard', 'Premium', 'GIN'], [$serviceType]) as $otherType) {
            $response->assertJsonPath('addons.'.$otherType, [])
                ->assertJsonPath('bandwidthChangeOfferings.upgrade.'.$otherType, [])
                ->assertJsonPath('bandwidthChangeOfferings.downgrade.'.$otherType, []);
        }

        Http::assertSent(fn ($request): bool => $request->method() === 'POST' && $request->body() === '' && $request->hasHeader('route', 'QueryPlan') && $request->hasHeader('Authorization', 'Bearer synthetic-token'));
        Http::assertSentCount(2);
    }

    public function test_timeout_is_unresolved_without_fallback_bandwidth(): void
    {
        Cache::put('crm_6d_access_token', 'synthetic-token');
        Http::preventStrayRequests();
        Http::fake(['http://crm.test:8080/*' => Http::failedConnection()]);

        $result = app(SubscriberService::class)->getNormalIllCurrentPlan('100035523');

        $this->assertSame('crm_connection_failed', $result['reason']);
        $this->assertNull($result['currentBandwidthPlan']);
    }

    public function test_rejected_token_is_refreshed_once_and_remains_unresolved(): void
    {
        $auth = $this->mock(CrmAuthService::class);
        $auth->shouldReceive('getAccessToken')->twice()->andReturn('synthetic-token');
        $auth->shouldReceive('forgetAccessToken')->once();
        Http::preventStrayRequests();
        Http::fake(['http://crm.test:8080/*' => Http::response(['responseHeader' => ['responseStatus' => '401']], 200)]);

        $result = app(SubscriberService::class)->getNormalIllCurrentPlan('100035523');

        $this->assertSame('authentication_failed', $result['reason']);
        Http::assertSentCount(2);
    }

    public function test_unresolved_catalogue_has_no_bandwidth_change_targets(): void
    {
        Cache::put('ill.normal.offerings', [['id' => 1, 'bandwidth' => 5, 'service_type' => 'Premium']], 100);
        Cache::put('crm_6d_access_token', 'synthetic-token');
        Http::preventStrayRequests();
        Http::fake([
            'http://crm.test:8080/*?filter=*' => Http::response(['result_code' => '0', 'service_info' => ['basic_details' => [['id' => 'rate_plan_name', 'value' => 'ILL Main Offering']]]]),
            'http://crm.test:8080/*?type=2' => Http::response(['subscriptions' => [['plan_name' => 'ILL Main Offering']]]),
        ]);

        $this->actingAs(User::factory()->make(), 'sanctum')->getJson('/api/v1/ill/catalog?service_id=100035523')
            ->assertOk()->assertJsonPath('state', 'unresolved')
            ->assertJsonPath('reason', 'no_matching_bandwidth_plan')
            ->assertJsonPath('currentBandwidthPlan', null)
            ->assertJsonPath('bandwidthChangeOfferings', [
                'upgrade' => ['Standard' => [], 'Premium' => [], 'GIN' => []],
                'downgrade' => ['Standard' => [], 'Premium' => [], 'GIN' => []],
            ]);

        Http::assertSentCount(2);
    }

    public function test_upstream_http_error_is_unresolved_without_exposing_body(): void
    {
        Cache::put('crm_6d_access_token', 'synthetic-token');
        Http::preventStrayRequests();
        Http::fake(['http://crm.test:8080/*' => Http::response('private upstream details', 500)]);

        $result = app(SubscriberService::class)->getNormalIllCurrentPlan('100035523');

        $this->assertSame((new IllCurrentPlanSelector)->unresolved('crm_lookup_failed'), $result);
        Http::assertSentCount(1);
    }

    public function test_authentication_failure_is_unresolved(): void
    {
        $this->mock(CrmAuthService::class)->shouldReceive('getAccessToken')->once()
            ->andThrow(new IntegrationException('Private authentication details', 'Authentication'));
        Http::preventStrayRequests();

        $result = app(SubscriberService::class)->getNormalIllCurrentPlan('100035523');

        $this->assertSame((new IllCurrentPlanSelector)->unresolved('authentication_failed'), $result);
        Http::assertNothingSent();
    }
}
