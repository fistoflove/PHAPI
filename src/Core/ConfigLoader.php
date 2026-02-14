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
        $this->loadEnvFile($this->envPath());

        $base = $this->configPath('phapi.php');
        $defaults = [];
        if ($base !== null && file_exists($base)) {
            /** @var array<string, mixed> $loaded */
            $loaded = require $base;
            $defaults = $loaded;
        }

        $debugEnv = getenv('APP_DEBUG');

        $defaults['runtime'] = 'swoole';
        $defaults['debug'] = $this->parseBoolEnv($debugEnv);

        return $defaults;
    }

    /**
     * @param string|false $value
     */
    private function parseBoolEnv($value): bool
    {
        if ($value === false) {
            return false;
        }

        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function envPath(): ?string
    {
        $path = dirname(__DIR__, 2) . '/.env';
        return file_exists($path) ? $path : null;
    }

    private function loadEnvFile(?string $path): void
    {
        if ($path === null) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $value = trim($parts[1]);
            if ($key === '') {
                continue;
            }

            $value = trim($value, " \t\n\r\0\x0B\"'");

            if (getenv($key) === false) {
                putenv($key . '=' . $value);
            }

            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
            }
        }
    }

    private function configPath(string $file): ?string
    {
        $path = dirname(__DIR__, 2) . '/config/' . $file;
        return file_exists($path) ? $path : null;
    }
}
