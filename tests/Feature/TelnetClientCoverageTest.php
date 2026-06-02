<?php

use SpanishForkCity\Telnet\TelnetClient;
use SpanishForkCity\Telnet\Tests\Fakes\TelnetServer;
use SpanishForkCity\Telnet\Exceptions\LoginException;
use SpanishForkCity\Telnet\Exceptions\ConnectionException;
use SpanishForkCity\Telnet\Exceptions\UnimplementedException;

// Coverage note:
// - TelnetClient line 307 (fclose() === false) is a defensive branch that is not
//   reliably triggerable with native PHP stream resources in a deterministic test.
// - TelnetClient lines 954..955 are in a waitPrompt() false-line branch that appears
//   unreachable with the current getMoreData()/getNextLine() control flow.

class TestableTelnetClient extends TelnetClient
{
    public function writePublic($buffer, $addNewLine = true)
    {
        return $this->write($buffer, $addNewLine);
    }

    public function getcPublic()
    {
        return $this->getc();
    }

    public function waitForNbDataPublic($length = null)
    {
        $call = \Closure::bind(fn ($length) => $this->waitForNbData($length), $this, TelnetClient::class);
        return $call($length);
    }

    public function processStateMachinePublic(array &$chars)
    {
        $call = \Closure::bind(fn (array &$chars) => $this->processStateMachine($chars), $this, TelnetClient::class);
        return $call($chars);
    }

    public function setStatePublic($state)
    {
        $set = \Closure::bind(fn ($state) => $this->state = $state, $this, TelnetClient::class);
        $set($state);
    }

    public function setSocketResourcePublic($socket)
    {
        $set = \Closure::bind(fn ($socket) => $this->socket = $socket, $this, TelnetClient::class);
        $set($socket);
    }
}

it('validates timeout and constructor arguments', function () {
    expect(fn () => new TelnetClient('127.0.0.1', '23'))
        ->toThrow(InvalidArgumentException::class, 'port must be int');

    $telnet = new TelnetClient();

    expect(fn () => $telnet->setSocketTimeout(5))
        ->toThrow(InvalidArgumentException::class, 'socket_timeout must be non-negative float or null');

    expect(fn () => $telnet->setFullLineTimeout(-1.0))
        ->toThrow(InvalidArgumentException::class, 'full_line_timeout must be null or non-negative float');

    expect(fn () => $telnet->setConnectTimeout(1))
        ->toThrow(InvalidArgumentException::class, 'connect_timeout must be float');

    expect($telnet->getFullLineTimeout())->toBe(0.10);
});

it('exposes helper code translation methods', function () {
    TelnetClient::setDebug(false);

    expect(TelnetClient::getNvtPrintSpecialStr(TelnetClient::NVT_CR))->toBe('CR');
    expect(TelnetClient::getCmdStr(TelnetClient::CMD_IAC))->toBe('IAC');
    expect(TelnetClient::getOptStr(TelnetClient::OPT_ECHO))->toBe('Echo');

    expect(TelnetClient::getCmdStr("\xAA"))->toBe('0xaa');
    expect(TelnetClient::getOptStr("\xAA"))->toBe('0xaa');
});

it('validates regex prompt and prompt matching', function () {
    $telnet = new TelnetClient();

    set_error_handler(static function () {
        return true;
    }, E_WARNING);

    try {
        expect(fn () => $telnet->setRegexPrompt('['))
            ->toThrow(InvalidArgumentException::class, 'Malformed PCRE error');
    } finally {
        restore_error_handler();
    }

    $telnet->setRegexPrompt('cisco\\s*#');

    expect($telnet->matchesPrompt('cisco   #'))->toBeTrue();
    expect($telnet->matchesPrompt('cisco >'))->toBeFalse();
});

it('can read line by line and detect prompt', function () {
    $server = new TelnetServer(0);
    $telnet = new TelnetClient('127.0.0.1', $server->getPort(), 1.0, 0.5, '$', 0.05);

    $telnet->connect();
    $server->accept();

    $telnet->setPrompt('$');
    $server->writeToClient("hello\r\n$\r\n");

    $matchesPrompt = false;
    $line = $telnet->getLine($matchesPrompt, true);
    expect($line)->toBe("hello\n");
    expect($matchesPrompt)->toBeFalse();

    $line = $telnet->getLine($matchesPrompt, true);
    expect(trim($line))->toBe('$');
    expect($matchesPrompt)->toBeTrue();

    $telnet->disconnect();
});

