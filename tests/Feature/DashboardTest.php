<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CatalogService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32))]);
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'session.driver' => 'array', 'cache.default' => 'array']);
        DB::purge('sqlite');
    }

    private function createUser(): User
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        return User::create(['name' => 'operator', 'email' => 'operator@example.test', 'password' => 'test-password']);
    }

    public function test_login_page_is_available(): void
    {
        $this->get('/login')->assertSee('Sign in to your workspace');
    }

    public static function protectedRoutes(): array
    {
        return [['GET', '/dashboard'], ['GET', '/logs'], ['GET', '/apis'], ['GET', '/dashboard/catalog'], ['GET', '/dashboard/fwa'], ['POST', '/dashboard/catalog/refresh'], ['POST', '/dashboard/fwa/sync'], ['POST', '/logout']];
    }

    #[DataProvider('protectedRoutes')]
    public function test_guests_cannot_access_operations(string $method, string $path): void
    {
        $this->call($method, $path)->assertRedirect('/login');
    }

    public function test_existing_user_can_sign_in(): void
    {
        $user = $this->createUser();
        $this->post('/login', ['name' => 'operator', 'password' => 'test-password'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->createUser();
        $this->from('/login')->post('/login', ['name' => 'operator', 'password' => 'incorrect'])->assertRedirect('/login')->assertSessionHasErrors(['name' => 'The username or password is incorrect.']);
        $this->assertGuest();
    }

    public function test_login_requires_credentials(): void
    {
        $this->post('/login')->assertSessionHasErrors(['name', 'password']);
        $this->assertGuest();
    }

    public function test_login_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login');
        }
        $this->post('/login')->assertTooManyRequests();
    }

    public function test_logout_invalidates_the_session(): void
    {
        $user = $this->createUser();
        $this->actingAs($user)->withSession(['private_value' => 'secret'])->post('/logout')->assertRedirect('/login')->assertSessionMissing('private_value');
        $this->assertGuest();
    }

    public function test_dashboard_escapes_the_user_name(): void
    {
        $user = new User(['name' => '<script>alert(1)</script>']);
        $user->id = 1;
        $this->actingAs($user)->get('/dashboard')->assertSee('Find subscriber plans')->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_api_directory_lists_routes_and_authentication(): void
    {
        $this->actingAs(new User(['name' => 'operator']))->get('/apis')->assertSee('/api/v1/login')->assertSee('/api/v1/catalog')->assertSee('/api/v1/fwa/sync')->assertSee('Bearer token required');
    }

    public function test_catalog_lookup_uses_existing_service(): void
    {
        $this->mock(CatalogService::class)->shouldReceive('getCatalog')->once()->with('12345678', 'prepaid')->andReturn(['poId' => '123', 'dataPlans' => []]);
        $this->actingAs(new User(['name' => 'operator']))->getJson('/dashboard/catalog?service_id=12345678&type=prepaid')->assertExactJson(['poId' => '123', 'dataPlans' => []]);
    }

    public function test_lookup_rejects_invalid_input(): void
    {
        $this->actingAs(new User(['name' => 'operator']))->getJson('/dashboard/catalog?type=invalid')->assertUnprocessable()->assertJsonValidationErrors(['service_id', 'type']);
    }

    public function test_logs_filter_and_paginate_existing_records(): void
    {
        Schema::create('api_request_logs', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id')->nullable();
            $table->string('request_id');
            $table->string('method');
            $table->string('path');
            $table->string('ip_address')->nullable();
            $table->integer('status_code');
            $table->integer('duration_ms');
            $table->timestamp('requested_at');
        });
        for ($id = 1; $id <= 26; $id++) {
            DB::table('api_request_logs')->insert(['request_id' => 'request-'.$id, 'method' => 'GET', 'path' => 'api/v1/catalog', 'status_code' => 422, 'duration_ms' => 12, 'requested_at' => '2026-09-24 12:00:00']);
        }
        DB::table('api_request_logs')->insert(['request_id' => 'excluded-request', 'method' => 'POST', 'path' => 'api/v1/login', 'status_code' => 200, 'duration_ms' => 4, 'requested_at' => '2026-09-23 12:00:00']);
        $this->actingAs(new User(['name' => 'operator']))->get('/logs?search=catalog&method=GET&status=4&date=2026-09-24')->assertSee('26 requests')->assertSee('request-26')->assertDontSee('excluded-request')->assertViewHas('logs', fn ($logs) => $logs->count() === 25 && $logs->total() === 26);
    }

    public function test_invalid_log_filters_are_rejected(): void
    {
        $this->actingAs(new User(['name' => 'operator']))->getJson('/logs?status=9&method=BAD&date=invalid')->assertUnprocessable()->assertJsonValidationErrors(['status', 'method', 'date']);
    }
}
