<?php

declare(strict_types=1);

namespace PHAPI\Supabase;

use PHAPI\Core\Container;
use PHAPI\Core\ServiceProviderInterface;
use PHAPI\Exceptions\ConfigException;
use PHAPI\PHAPI;
use PHAPI\Supabase\Middleware\SupabaseAuthMiddleware;
use PHAPI\Supabase\Middleware\SupabaseRoleMiddleware;

/**
 * Registers Supabase services in the PHAPI container and middleware stack.
 *
 * Singletons (per-worker, immutable):
 *   - SupabaseConfig
 *   - SupabaseTransport
 *   - SupabaseFactory
 *
 * Request-scoped:
 *   - SupabaseContext
 *
 * Named middleware:
 *   - 'supabase.auth'
 *   - 'supabase.role'
 *
 * Declarative buckets (config):
 *   ```php
 *   'supabase' => [
 *       'buckets' => [
 *           'avatars' => ['public' => true],
 *           'documents' => ['public' => false, 'file_size_limit' => 10485760],
 *       ],
 *   ]
 *   ```
 *   Buckets are provisioned in parallel on worker start (worker 0 only)
 *   using Swoole coroutines via `ensureBucket()` — idempotent create-or-update.
 */
final class SupabaseProvider implements ServiceProviderInterface
{
    public function register(Container $container, array $config): void
    {
        /** @var array<string, mixed> $supabaseConfig */
        $supabaseConfig = $config['supabase'] ?? [];

        $url = (string) ($supabaseConfig['url'] ?? '');
        $anonKey = (string) ($supabaseConfig['anon_key'] ?? '');

        if ($url === '' || $anonKey === '') {
            throw new ConfigException(
                'Supabase config requires "url" and "anon_key". Set supabase.url and supabase.anon_key in your config.'
            );
        }

        $container->singleton(SupabaseConfig::class, static function () use ($supabaseConfig): SupabaseConfig {
            return new SupabaseConfig($supabaseConfig);
        });

        $container->singleton(SupabaseTransport::class, static function (Container $c): SupabaseTransport {
            return new SupabaseTransport($c->get(SupabaseConfig::class));
        });

        $container->singleton(SupabaseFactory::class, static function (Container $c): SupabaseFactory {
            return new SupabaseFactory(
                $c->get(SupabaseTransport::class),
                $c->get(SupabaseConfig::class),
            );
        });

        $container->request(SupabaseContext::class, static function (Container $c): SupabaseContext {
            return $c->get(SupabaseFactory::class)->createContext();
        });
    }

    public function boot(PHAPI $app): void
    {
        $container = $app->container();

        $app->addMiddleware('supabase.auth', function ($request, $next) use ($container) {
            /** @var SupabaseFactory $factory */
            $factory = $container->get(SupabaseFactory::class);

            $supabaseConfig = $container->get(SupabaseConfig::class);
            $config = [];
            if (is_object($supabaseConfig)) {
                $config = (array) $supabaseConfig;
            }
            $tokenResolver = $config['token_resolver'] ?? null;

            $middleware = new SupabaseAuthMiddleware(
                $factory,
                $container,
                is_callable($tokenResolver) ? $tokenResolver : null,
            );
            return $middleware($request, $next);
        });

        $app->addMiddleware('supabase.role', function ($request, $next, $args = []) use ($container) {
            $middleware = new SupabaseRoleMiddleware($container);
            return $middleware($request, $next, $args);
        });

        $this->registerBucketProvisioning($app, $container);
    }

    /**
     * Register parallel bucket provisioning on worker start.
     *
     * Only worker 0 provisions buckets to avoid race conditions in multi-worker
     * deployments. Each bucket is provisioned in its own Swoole coroutine via
     * WaitGroup for maximum parallelism — all HTTP requests to the Storage API
     * run concurrently rather than sequentially.
     */
    private function registerBucketProvisioning(PHAPI $app, Container $container): void
    {
        /** @var SupabaseConfig $config */
        $config = $container->get(SupabaseConfig::class);
        $buckets = $config->buckets;

        if ($buckets === []) {
            return;
        }

        $app->onWorkerStart(function (mixed $server, int $workerId) use ($container, $buckets): void {
            // Only provision on worker 0 to avoid parallel races across workers
            if ($workerId !== 0) {
                return;
            }

            /** @var SupabaseFactory $factory */
            $factory = $container->get(SupabaseFactory::class);
            $storage = $factory->createServiceContext()->storage();

            if (class_exists(\Swoole\Coroutine\WaitGroup::class)) {
                $wg = new \Swoole\Coroutine\WaitGroup();
                $errors = [];

                foreach ($buckets as $name => $options) {
                    $wg->add();
                    \Swoole\Coroutine::create(function () use ($storage, $name, $options, $wg, &$errors) {
                        try {
                            $storage->ensureBucket($name, $options);
                        } catch (\Throwable $e) {
                            $errors[$name] = $e;
                        } finally {
                            $wg->done();
                        }
                    });
                }

                $wg->wait(30.0);

                if ($errors !== []) {
                    $messages = [];
                    foreach ($errors as $bucketName => $error) {
                        $messages[] = $bucketName . ': ' . $error->getMessage();
                    }
                    error_log('[PHAPI Supabase] Bucket provisioning errors: ' . implode('; ', $messages));
                }

                return;
            }

            // Fallback: sequential provisioning when WaitGroup is unavailable
            foreach ($buckets as $name => $options) {
                try {
                    $storage->ensureBucket($name, $options);
                } catch (\Throwable $e) {
                    error_log('[PHAPI Supabase] Failed to provision bucket "' . $name . '": ' . $e->getMessage());
                }
            }
        });
    }
}