it('wraps login timeout failures in a LoginException', function () {
    $server = new TelnetServer(0);
    $telnet = new TelnetClient('127.0.0.1', $server->getPort(), 1.0, 0.05, '$', 0.01);

    $telnet->connect();
    $server->accept();

    $server->writeToClient("login:\r\n");

    expect(fn () => $telnet->login('user', 'password'))
        ->toThrow(LoginException::class, 'Login failed');

    $telnet->disconnect();
});

it('can prune control sequences and keep remaining data after prompt', function () {
    $server = new TelnetServer(0);
    $telnet = new TelnetClient('127.0.0.1', $server->getPort(), 1.0, 0.5, '$', 0.05);

    $telnet->connect();
    $server->accept();

    $telnet->setPruneCtrlSeq(true);
    $telnet->setDoGetRemainingData(true);

    $server->writeToClient("val\x1B[31mue\x1B[0m\r\n$\r\ntail");

    $result = $telnet->exec('ls');
    $payload = $server->readUntilContains(['ls']);

    expect($telnet->getPruneCtrlSeq())->toBeTrue();
    expect($telnet->getDoGetRemainingData())->toBeTrue();
    expect($result)->toBeArray()->toContain('value', '$', 'tail');
    expect($payload)->toContain('ls');

    $telnet->discardRemainingData();
    $telnet->disconnect();
});

it('answers telnet option negotiations from the server', function () {
    $server = new TelnetServer(0);
    $telnet = new TelnetClient('127.0.0.1', $server->getPort(), 1.0, 0.5, '$', 0.05);

    $telnet->connect();
    $server->accept();

    $server->writeToClient(TelnetClient::CMD_IAC . TelnetClient::CMD_DO . TelnetClient::OPT_ECHO . "ready\r\n");

    $matchesPrompt = false;
    $line = $telnet->getLine($matchesPrompt, true);
    $replyDo = $server->readUntilContains([TelnetClient::CMD_IAC . TelnetClient::CMD_WONT . TelnetClient::OPT_ECHO]);

    expect($line)->toBe("ready\n");
    expect(strpos($replyDo, TelnetClient::CMD_IAC . TelnetClient::CMD_WONT . TelnetClient::OPT_ECHO))->not->toBeFalse();

    $server->writeToClient(TelnetClient::CMD_IAC . TelnetClient::CMD_WILL . TelnetClient::OPT_ECHO . "again\r\n");

    $line = $telnet->getLine($matchesPrompt, true);
    $replyWill = $server->readUntilContains([TelnetClient::CMD_IAC . TelnetClient::CMD_DO . TelnetClient::OPT_ECHO]);

    expect($line)->toBe("again\n");
    expect(strpos($replyWill, TelnetClient::CMD_IAC . TelnetClient::CMD_DO . TelnetClient::OPT_ECHO))->not->toBeFalse();

    $telnet->disconnect();
});

it('throws when writing without a connection', function () {
    $telnet = new TestableTelnetClient();

    expect(fn () => $telnet->writePublic('noop'))
        ->toThrow(ConnectionException::class, 'Telnet connection closed');
});

it('covers deprecated getc and waitForNbData validations', function () {
    $server = new TelnetServer(0);
    $telnet = new TestableTelnetClient('127.0.0.1', $server->getPort(), 1.0, 0.5);

    $telnet->connect();
    $server->accept();
    $server->writeToClient('Z');

    expect($telnet->getcPublic())->toBe('Z');

    $telnet->setSocketTimeout(null);
    expect(fn () => $telnet->waitForNbDataPublic(null))
        ->toThrow(InvalidArgumentException::class, 'Would wait infinitely');

    expect(fn () => $telnet->waitForNbDataPublic(0))
        ->toThrow(InvalidArgumentException::class, '$length must be a positive int');

    $telnet->disconnect();
});

