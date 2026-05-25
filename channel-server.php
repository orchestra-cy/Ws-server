<?php
require_once dirname(__DIR__) . "/vendor/autoload.php";
use Workerman\Worker;
use Channel\Server;

$channelListenHost = getenv("CHANNEL_LISTEN_HOST") ?: "0.0.0.0";
$channelPort = (int) (getenv("CHANNEL_PORT") ?: 2206);

echo "[Channel Server] Listening on frame://{$channelListenHost}:{$channelPort}\n";

$channel_server = new Server($channelListenHost, $channelPort);
Worker::runAll();
