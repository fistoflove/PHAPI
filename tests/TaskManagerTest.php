<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\Logging\Logger;
use PHAPI\Server\TaskManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests TaskManager: register, setupHandlers, dispatch, handleTask.
 * Uses a mock Swoole\Http\Server to avoid starting a real server.
 */
final class TaskManagerTest extends TestCase
{
    private string $tmpDir;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phapi_taskmgr_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        $ref = new \ReflectionClass(Logger::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $this->logger = Logger::getInstance();
        $this->logger->setChannel('task', $this->tmpDir . '/task.log');
        $this->logger->setChannel('debug', $this->tmpDir . '/debug.log');
        $this->logger->setDebugMode(true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/*');
        if ($files) {
            array_map('unlink', $files);
        }
        @rmdir($this->tmpDir);

        $ref = new \ReflectionClass(Logger::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    // ── register ────────────────────────────────────────────

    public function testRegisterStoresHandler(): void
    {
        $tm = new TaskManager($this->logger);
        $called = false;
        $tm->register('test-task', function ($data, Logger $l) use (&$called) {
            $called = true;
        });

        // Verify by dispatching via handleTask (using reflection)
        $server = $this->createMockServer();
        $server->expects($this->once())->method('finish')->with($this->stringContains('done:test-task'));

        $this->invokeHandleTask($tm, $server, 1, ['name' => 'test-task', 'data' => null]);
        $this->assertTrue($called);
    }

    // ── setupHandlers ───────────────────────────────────────

    public function testSetupHandlersRegistersOnTaskAndOnFinish(): void
    {
        $tm = new TaskManager($this->logger);
        $server = $this->createMockServer();

        $registeredEvents = [];
        $server->expects($this->exactly(2))
            ->method('on')
            ->willReturnCallback(function (string $event, callable $handler) use (&$registeredEvents): bool {
                $registeredEvents[] = $event;
                return true;
            });

        $tm->setupHandlers($server);

        $this->assertContains('task', $registeredEvents);
        $this->assertContains('finish', $registeredEvents);
    }

    // ── dispatch ────────────────────────────────────────────

    public function testDispatchReturnsTrueOnSuccess(): void
    {
        $tm = new TaskManager($this->logger);
        $server = $this->createMockServer();

        $server->expects($this->once())
            ->method('task')
            ->with(['name' => 'my-task', 'data' => ['key' => 'value']])
            ->willReturn(1); // task ID

        $result = $tm->dispatch($server, 'my-task', ['key' => 'value']);
        $this->assertTrue($result);
    }

    public function testDispatchReturnsFalseOnFailure(): void
    {
        $tm = new TaskManager($this->logger);
        $server = $this->createMockServer();

        $server->expects($this->once())
            ->method('task')
            ->with(['name' => 'empty-task', 'data' => null])
            ->willReturn(false);

        $result = $tm->dispatch($server, 'empty-task', null);
        $this->assertFalse($result);
    }

    // ── handleTask (via reflection) ─────────────────────────

    public function testHandleTaskCallsRegisteredHandler(): void
    {
        $receivedData = null;
        $tm = new TaskManager($this->logger);
        $tm->register('process', function ($data, Logger $l) use (&$receivedData) {
            $receivedData = $data;
        });

        $server = $this->createMockServer();
        $server->expects($this->once())->method('finish')->with('done:process');

        $this->invokeHandleTask($tm, $server, 1, ['name' => 'process', 'data' => ['id' => 42]]);

        $this->assertSame(['id' => 42], $receivedData);
    }

    public function testHandleTaskUnknownTaskFinishesWithUnknown(): void
    {
        $tm = new TaskManager($this->logger);
        $server = $this->createMockServer();

        $server->expects($this->once())->method('finish')->with('unknown:no-such-task');

        $this->invokeHandleTask($tm, $server, 1, ['name' => 'no-such-task', 'data' => null]);

        $taskLog = file_get_contents($this->tmpDir . '/task.log');
        $this->assertStringContainsString('Unknown task', $taskLog);
        $this->assertStringContainsString('no-such-task', $taskLog);
    }

    public function testHandleTaskExceptionIsLoggedAndFinished(): void
    {
        $tm = new TaskManager($this->logger, true);
        $tm->register('failing', function ($data, Logger $l) {
            throw new \RuntimeException('task exploded');
        });

        $server = $this->createMockServer();
        $server->expects($this->once())
            ->method('finish')
            ->with($this->stringContains('error:failing:task exploded'));

        $this->invokeHandleTask($tm, $server, 1, ['name' => 'failing', 'data' => null]);

        $taskLog = file_get_contents($this->tmpDir . '/task.log');
        $this->assertStringContainsString('Task failed', $taskLog);
        $this->assertStringContainsString('task exploded', $taskLog);
    }

    public function testHandleTaskWithMissingNameField(): void
    {
        $tm = new TaskManager($this->logger);
        $server = $this->createMockServer();

        // Payload without 'name' key — defaults to empty string
        $server->expects($this->once())->method('finish')->with('unknown:');

        $this->invokeHandleTask($tm, $server, 1, ['data' => 'something']);
    }

    // ── Helpers ──────────────────────────────────────────────

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject&\Swoole\Http\Server
     */
    private function createMockServer()
    {
        return $this->createMock(\Swoole\Http\Server::class);
    }

    private function invokeHandleTask(TaskManager $tm, $server, int $taskId, mixed $payload): void
    {
        $ref = new \ReflectionClass($tm);
        $method = $ref->getMethod('handleTask');
        $method->setAccessible(true);
        $method->invoke($tm, $server, $taskId, $payload);
    }
}