it('handles escaped iac plus mixed telnet command branches', function () {
    TelnetClient::setDebug(true);
    ob_start();

    $server = new TelnetServer(0);
    $telnet = new TelnetClient('127.0.0.1', $server->getPort(), 1.0, 0.5, '$', 0.05);

    $telnet->connect();
    $server->accept();

    $payload = '';
    $payload .= TelnetClient::CMD_IAC . TelnetClient::CMD_IAC; // Escaped IAC as data.
    $payload .= TelnetClient::CMD_IAC . TelnetClient::CMD_DO . TelnetClient::OPT_ECHO;
    $payload .= TelnetClient::CMD_IAC . TelnetClient::CMD_WILL . TelnetClient::OPT_ECHO;
    $payload .= TelnetClient::CMD_IAC . TelnetClient::CMD_WONT . TelnetClient::OPT_ECHO;
    $payload .= TelnetClient::CMD_IAC . TelnetClient::CMD_SB . TelnetClient::OPT_ECHO . 'abc' . TelnetClient::CMD_SE;
    $payload .= TelnetClient::CMD_IAC . "\xAA" . TelnetClient::OPT_ECHO;
    $payload .= "done\r\n";

    $server->writeToClient($payload);

    $matchesPrompt = false;
    $line = $telnet->getLine($matchesPrompt, true);
    $reply = $server->readUntilContains([
        TelnetClient::CMD_IAC . TelnetClient::CMD_WONT . TelnetClient::OPT_ECHO,
        TelnetClient::CMD_IAC . TelnetClient::CMD_DO . TelnetClient::OPT_ECHO,
    ]);

    expect(str_starts_with($line, TelnetClient::CMD_IAC))->toBeTrue();
    expect($line)->toContain('done');
    expect(strpos($reply, TelnetClient::CMD_IAC . TelnetClient::CMD_WONT . TelnetClient::OPT_ECHO))->not->toBeFalse();
    expect(strpos($reply, TelnetClient::CMD_IAC . TelnetClient::CMD_DO . TelnetClient::OPT_ECHO))->not->toBeFalse();

    $telnet->disconnect();
    TelnetClient::setDebug(false);
    ob_end_clean();
});

it('throws for unknown internal state in state machine', function () {
    $telnet = new TestableTelnetClient();
    $telnet->setStatePublic(999);
    $chars = ['x'];

    expect(fn () => $telnet->processStateMachinePublic($chars))
        ->toThrow(UnimplementedException::class, 'Unimplement state 999');
});

it('throws a connection exception when socket is not writable', function () {
    $telnet = new TestableTelnetClient();
    $readOnlySocket = fopen('php://memory', 'r');
    $telnet->setSocketResourcePublic($readOnlySocket);

    expect(fn () => $telnet->writePublic('ls'))
        ->toThrow(ConnectionException::class, 'Error writing to socket');

    $telnet->disconnect();
});

it('emits wait prompt debug output when debug is enabled', function () {
    TelnetClient::setDebug(true);
    $server = new TelnetServer(0);
    $telnet = new TelnetClient('127.0.0.1', $server->getPort(), 1.0, 0.5, '$', 0.05);

    $telnet->connect();
    $server->accept();

    $server->writeToClient("ok\r\n$\r\n");

    ob_start();
    $result = $telnet->exec('ls');
    $debugOutput = ob_get_clean();

    expect($result)->toBeArray()->toContain('ok', '$');
    expect($debugOutput)->toContain('Waiting for prompt');

    $telnet->disconnect();
    TelnetClient::setDebug(false);
});

it('covers command state short buffer and reply write failure', function () {
    $telnet = new TestableTelnetClient();

    $chars = [TelnetClient::CMD_IAC, TelnetClient::CMD_DO];
    $telnet->setStatePublic(TelnetClient::STATE_CMD);
    expect($telnet->processStateMachinePublic($chars))->toBeTrue();

    $telnet->setStatePublic(TelnetClient::STATE_CMD);
    $readOnlySocket = fopen('php://memory', 'r');
    $telnet->setSocketResourcePublic($readOnlySocket);
    $chars = [TelnetClient::CMD_IAC, TelnetClient::CMD_DO, TelnetClient::OPT_ECHO];

    expect(fn () => $telnet->processStateMachinePublic($chars))
        ->toThrow(ConnectionException::class, 'Error writing to socket');
});

it('does not send duplicate reply for repeated will on an enabled option', function () {
    $server = new TelnetServer(0);
    $telnet = new TelnetClient('127.0.0.1', $server->getPort(), 1.0, 0.5, '$', 0.05);

    $telnet->connect();
    $server->accept();

    $server->writeToClient(TelnetClient::CMD_IAC . TelnetClient::CMD_WILL . TelnetClient::OPT_ECHO . "first\r\n");
    $matchesPrompt = false;
    $line = $telnet->getLine($matchesPrompt, true);
    $firstReply = $server->readUntilContains([TelnetClient::CMD_IAC . TelnetClient::CMD_DO . TelnetClient::OPT_ECHO]);

    expect($line)->toBe("first\n");
    expect(substr_count($firstReply, TelnetClient::CMD_IAC . TelnetClient::CMD_DO . TelnetClient::OPT_ECHO))->toBe(1);

    $server->writeToClient(TelnetClient::CMD_IAC . TelnetClient::CMD_WILL . TelnetClient::OPT_ECHO . "second\r\n");
    $line = $telnet->getLine($matchesPrompt, true);
    $secondReply = $server->readFromClient(1024, 0.1);

    expect($line)->toBe("second\n");
    expect($secondReply)->toBeFalse();

    $telnet->disconnect();
});


