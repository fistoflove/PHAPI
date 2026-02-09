<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\HTTP\Request;
use PHAPI\HTTP\Response;
use PHAPI\PHAPI;
use PHAPI\Routing\Route;

final class RouteLoaderTest extends SwooleTestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phapi-route-loader-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->tmpDir);
        parent::tearDown();
    }

    public function testLoadGroupAppliesNestedPrefixAndMiddlewareInheritance(): void
    {
        $this->writeFile('routes/V1/V1.php', <<<'PHP'
<?php
$api->group('/v1', function (\PHAPI\PHAPI $api): void {
    $api->groupMiddleware('track:v1');
    \PHAPI\Routing\Route::load('V1/users');
});
PHP);
        $this->writeFile('routes/V1/users.php', <<<'PHP'
<?php
$api->get('/users', function (): \PHAPI\HTTP\Response {
    return \PHAPI\HTTP\Response::text('v1-users');
});
PHP);
        $this->writeFile('routes/V1/Admin/Admin.php', <<<'PHP'
<?php
$api->group('/admin', function (\PHAPI\PHAPI $api): void {
    $api->groupMiddleware('track:admin');
    \PHAPI\Routing\Route::load('V1/Admin/users');
});
PHP);
        $this->writeFile('routes/V1/Admin/users.php', <<<'PHP'
<?php
$api->get('/users', function (): \PHAPI\HTTP\Response {
    return \PHAPI\HTTP\Response::text('admin-users');
});
PHP);

        $api = new PHAPI([
            'runtime' => 'swoole',
            'default_endpoints' => false,
            'app_base_dir' => $this->tmpDir,
        ]);
        $api->addMiddleware('track', static function (Request $request, callable $next, array $args = []): Response {
            $response = $next($request);
            $name = (string)($args[0] ?? 'unknown');
            return $response->withAddedHeader('X-Group-MW', $name);
        });

        Route::init($this->tmpDir . '/routes', $api);
        Route::loadGroup('V1');

        $response = $api->kernel()->handle(new Request('GET', '/v1/admin/users'));

        $this->assertSame(200, $response->status());
        $this->assertSame('admin-users', $response->body());
        $this->assertSame(['admin', 'v1'], $response->headerValues('X-Group-MW'));
    }

    public function testLoadGroupUsesStableSortedFileOrder(): void
    {
        $this->writeFile('routes/Apps/Apps.php', <<<'PHP'
<?php
$api->group('/apps', function (\PHAPI\PHAPI $api): void {
});
PHP);
        $this->writeFile('routes/Apps/b.php', <<<'PHP'
<?php
$api->get('/order', function (): \PHAPI\HTTP\Response {
    return \PHAPI\HTTP\Response::text('b');
});
PHP);
        $this->writeFile('routes/Apps/a.php', <<<'PHP'
<?php
$api->get('/order', function (): \PHAPI\HTTP\Response {
    return \PHAPI\HTTP\Response::text('a');
});
PHP);

        $api = new PHAPI([
            'runtime' => 'swoole',
            'default_endpoints' => false,
            'app_base_dir' => $this->tmpDir,
        ]);

        $api->loadGroup('Apps');
        $response = $api->kernel()->handle(new Request('GET', '/apps/order'));

        $this->assertSame(200, $response->status());
        $this->assertSame('a', $response->body());
    }

    private function writeFile(string $relativePath, string $content): void
    {
        $fullPath = $this->tmpDir . '/' . str_replace('\\', '/', $relativePath);
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($fullPath, $content);
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteDir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
