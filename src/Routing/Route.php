<?php

declare(strict_types=1);

namespace PHAPI\Routing;

use PHAPI\PHAPI;

final class Route
{
    private static ?string $routesDir = null;
    private static ?PHAPI $api = null;

    public static function init(string $routesDir, PHAPI $api): void
    {
        self::$routesDir = rtrim($routesDir, '/\\');
        self::$api = $api;
    }

    public static function load(string $name): void
    {
        [$routesDir, ] = self::context();
        $file = self::fileForName($routesDir, $name);
        if (!file_exists($file)) {
            throw new \RuntimeException("Route file '{$name}' not found at '{$file}'");
        }

        $api = self::$api;
        require $file;
    }

    public static function loadGroup(string $group): void
    {
        [$routesDir, $api] = self::context();
        $group = trim($group, '/\\');
        $groupDir = $routesDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $group);
        if (!is_dir($groupDir)) {
            throw new \RuntimeException("Route group '{$group}' not found at '{$groupDir}'");
        }

        $groupName = basename($group);
        $groupConfigFile = $groupDir . DIRECTORY_SEPARATOR . $groupName . '.php';
        $api->beginDeferredGroupScope();
        try {
            if (is_file($groupConfigFile)) {
                require $groupConfigFile;
            }

            $files = [];
            $dirs = [];
            $entries = scandir($groupDir);
            if ($entries === false) {
                return;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $fullPath = $groupDir . DIRECTORY_SEPARATOR . $entry;
                if (is_dir($fullPath)) {
                    $dirs[] = $entry;
                    continue;
                }

                if (!is_file($fullPath) || pathinfo($entry, PATHINFO_EXTENSION) !== 'php') {
                    continue;
                }

                if ($entry === $groupName . '.php') {
                    continue;
                }

                $files[] = $entry;
            }

            sort($files, SORT_STRING);
            foreach ($files as $file) {
                require $groupDir . DIRECTORY_SEPARATOR . $file;
            }

            sort($dirs, SORT_STRING);
            foreach ($dirs as $dir) {
                self::loadGroup($group . '/' . $dir);
            }
        } finally {
            $api->endDeferredGroupScope();
        }
    }

    /**
     * @return array{0: string, 1: PHAPI}
     */
    private static function context(): array
    {
        if (self::$routesDir === null || self::$api === null) {
            throw new \RuntimeException('Route helper not initialized. Call Route::init() first.');
        }

        return [self::$routesDir, self::$api];
    }

    private static function fileForName(string $routesDir, string $name): string
    {
        $normalized = trim($name, '/\\');
        if (!str_ends_with($normalized, '.php')) {
            $normalized .= '.php';
        }

        return $routesDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $normalized);
    }
}
