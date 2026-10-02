<?php

namespace Tests\Feature;

use App\Http\Middleware\LogApiRequest;
use App\Models\User;
use App\Repositories\IllOfferingRepository;
use App\Repositories\LeasedlineCatalogRepository;
use App\Services\CbsFwaUsageService;
use App\Services\PostpaidFwaPlanService;
use App\Services\SubscriberService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LeasedlineCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'cache.default' => 'array',
            'session.driver' => 'array',
            'database.connections.leasedline' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'database.connections.catalog' => ['driver' => 'forbidden'],
            'database.default' => 'sqlite',
            'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]);
        DB::purge('leasedline');
        DB::purge('sqlite');
        $this->withoutMiddleware(LogApiRequest::class);
    }

    private function createSourceTables(): void
    {
        DB::connection('leasedline')->statement('CREATE TABLE crm_ill_offering (Id INTEGER, CRMOfferingId INTEGER, Name TEXT, StartDate TEXT, CreatedAt TEXT)');
        DB::connection('leasedline')->statement('CREATE TABLE crm_ill_offering_4g5g (Id INTEGER, CBSId TEXT, CRMOfferingId INTEGER, Name TEXT, "5GOr4G" TEXT, Is_PO TEXT, FWANewPlan TEXT, MaxGB TEXT, MaxSpeed TEXT)');
    }

    /** @param array<string, mixed> $overrides */
    private function insertNormal(array $overrides = []): void
    {
        DB::connection('leasedline')->table('crm_ill_offering')->insert([
            'Id' => 34, 'CRMOfferingId' => 28, 'Name' => '20 Mbps Standard 13500 New',
            'StartDate' => '0000-00-00', 'CreatedAt' => '2023-09-11', ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function insertFwa(array $overrides = []): void
    {
        DB::connection('leasedline')->table('crm_ill_offering_4g5g')->insert([
            'Id' => 27, 'CBSId' => '1201771411', 'CRMOfferingId' => 921,
            'Name' => '5GHome 1777_Postpaid', '5GOr4G' => '5G ILL',
            'Is_PO' => 'No', 'FWANewPlan' => 'Y', 'MaxGB' => '240', 'MaxSpeed' => '30 Mbps',
            ...$overrides,
        ]);
    }

    public static function illEndpoints(): array
    {
        return ['API' => ['/api/v1/ill/catalog', 'sanctum'], 'dashboard' => ['/dashboard/ill/catalog', 'web']];
    }

    #[DataProvider('illEndpoints')]
    public function test_normal_ill_reads_leasedline_and_preserves_subscriber_response(string $endpoint, string $guard): void
    {
        $this->createSourceTables();
        $this->insertNormal();
        Http::preventStrayRequests();
        $this->mock(SubscriberService::class)->shouldReceive('getIllCustomerData')->once()->with('12345678')
            ->andReturn(['success' => true, 'subscription' => 'Postpaid', 'BasePlan' => 'ILL_Main_Offering']);
        DB::connection('leasedline')->enableQueryLog();

        $this->actingAs(User::factory()->make(), $guard)->getJson($endpoint.'?service_id=12345678')
            ->assertOk()->assertExactJson([
                'success' => true, 'service_id' => '12345678', 'subscription' => 'Postpaid',
                'crm_base_plan' => 'ILL_Main_Offering', 'base_plan' => ['id' => '109', 'name' => 'ILL Main Offering'],
                'addons' => [[
                    'id' => 34, 'crm_offering_id' => 28, 'name' => '20 Mbps Standard 13500 New',
                    'bandwidth' => 20, 'service_type' => 'Standard',
                    'start_date' => '0000-00-00', 'created_at' => '2023-09-11',
                ]],
            ]);

        $queries = DB::connection('leasedline')->getQueryLog();
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('from "crm_ill_offering"', $queries[0]['query']);
        $this->assertStringStartsWith('select ', $queries[0]['query']);
        Http::assertNothingSent();
    }

    public static function normalNames(): array
    {
        return [
            'standard' => ['20 Mbps Standard 13500 New', 20, 'Standard'],
            'premium mixed case' => ['50 mbps pReMiUm 33750', 50, 'Premium'],
            'GIN suffix' => ['100 Mbps GIN 50000', 100, 'GIN'],
            'GIN prefix' => ['gIn 100 Mbps 50000', 100, 'GIN'],
            'decimal' => ['2.5 Mbps Standard', 2.5, 'Standard'],
            'no service type' => ['500 Mbps 142500', null, null],
            'home is not standard' => ['Home 20 Mbps 6000', null, null],
            'enterprise is not premium' => ['Enterprise 50 Mbps 13750', null, null],
            'wrong unit' => ['20 Gbps Standard', null, null],
            'negative' => ['-20 Mbps Standard', null, null],
            'zero' => ['0 Mbps Standard', null, null],
            'partial service type' => ['20 Mbps StandardPlus', null, null],
            'ambiguous placement' => ['Standard 20 Mbps Premium', null, null],
        ];
    }

    #[DataProvider('normalNames')]
    public function test_normal_names_are_parsed_without_inventing_values(string $name, int|float|null $bandwidth, ?string $serviceType): void
    {
        $this->createSourceTables();
        $this->insertNormal(['Name' => $name]);

        $plans = app(LeasedlineCatalogRepository::class)->getNormalOfferings();

        if ($bandwidth === null) {
            $this->assertSame([], $plans->all());
        } else {
            $this->assertSame($bandwidth, $plans->first()['bandwidth']);
            $this->assertSame($serviceType, $plans->first()['service_type']);
        }
    }

    public function test_normal_catalog_excludes_missing_crm_ids_and_all_ambiguous_pairs(): void
    {
        $this->createSourceTables();
        $this->insertNormal();
        $this->insertNormal(['Id' => 35, 'Name' => '20 Mbps Standard another tariff']);
        $this->insertNormal(['Id' => 36, 'Name' => '50 Mbps Premium', 'CRMOfferingId' => null]);
        $this->insertNormal(['Id' => 37, 'Name' => '70 Mbps Premium', 'CRMOfferingId' => '']);
        $this->insertNormal(['Id' => 38, 'Name' => '10 Mbps Standard']);

        $this->assertSame([38], app(LeasedlineCatalogRepository::class)->getNormalOfferings()->pluck('id')->all());
    }

    public function test_normal_catalog_rejects_ineligible_subscriber_before_database_lookup(): void
    {
        config(['database.connections.leasedline.driver' => 'forbidden']);
        $this->mock(SubscriberService::class)->shouldReceive('getIllCustomerData')->once()
            ->andReturn(['success' => true, 'subscription' => 'Postpaid', 'BasePlan' => '5G Unlimited']);

        $this->actingAs(User::factory()->make(), 'sanctum')->getJson('/api/v1/ill/catalog?service_id=12345678')
            ->assertUnprocessable()->assertExactJson(['success' => false, 'message' => 'Subscriber is not eligible for ILL catalog.']);
    }

    public static function fwaCustomers(): array
    {
        return [
            'unified 5G' => ['5G Unlimited', '5G ILL', '/api/v1/fwa/plans'],
            'unified 4G' => ['4G Home Unlimited Postpaid', '4G ILL', '/api/v1/fwa/plans'],
            'dedicated postpaid' => ['5G Unlimited', '5G ILL', '/api/v1/fwa/postpaid/plans'],
            'dashboard postpaid' => ['5G Unlimited', '5G ILL', '/dashboard/fwa'],
        ];
    }

    #[DataProvider('fwaCustomers')]
    public function test_postpaid_catalog_resolves_category_and_preserves_response_and_source_id(string $basePlan, string $category, string $endpoint): void
    {
        $this->createSourceTables();
        $this->insertFwa(['5GOr4G' => $category]);
        $this->insertFwa(['Id' => 32, 'CBSId' => '1101580013', '5GOr4G' => $category === '5G ILL' ? '4G ILL' : '5G ILL']);
        $subscriber = $this->mock(SubscriberService::class);
        $subscriber->shouldReceive('getIllCustomerData')->once()->with('12345678')
            ->andReturn(['success' => true, 'subscription' => 'Postpaid', 'BasePlan' => $basePlan]);
        $subscriber->shouldReceive('getIllService')->once()->with('12345678')
            ->andReturn(['bandwidth' => 'No Active Bandwidth', 'subscriptions' => []]);
        $legacy = $this->mock(IllOfferingRepository::class);
        $legacy->shouldReceive('getCurrentPlan')->once()->with('No Active Bandwidth')->andReturnNull();
        $legacy->shouldNotReceive('getAlternativeBasePlans');
        $legacy->shouldNotReceive('getBoosterPlans');
        $this->mock(CbsFwaUsageService::class)->shouldReceive('getUsage')->once()->andReturn([]);
        DB::connection('leasedline')->enableQueryLog();

        $this->actingAs(User::factory()->make(), str_starts_with($endpoint, '/dashboard') ? 'web' : 'sanctum')
            ->getJson($endpoint.'?service_id=12345678')->assertOk()->assertExactJson([
                'success' => true, 'service_id' => '12345678', 'subscription' => 'Postpaid', 'BasePlan' => $basePlan,
                'bandwidth' => 'No Active Bandwidth', 'subscriptions' => [], 'currentBasePlan' => null,
                'basePlanOfferings' => [[
                    'Id' => 27, 'id' => 27, 'CBSId' => '1201771411', 'CRMOfferingId' => 921,
                    'Name' => '5GHome 1777_Postpaid', '5GOr4G' => $category, 'Is_PO' => 'No',
                    'FWANewPlan' => 'Y', 'MaxGB' => '240', 'MaxSpeed' => '30 Mbps',
                    'sim_type' => 'Postpaid', 'base_plan' => $category === '5G ILL' ? '5G' : '4G',
                    'is_booster' => 'N', 'status' => 'new', 'data_cap' => '240', 'max_speed' => '30 Mbps',
                    'price' => null, 'default_speed' => null,
                ]],
                'addOnOfferings' => false, 'usage' => [],
            ]);

        foreach (DB::connection('leasedline')->getQueryLog() as $query) {
            $this->assertStringStartsWith('select ', $query['query']);
        }
    }

    public static function invalidFwaRows(): array
    {
        return [
            'wrong category' => [['5GOr4G' => '5G BB']],
            'primary offering' => [['Is_PO' => 'Yes']],
            'old plan' => [['FWANewPlan' => 'N']],
            'prepaid name' => [['Name' => '5GHome_Prepaid']],
            'data suffix' => [['Name' => '5GHome_Postpaid_DATA']],
            'zero cap' => [['MaxGB' => '0']],
            'negative cap' => [['MaxGB' => '-1']],
            'nonnumeric cap' => [['MaxGB' => '240 GB']],
            'missing cap' => [['MaxGB' => null]],
            'missing CBS' => [['CBSId' => null]],
            'empty CBS' => [['CBSId' => '']],
            'zero CBS' => [['CBSId' => '0']],
            'negative CBS' => [['CBSId' => '-123']],
            'decimal CBS' => [['CBSId' => '123.5']],
            'nonnumeric CBS' => [['CBSId' => 'invalid']],
        ];
    }

    #[DataProvider('invalidFwaRows')]
    public function test_postpaid_catalog_excludes_incompatible_targets(array $overrides): void
    {
        $this->createSourceTables();
        $this->insertFwa($overrides);

        $this->assertSame([], app(LeasedlineCatalogRepository::class)->getPostpaidFwaOfferings('5G ILL')->all());
    }

    public function test_postpaid_catalog_accepts_space_suffix_and_excludes_duplicate_cbs_within_category(): void
    {
        $this->createSourceTables();
        $this->insertFwa();
        $this->insertFwa(['Id' => 28, 'Name' => 'Duplicate_Postpaid', 'FWANewPlan' => 'N']);
        $this->insertFwa(['Id' => 29, 'CBSId' => '123456', 'Name' => 'Example Postpaid']);
        $this->insertFwa(['Id' => 30, 'CBSId' => '123456', 'Name' => '4GExample_Postpaid', '5GOr4G' => '4G ILL']);

        $repository = app(LeasedlineCatalogRepository::class);

        $this->assertSame([29], $repository->getPostpaidFwaOfferings('5G ILL')->pluck('id')->all());
        $this->assertSame([30], $repository->getPostpaidFwaOfferings('4G ILL')->pluck('id')->all());
    }

    public function test_arbitrary_fwa_category_is_rejected_before_querying(): void
    {
        config(['database.connections.leasedline.driver' => 'forbidden']);

        $this->expectException(InvalidArgumentException::class);
        app(LeasedlineCatalogRepository::class)->getPostpaidFwaOfferings("5G ILL' OR 1=1");
    }

    public function test_postpaid_targets_exclude_current_name_and_preserve_legacy_boosters_and_usage(): void
    {
        $this->createSourceTables();
        $this->insertFwa();
        $this->insertFwa(['Id' => 28, 'CBSId' => '123456', 'Name' => 'Alternative_Postpaid']);
        $customer = ['success' => true, 'subscription' => 'Postpaid', 'BasePlan' => '5G Unlimited'];
        $current = ['Id' => 1801771352, 'Name' => '5GHome 1777_Postpaid'];
        $boosters = [['Id' => 1304191889, 'Name' => 'Booster']];
        $subscriptions = [['planId' => '1801771352']];
        $usage = [['remainingAmount' => '164.64 GB']];
        $this->mock(SubscriberService::class)->shouldReceive('getIllService')->once()
            ->andReturn(['bandwidth' => '5GHome 1777_Postpaid', 'subscriptions' => $subscriptions]);
        $legacy = $this->mock(IllOfferingRepository::class);
        $legacy->shouldReceive('getCurrentPlan')->once()->andReturn($current);
        $legacy->shouldReceive('getBoosterPlans')->once()->with('Postpaid')->andReturn(collect($boosters));
        $legacy->shouldNotReceive('getAlternativeBasePlans');
        $this->mock(CbsFwaUsageService::class)->shouldReceive('getUsage')->once()->andReturn($usage);

        $result = app(PostpaidFwaPlanService::class)->getPlans('12345678', $customer);

        $this->assertSame([28], array_column($result['basePlanOfferings'], 'id'));
        $this->assertSame($current, $result['currentBasePlan']);
        $this->assertSame($boosters, $result['addOnOfferings']);
        $this->assertSame($subscriptions, $result['subscriptions']);
        $this->assertSame($usage, $result['usage']);
    }

    public static function prepaidTypes(): array
    {
        return ['5G' => ['5G', '702186264'], '4G' => ['4G', '797820976']];
    }

    #[DataProvider('prepaidTypes')]
    public function test_prepaid_still_uses_local_plans_cbs_ids_gst_and_all_active_amounts(string $type, string $primaryId): void
    {
        config(['database.connections.leasedline.driver' => 'forbidden']);
        DB::connection()->statement('CREATE TABLE fwa_plans (id INTEGER, cbs_id INTEGER, plan_name TEXT, plan_type TEXT, amount DECIMAL, data_cap TEXT, max_speed TEXT, status INTEGER)');
        DB::table('fwa_plans')->insert([
            ['id' => 1, 'cbs_id' => 987654, 'plan_name' => 'Selected prepaid', 'plan_type' => $type, 'amount' => 100, 'data_cap' => '10 GB', 'max_speed' => '15 Mbps', 'status' => 1],
            ['id' => 2, 'cbs_id' => 654321, 'plan_name' => 'Other prepaid', 'plan_type' => $type === '5G' ? '4G' : '5G', 'amount' => 50, 'data_cap' => '5 GB', 'max_speed' => '5 Mbps', 'status' => 1],
            ['id' => 3, 'cbs_id' => 111222, 'plan_name' => 'Inactive', 'plan_type' => $type, 'amount' => 200, 'data_cap' => '20 GB', 'max_speed' => '20 Mbps', 'status' => 0],
        ]);
        $subscriber = $this->mock(SubscriberService::class);
        $subscriber->shouldReceive('getIllCustomerData')->once()->andReturn(['success' => true, 'subscription' => 'Prepaid', 'BasePlan' => 'Prepaid']);
        $subscriber->shouldReceive('getPrimaryOfferingId')->once()->andReturn($primaryId);

        $this->actingAs(User::factory()->make(), 'sanctum')->getJson('/api/v1/fwa/plans?service_id=12345678')
            ->assertOk()->assertExactJson([
                'success' => true, 'service_id' => '12345678', 'primary_offering_id' => (int) $primaryId,
                'plan_type' => $type, 'GST' => '5%',
                'plans' => [['plan_id' => 987654, 'plan_name' => 'Selected prepaid', 'amount' => 100,
                    'data_cap' => '10 GB', 'max_speed' => '15 Mbps', 'gstAmount' => 5, 'totalAmount' => 105]],
                'prepaidILLPlans' => [100, 50],
            ]);
    }

    public function test_postpaid_service_rejects_prepaid_without_fetching_plans(): void
    {
        $this->mock(SubscriberService::class)->shouldNotReceive('getIllService');
        $this->mock(LeasedlineCatalogRepository::class)->shouldNotReceive('getPostpaidFwaOfferings');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Subscriber is not eligible for postpaid FWA catalog.');
        app(PostpaidFwaPlanService::class)->getPlans('12345678', ['success' => true, 'subscription' => 'Prepaid', 'BasePlan' => '5G Unlimited']);
    }

    public function test_warm_catalogs_remain_available_when_source_connection_fails(): void
    {
        $this->createSourceTables();
        $this->insertNormal();
        $this->insertFwa();
        $repository = app(LeasedlineCatalogRepository::class);
        $normal = $repository->getNormalOfferings()->all();
        $fwa = $repository->getPostpaidFwaOfferings('5G ILL')->all();
        DB::purge('leasedline');
        config(['database.connections.leasedline.driver' => 'forbidden']);

        $this->assertSame($normal, $repository->getNormalOfferings()->all());
        $this->assertSame($fwa, $repository->getPostpaidFwaOfferings('5G ILL')->all());
    }

    public function test_catalog_caches_are_separate_and_expire_after_twelve_hours(): void
    {
        $this->freezeTime();
        $this->createSourceTables();
        $this->insertNormal();
        $this->insertFwa();
        $this->insertFwa(['Id' => 32, '5GOr4G' => '4G ILL']);
        Cache::put('ill.offerings', ['legacy'], 43200);
        Cache::put('catalog.base_data', ['mobile'], 43200);
        $repository = app(LeasedlineCatalogRepository::class);
        $repository->getNormalOfferings();
        $repository->getPostpaidFwaOfferings('5G ILL');
        $repository->getPostpaidFwaOfferings('4G ILL');
        DB::connection('leasedline')->enableQueryLog();

        $this->travel(43199)->seconds();
        $this->assertSame([34], $repository->getNormalOfferings()->pluck('id')->all());
        $this->assertSame([27], $repository->getPostpaidFwaOfferings('5G ILL')->pluck('id')->all());
        $this->assertSame([32], $repository->getPostpaidFwaOfferings('4G ILL')->pluck('id')->all());
        $this->assertSame([], DB::connection('leasedline')->getQueryLog());
        $this->assertSame(['legacy'], Cache::get('ill.offerings'));
        $this->assertSame(['mobile'], Cache::get('catalog.base_data'));

        $this->travel(2)->seconds();
        $repository->getNormalOfferings();
        $repository->getPostpaidFwaOfferings('5G ILL');
        $this->assertCount(3, DB::connection('leasedline')->getQueryLog());
    }

    public function test_source_failure_returns_controlled_error_and_logs_no_database_details(): void
    {
        $this->mock(SubscriberService::class)->shouldReceive('getIllCustomerData')->once()
            ->andReturn(['success' => true, 'subscription' => 'Postpaid', 'BasePlan' => 'ILL_Main_Offering']);
        Log::shouldReceive('error')->once()->with('Leased-line catalog read failed.', [
            'table' => 'crm_ill_offering', 'exception_type' => QueryException::class,
        ]);
        Cache::put('fwa.postpaid.offerings.5G ILL', [['id' => 27]], 43200);

        $this->actingAs(User::factory()->make(), 'sanctum')->getJson('/api/v1/ill/catalog?service_id=12345678')
            ->assertUnprocessable()->assertExactJson([
                'success' => false, 'message' => 'Unable to retrieve leased-line catalog. Please try again later.',
            ]);

        $this->assertNull(Cache::get('ill.normal.offerings'));
        $this->assertSame([['id' => 27]], Cache::get('fwa.postpaid.offerings.5G ILL'));
    }

    public static function postpaidEndpoints(): array
    {
        return [['/api/v1/fwa/plans'], ['/api/v1/fwa/postpaid/plans']];
    }

    #[DataProvider('postpaidEndpoints')]
    public function test_postpaid_source_failure_returns_400_without_sql_or_trace(string $endpoint): void
    {
        config(['app.debug' => true]);
        $subscriber = $this->mock(SubscriberService::class);
        $subscriber->shouldReceive('getIllCustomerData')->once()
            ->andReturn(['success' => true, 'subscription' => 'Postpaid', 'BasePlan' => '5G Unlimited']);
        $subscriber->shouldReceive('getIllService')->once()->andReturn(['bandwidth' => 'No Active Bandwidth', 'subscriptions' => []]);
        $this->mock(IllOfferingRepository::class)->shouldReceive('getCurrentPlan')->once()->andReturnNull();
        $this->mock(CbsFwaUsageService::class)->shouldReceive('getUsage')->once()->andReturn([]);
        Log::shouldReceive('error')->once()->with('Leased-line catalog read failed.', [
            'table' => 'crm_ill_offering_4g5g', 'exception_type' => QueryException::class,
        ]);

        $this->actingAs(User::factory()->make(), 'sanctum')->getJson($endpoint.'?service_id=12345678')
            ->assertStatus(400)->assertExactJson(['success' => false, 'message' => 'Unable to retrieve leased-line catalog. Please try again later.']);
    }
}
