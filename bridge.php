<?php
require_once dirname(__DIR__) . "/vendor/autoload.php";
use Workerman\Worker;
use Channel\Client;

$bridgeListenHost = getenv("BRIDGE_LISTEN_HOST") ?: "0.0.0.0";
$bridgePort = (int) (getenv("BRIDGE_PORT") ?: 1234);
$channelHost = getenv("CHANNEL_HOST") ?: "127.0.0.1";
$channelPort = (int) (getenv("CHANNEL_PORT") ?: 2206);

function summarizeBridgeMessage(array $message): string
{
    $logMessage = $message;
    if (isset($logMessage["token"]) && is_string($logMessage["token"])) {
        $token = $logMessage["token"];
        $len = strlen($token);
        $logMessage["token"] =
            $len <= 12
                ? str_repeat("*", $len)
                : substr($token, 0, 6) . "…" . substr($token, -6);
    }
    if (array_key_exists("payload", $logMessage)) {
        $payload = $logMessage["payload"];
        $logMessage["payload"] = is_array($payload)
            ? "keys=" . implode(",", array_keys($payload))
            : gettype($payload);
    }
    return json_encode($logMessage, JSON_UNESCAPED_SLASHES);
}

echo "[Bridge] Listening on text://{$bridgeListenHost}:{$bridgePort}\n";
echo "[Bridge] Channel target {$channelHost}:{$channelPort}\n";

$inner_worker = new Worker("text://{$bridgeListenHost}:{$bridgePort}");
$inner_worker->onWorkerStart = function () use ($channelHost, $channelPort) {
    echo "[Bridge] Worker started, connecting to Channel Server...\n";
    Client::$onConnect = function () use ($channelHost, $channelPort) {
        echo "[Bridge] Channel connected to {$channelHost}:{$channelPort}\n";
    };
    Client::$onClose = function () use ($channelHost, $channelPort) {
        echo "[Bridge] Channel connection closed ({$channelHost}:{$channelPort}) - retrying\n";
    };
    Client::connect($channelHost, $channelPort);
};

$inner_worker->onConnect = function ($connection) {
    echo "[Bridge] New connection from {$connection->getRemoteIp()}:{$connection->getRemotePort()}\n";
};

$inner_worker->onMessage = function ($connection, $buffer) {
    echo "[Bridge] ← Raw message: {$buffer}\n";

    $data = json_decode($buffer, true);
    if (!is_array($data)) {
        echo "[Bridge] ⚠ Invalid JSON received, dropping\n";
        return;
    }
    echo "[Bridge] ← Parsed message: " . summarizeBridgeMessage($data) . "\n";

    // Push the message to the Channel Server
    echo "[Bridge] → Publishing send_notification: " .
        summarizeBridgeMessage($data) .
        "\n";
    Client::publish("send_notification", $data);
    echo "[Bridge] → Published send_notification\n";
};

Worker::runAll();
