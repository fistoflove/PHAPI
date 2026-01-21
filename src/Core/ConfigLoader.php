<?php

declare(strict_types=1);

namespace PHAPI\Core;

final class ConfigLoader
{
    /**
     * Load default configuration and merge with overrides.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function load(array $overrides = []): array
    {
        $defaults = $this->defaults();
        return array_replace_recursive($defaults, $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        $base = $this->configPath('phapi.php');
        $defaults = [];
        if ($base !== null && file_exists($base)) {
            /** @var array<string, mixed> $loaded */
            $loaded = require $base;
            $defaults = $loaded;
        }

        $runtimeEnv = getenv('APP_RUNTIME');
        $debugEnv = getenv('APP_DEBUG');

        $defaults['runtime'] = ($runtimeEnv === false || $runtimeEnv === '')
            ? ($defaults['runtime'] ?? 'fpm')
            : $runtimeEnv;
        $defaults['debug'] = ($debugEnv !== false && $debugEnv !== '');

        return $defaults;
    }

    private function configPath(string $file): ?string
    {
        $path = dirname(__DIR__, 2) . '/config/' . $file;
        return file_exists($path) ? $path : null;
    }
}
