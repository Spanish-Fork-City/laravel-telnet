<?php

use SpanishForkCity\Telnet\TelnetClient;
use SpanishForkCity\Telnet\Tests\Fakes\TelnetServer;
use SpanishForkCity\Telnet\Exceptions\ConnectionException;
use SpanishForkCity\Telnet\Exceptions\NameResolutionException;

function findUnusedLocalPort()
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!is_resource($socket)) {
        throw new RuntimeException("Could not reserve local port: [{$errno}] {$errstr}");
    }

    $name = stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr(strrchr($name, ':'), 1);
}

it('can connect, login, and execute a command', function () {
    $server = new TelnetServer(0);
    $port = $server->getPort();

    $telnet = new TelnetClient('127.0.0.1', $port, 1.0, 1.0);
    $telnet->connect();
    $server->accept();
    $telnet->setDoGetRemainingData(false);

    // Queue prompts that login() waits for.
    $server->writeToClient("login:\r\n");
    $server->writeToClient("Password:\r\n");
    $server->writeToClient("$\r\n");

    $telnet->setPrompt('$');
    $telnet->login('user', 'password');
    $loginPayload = $server->readUntilContains(['user', 'password']);

    $server->writeToClient("file1\r\nfile2\r\n$");
    $result = $telnet->exec('ls');
    $commandPayload = $server->readUntilContains(['ls']);

    $telnet->disconnect();

    expect($loginPayload)->toContain('user', 'password');
    expect($commandPayload)->toContain('ls');
    expect($result)->toBeArray()->toContain('file1', 'file2');
});

it('throws an exception when it cannot connect to a server', function () {
    $telnet = new TelnetClient('127.0.0.1', findUnusedLocalPort());

    set_error_handler(static function () {
        return true;
    }, E_WARNING);

    try {
        $telnet->connect();
    } finally {
        restore_error_handler();
    }
})->throws(ConnectionException::class);

it('does not match prompt when # appears in data (e.g., circuit description)', function () {
    $server = new TelnetServer(0);
    $port = $server->getPort();

    $telnet = new TelnetClient('127.0.0.1', $port, 1.0, 1.0);
    $telnet->connect();
    $server->accept();
    $telnet->setDoGetRemainingData(false);

    // Queue login prompts
    $server->writeToClient("login:\r\n");
    $server->writeToClient("Password:\r\n");
    $server->writeToClient("#\r\n");

    $telnet->setPrompt('#');
    $telnet->login('user', 'password');

    // Send configuration output that contains # in the middle of lines
    // This simulates the circuit description case: "Account 5231030 Circuit 11.4 275 N 100 W #4"
    $server->writeToClient("!\r\n");
    $server->writeToClient("storm-control broadcast  2000\r\n");
    $server->writeToClient("storm-control multicast  2000\r\n");
    $server->writeToClient("storm-control unicast  20000\r\n");
    $server->writeToClient("description Account 5231030 Circuit 11.4 275 N 100 W #4\r\n");
    $server->writeToClient("mtu 9216\r\n");
    $server->writeToClient("switchport mode hybrid\r\n");
    $server->writeToClient("switchport hybrid native vlan 10\r\n");
    $server->writeToClient("switchport hybrid allowed vlan remove 1-9,11-4094\r\n");
    $server->writeToClient("switchport hybrid allowed vlan add untagged 10\r\n");
    $server->writeToClient("rate-limit output 416000 1024\r\n");
    $server->writeToClient("switchport port-security maximum 3\r\n");
    $server->writeToClient("switchport port-security\r\n");
    $server->writeToClient("ip verify source\r\n");
    $server->writeToClient("ipv6 verify source\r\n");
    $server->writeToClient("!\r\n");
    $server->writeToClient("end\r\n");
    $server->writeToClient("#");

    $result = $telnet->exec('show config');

    $telnet->disconnect();

    // Verify all lines are returned, including the circuit description line with # in the middle
    expect($result)->toBeArray();
    expect($result)->toContain('description Account 5231030 Circuit 11.4 275 N 100 W #4');
    expect($result)->toContain('mtu 9216');
    expect($result)->toContain('switchport mode hybrid');
    expect($result)->toContain('end');
});

it('throws a name resolution exception when hostname cannot be resolved', function () {
    $telnet = new TelnetClient('definitely.invalid.test.host');
    $telnet->connect();
})->throws(NameResolutionException::class);

it('can set and get socket timeout', function () {
    $telnet = new TelnetClient();
    $telnet->setSocketTimeout(5.0);
    expect($telnet->getSocketTimeout())->toBe(5.0);
});

it('can set and get stream timeout', function () {
    $telnet = new TelnetClient();
    $telnet->setStreamTimeout(5.0);
    expect($telnet->getSocketTimeout())->toBe(5.0);
});

it('can set and get prompt', function () {
    $telnet = new TelnetClient();
    $telnet->setPrompt('>');
    expect($telnet->getRegexPrompt())->toBe(preg_quote('>', '/'));
});

it('can set and get regex prompt', function () {
    $telnet = new TelnetClient();
    $telnet->setRegexPrompt('cisco\\s*#');
    expect($telnet->getRegexPrompt())->toBe('cisco\\s*#');
});

it('can get hostname and ip address', function () {
    $server = new TelnetServer(0);
    $port = $server->getPort();

    $telnet = new TelnetClient('localhost', $port);
    $telnet->connect();
    $server->accept();

    expect($telnet->getHostname())->toBe('localhost');
    expect($telnet->getIpAddress())->toBe('127.0.0.1');

    $telnet->disconnect();
});

it('can connect with a specific timeout', function () {
    $telnet = new TelnetClient('127.0.0.1', findUnusedLocalPort(), 0.1);
    expect($telnet->getConnectTimeout())->toBe(0.1);

    set_error_handler(static function () {
        return true;
    }, E_WARNING);

    try {
        $telnet->connect();
    } finally {
        restore_error_handler();
    }
})->throws(ConnectionException::class);
