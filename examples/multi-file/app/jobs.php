<?php

$api->schedule('cleanup', 300, function () {
    echo "cleanup executed";
}, [
    'log_file' => 'cleanup-job.log',
    'log_enabled' => true,
    'lock_mode' => 'skip',
]);

$api->schedule('silent', 120, function () {
    // No logging for this job.
}, [
    'log_enabled' => false,
    'lock_mode' => 'block',
]);
