<?php

namespace PHAPI\Tools;

/**
 * Scaffold Generator - Creates PHAPI project structures
 */
class ScaffoldGenerator
{
    private const TEMPLATE_SINGLE = 'single';
    private const TEMPLATE_MULTI = 'multi';

    /**
     * Run the scaffold generator
     *
     * @param array $argv Command line arguments
     */
    public function run(array $argv): void
    {
        $command = $argv[1] ?? '';

        if ($command !== 'new') {
            $this->showUsage();
            exit(1);
        }

        $projectName = $argv[2] ?? null;
        if (!$projectName) {
            echo "Error: Project name is required.\n";
            echo "Usage: php bin/phapi new <project-name>\n";
            exit(1);
        }

        $this->generate($projectName);
    }

    /**
     * Generate project scaffold
     *
     * @param string $projectName Project name
     */
    private function generate(string $projectName): void
    {
        echo "\n🚀 PHAPI Project Scaffold Generator\n";
        echo "===================================\n\n";

        // Validate project name
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $projectName)) {
            echo "Error: Project name can only contain letters, numbers, underscores, and hyphens.\n";
            exit(1);
        }

        // Check if directory exists
        $projectDir = getcwd() . '/' . $projectName;
        if (is_dir($projectDir)) {
            echo "Error: Directory '$projectName' already exists.\n";
            exit(1);
        }

        // Get project configuration
        $config = $this->promptConfiguration($projectName);

        // Create project structure
        $this->createProjectStructure($projectDir, $projectName, $config);

        echo "\n✅ Project '$projectName' created successfully!\n\n";
        echo "Next steps:\n";
        echo "  cd $projectName\n";
        echo "  composer install\n";
        echo "  php app.php\n\n";
    }

    /**
     * Prompt user for project configuration
     *
     * @param string $projectName Project name
     * @return array Configuration array
     */
    private function promptConfiguration(string $projectName): array
    {
        $config = [
            'structure' => $this->chooseStructure(),
            'host' => $this->prompt('Host [0.0.0.0]: ', '0.0.0.0'),
            'port' => (int)$this->prompt('Port [9501]: ', '9501'),
            'cors' => $this->promptYesNo('Enable CORS? [y/N]: ', false),
            'logging' => $this->promptYesNo('Enable logging? [Y/n]: ', true),
            'debug' => $this->promptYesNo('Enable debug mode? [y/N]: ', false),
        ];

        return $config;
    }

    /**
     * Choose project structure
     *
     * @return string Structure type
     */
    private function chooseStructure(): string
    {
        echo "\nChoose project structure:\n";
        echo "  1) Single-file (simple, everything in one file)\n";
        echo "  2) Multi-file (organized, separated structure)\n";
        echo "\n";

        while (true) {
            $choice = $this->prompt('Select structure [1-2]: ', '1');
            if ($choice === '1' || $choice === '2') {
                return $choice === '1' ? self::TEMPLATE_SINGLE : self::TEMPLATE_MULTI;
            }
            echo "Invalid choice. Please enter 1 or 2.\n";
        }
    }

    /**
     * Prompt for user input
     *
     * @param string $message Prompt message
     * @param string|null $default Default value
     * @return string User input
     */
    private function prompt(string $message, ?string $default = null): string
    {
        echo $message;
        $input = trim(fgets(STDIN));
        return $input !== '' ? $input : ($default ?? '');
    }

    /**
     * Prompt for yes/no input
     *
     * @param string $message Prompt message
     * @param bool $default Default value
     * @return bool True for yes, false for no
     */
    private function promptYesNo(string $message, bool $default): bool
    {
        $defaultStr = $default ? 'y' : 'n';
        $input = strtolower(trim($this->prompt($message, $defaultStr)));
        
        if ($input === '') {
            return $default;
        }
        
        return in_array($input, ['y', 'yes'], true);
    }

    /**
     * Create project structure
     *
     * @param string $projectDir Project directory
     * @param string $projectName Project name
     * @param array $config Configuration
     */
    private function createProjectStructure(string $projectDir, string $projectName, array $config): void
    {
        mkdir($projectDir, 0755, true);

        // Create composer.json
        $this->createComposerJson($projectDir, $projectName);

        // Create .gitignore
        $this->createGitignore($projectDir);

        // Create README
        $this->createReadme($projectDir, $projectName, $config);

        // Create project structure based on template
        if ($config['structure'] === self::TEMPLATE_SINGLE) {
            $this->createSingleFileStructure($projectDir, $config);
        } else {
            $this->createMultiFileStructure($projectDir, $config);
        }

        // Create .env.example if needed
        $this->createEnvExample($projectDir);
    }

    /**
     * Create composer.json
     *
     * @param string $projectDir Project directory
     * @param string $projectName Project name
     */
    private function createComposerJson(string $projectDir, string $projectName): void
    {
        $packageName = strtolower(preg_replace('/[^a-z0-9]/', '-', $projectName));
        
        $composer = [
            'name' => $packageName . '/' . $packageName,
            'description' => 'PHAPI application',
            'type' => 'project',
            'require' => [
                'php' => '^8.1',
                'ext-swoole' => '^5.0|^6.0',
                'phapi/phapi' => '^1.0'
            ],
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/'
                ]
            ]
        ];

        file_put_contents(
            $projectDir . '/composer.json',
            json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    /**
     * Create .gitignore
     *
     * @param string $projectDir Project directory
     */
    private function createGitignore(string $projectDir): void
    {
        $gitignore = <<<'EOT'
/vendor/
composer.lock
.env
*.log
/logs/
.phpunit.result.cache
.idea/
.vscode/
.DS_Store
Thumbs.db
EOT;

        file_put_contents($projectDir . '/.gitignore', $gitignore);
    }

    /**
     * Create README
     *
     * @param string $projectDir Project directory
     * @param string $projectName Project name
     * @param array $config Configuration
     */
    private function createReadme(string $projectDir, string $projectName, array $config): void
    {
        $readme = <<<EOT
# {$projectName}

PHAPI Application

## Installation

```bash
composer install
```

## Running

```bash
php app.php
```

The server will be available at `http://{$config['host']}:{$config['port']}`

## Structure

EOT;

        if ($config['structure'] === self::TEMPLATE_SINGLE) {
            $readme .= "Single-file structure - everything in `app.php`\n";
        } else {
            $readme .= <<<'EOT'
Multi-file structure:
- `app.php` - Main entry point
- `app/middlewares.php` - Middleware definitions
- `app/routes.php` - Route definitions
- `app/tasks.php` - Background task definitions

EOT;
        }

        file_put_contents($projectDir . '/README.md', $readme);
    }

    /**
     * Create single-file structure
     *
     * @param string $projectDir Project directory
     * @param array $config Configuration
     */
    private function createSingleFileStructure(string $projectDir, array $config): void
    {
        $corsCode = $config['cors'] ? "\$api->enableCORS();" : "// \$api->enableCORS();";
        $loggingCode = $config['logging'] 
            ? "\$api->configureLogging(debug: " . ($config['debug'] ? 'true' : 'false') . ");"
            : "// \$api->configureLogging();";

        $app = <<<EOT
<?php

/**
 * PHAPI Application
 * Single-file structure
 */

require __DIR__ . '/vendor/autoload.php';

use PHAPI\PHAPI;
use PHAPI\HTTP\Response;

// Initialize API
\$api = new PHAPI('{$config['host']}', {$config['port']});

// Configure logging
{$loggingCode}

// Enable CORS
{$corsCode}

// ============================================================================
// ROUTES
// ============================================================================

// Root endpoint
\$api->get('/', function(\$input, \$request, \$response, \$api) {
    Response::json(\$response, [
        'message' => 'Welcome to PHAPI',
        'status' => 'running'
    ]);
});

// Health check endpoint
\$api->get('/health', function(\$input, \$request, \$response, \$api) {
    Response::json(\$response, [
        'ok' => true,
        'time' => date('c')
    ]);
});

// ============================================================================
// TASKS (optional)
// ============================================================================

// \$api->task('processData', function(\$data, \$logger) {
//     \$logger->task()->info("Processing data", ['data' => \$data]);
// });

// ============================================================================
// START SERVER
// ============================================================================

\$api->run();

EOT;

        file_put_contents($projectDir . '/app.php', $app);
    }

    /**
     * Create multi-file structure
     *
     * @param string $projectDir Project directory
     * @param array $config Configuration
     */
    private function createMultiFileStructure(string $projectDir, array $config): void
    {
        mkdir($projectDir . '/app', 0755, true);

        $corsCode = $config['cors'] ? "\$api->enableCORS();" : "// \$api->enableCORS();";
        $loggingCode = $config['logging'] 
            ? "\$api->configureLogging(debug: " . ($config['debug'] ? 'true' : 'false') . ");"
            : "// \$api->configureLogging();";

        // Create app.php
        $app = <<<EOT
<?php

/**
 * PHAPI Application
 * Multi-file structure
 */

require __DIR__ . '/vendor/autoload.php';

use PHAPI\PHAPI;

// Initialize API
\$api = new PHAPI('{$config['host']}', {$config['port']});

// Configure logging
{$loggingCode}

// Enable CORS
{$corsCode}

// Load app structure (middlewares.php, routes.php, tasks.php)
\$api->loadApp();

// Start server
\$api->run();

EOT;

        file_put_contents($projectDir . '/app.php', $app);

        // Create app/middlewares.php
        $middlewares = <<<'EOT'
<?php

/**
 * Middleware definitions
 */

use PHAPI\HTTP\Response;

// ============================================================================
// GLOBAL MIDDLEWARE - Runs before all routes
// ============================================================================

// Example: Add global middleware here
// $api->middleware(function($request, $response, $next) use ($api) {
//     // Your middleware logic
//     return $next();
// });

// ============================================================================
// AFTER MIDDLEWARE - Runs after route handler
// ============================================================================

$api->afterMiddleware(function($request, $response, $next) use ($api) {
    // Non-blocking logging example
    // Can access response status via $response->statusCode
    return $next();
});

// ============================================================================
// NAMED MIDDLEWARE - Reusable across routes
// ============================================================================

// Example: Authentication middleware
// $api->addMiddleware('auth', function($request, $response, $next) {
//     $token = $request->header['authorization'] ?? null;
//     
//     if (!$token) {
//         return Response::json($response, ['error' => 'Unauthorized'], 401);
//     }
//     
//     return $next();
// });

EOT;

        file_put_contents($projectDir . '/app/middlewares.php', $middlewares);

        // Create app/routes.php
        $routes = <<<'EOT'
<?php

/**
 * Route definitions
 */

use PHAPI\HTTP\Response;

// ============================================================================
// PUBLIC ROUTES
// ============================================================================

// Root endpoint
$api->get('/', function($input, $request, $response, $api) {
    Response::json($response, [
        'message' => 'Welcome to PHAPI',
        'status' => 'running'
    ]);
});

// Health check endpoint
$api->get('/health', function($input, $request, $response, $api) {
    Response::json($response, [
        'ok' => true,
        'time' => date('c')
    ]);
});

// ============================================================================
// PROTECTED ROUTES (with middleware)
// ============================================================================

// Example: Protected route with auth middleware
// $api->middleware('auth')->get('/protected', function($input, $request, $response, $api) {
//     Response::json($response, ['message' => 'This is protected']);
// });

// ============================================================================
// ROUTE GROUPS
// ============================================================================

// Example: API v1 routes
// $api->group('/api/v1', function($api) {
//     $api->get('/users', function($input, $request, $response, $api) {
//         Response::json($response, ['users' => []]);
//     });
// });

EOT;

        file_put_contents($projectDir . '/app/routes.php', $routes);

        // Create app/tasks.php
        $tasks = <<<'EOT'
<?php

/**
 * Background task definitions
 */

// Example: Background task
// $api->task('processData', function($data, $logger) {
//     $logger->task()->info("Processing data", ['data' => $data]);
//     
//     // Your task logic here
//     // This runs asynchronously in background
// });

EOT;

        file_put_contents($projectDir . '/app/tasks.php', $tasks);
    }

    /**
     * Create .env.example
     *
     * @param string $projectDir Project directory
     */
    private function createEnvExample(string $projectDir): void
    {
        $envExample = <<<'EOT'
# PHAPI Configuration
HOST=0.0.0.0
PORT=9501
DEBUG=false

EOT;

        file_put_contents($projectDir . '/.env.example', $envExample);
    }

    /**
     * Show usage information
     */
    private function showUsage(): void
    {
        echo <<<'EOT'

PHAPI CLI - Project Scaffold Generator

Usage:
  php bin/phapi new <project-name>

Examples:
  php bin/phapi new my-api
  composer exec phapi new my-project

EOT;
    }
}

