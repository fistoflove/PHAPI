<?php

$api->schedule('heartbeat', 60, function () {
    return 'ok';
}, [
    'log_enabled' => true,
    'log_file' => 'heartbeat.log',
    'lock_mode' => 'skip',
]);
