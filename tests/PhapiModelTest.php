<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use Hyperf\Context\ApplicationContext;
use PHAPI\Database\PhapiModel;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

// Concrete model for testing
class TestItem extends PhapiModel
{
    protected ?string $table = 'test_items';
    protected array $fillable = ['name', 'value'];
}

/**
 * Tests PhapiModel: construction, guard, timestamps, attribute fill.
 * Requires a minimal Hyperf ApplicationContext with an event dispatcher stub.
 */
final class PhapiModelTest extends TestCase
{
    protected function setUp(): void
    {
        // Set up a minimal container so Hyperf Model can boot
        $dispatcher = new class () implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                return $event;
            }
        };

        $container = new class ($dispatcher) implements ContainerInterface {
            private EventDispatcherInterface $dispatcher;

            public function __construct(EventDispatcherInterface $dispatcher)
            {
                $this->dispatcher = $dispatcher;
            }

            public function get(string $id): mixed
            {
                if ($id === EventDispatcherInterface::class) {
                    return $this->dispatcher;
                }
                throw new class ("Not found: $id") extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {};
            }

            public function has(string $id): bool
            {
                return $id === EventDispatcherInterface::class;
            }
        };

        ApplicationContext::setContainer($container);
    }

    public function testModelSetsGuardToEmpty(): void
    {
        $model = new TestItem();
        $this->assertSame([], $model->getGuarded());
    }

    public function testModelHasTimestampsEnabled(): void
    {
        $model = new TestItem();
        $this->assertTrue($model->timestamps);
    }

    public function testModelTableName(): void
    {
        $model = new TestItem();
        $this->assertSame('test_items', $model->getTable());
    }

    public function testModelFillsAttributes(): void
    {
        $model = new TestItem(['name' => 'Alice', 'value' => 'data']);
        $this->assertSame('Alice', $model->getAttribute('name'));
        $this->assertSame('data', $model->getAttribute('value'));
    }

    public function testModelToArray(): void
    {
        $model = new TestItem(['name' => 'Bob', 'value' => 'info']);
        $arr = $model->toArray();
        $this->assertSame('Bob', $arr['name']);
        $this->assertSame('info', $arr['value']);
    }

    public function testModelIsInstanceOfHyperfModel(): void
    {
        $model = new TestItem();
        $this->assertInstanceOf(\Hyperf\DbConnection\Model\Model::class, $model);
        $this->assertInstanceOf(PhapiModel::class, $model);
    }

    public function testModelSetAndGetAttribute(): void
    {
        $model = new TestItem();
        $model->setAttribute('name', 'Charlie');
        $this->assertSame('Charlie', $model->getAttribute('name'));
    }

    public function testModelIsDirtyAfterChange(): void
    {
        $model = new TestItem(['name' => 'original']);
        $model->syncOriginal();

        $model->setAttribute('name', 'changed');
        $this->assertTrue($model->isDirty('name'));
    }

    public function testModelIsNotDirtyWithoutChange(): void
    {
        $model = new TestItem(['name' => 'same']);
        $model->syncOriginal();

        $this->assertFalse($model->isDirty('name'));
    }
}
