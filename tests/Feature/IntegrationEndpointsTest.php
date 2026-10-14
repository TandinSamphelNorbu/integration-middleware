<?php

namespace Tests\Feature;

use App\Http\Middleware\LogApiRequest;
use App\Models\User;
use App\Repositories\CatalogRepository;
use App\Repositories\IllOfferingRepository;
use App\Repositories\LeasedlineCatalogRepository;
use App\Services\CrmAuthService;
use App\Services\SubscriberService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IntegrationEndpointsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'cache.default' => 'array',
            'session.driver' => 'array',
            'services.integration.test_users' => ['test-operator'],
            'services.crm_6d.url' => 'https://crm.production.example',
            'services.crm_6d.test_url' => 'https://crm.test.example',
            'services.crm_6d.username' => 'shared-user',
            'services.crm_6d.password' => 'shared-password',
            'services.cbs.url' => 'https://cbs.production.example/soap',
            'services.cbs.test_url' => 'https://cbs.test.example/soap',
            'services.cbs.password' => 'shared-cbs-password',
        ]);
        $this->withoutMiddleware(LogApiRequest::class);
    }

    public static function environments(): array
    {
        return [
            'production user' => ['production-operator', 'production'],
            'test user' => ['test-operator', 'test'],
        ];
    }

    #[DataProvider('environments')]
    public function test_login_token_routes_crm_and_cbs_using_the_authenticated_username(string $username, string $environment): void
    {
        User::factory()->create(['name' => $username, 'password' => 'password']);
        $this->fakeFwaRequests($environment);

        $login = $this->postJson('/api/v1/login', ['name' => $username, 'password' => 'password'])
            ->assertOk()->assertJsonPath('success', true);
        Auth::forgetGuards();
        $this->app['session']->flush();

        $this->withToken($login->json('access_token'))
            ->getJson('/api/v1/fwa/plans?service_id=12345678&environment=production&username=production-operator')
            ->assertOk()->assertJsonPath('BasePlan', '5G Unlimited New')->assertJsonPath('usage', []);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://crm.'.$environment.'.example/ticl/api/v1/authentication'
            && json_decode($request->body(), true) === ['userName' => 'shared-user', 'password' => 'shared-password']);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://crm.'.$environment.'.example/ticl/api/v1/passthrough/')
            && $request->hasHeader('Authorization', 'Bearer '.$environment.'-token'));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://cbs.'.$environment.'.example/soap'
            && str_contains($request->body(), '<cbs:Password>shared-cbs-password</cbs:Password>'));
        Http::assertSentCount(4);
    }

    #[DataProvider('environments')]
    public function test_dashboard_session_routes_to_the_users_crm_url(string $username, string $environment): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://crm.'.$environment.'.example/ticl/api/v1/authentication' => Http::response(['status' => '200', 'accesToken' => $environment.'-token']),
            'https://crm.'.$environment.'.example/ticl/api/v1/passthrough/12345678*' => Http::response([
                'result_code' => '0',
                'service_info' => ['basic_details' => [
                    ['id' => 'connection_type', 'value' => '1'],
                    ['id' => 'rate_plan_name', 'value' => 'ILL Main Offering'],
                ]],
            ]),
        ]);
        $this->mock(LeasedlineCatalogRepository::class)->shouldReceive('getNormalOfferings')->once()->andReturn(collect());

        $this->actingAs(User::factory()->make(['name' => $username]))
            ->getJson('/dashboard/ill/catalog?service_id=12345678&environment=test')
            ->assertOk()->assertJsonPath('crm_base_plan', 'ILL Main Offering');

        Http::assertSentCount(2);
    }

    public function test_alternating_users_keep_crm_tokens_and_urls_separate(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://crm.production.example/ticl/api/v1/authentication' => Http::response(['status' => '200', 'accesToken' => 'production-token']),
            'https://crm.test.example/ticl/api/v1/authentication' => Http::response(['status' => '200', 'accesToken' => 'test-token']),
            'https://crm.production.example/ticl/api/v1/passthrough/12345678*' => Http::response(['result_code' => '0']),
            'https://crm.test.example/ticl/api/v1/passthrough/12345678*' => Http::response(['result_code' => '0']),
        ]);
        $this->mock(LeasedlineCatalogRepository::class)->shouldReceive('getNormalOfferings')->never();

        foreach (['production-operator', 'test-operator', 'production-operator'] as $username) {
            $this->actingAs(User::factory()->make(['name' => $username]), 'sanctum')
                ->getJson('/api/v1/ill/catalog?service_id=12345678')
                ->assertUnprocessable()->assertJsonPath('message', 'Subscriber is not eligible for ILL catalog.');
        }

        $this->assertSame('production-token', Cache::get('crm_6d_access_token'));
        $this->assertSame('test-token', Cache::get('crm_6d_access_token:test'));
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://crm.production.example/ticl/api/v1/passthrough/')
            && $request->hasHeader('Authorization', 'Bearer production-token'));
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://crm.test.example/ticl/api/v1/passthrough/')
            && $request->hasHeader('Authorization', 'Bearer test-token'));
        Http::assertSentCount(5);
    }

    public function test_refreshing_a_rejected_test_token_preserves_the_production_token(): void
    {
        $this->actingAs(User::factory()->make(['name' => 'test-operator']), 'sanctum');
        Cache::put('crm_6d_access_token', 'production-token', 300);
        Cache::put('crm_6d_access_token:test', 'expired-test-token', 300);
        Http::preventStrayRequests();
        Http::fake([
            'https://crm.test.example/ticl/api/v1/authentication' => Http::response(['status' => '200', 'accesToken' => 'fresh-test-token']),
            'https://crm.test.example/ticl/api/v1/passthrough/12345678*' => Http::sequence()
                ->push([], 401)
                ->push(['result_code' => '0', 'subscriptions' => [['is_base_plan' => 1, 'external_plan_id' => '123']]]),
        ]);

        $this->assertSame('123', app(SubscriberService::class)->getPrimaryOfferingId('12345678'));

        $this->assertSame('production-token', Cache::get('crm_6d_access_token'));
        $this->assertSame('fresh-test-token', Cache::get('crm_6d_access_token:test'));
        Http::assertSentCount(3);
    }

    public function test_mobile_catalog_uses_test_urls_for_primary_offering_and_network_lookups(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://crm.test.example/ticl/api/v1/authentication' => Http::response(['status' => '200', 'accesToken' => 'test-token']),
            'https://crm.test.example/ticl/api/v1/passthrough/12345678*' => Http::response([
                'result_code' => '0',
                'service_info' => ['basic_details' => [['id' => 'connection_type', 'value' => '1']]],
                'subscriptions' => [['is_base_plan' => 1, 'external_plan_id' => '123']],
            ]),
            'https://crm.test.example/ticl/api/v1/passthrough?*' => Http::response([
                'result_code' => '0', 'hlr_services' => ['4g_lte_service' => '1'],
            ]),
        ]);
        $repository = $this->mock(CatalogRepository::class);
        $repository->shouldReceive('isStudentPostpaidNumber')->once()->with('12345678')->andReturn(false);
        $repository->shouldReceive('getEligiblePlansFromCache')->once()->with([
            'service_id' => '12345678', 'type' => 'postpaid', 'primary_offering_id' => '123',
            'has_4g' => true, 'is_student_postpaid' => false,
        ])->andReturn(collect());

        $this->actingAs(User::factory()->make(['name' => 'test-operator']), 'sanctum')
            ->getJson('/api/v1/catalog?service_id=12345678&environment=production')
            ->assertOk()->assertExactJson(['poId' => '123', 'dataPlans' => []]);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('route', 'FetchHLR')
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && str_starts_with($request->url(), 'https://crm.test.example/'));
        Http::assertSentCount(4);
    }

    public function test_missing_test_crm_url_does_not_fall_back_to_production_even_with_a_cached_token(): void
    {
        config(['services.crm_6d.test_url' => null]);
        Cache::put('crm_6d_access_token:test', 'test-token', 300);
        Http::preventStrayRequests();
        Http::fake(['https://crm.production.example/*' => Http::response()]);

        $this->actingAs(User::factory()->make(['name' => 'test-operator']), 'sanctum')
            ->getJson('/api/v1/ill/catalog?service_id=12345678')
            ->assertUnprocessable()->assertExactJson(['success' => false, 'message' => 'CRM test URL is not configured.']);

        Http::assertNothingSent();
    }

    public function test_missing_test_cbs_url_does_not_fall_back_to_production(): void
    {
        config(['services.cbs.test_url' => '']);
        $this->fakeFwaRequests('test');

        $this->actingAs(User::factory()->make(['name' => 'test-operator']), 'sanctum')
            ->getJson('/api/v1/fwa/plans?service_id=12345678')
            ->assertStatus(400)->assertExactJson(['success' => false, 'message' => 'CBS test URL is not configured.']);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'cbs.'));
    }

    public function test_unauthenticated_requests_cannot_call_upstream_services(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://crm.production.example/*' => Http::response()]);

        $this->getJson('/api/v1/fwa/plans?service_id=12345678&environment=test')->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_console_lookups_keep_using_the_existing_crm_url(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://crm.production.example/ticl/api/v1/authentication' => Http::response(['status' => '200', 'accesToken' => 'production-token']),
        ]);

        $this->assertSame('production-token', app(CrmAuthService::class)->getAccessToken());

        Http::assertSentCount(1);
    }

    private function fakeFwaRequests(string $environment): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://crm.'.$environment.'.example/ticl/api/v1/authentication' => Http::response(['status' => '200', 'accesToken' => $environment.'-token']),
            'https://crm.'.$environment.'.example/ticl/api/v1/passthrough/12345678*' => function (Request $request) {
                return Http::response($request->hasHeader('Route', 'queryservice') ? [
                    'result_code' => '0',
                    'service_info' => ['basic_details' => [
                        ['id' => 'connection_type', 'value' => '1'],
                        ['id' => 'rate_plan_name', 'value' => '5G Unlimited New'],
                    ]],
                ] : ['result_code' => '1']);
            },
            'https://cbs.'.$environment.'.example/soap' => Http::response('<root/>'),
        ]);
        $this->mock(IllOfferingRepository::class)->shouldReceive('getCurrentPlan')->andReturnNull();
        $this->mock(LeasedlineCatalogRepository::class)->shouldReceive('getPostpaidFwaOfferings')->with('5G ILL')->andReturn(collect());
    }
}
