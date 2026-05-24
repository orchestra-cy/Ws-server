<?php
require_once dirname(__DIR__) . "/vendor/autoload.php";

use Workerman\Worker;
use Channel\Client;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;

$ws_worker = new Worker("websocket://0.0.0.0:8086");
$ws_worker->userConnections = [];
$ws_worker->connectionCount = 0;

// JWT Configuration
$publicKeyPath = dirname(__DIR__) . "/config/jwt/public.pem";
$algorithm = "RS256";

// Validate public key exists
if (!file_exists($publicKeyPath)) {
    die("CRITICAL ERROR: JWT public key not found at: {$publicKeyPath}\n");
}

$publicKey = file_get_contents($publicKeyPath);
if (!$publicKey) {
    die("CRITICAL ERROR: Could not read JWT public key\n");
}

$channelHost = getenv("CHANNEL_HOST") ?: "127.0.0.1";
$channelPort = (int) (getenv("CHANNEL_PORT") ?: 2206);

echo "[WebSocket Server] Starting on ws://0.0.0.0:8086\n";
echo "[WebSocket Server] JWT validation enabled with RS256\n";

$ws_worker->onWorkerStart = function () use (
    &$ws_worker,
    $channelHost,
    $channelPort,
) {
    echo "[WebSocket Server] Worker started, connecting to Channel Server...\n";

    Client::connect($channelHost, $channelPort);

    // Listen for notifications coming from Symfony via the Bridge
    Client::on("send_notification", function ($data) use (&$ws_worker) {
        $userId = $data["userId"] ?? null;

        if (!$userId) {
            echo "[WebSocket Server] ⚠ Notification received with no userId\n";
            return;
        }

        if (isset($ws_worker->userConnections[$userId])) {
            $connections = $ws_worker->userConnections[$userId];

            // Handle both array of connections and single connection for backward compatibility
            if (!is_array($connections)) {
                $connections = [$connections];
            }

            $sentCount = 0;
            foreach ($connections as $connection) {
                // Verify connection is still established before sending
                if (
                    $connection &&
                    method_exists($connection, "getStatus") &&
                    $connection->getStatus() ===
                        \Workerman\Connection\TcpConnection::STATUS_ESTABLISHED
                ) {
                    $connection->send(
                        json_encode([
                            "type" => "notification",
                            "payload" => $data["payload"],
                        ]),
                    );
                    $sentCount++;
                }
            }

            if ($sentCount > 0) {
                echo "[WebSocket Server] ✓ Notification sent to user {$userId} ({$sentCount} connection(s))\n";
            } else {
                echo "[WebSocket Server] ⚠ User {$userId} has {$sentCount} active connection(s)\n";
            }
        } else {
            echo "[WebSocket Server] ⚠ User {$userId} not connected\n";
        }
    });
};

$ws_worker->onConnect = function ($connection) use (&$ws_worker) {
    $ws_worker->connectionCount++;
    $connection->connectionId = uniqid();
    echo "[WebSocket Server] New connection from {$connection->getRemoteIp()}:{$connection->getRemotePort()} (ID: {$connection->connectionId})\n";
};

