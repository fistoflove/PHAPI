<?php

declare(strict_types=1);

namespace PHAPI\Database;


/**
 * Database Schema Management
 * Handles table creation and migrations
 */
class Schema
{
    /**
     * Initialize database schema (creates tables if they don't exist)
     *
     * @return void
     */
    public static function initialize(): void
    {
        if (!ConnectionManager::isConfigured()) {
            return;
        }

        $db = ConnectionManager::getConnection();

        // Create documents table (flexible JSON storage)
        $db->exec("
            CREATE TABLE IF NOT EXISTS documents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL DEFAULT 'document',
                key TEXT,
                data TEXT NOT NULL,
                meta TEXT,
                autoload INTEGER DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                UNIQUE(key, type)
            )
        ");

        // Create options table (optimized for key-value storage)
        $db->exec("
            CREATE TABLE IF NOT EXISTS options (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                key TEXT NOT NULL UNIQUE,
                value TEXT NOT NULL,
                autoload INTEGER DEFAULT 0,
                expires_at TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");

        // Create indexes
        $db->exec('CREATE INDEX IF NOT EXISTS idx_documents_type ON documents(type)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_documents_key ON documents(key)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_options_key ON options(key)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_options_autoload ON options(autoload)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_options_expires ON options(expires_at)');
    }

    /**
     * Check if schema is initialized
     *
     * @return bool
     */
    public static function isInitialized(): bool
    {
        if (!ConnectionManager::isConfigured()) {
            return false;
        }

        try {
            $db = ConnectionManager::getConnection();
            $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='options'");
            return $result->rowCount() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
