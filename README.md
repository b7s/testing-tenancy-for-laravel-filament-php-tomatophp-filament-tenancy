<h1 align="center">🧪 + 🍅</h1>

Testing (Laravel) **Filament PHP** with the [tomatophp/filament-tenancy](https://github.com/tomatophp/filament-tenancy) package (that uses [tenancy for laravel](https://tenancyforlaravel.com)) can be a bit tricky. So, I had to create a custom setup to generate and delete files in order for the tests to work as expected. I am using this configuration for browser testing with **Pest 4**.

> ⚠️ This is only for the panel that has active tenants; the admin doesn't need it.
> 
> [See how to configure Tomato-Tenancy](https://github.com/tomatophp/filament-tenancy/tree/master#installation)

## Steps

1) Separate the tests into the appropriate folders: `Tenant/Admin` and `Tenant/Tenants`
   - The script uses this separation to automatically identify whether or not to activate the tenant.
3) Configure your root `phpunit.xml`:
   - If you use SQLite: I needed to change the test, which previously used an in-memory "sqlite" database, to a physical file `database/testing.sqlite` (remember to add it to the ".gitignore" file).

```xml
    <testsuites>
        <!-- others here ... -->
        <testsuite name="Tenant">
            <directory>tests/Tenant</directory>
        </testsuite>
    </testsuites>

    <php>
        <env name="APP_ENV" value="testing"/>
        <env name="APP_URL" value="https://127.0.0.1"/>
        <env name="CENTRAL_DOMAIN" value="127.0.0.1"/>
        <env name="DB_CONNECTION" value="sqlite"/>
        <env name="DB_DATABASE" value="database/testing.sqlite"/>
        <env name="CACHE_DRIVER" value="array"/>
        <env name="SESSION_DRIVER" value="array"/>
        <env name="QUEUE_CONNECTION" value="sync"/>
        <env name="DB_REBUILD_DATABASE" value="false"/>
    </php>
```

3) Create a custom TestCase for tenants in `tests/TenantTestCase.php`: See the [code here](src/tests/TenantTestCase.php)

4) Now add a custom configuration for the Tenants in: `tests/Pest.php` file
   - All tenant tests are located in the `tests/Tenants` folder.
   - In the "beforeEach" block, configure it however you like.

```php
pest()->extend(Tests\TenantTestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->beforeEach(function (): void {
        Str::createRandomStringsNormally();
        Str::createUuidsNormally();
        Http::preventStrayRequests();
        Sleep::fake();
        $this->freezeTime();
    })
    ->in('Tenant');
```
5) Add your tests to the `tests/Tenants` folder and that's it! 🚀

> If you have a more practical idea, let me know. 😅
