<?php

declare(strict_types=1);

namespace PHAPI\Core;

use ReflectionClass;

class Container
{
    /**
     * @var array<string, mixed>
     */
    private array $bindings = [];
    /**
     * @var array<string, mixed>
     */
    private array $instances = [];

    /**
     * Bind a value or factory to an id.
     *
     * @param string $id
     * @param mixed $value
     * @return void
     */
    public function set(string $id, $value): void
    {
        $this->bindings[$id] = $value;
        unset($this->instances[$id]);
    }

    /**
     * Determine if a binding or instance exists.
     *
     * @param string $id
     * @return bool
     */
    public function has(string $id): bool
    {
        return array_key_exists($id, $this->instances) || array_key_exists($id, $this->bindings);
    }

    /**
     * Resolve a binding or autowire a class by name.
     *
     * @param string $id
     * @return mixed
     *
     * @throws \RuntimeException When the service cannot be resolved.
     */
    public function get(string $id)
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (array_key_exists($id, $this->bindings)) {
            $value = $this->bindings[$id];
            $instance = is_callable($value) ? $value($this) : $value;
            $this->instances[$id] = $instance;
            return $instance;
        }

        if (!class_exists($id)) {
            throw new \RuntimeException("Service '$id' not found");
        }

        $instance = $this->autowire($id);
        $this->instances[$id] = $instance;
        return $instance;
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
                throw new \RuntimeException("Cannot autowire parameter '{$param->getName()}' for '$className'");
            }

            $params[] = $this->get($type->getName());
        }

        return $reflection->newInstanceArgs($params);
    }
}
