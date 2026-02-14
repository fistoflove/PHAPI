<?php

$api->container()->bind('example.task', function () {
    return fn() => ['ok' => true];
});
