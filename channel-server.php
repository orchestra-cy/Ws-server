<?php
require_once dirname(__DIR__) . "/vendor/autoload.php";
use Workerman\Worker;
use Channel\Server;

$channelHost = getenv("CHANNEL_HOST") ?: "127.0.0.1";
$channelPort = (int) (getenv("CHANNEL_PORT") ?: 2206);

$channel_server = new Server($channelHost, $channelPort);
Worker::runAll();