$ws_worker->onMessage = function ($connection, $data) use (
    &$ws_worker,
    $publicKey,
    $algorithm,
) {
    try {
        $payload = json_decode($data, true);

        if (!is_array($payload)) {
            echo "[WebSocket Server] ⚠ Invalid JSON received from {$connection->connectionId}\n";
            return;
        }

        $messageType = $payload["type"] ?? null;

        // ====== AUTH MESSAGE ======
        if ($messageType === "auth") {
            $token = $payload["token"] ?? null;

            if (!$token) {
                echo "[WebSocket Server] ⚠ Auth attempt without token from {$connection->connectionId}\n";
                $connection->send(
                    json_encode([
                        "type" => "auth_error",
                        "message" => "Token required",
                    ]),
                );
                return;
            }

            try {
                // Decode and validate JWT token
                $decoded = JWT::decode($token, new Key($publicKey, $algorithm));

                // Extract user ID from token claims
                // Lexik JWT stores user identifier in 'username' or custom 'id' claim
                // We need the numeric ID, not the username string
                $userId =
                    isset($decoded->id) && is_numeric($decoded->id)
                        ? (int) $decoded->id
                        : null;

                if (!$userId) {
                    echo "[WebSocket Server] ⚠ Token missing numeric user ID from {$connection->connectionId}\n";
                    $connection->send(
                        json_encode([
                            "type" => "auth_error",
                            "message" => "Invalid token claims",
                        ]),
                    );
                    return;
                }

                // Store connection by user ID (support multiple connections per user)
                $connection->userId = $userId;

                if (!isset($ws_worker->userConnections[$userId])) {
                    $ws_worker->userConnections[$userId] = [];
                }

                // If it's not already an array, convert it
                if (!is_array($ws_worker->userConnections[$userId])) {
                    $ws_worker->userConnections[$userId] = [
                        $ws_worker->userConnections[$userId],
                    ];
                }

                $ws_worker->userConnections[$userId][] = $connection;

                $connCount = is_array($ws_worker->userConnections[$userId])
                    ? count($ws_worker->userConnections[$userId])
                    : 1;
                echo "[WebSocket Server] ✓ AUTH SUCCESS: User {$userId} (connection: {$connection->connectionId}) - Total connections: {$connCount}\n";

                $connection->send(
                    json_encode([
                        "type" => "auth_success",
                        "userId" => $userId,
                        "message" => "Authenticated",
                    ]),
                );
            } catch (ExpiredException $e) {
                echo "[WebSocket Server] 🔴 SECURITY: Expired token from {$connection->connectionId}\n";
                $connection->send(
                    json_encode([
                        "type" => "auth_error",
                        "message" => "Token expired",
                    ]),
                );
                $connection->close();
            } catch (SignatureInvalidException $e) {
                echo "[WebSocket Server] 🔴 SECURITY: Invalid signature from {$connection->connectionId}\n";
                $connection->send(
                    json_encode([
                        "type" => "auth_error",
                        "message" => "Invalid token signature",
                    ]),
                );
                $connection->close();
            } catch (\Exception $e) {
                echo "[WebSocket Server] 🔴 SECURITY: JWT validation failed from {$connection->connectionId}: " .
                    $e->getMessage() .
                    "\n";
                $connection->send(
                    json_encode([
                        "type" => "auth_error",
                        "message" => "Authentication failed",
                    ]),
                );
                $connection->close();
            }
        }

        // ====== PING/HEARTBEAT ======
        elseif ($messageType === "ping") {
            if (!isset($connection->userId)) {
                echo "[WebSocket Server] ⚠ Ping from unauthenticated connection {$connection->connectionId}\n";
                return;
            }

            $connection->send(
                json_encode([
                    "type" => "pong",
                    "timestamp" => time(),
                ]),
            );
        }

        // ====== UNKNOWN MESSAGE TYPE ======
        else {
            if (isset($connection->userId)) {
                echo "[WebSocket Server] ⚠ Unknown message type '{$messageType}' from user {$connection->userId}\n";
            } else {
                echo "[WebSocket Server] ⚠ Unknown message type '{$messageType}' from unauthenticated connection {$connection->connectionId}\n";
            }
        }
    } catch (\Exception $e) {
        echo "[WebSocket Server] ERROR processing message: " .
            $e->getMessage() .
            "\n";
    }
};

$ws_worker->onClose = function ($connection) use (&$ws_worker) {
    $ws_worker->connectionCount--;

    if (isset($connection->userId)) {
        $userId = $connection->userId;

        if (isset($ws_worker->userConnections[$userId])) {
            $connections = &$ws_worker->userConnections[$userId];

            // Remove this specific connection from the array
            if (is_array($connections)) {
                $connections = array_filter(
                    $connections,
                    fn($conn) => $conn !== $connection,
                );

                if (empty($connections)) {
                    unset($ws_worker->userConnections[$userId]);
                    echo "[WebSocket Server] ✓ User {$userId} disconnected (ID: {$connection->connectionId}) - No active connections remain\n";
                } else {
                    $ws_worker->userConnections[$userId] = array_values(
                        $connections,
                    ); // Re-index array
                    echo "[WebSocket Server] ✓ User {$userId} disconnected (ID: {$connection->connectionId}) - " .
                        count($ws_worker->userConnections[$userId]) .
                        " connection(s) remain\n";
                }
            } else {
                // Fallback for single connection scenario
                unset($ws_worker->userConnections[$userId]);
                echo "[WebSocket Server] ✓ User {$userId} disconnected (ID: {$connection->connectionId})\n";
            }
        }
    } else {
        echo "[WebSocket Server] ✓ Unauthenticated connection closed (ID: {$connection->connectionId})\n";
    }
};

$ws_worker->onWorkerStop = function () use (&$ws_worker) {
    echo "[WebSocket Server] Worker stopping. Active connections: {$ws_worker->connectionCount}\n";
};

Worker::runAll();
