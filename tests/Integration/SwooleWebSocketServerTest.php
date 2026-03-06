<?php

declare(strict_types=1);

namespace PHAPI\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Boots a real Swoole WebSocket server and exercises the full server-side
 * WebSocket stack over the network: connect, send messages, subscribe to
 * channels, broadcast, and disconnect.
 *
 * @group integration
 */
final class SwooleWebSocketServerTest extends TestCase
{
    private static int $port = 9598;
    private static string $serverScript = '/tmp/phapi_ws_test_server.php';
    private static ?int $pid = null;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('Swoole extension required for WebSocket server tests.');
        }

        self::writeServerScript();
        self::startServer();
    }

    public static function tearDownAfterClass(): void
    {
        self::stopServer();
        @unlink(self::$serverScript);
    }

    // ── Connection ──────────────────────────────────────────

    public function testWebSocketConnectAndEcho(): void
    {
        $ws = $this->connectWs();
        $this->assertNotNull($ws, 'WebSocket connection failed');

        // Echo verifies full round-trip
        $this->wsSend($ws, json_encode([
            'action' => 'echo',
            'payload' => ['text' => 'hello'],
        ]));

        $msg = $this->wsRecv($ws, 3.0);
        $this->assertNotNull($msg, 'Should receive echo response');
        $data = json_decode($msg, true);
        $this->assertSame('echo', $data['event'] ?? null);
        $this->assertSame('hello', $data['payload']['text'] ?? null);

        $this->wsClose($ws);
    }

    // ── Subscribe + Broadcast ───────────────────────────────

    public function testSubscribeAndBroadcast(): void
    {
        $ws1 = $this->connectWs();
        $ws2 = $this->connectWs();
        $this->assertNotNull($ws1);
        $this->assertNotNull($ws2);

        // Subscribe both to "updates" channel
        $this->wsSend($ws1, json_encode(['action' => 'subscribe', 'channel' => 'updates']));
        $this->wsSend($ws2, json_encode(['action' => 'subscribe', 'channel' => 'updates']));
        usleep(200_000);

        // Trigger broadcast via HTTP endpoint
        $res = self::http('POST', '/broadcast', ['channel' => 'updates', 'message' => ['text' => 'hello all']]);
        $this->assertSame(200, $res['status']);

        // Both clients should receive the broadcast
        $msg1 = $this->wsRecv($ws1, 3.0);
        $msg2 = $this->wsRecv($ws2, 3.0);

        $this->assertNotNull($msg1, 'Client 1 should receive broadcast');
        $this->assertNotNull($msg2, 'Client 2 should receive broadcast');

        $data1 = json_decode($msg1, true);
        $data2 = json_decode($msg2, true);

        $this->assertSame('updates', $data1['channel'] ?? null);
        $this->assertSame('hello all', $data1['message']['text'] ?? null);
        $this->assertSame('updates', $data2['channel'] ?? null);

        $this->wsClose($ws1);
        $this->wsClose($ws2);
    }

    // ── Unsubscribe ─────────────────────────────────────────

    public function testUnsubscribeStopsBroadcast(): void
    {
        $ws1 = $this->connectWs();
        $ws2 = $this->connectWs();
        $this->assertNotNull($ws1);
        $this->assertNotNull($ws2);

        // Subscribe both to "alerts"
        $this->wsSend($ws1, json_encode(['action' => 'subscribe', 'channel' => 'alerts']));
        $this->wsSend($ws2, json_encode(['action' => 'subscribe', 'channel' => 'alerts']));
        usleep(200_000);

        // Unsubscribe ws1
        $this->wsSend($ws1, json_encode(['action' => 'unsubscribe', 'channel' => 'alerts']));
        usleep(200_000);

        // Broadcast to "alerts"
        self::http('POST', '/broadcast', ['channel' => 'alerts', 'message' => ['type' => 'alert']]);

        // ws2 should receive it
        $msg2 = $this->wsRecv($ws2, 3.0);
        $this->assertNotNull($msg2, 'Subscribed client should receive broadcast');

        // ws1 should NOT receive it (unsubscribed)
        $msg1 = $this->wsRecv($ws1, 1.0);
        $this->assertNull($msg1, 'Unsubscribed client should not receive broadcast');

        $this->wsClose($ws1);
        $this->wsClose($ws2);
    }

    // ── Channel isolation ───────────────────────────────────

    public function testBroadcastOnlyReachesSubscribedChannel(): void
    {
        $ws1 = $this->connectWs();
        $ws2 = $this->connectWs();
        $this->assertNotNull($ws1);
        $this->assertNotNull($ws2);

        // ws1 subscribes to "chan-a", ws2 subscribes to "chan-b"
        $this->wsSend($ws1, json_encode(['action' => 'subscribe', 'channel' => 'chan-a']));
        $this->wsSend($ws2, json_encode(['action' => 'subscribe', 'channel' => 'chan-b']));
        usleep(200_000);

        // Broadcast to "chan-a"
        self::http('POST', '/broadcast', ['channel' => 'chan-a', 'message' => ['for' => 'a']]);

        // ws1 should receive
        $msg1 = $this->wsRecv($ws1, 3.0);
        $this->assertNotNull($msg1, 'Client subscribed to chan-a should receive');

        // ws2 should NOT receive
        $msg2 = $this->wsRecv($ws2, 1.0);
        $this->assertNull($msg2, 'Client subscribed to chan-b should not receive chan-a broadcast');

        $this->wsClose($ws1);
        $this->wsClose($ws2);
    }

    // ── Global broadcast (empty channel) ────────────────────

    public function testGlobalBroadcastReachesAll(): void
    {
        $ws1 = $this->connectWs();
        $ws2 = $this->connectWs();
        $this->assertNotNull($ws1);
        $this->assertNotNull($ws2);

        // No subscription needed — global broadcast
        usleep(100_000);
        self::http('POST', '/broadcast', ['channel' => '', 'message' => ['type' => 'global']]);

        $msg1 = $this->wsRecv($ws1, 3.0);
        $msg2 = $this->wsRecv($ws2, 3.0);
        $this->assertNotNull($msg1, 'Client 1 should receive global broadcast');
        $this->assertNotNull($msg2, 'Client 2 should receive global broadcast');

        $this->wsClose($ws1);
        $this->wsClose($ws2);
    }

    // ── Disconnect cleanup ──────────────────────────────────

    public function testDisconnectedClientDoesNotReceiveBroadcast(): void
    {
        $ws1 = $this->connectWs();
        $ws2 = $this->connectWs();
        $this->assertNotNull($ws1);
        $this->assertNotNull($ws2);

        // Subscribe both to "cleanup-test"
        $this->wsSend($ws1, json_encode(['action' => 'subscribe', 'channel' => 'cleanup-test']));
        $this->wsSend($ws2, json_encode(['action' => 'subscribe', 'channel' => 'cleanup-test']));
        usleep(200_000);

        // Disconnect ws1
        $this->wsClose($ws1);
        usleep(500_000); // allow server to process close

        // Broadcast — only ws2 should receive
        self::http('POST', '/broadcast', ['channel' => 'cleanup-test', 'message' => ['after' => 'disconnect']]);

        $msg2 = $this->wsRecv($ws2, 3.0);
        $this->assertNotNull($msg2, 'Still-connected client should receive broadcast');

        $this->wsClose($ws2);
    }

    // ── HTTP routes still work alongside WebSocket ──────────

    public function testHttpRoutesWorkWithWebSocketEnabled(): void
    {
        $res = self::http('GET', '/health');
        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['body']['ok']);
    }

    // ── Helpers ──────────────────────────────────────────────

    /**
     * Connect a WebSocket client via raw TCP socket + HTTP upgrade.
     *
     * @return resource|null
     */
    private function connectWs()
    {
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 5);
        if ($socket === false) {
            return null;
        }

        $key = base64_encode(random_bytes(16));
        $request = "GET / HTTP/1.1\r\n"
            . "Host: 127.0.0.1:" . self::$port . "\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "\r\n";

        fwrite($socket, $request);
        stream_set_timeout($socket, 5);

        $response = '';
        while (($line = fgets($socket)) !== false) {
            $response .= $line;
            if (trim($line) === '') {
                break;
            }
        }

        if (strpos($response, '101') === false) {
            fclose($socket);
            return null;
        }

        stream_set_blocking($socket, false);
        return $socket;
    }

    /**
     * Send a masked WebSocket text frame.
     *
     * @param resource $socket
     */
    private function wsSend($socket, string $data): void
    {
        $len = strlen($data);
        $frame = chr(0x81); // FIN + text opcode

        if ($len <= 125) {
            $frame .= chr($len | 0x80);
        } elseif ($len <= 65535) {
            $frame .= chr(126 | 0x80) . pack('n', $len);
        } else {
            $frame .= chr(127 | 0x80) . pack('J', $len);
        }

        $mask = random_bytes(4);
        $frame .= $mask;

        for ($i = 0; $i < $len; $i++) {
            $frame .= chr(ord($data[$i]) ^ ord($mask[$i % 4]));
        }

        fwrite($socket, $frame);
    }

    /**
     * Receive a WebSocket text frame with timeout.
     *
     * @param resource $socket
     */
    private function wsRecv($socket, float $timeout): ?string
    {
        $start = microtime(true);
        $buffer = '';

        while (microtime(true) - $start < $timeout) {
            $read = [$socket];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 0, 100_000);

            if ($ready === false) {
                return null;
            }

            if ($ready > 0) {
                $chunk = fread($socket, 65536);
                if ($chunk === false || $chunk === '') {
                    return null;
                }
                $buffer .= $chunk;

                $decoded = $this->decodeWsFrame($buffer);
                if ($decoded !== null) {
                    return $decoded;
                }
            }
        }

        return null;
    }

    /**
     * Close a WebSocket connection with proper close frame.
     *
     * @param resource $socket
     */
    private function wsClose($socket): void
    {
        // Build masked close frame with status 1000
        $statusCode = pack('n', 1000);
        $payloadLen = 2;
        $frame = chr(0x88); // FIN + close opcode
        $frame .= chr($payloadLen | 0x80); // masked + length
        $mask = random_bytes(4);
        $frame .= $mask;
        $frame .= chr(ord($statusCode[0]) ^ ord($mask[0]));
        $frame .= chr(ord($statusCode[1]) ^ ord($mask[1]));
        @fwrite($socket, $frame);
        usleep(50_000);
        @fclose($socket);
    }

    /**
     * Decode a single WebSocket frame from buffer. Returns null if incomplete.
     */
    private function decodeWsFrame(string &$buffer): ?string
    {
        $len = strlen($buffer);
        if ($len < 2) {
            return null;
        }

        $byte1 = ord($buffer[0]);
        $byte2 = ord($buffer[1]);
        $opcode = $byte1 & 0x0F;
        $masked = ($byte2 & 0x80) !== 0;
        $payloadLen = $byte2 & 0x7F;
        $offset = 2;

        if ($payloadLen === 126) {
            if ($len < 4) {
                return null;
            }
            $payloadLen = unpack('n', substr($buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($payloadLen === 127) {
            if ($len < 10) {
                return null;
            }
            $payloadLen = unpack('J', substr($buffer, 2, 8))[1];
            $offset = 10;
        }

        if ($masked) {
            $offset += 4;
        }

        if ($len < $offset + $payloadLen) {
            return null;
        }

        $data = substr($buffer, $offset, $payloadLen);
        if ($masked) {
            $mask = substr($buffer, $offset - 4, 4);
            for ($i = 0; $i < $payloadLen; $i++) {
                $data[$i] = chr(ord($data[$i]) ^ ord($mask[$i % 4]));
            }
        }

        $buffer = substr($buffer, $offset + $payloadLen);

        // Close frame — return null
        if ($opcode === 0x8) {
            return null;
        }

        return $data;
    }

    /**
     * @return array{status: int, body: mixed, headers: array<string, string>}
     */
    private static function http(string $method, string $path, ?array $jsonBody = null): array
    {
        $ch = curl_init('http://127.0.0.1:' . self::$port . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        $headers = [];
        if ($jsonBody !== null) {
            $encoded = json_encode($jsonBody);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($encoded);
        }
        if ($headers !== []) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $responseHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $line) use (&$responseHeaders) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        });

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'status' => $status,
            'body' => json_decode((string) $body, true),
            'headers' => $responseHeaders,
        ];
    }

    private static function writeServerScript(): void
    {
        $autoloadPath = dirname(__DIR__, 2) . '/vendor/autoload.php';

        $script = <<<'PHP'
<?php
require '__AUTOLOAD_PATH__';

use PHAPI\HTTP\Request;
use PHAPI\HTTP\Response;
use PHAPI\PHAPI;

$api = new PHAPI([
    'host' => '127.0.0.1',
    'port' => 9598,
    'enable_websockets' => true,
    'debug' => true,
    'default_endpoints' => false,
    'swoole' => ['worker_num' => 1, 'log_level' => SWOOLE_LOG_ERROR],
]);

$api->setWebSocketHandler(function ($server, $frame, $driver): void {
    $payload = json_decode($frame->data ?? '', true);
    if (!is_array($payload)) {
        return;
    }

    $action = $payload['action'] ?? '';

    if ($action === 'subscribe') {
        $driver->subscribe($frame->fd, (string) ($payload['channel'] ?? ''));
        return;
    }

    if ($action === 'unsubscribe') {
        $driver->unsubscribe($frame->fd, (string) ($payload['channel'] ?? ''));
        return;
    }

    if ($action === 'echo') {
        $server->push($frame->fd, json_encode([
            'event' => 'echo',
            'payload' => $payload['payload'] ?? null,
        ]));
        return;
    }
});

$api->get('/health', fn() => Response::json(['ok' => true]));

$api->post('/broadcast', function (Request $request) use ($api): Response {
    $body = $request->body();
    $channel = (string) ($body['channel'] ?? '');
    $message = $body['message'] ?? [];
    $api->services()->realtime()->broadcast($channel, $message);
    return Response::json(['sent' => true]);
});

$api->run();
PHP;

        $script = str_replace('__AUTOLOAD_PATH__', $autoloadPath, $script);
        file_put_contents(self::$serverScript, $script);
    }

    private static function startServer(): void
    {
        $cmd = sprintf('nohup php %s > /tmp/phapi_ws_test.log 2>&1 & echo $!', self::$serverScript);
        $pid = (int) trim(shell_exec($cmd));
        self::$pid = $pid;

        $ready = false;
        for ($i = 0; $i < 50; $i++) {
            $ch = @curl_init('http://127.0.0.1:' . self::$port . '/health');
            if ($ch) {
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 1);
                curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($code === 200) {
                    $ready = true;
                    break;
                }
            }
            usleep(100_000);
        }

        if (!$ready) {
            self::stopServer();
            self::fail('Swoole WebSocket server did not start. Log: ' . @file_get_contents('/tmp/phapi_ws_test.log'));
        }
    }

    private static function stopServer(): void
    {
        if (self::$pid !== null) {
            posix_kill(self::$pid, SIGTERM);
            usleep(500_000);
            if (posix_kill(self::$pid, 0)) {
                posix_kill(self::$pid, SIGKILL);
                usleep(500_000);
            }
            self::$pid = null;
        }
    }
}
