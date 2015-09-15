<?php
/**
 * run wrapper for our worker - see src/Worker.php for the logic
 */

require_once __DIR__.'/vendor/autoload.php';

use Graviton\Worker\Worker;

// example settings - you may want to configure
$configuration = [
    // rabbit mq host
    'host' => 'localhost',
    // rabbitmq port
    'port' => 5672,
    // rabbitmq username
    'user' => 'guest',
    // rabbitmq password
    'password' => 'guest',
    // rabitmq vhost
    'vhost' => '/',
    // exchange name of the graviton topic exchange
    'exchangeName' => 'graviton',
    // routing key used when binding to topic exchange
    'routingKey' => 'document.core.app.*',
    // subscriptions we will send to Graviton when registering our worker
    'subscriptions' => [
        'document.core.app.*'
    ],
    // the worker id we use when registering with Graviton
    'workerId' => 'example',
    // the URL we will PUT to when registering our worker with Graviton
    'registerUrl' => 'http://localhost:9000/event/worker/'
];

$worker = new Worker($configuration);
$worker->run();
