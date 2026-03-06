<?php

declare(strict_types=1);

namespace PHAPI\Tests\Integration\Supabase;

/**
 * Bootstraps the test schema on a real or local Supabase PostgreSQL instance.
 *
 * Uses direct PDO (pdo_pgsql) to run DDL since PostgREST is read/write only (no DDL).
 * All operations are idempotent (IF NOT EXISTS / IF EXISTS).
 */
final class SupabaseTestBootstrapper
{
    private \PDO $pdo;

    public function __construct(string $dsn)
    {
        // Parse postgresql:// URLs into PDO-compatible DSN
        if (str_starts_with($dsn, 'postgresql://') || str_starts_with($dsn, 'postgres://')) {
            $parts = parse_url($dsn);
            if ($parts === false || !isset($parts['host'])) {
                throw new \InvalidArgumentException('Invalid PostgreSQL URL: ' . $dsn);
            }
            $pdoDsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $parts['host'],
                $parts['port'] ?? 5432,
                ltrim($parts['path'] ?? '/postgres', '/'),
            );
            $user = isset($parts['user']) ? urldecode($parts['user']) : null;
            $pass = isset($parts['pass']) ? urldecode($parts['pass']) : null;
            $this->pdo = new \PDO($pdoDsn, $user, $pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 10,
            ]);
        } else {
            $this->pdo = new \PDO($dsn, options: [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 10,
            ]);
        }
    }

    /**
     * Create all test tables, functions, and grants.
     * Idempotent — safe to call multiple times.
     */
    public function setUp(): void
    {
        $this->pdo->exec($this->setupSql());
    }

    /**
     * Drop all test tables and functions.
     * Leaves Supabase system schemas (auth, storage) untouched.
     */
    public function tearDown(): void
    {
        $this->pdo->exec($this->teardownSql());
    }

    /**
     * Delete all data from test tables without dropping them.
     */
    public function truncate(): void
    {
        $this->pdo->exec('TRUNCATE posts, categories RESTART IDENTITY CASCADE;');
    }

    /**
     * Check if the test schema is already set up.
     */
    public function isReady(): bool
    {
        $stmt = $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'posts'"
        );
        return $stmt !== false && (int) $stmt->fetchColumn() > 0;
    }

    private function setupSql(): string
    {
        return <<<'SQL'
-- Extensions (may already exist on Supabase)
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- Ensure roles exist (no-op on Supabase cloud where they're pre-created)
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'anon') THEN
        CREATE ROLE anon NOLOGIN NOINHERIT;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'authenticated') THEN
        CREATE ROLE authenticated NOLOGIN NOINHERIT;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'service_role') THEN
        CREATE ROLE service_role NOLOGIN NOINHERIT BYPASSRLS;
    END IF;
END
$$;

-- Grants for PostgREST
GRANT USAGE ON SCHEMA public TO anon, authenticated, service_role;

-- Test tables
CREATE TABLE IF NOT EXISTS posts (
    id SERIAL PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    body TEXT DEFAULT '',
    published BOOLEAN DEFAULT false,
    views INT DEFAULT 0,
    tags TEXT[] DEFAULT '{}',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS categories (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT DEFAULT ''
);

-- Table grants
GRANT ALL ON posts TO anon, authenticated, service_role;
GRANT ALL ON categories TO anon, authenticated, service_role;
GRANT USAGE, SELECT ON SEQUENCE posts_id_seq TO anon, authenticated, service_role;
GRANT USAGE, SELECT ON SEQUENCE categories_id_seq TO anon, authenticated, service_role;

-- Default privileges for future tables
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO anon, authenticated, service_role;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO anon, authenticated, service_role;

-- RPC test function
CREATE OR REPLACE FUNCTION hello(name TEXT DEFAULT 'world')
RETURNS JSON AS $$
    SELECT json_build_object('message', 'Hello, ' || name || '!');
$$ LANGUAGE sql IMMUTABLE;

GRANT EXECUTE ON FUNCTION hello(TEXT) TO anon, authenticated, service_role;
SQL;
    }

    private function teardownSql(): string
    {
        return <<<'SQL'
DROP FUNCTION IF EXISTS hello(TEXT);
DROP TABLE IF EXISTS posts CASCADE;
DROP TABLE IF EXISTS categories CASCADE;
SQL;
    }
}
