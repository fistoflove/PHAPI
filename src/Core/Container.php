<?php

declare(strict_types=1);

namespace PHAPI\Core;

use PHAPI\Exceptions\ContainerException;
use PHAPI\Exceptions\NotFoundException;
use Psr\Container\ContainerInterface;
use ReflectionClass;

class Container implements ContainerInterface
{
    /**
     * @var array<string, mixed>
     */
    private array $instances = [];
    /**
     * @var array<string, array{concrete: mixed, scope: 'singleton'|'request'|'transient'}>
     */
    private array $bindings = [];
    /**
     * @var array<string, mixed>
     */
    private array $requestInstances = [];

    /**
     * Bind a value or factory to an id.
     *
     * @param string $id
     * @param mixed $value
     * @return void
     */
    public function set(string $id, $value): void
    {
        $this->instances[$id] = $value;
    }

    /**
     * Bind an abstract to a concrete implementation.
     *
     * @param string $id
     * @param mixed $concrete
     * @param bool $singleton
     * @return void
     */
    public function bind(string $id, $concrete, bool $singleton = false): void
    {
        $this->bindings[$id] = [
            'concrete' => $concrete,
            'scope' => $singleton ? 'singleton' : 'transient',
        ];
        unset($this->instances[$id]);
    }

    /**
     * Bind a request-scoped entry.
     *
     * @param string $id
     * @param mixed $concrete
     * @return void
     */
    public function request(string $id, $concrete): void
    {
        $this->bindings[$id] = [
            'concrete' => $concrete,
            'scope' => 'request',
        ];
        unset($this->instances[$id]);
        unset($this->requestInstances[$id]);
    }

    /**
     * Bind a singleton.
     *
     * @param string $id
     * @param mixed $concrete
     * @return void
     */
    public function singleton(string $id, $concrete): void
    {
        $this->bindings[$id] = [
            'concrete' => $concrete,
            'scope' => 'singleton',
        ];
        unset($this->requestInstances[$id]);
    }

    /**
     * Determine if a binding or instance exists.
     *
     * @param string $id
     * @return bool
     */
    public function has(string $id): bool
    {
        return array_key_exists($id, $this->instances)
            || array_key_exists($id, $this->bindings)
            || class_exists($id);
    }

    /**
     * Resolve a binding or autowire a class by name.
     *
     * @param string $id
     * @return mixed
     *
     * @throws \Psr\Container\NotFoundExceptionInterface When the service cannot be resolved.
     */
    public function get(string $id)
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (array_key_exists($id, $this->requestInstances)) {
            return $this->requestInstances[$id];
        }

        if (array_key_exists($id, $this->bindings)) {
            $binding = $this->bindings[$id];
            $instance = $this->resolveConcrete($binding['concrete']);
            if ($binding['scope'] === 'singleton') {
                $this->instances[$id] = $instance;
            } elseif ($binding['scope'] === 'request') {
                $this->requestInstances[$id] = $instance;
            }
            return $instance;
        }

        if (!class_exists($id)) {
            throw new NotFoundException("Service '{$id}' not found");
        }

        $instance = $this->autowire($id);
        $this->requestInstances[$id] = $instance;
        return $instance;
    }

    /**
     * Begin a new request scope.
     *
     * @return void
     */
    public function beginRequestScope(): void
    {
        $this->requestInstances = [];
    }

    /**
     * Clear the current request scope.
     *
     * @return void
     */
    public function endRequestScope(): void
    {
        $this->requestInstances = [];
    }

    /**
     * @param class-string $className
     * @return object
     */
    private function autowire(string $className): object
    {
        $reflection = new ReflectionClass($className);

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return new $className();
        }

        $params = [];
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                if ($param->isDefaultValueAvailable()) {
                    $params[] = $param->getDefaultValue();
                    continue;
                }
                throw new ContainerException("Cannot autowire parameter '{$param->getName()}' for '{$className}'");
            }

            $params[] = $this->get($type->getName());
        }

        return $reflection->newInstanceArgs($params);
    }

    /**
     * @param mixed $concrete
     * @return mixed
     */
    private function resolveConcrete($concrete)
    {
        if (is_callable($concrete)) {
            return $concrete($this);
        }

        if (is_string($concrete)) {
            return $this->get($concrete);
        }

        return $concrete;
    }
}
