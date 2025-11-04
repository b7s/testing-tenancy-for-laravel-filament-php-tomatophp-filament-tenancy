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

    protected const string TENANT_DOMAIN = '127.0.0.1'; // The same in "phpunit.xml"

    protected ?string $tenantId = null;

    protected bool $tenancy = true;

    protected ?string $tenantDatabasePath = null;

    protected ?string $tenantStoragePath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->determineTenancyFromTestPath();

        // Clean up orphaned test files from previous failed tests
        $this->cleanupOrphanedTestFiles();

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
                str_contains($trace['class'], 'Tenant\\') &&
                str_contains($trace['class'], '\\Admin')
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
            } finally {
                parent::tearDown();
            }
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
        ], static fn($path) => is_string($path) && $path !== '' && $path !== ':memory:');

        $deleted = [];

        foreach ($candidatePaths as $path) {
            if (isset($deleted[$path])) {
                continue;
            }

            $this->deleteTenantDatabaseFile($path);
            $this->deleteTenantDatabaseFile($path . '-wal');
            $this->deleteTenantDatabaseFile($path . '-shm');

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
        if (! File::exists($path)) {
            return;
        }

        $databaseDir = rtrim(realpath(database_path()) ?: database_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $realPath = realpath($path) ?: $path;

        // Check if file is in database directory
        if (! str_starts_with($realPath, $databaseDir)) {
            return;
        }

        // Check if file contains tenant ID or test pattern
        $basename = basename($path);
        if ($this->tenantId !== null && str_contains($basename, $this->tenantId)) {
            File::delete($path);
            return;
        }

        // Check for test pattern in filename
        if (str_contains($basename, 'pest_test_')) {
            File::delete($path);
        }
    }

    private function resolveTenantDatabasePath(Tenant $tenant): ?string
    {
        $databaseConfig = $tenant->database();

        $databaseName = $databaseConfig->getName();

        if (in_array($databaseName, [null, '', ':memory:'], true)) {
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

    /**
     * Clean up orphaned test files from previous failed tests
     */
    private function cleanupOrphanedTestFiles(): void
    {
        try {
            // Clean up orphaned database files
            $databasePath = database_path();
            if (File::isDirectory($databasePath)) {
                $files = File::glob($databasePath . '/*pest_test_*');
                foreach ($files as $file) {
                    if (File::exists($file)) {
                        File::delete($file);
                    }
                }
            }

            // Clean up orphaned storage directories
            $storageBase = $this->tenantStorageBasePath();
            if ($storageBase !== null && File::isDirectory($storageBase)) {
                $directories = File::directories($storageBase);
                foreach ($directories as $directory) {
                    if (str_contains(basename($directory), 'pest_test_')) {
                        File::deleteDirectory($directory);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Silently fail - this is just cleanup
            error_log("Failed to cleanup orphaned test files: {$e->getMessage()}");
        }
    }
}
