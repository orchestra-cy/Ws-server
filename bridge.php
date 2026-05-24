<?php
require_once dirname(__DIR__) . "/vendor/autoload.php";
use Workerman\Worker;
use Channel\Client;

$bridgeHost = getenv("BRIDGE_HOST") ?: "127.0.0.1";
$bridgePort = (int) (getenv("BRIDGE_PORT") ?: 1234);
$channelHost = getenv("CHANNEL_HOST") ?: "127.0.0.1";
$channelPort = (int) (getenv("CHANNEL_PORT") ?: 2206);

$inner_worker = new Worker("text://{$bridgeHost}:{$bridgePort}");
$inner_worker->onWorkerStart = function () use ($channelHost, $channelPort) {
    Client::connect($channelHost, $channelPort);
};

$inner_worker->onMessage = function ($connection, $buffer) {
    $data = json_decode($buffer, true);
    // Push the message to the Channel Server
    Client::publish("send_notification", $data);
};

Worker::runAll();
