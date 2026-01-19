<?php

namespace PHAPI\Core;

use ReflectionClass;
use ReflectionException;

class Container
{
    private array $bindings = [];
    private array $instances = [];

    public function set(string $id, $value): void
    {
        $this->bindings[$id] = $value;
        unset($this->instances[$id]);
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->instances) || array_key_exists($id, $this->bindings);
    }

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

    private function autowire(string $className)
    {
        try {
            $reflection = new ReflectionClass($className);
        } catch (ReflectionException $e) {
            throw new \RuntimeException("Cannot reflect '$className': " . $e->getMessage());
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return new $className();
        }

        $params = [];
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            if ($type === null || $type->isBuiltin()) {
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
