<?php

/**
 * Scheduled Jobs
 * Recurring tasks that run automatically at specified intervals
 * 
 * Jobs are time-triggered tasks that use Swoole timers.
 * The handler receives the logger instance.
 */

// ============================================================================
// SCHEDULED JOBS
// ============================================================================

// Cleanup job - runs every 60 seconds (1 minute)
$api->schedule('cleanup', 60, function($logger) {
    $logger->system()->info("Cleanup job running");
    
    // Perform cleanup tasks here
    // Example: Delete old temporary files, expire cache entries, etc.
});

// Reporting job - runs every 300 seconds (5 minutes)
$api->schedule('reporting', 300, function($logger) {
    $logger->system()->info("Reporting job running");
    
    // Generate reports, send summary emails, etc.
});

// Data sync job - runs every 3600 seconds (1 hour)
$api->schedule('dataSync', 3600, function($logger) {
    $logger->system()->info("Data sync job running");
    
    // Sync data with external services, backup databases, etc.
});

// Clean expired transients job - runs every 300 seconds (5 minutes)
// Only needed if using database with transients
if ($api->db()) {
    $api->schedule('cleanExpiredTransients', 300, function($logger) {
        $deleted = \PHAPI\Database\Options::clearExpiredTransients();
        if ($deleted > 0) {
            $logger->system()->info("Cleaned expired transients", ['count' => $deleted]);
        }
    });
}

