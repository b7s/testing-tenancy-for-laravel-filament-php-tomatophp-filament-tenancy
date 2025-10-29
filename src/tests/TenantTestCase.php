<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;
use TomatoPHP\FilamentTenancy\Filament\Resources\TenantResource\Pages\CreateTenant;
use TomatoPHP\FilamentTenancy\Models\Tenant;

abstract class TenantTestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected const string TENANT_DOMAIN = '127.0.0.1';

    protected ?string $tenantId = null;

    protected bool $tenancy = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        if ($this->tenancy) {
            $this->initializeTenancy();
        }
    }

    private function initializeTenancy(): void
    {
        $this->tenantId = uniqid('pest_test_') . mt_rand(1000, 9999);

        $tenant = $this->createTenant();

        tenancy()->initialize($tenant);
    }

    protected function tearDown(): void
    {
        try {
            if (function_exists('tenancy')) {
                tenancy()->end();
            }
        } finally {
            try {
                $this->removeTenantFootprints();
            } catch (\Throwable $e) {
                // Log error but continue teardown
                error_log("Failed to remove tenant footprints: {$e->getMessage()}");
            }

            parent::tearDown();
        }
    }

    private function createTenant(): Tenant
    {
        $tenant = Tenant::create([
            'id'   => $this->tenantId,
            'name' => 'Tenant Test',
        ]);

        $tenant->domains()->create([
            'domain' => self::TENANT_DOMAIN,
        ]);

        return $tenant;
    }
  
    private function removeTenantFootprints(): void
    {
        if ($this->tenantId === null) {
            return;
        }

        // remove sqlite database file
        if (config('database.default') === 'sqlite') {
            $databasePath = database_path(config('tenancy.database.prefix') . $this->tenantId . config('tenancy.database.suffix'));

            if (file_exists($databasePath)) {
                unlink($databasePath);
            }
        }

        // remove tenant storage directories
        $tenantStoragePath = storage_path(config('tenancy.filesystem.suffix_base') . $this->tenantId);

        if (File::isDirectory($tenantStoragePath)) {
            File::deleteDirectory($tenantStoragePath);
        }
    }

    public function refreshDatabase()
    {
        $this->beforeRefreshingDatabase();

        $this->artisan('migrate:fresh', [
            '--drop-views' => $this->shouldDropViews(),
            '--drop-types' => $this->shouldDropTypes(),
        ]);

        $this->afterRefreshingDatabase();
    }
}
