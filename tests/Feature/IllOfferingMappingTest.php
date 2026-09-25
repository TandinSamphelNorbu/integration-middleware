<?php

namespace Tests\Feature;

use App\Http\Middleware\LogApiRequest;
use App\Models\IllOfferingMapping;
use App\Models\User;
use App\Repositories\IllOfferingMappingRepository;
use App\Services\IllOfferingMappingService;
use App\Services\SubscriberService;
use Database\Seeders\IllOfferingMappingSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IllOfferingMappingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true],
            'database.connections.catalog' => ['driver' => 'forbidden'],
        ]);
        DB::purge('sqlite');
        $this->withoutMiddleware(LogApiRequest::class);
    }

    private function createMappings(): void
    {
        $migration = require database_path('migrations/2026_09_25_111505_create_ill_offering_mappings_table.php');
        $migration->up();
        $this->seed(IllOfferingMappingSeeder::class);
    }

    /** @return list<array{id: string, name: string}> */
    private function expectedAddons(): array
    {
        return [
            ['id' => '1303', 'name' => 'Home 5 Mbps 1500'],
            ['id' => '1306', 'name' => 'Home 7 Mbps 2100'],
            ['id' => '1307', 'name' => 'Enterprise 50 Mbps 13750'],
            ['id' => '1308', 'name' => 'Home 10 Mbps 3000'],
            ['id' => '1309', 'name' => 'Home 15 Mbps 4500'],
            ['id' => '1310', 'name' => 'Home 20 Mbps 6000'],
            ['id' => '1311', 'name' => 'Enterprise 70 Mbps 19250'],
            ['id' => '1312', 'name' => 'Enterprise 100 Mbps 27500'],
            ['id' => '1313', 'name' => 'Enterprise 300 Mbps 82500'],
            ['id' => '1314', 'name' => 'Enterprise 400 Mbps 110000'],
            ['id' => '1315', 'name' => 'Enterprise 800 Mbps 220000'],
            ['id' => '1337', 'name' => 'Home 20 Mbps 3000'],
        ];
    }

    public function test_repository_returns_all_twelve_addons_in_order(): void
    {
        $this->createMappings();

        $this->assertSame($this->expectedAddons(), app(IllOfferingMappingRepository::class)->getAddonsForBasePlan('109'));
    }

    public static function addonPairs(): array
    {
        return [
            ['109', '1303', ['id' => '1303', 'name' => 'Home 5 Mbps 1500']],
            ['109', '1337', ['id' => '1337', 'name' => 'Home 20 Mbps 3000']],
            ['109', 'unknown', null],
            ['different-base', '1303', null],
        ];
    }

    #[DataProvider('addonPairs')]
    public function test_service_only_finds_addons_for_the_supplied_base(string $baseId, string $addonId, ?array $expected): void
    {
        $this->createMappings();

        $this->assertSame($expected, app(IllOfferingMappingService::class)->findAddon($baseId, $addonId));
    }

    public function test_unknown_base_returns_empty_addons_without_name_fallback(): void
    {
        $this->createMappings();
        $service = app(IllOfferingMappingService::class);

        $this->assertSame(['base_plan' => ['id' => 'unknown', 'name' => null], 'addons' => []], $service->getAddons('unknown'));
        $this->assertSame(['base_plan' => ['id' => 'unknown', 'name' => 'ILL Main Offering'], 'addons' => []], $service->getAddons('unknown', 'ILL Main Offering'));
    }

    public function test_reseeding_updates_confirmed_names_without_duplicates_or_deleting_other_mappings(): void
    {
        $this->createMappings();
        $other = IllOfferingMapping::factory()->create(['base_plan_id' => '109', 'addon_id' => 'custom']);
        IllOfferingMapping::where('addon_id', '1303')->update(['addon_name' => 'Outdated', 'base_plan_name' => 'Outdated']);

        $this->seed(IllOfferingMappingSeeder::class);

        $this->assertDatabaseCount('ill_offering_mappings', 13);
        $this->assertModelExists($other);
        $this->assertDatabaseHas('ill_offering_mappings', ['base_plan_id' => '109', 'base_plan_name' => 'ILL Main Offering', 'addon_id' => '1303', 'addon_name' => 'Home 5 Mbps 1500']);
    }

    public function test_database_rejects_duplicate_base_addon_pairs(): void
    {
        $this->createMappings();
        $this->expectException(QueryException::class);

        IllOfferingMapping::factory()->create(['base_plan_id' => '109', 'addon_id' => '1303']);
    }

    public function test_external_ids_keep_leading_zeros_and_sort_as_strings(): void
    {
        $this->createMappings();
        foreach (['2', '10', '001'] as $addonId) {
            IllOfferingMapping::factory()->create(['base_plan_id' => '00109', 'base_plan_name' => 'Zero-prefixed base', 'addon_id' => $addonId, 'addon_name' => 'Addon '.$addonId]);
        }

        $result = app(IllOfferingMappingService::class)->getAddons('00109');

        $this->assertSame(['id' => '00109', 'name' => 'Zero-prefixed base'], $result['base_plan']);
        $this->assertSame(['001', '10', '2'], array_column($result['addons'], 'id'));
    }

    public function test_api_requires_authentication(): void
    {
        $this->getJson('/api/v1/ill/offerings/109/addons')->assertUnauthorized();
    }

    public function test_authenticated_api_returns_only_external_ids_and_names(): void
    {
        $this->createMappings();
        Http::preventStrayRequests();
        Http::fake();

        $this->actingAs(User::factory()->make(), 'sanctum')
            ->getJson('/api/v1/ill/offerings/109/addons?base_plan_name=ILL%20Main%20Offering')
            ->assertOk()->assertExactJson([
                'success' => true,
                'base_plan' => ['id' => '109', 'name' => 'ILL Main Offering'],
                'addons' => $this->expectedAddons(),
            ]);

        Http::assertNothingSent();
    }

    public function test_api_returns_422_for_base_name_mismatch(): void
    {
        $this->createMappings();

        $this->actingAs(User::factory()->make(), 'sanctum')
            ->getJson('/api/v1/ill/offerings/109/addons?base_plan_name=Wrong')
            ->assertUnprocessable()->assertJsonValidationErrors([
                'base_plan_name' => 'The base plan name does not match the supplied base plan ID.',
            ]);
    }

    public function test_api_rejects_non_string_name(): void
    {
        $this->actingAs(User::factory()->make(), 'sanctum')
            ->getJson('/api/v1/ill/offerings/109/addons?base_plan_name[]=invalid')
            ->assertUnprocessable()->assertJsonValidationErrors('base_plan_name');
    }

    public function test_api_returns_empty_addons_for_unknown_base(): void
    {
        $this->createMappings();

        $this->actingAs(User::factory()->make(), 'sanctum')
            ->getJson('/api/v1/ill/offerings/unknown/addons')
            ->assertOk()->assertExactJson(['success' => true, 'base_plan' => ['id' => 'unknown', 'name' => null], 'addons' => []]);
    }

    public static function prohibitedOperations(): array
    {
        return [['up'], ['down'], ['seed']];
    }

    #[DataProvider('prohibitedOperations')]
    public function test_migration_and_seeder_refuse_catalog_connection(string $operation): void
    {
        config(['database.default' => 'catalog']);
        $migration = require database_path('migrations/2026_09_25_111505_create_ill_offering_mappings_table.php');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ILL mappings must use the middleware database.');

        if ($operation === 'seed') {
            (new IllOfferingMappingSeeder)->run();
        } else {
            $migration->{$operation}();
        }
    }

    public function test_ill_page_requires_login(): void
    {
        $this->get('/ill')->assertRedirect('/login');
        $this->get('/dashboard/ill/catalog?service_id=123')->assertRedirect('/login');
    }

    public function test_ill_page_shows_seeded_offerings(): void
    {
        $this->withoutVite();
        $this->createMappings();

        $this->actingAs(User::factory()->make())->get('/ill')
            ->assertSee('ILL Main Offering')->assertSee('12 add-ons')
            ->assertSee('Home 5 Mbps 1500')->assertSee('1337')
            ->assertSee('Enterprise 800 Mbps 220000');
    }

    public function test_ill_page_shows_name_errors_and_empty_results(): void
    {
        $this->withoutVite();
        $this->createMappings();
        $this->actingAs(User::factory()->make());

        $this->get('/ill?base_plan_id=109&base_plan_name=Wrong')
            ->assertSee('The base plan name does not match the supplied base plan ID.')
            ->assertDontSee('Home 5 Mbps 1500');
        $this->get('/ill?base_plan_id=unknown')->assertSee('No add-ons mapped');
    }

    public function test_ill_page_escapes_offering_names(): void
    {
        $this->withoutVite();
        $this->createMappings();
        IllOfferingMapping::where('addon_id', '1303')->update(['addon_name' => '<script>alert(1)</script>']);

        $this->actingAs(User::factory()->make())->get('/ill')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_web_ill_subscriber_lookup_uses_existing_customer_and_local_mapping_services(): void
    {
        $this->createMappings();
        $this->mock(SubscriberService::class)->shouldReceive('getIllCustomerData')->once()->with('12345678')
            ->andReturn(['success' => true, 'BasePlan' => 'ILL_Main_Offering', 'subscription' => 'Postpaid']);

        $this->actingAs(User::factory()->make())->getJson('/dashboard/ill/catalog?service_id=12345678')
            ->assertOk()->assertExactJson([
                'success' => true, 'service_id' => '12345678', 'subscription' => 'Postpaid',
                'crm_base_plan' => 'ILL_Main_Offering',
                'base_plan' => ['id' => '109', 'name' => 'ILL Main Offering'],
                'addons' => $this->expectedAddons(),
            ]);
    }
}
