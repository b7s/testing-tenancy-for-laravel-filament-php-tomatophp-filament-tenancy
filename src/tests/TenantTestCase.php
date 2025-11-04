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

    // Active tenant
    protected bool $tenancy = true;

    protected ?string $loginUrl = null;

    protected ?string $tenantDatabasePath = null;

    protected ?string $tenantStoragePath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->loginUrl = '/login';

        $this->determineTenancyFromTestPath();

        if ($this->tenancy) {
            $this->initializeTenancy();
        }
    }

    /**
     * Automatically disable tenancy for Admin panel tests
     */
    protected function determineTenancyFromTestPath(): void
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        foreach ($backtrace as $trace) {
            if (
                isset($trace['class']) &&
                str_contains($trace['class'], 'Tenant\\Browser\\Admin')
            ) {
                $this->tenancy = false;
                break;
            }
        }
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

    public function refreshDatabase()
    {
        $this->beforeRefreshingDatabase();

        $this->artisan('migrate:fresh', [
            '--drop-views' => $this->shouldDropViews(),
            '--drop-types' => $this->shouldDropTypes(),
        ]);

        $this->afterRefreshingDatabase();
    }

    protected function initializeTenancy(): void
    {
        $this->tenantId = uniqid('pest_test_') . mt_rand(1000, 9999);

        $this->tenantStoragePath = $this->resolveTenantStoragePath();

        $tenant = $this->createTenant();

        if ($this->tenantUsesSqliteDriver()) {
            $this->tenantDatabasePath = $this->resolveTenantDatabasePath($tenant);
        }

        $this->createTenantFootprints($tenant);

        tenancy()->initialize($tenant);

        $this->loginUrl = '/' . config('cms.tenant_panel.path') . '/login';
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

    private function createTenantFootprints(Tenant $tenant): void
    {
        $createTenant = new CreateTenant();
        $createTenant->createTenantStorageDirectories($tenant);
        $createTenant->copyFilesToTenantStorage($tenant);
    }

    private function removeTenantFootprints(): void
    {
        if ($this->tenantId === null) {
            return;
        }

        if ($this->tenantUsesSqliteDriver()) {
            $this->cleanupTenantDatabase();
        }

        $this->cleanupTenantStorage();

        $this->tenantId = null;
        $this->tenantDatabasePath = null;
        $this->tenantStoragePath = null;
    }

    private function cleanupTenantDatabase(): void
    {
        if ($this->tenantId === null) {
            return;
        }

        $candidatePaths = array_filter([
            $this->tenantDatabasePath,
            database_path(config('tenancy.database.prefix') . $this->tenantId . config('tenancy.database.suffix')),
            config('database.connections.dynamic.database'),
        ], static fn ($path) => is_string($path) && $path !== '' && $path !== ':memory:');

        $deleted = [];

        foreach ($candidatePaths as $path) {
            if (isset($deleted[$path])) {
                continue;
            }

            $this->deleteTenantDatabaseFile($path);

            $deleted[$path] = true;
        }
    }

    private function cleanupTenantStorage(): void
    {
        if ($this->tenantId === null) {
            return;
        }

        $tenantStoragePath = $this->tenantStoragePath ?? $this->resolveTenantStoragePath();

        if (! is_string($tenantStoragePath) || $tenantStoragePath === '') {
            return;
        }

        if (! File::isDirectory($tenantStoragePath)) {
            return;
        }

        $storageBase = $this->tenantStorageBasePath();
        $realStorageBase = $storageBase !== null ? rtrim($storageBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR : null;
        $realPath = realpath($tenantStoragePath) ?: $tenantStoragePath;

        if ($realStorageBase !== null && ! str_starts_with($realPath, $realStorageBase)) {
            return;
        }

        if (! str_contains($realPath, $this->tenantId)) {
            return;
        }

        File::deleteDirectory($tenantStoragePath);
    }

    private function deleteTenantDatabaseFile(string $path): void
    {
        if ($this->tenantId === null || ! File::exists($path)) {
            return;
        }

        $databaseDir = rtrim(realpath(database_path()) ?: database_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $realPath = realpath($path) ?: $path;

        if (! str_contains($realPath, $this->tenantId)) {
            return;
        }

        if (! str_starts_with($realPath, $databaseDir)) {
            return;
        }

        File::delete($path);
    }

    private function resolveTenantDatabasePath(Tenant $tenant): ?string
    {
        $databaseConfig = $tenant->database();

        $databaseName = $databaseConfig->getName();

        if ($databaseName === null || $databaseName === '' || $databaseName === ':memory:') {
            return null;
        }

        if (str_contains($databaseName, DIRECTORY_SEPARATOR)) {
            return $databaseName;
        }

        return database_path($databaseName);
    }

    private function resolveTenantStoragePath(): ?string
    {
        if ($this->tenantId === null) {
            return null;
        }

        $suffixBase = trim((string) config('tenancy.filesystem.suffix_base', 'tenant/'), '/');

        $relativePath = $suffixBase === '' ? $this->tenantId : $suffixBase . DIRECTORY_SEPARATOR . $this->tenantId;

        return storage_path($relativePath);
    }

    private function tenantUsesSqliteDriver(): bool
    {
        $templateConnection = config('tenancy.database.template_tenant_connection', 'dynamic');

        return config("database.connections.{$templateConnection}.driver") === 'sqlite';
    }

    private function tenantStorageBasePath(): ?string
    {
        $suffixBase = trim((string) config('tenancy.filesystem.suffix_base', 'tenant/'), '/');

        $base = storage_path($suffixBase === '' ? '' : $suffixBase);

        return realpath($base) ?: $base;
    }
}
