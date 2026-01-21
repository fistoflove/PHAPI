<?php

/**
 * Tasks definition
 * All background tasks go here
 */

// ============================================================================
// BACKGROUND TASKS
// ============================================================================

// Process data task
$api->task('processData', function($data, $logger) {
    $logger->task()->info("Task started", ['data' => $data]);
    
    // Simulate heavy work
    sleep(5);
    
    $logger->task()->info("Task completed successfully", ['data' => $data]);
});

// Send email task
$api->task('sendEmail', function($data, $logger) {
    $logger->task()->info("Sending email", [
        'to' => $data['to'] ?? 'unknown',
        'subject' => $data['subject'] ?? 'No subject'
    ]);
    
    // Email sending logic here
    // In real app, send email using mailer library
});
