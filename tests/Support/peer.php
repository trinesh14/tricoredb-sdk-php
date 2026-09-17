<?php

declare(strict_types=1);

// A scripted TriCoreDB peer, run as its own process because PHP has no threads.
//
// Usage: php peer.php <base64 JSON script>
//
// It prints the port it listens on, accepts one connection, and plays one step
// per inbound frame. A step is one of:
//   {"reply": [tag, json]}   a frame carrying this JSON
//   {"raw": "<base64>"}      these exact bytes
//   {"hangup": true}         close the socket; plays with the step before it
//   {"silence": true}        answer nothing and keep the socket open

$steps = json_decode(base64_decode($argv[1] ?? '', true) ?: '[]', true, 512, JSON_THROW_ON_ERROR);

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "bind failed: $errstr\n");
    exit(1);
}
$name = stream_socket_get_name($server, false);
fwrite(STDOUT, substr($name, strrpos($name, ':') + 1) . "\n");
fflush(STDOUT);

$socket = @stream_socket_accept($server, 30);
if ($socket === false) {
    exit(0);
}
stream_set_timeout($socket, 30);

$readExactly = static function ($socket, int $length): ?string {
    $buffer = '';
    while (strlen($buffer) < $length) {
        $chunk = fread($socket, $length - strlen($buffer));
        if ($chunk === false || $chunk === '') {
            if (feof($socket) || stream_get_meta_data($socket)['timed_out']) {
                return null;
            }
            continue;
        }
        $buffer .= $chunk;
    }

    return $buffer;
};

$play = static function ($socket, array $step): bool {
    if (isset($step['reply'])) {
        [$tag, $json] = $step['reply'];
        $body = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        fwrite($socket, pack('CCN', 1, $tag, strlen($body)) . $body);

        return true;
    }
    if (isset($step['raw'])) {
        fwrite($socket, base64_decode($step['raw']));

        return true;
    }
    if (isset($step['hangup'])) {
        fclose($socket);

        return false;
    }

    return true;
};

$index = 0;
while (true) {
    $header = $readExactly($socket, 6);
    if ($header === null) {
        break;
    }
    ['tag' => $tag, 'length' => $length] = unpack('Ctag/Nlength', substr($header, 1));
    if ($length > 0 && $readExactly($socket, $length) === null) {
        break;
    }
    if ($tag === 7) {
        // CLOSE is answered with BYE, as a real server does.
        fwrite($socket, pack('CCN', 1, 10, 0));
        break;
    }
    if ($index >= count($steps)) {
        continue;
    }
    if (!$play($socket, $steps[$index++])) {
        exit(0);
    }
    while ($index < count($steps) && isset($steps[$index]['hangup'])) {
        $play($socket, $steps[$index++]);
        exit(0);
    }
}
