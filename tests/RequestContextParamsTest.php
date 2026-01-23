<?php

namespace PHAPI\Tests;

use PHAPI\HTTP\Request;
use PHAPI\HTTP\RequestContext;
use PHAPI\HTTP\Response;
use PHAPI\PHAPI;

class RequestContextParamsTest extends SwooleTestCase
{
    public function testParamsAvailableFromRequestContext(): void
    {
        $api = new PHAPI(['runtime' => 'swoole']);
        $api->get('/bases/{id}', function (): Response {
            $request = PHAPI::request();
            return Response::json([
                'id' => $request?->param('id'),
            ]);
        });

        $reflection = new \ReflectionClass($api);
        $kernelProp = $reflection->getProperty('kernel');
        $kernelProp->setAccessible(true);
        $kernel = $kernelProp->getValue($api);

        $response = $kernel->handle(new Request('GET', '/bases/123'));
        $body = json_decode($response->body(), true);

        $this->assertSame(200, $response->status());
        $this->assertSame(['id' => '123'], $body);

        RequestContext::clear();
    }
}
