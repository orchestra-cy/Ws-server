<?php
require_once dirname(__DIR__) . "/vendor/autoload.php";
use Workerman\Worker;
use Channel\Client;

$bridgeListenHost = getenv("BRIDGE_LISTEN_HOST") ?: "0.0.0.0";
$bridgePort = (int) (getenv("BRIDGE_PORT") ?: 1234);
$channelHost = getenv("CHANNEL_HOST") ?: "127.0.0.1";
$channelPort = (int) (getenv("CHANNEL_PORT") ?: 2206);

echo "[Bridge] Listening on text://{$bridgeListenHost}:{$bridgePort}\n";
echo "[Bridge] Channel target {$channelHost}:{$channelPort}\n";

$inner_worker = new Worker("text://{$bridgeListenHost}:{$bridgePort}");
$inner_worker->onWorkerStart = function () use ($channelHost, $channelPort) {
    echo "[Bridge] Worker started, connecting to Channel Server...\n";
    Client::connect($channelHost, $channelPort);
};

$inner_worker->onMessage = function ($connection, $buffer) {
    echo "[Bridge] ← Raw message: {$buffer}\n";

    $data = json_decode($buffer, true);
    if (!is_array($data)) {
        echo "[Bridge] ⚠ Invalid JSON received, dropping\n";
        return;
    }

    // Push the message to the Channel Server
    $result = Client::publish("send_notification", $data);
    echo "[Bridge] → Published send_notification (result: " .
        var_export($result, true) .
        ")\n";
};

Worker::runAll();
