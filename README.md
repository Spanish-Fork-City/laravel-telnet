# laravel-telnet

A TELNET client library for PHP, focused on command/response automation against network gear and shell-like TELNET endpoints.

## Requirements

- PHP `>= 8.4`

## Installation

```bash
composer require spanish-fork-city/laravel-telnet
```

> Note: The Composer package vendor is `spanish-fork-city` and the PHP namespace is `SpanishForkCity\\Telnet`.

## Quick Start

```php
<?php

use SpanishForkCity\Telnet\TelnetClient;

$telnet = new TelnetClient('127.0.0.1', 23);
$telnet->connect();

$telnet->setPrompt('$');
$telnet->login('telnetuser', 'weak');

$result = $telnet->exec('ls /');

$telnet->disconnect();

print_r($result); // array of lines
```

## API Usage

### Constructor and Connection

```php
$telnet = new TelnetClient(
	host: '127.0.0.1',
	port: 23,
	connect_timeout: 1.0,
	socket_timeout: 10.0,
	prompt: '$',
	full_line_timeout: 0.10,
);

$telnet->connect();
// ...
$telnet->disconnect();
```

Notes:

- The constructor does **not** call `connect()`.
- `disconnect()` is also called by the destructor as a cleanup safeguard.

### Login

```php
// Default prompts: login: and Password:
$telnet->login('admin', 'secret');

// Custom prompts
$telnet->login('admin', 'secret', 'Username:', 'Passcode:');

// Skip one prompt by passing null
$telnet->login('admin', 'secret', null, 'Password:');
```

### Command Execution

```php
$lines = $telnet->exec('show version');

// If you need to control command write separately:
$telnet->sendCommand('show ip int brief');
```

`exec()` returns an array of lines collected until prompt match.

### Prompt Matching

```php
// Literal prompt
$telnet->setPrompt('#');

// Regex prompt (without enclosing slashes)
$telnet->setRegexPrompt('router[0-9]+\s*#');

$regex = $telnet->getRegexPrompt();
$matches = $telnet->matchesPrompt('router01 #');
```

### Timeouts

```php
$telnet->setConnectTimeout(2.0);     // TCP connect timeout
$telnet->setSocketTimeout(5.0);      // timeout waiting for incoming bytes
$telnet->setStreamTimeout(5.0);      // deprecated alias of setSocketTimeout()
$telnet->setFullLineTimeout(0.25);   // max wait for line terminator

$connectTimeout = $telnet->getConnectTimeout();
$socketTimeout = $telnet->getSocketTimeout();
$fullLineTimeout = $telnet->getFullLineTimeout();
```

### Line-Oriented Reads

```php
$matchesPrompt = false;
$line = $telnet->getLine($matchesPrompt, true);

if ($matchesPrompt) {
	// current line matched the configured prompt
}
```

### Remaining Data Behavior

```php
// When true, read extra already-received bytes after prompt is found
$telnet->setDoGetRemainingData(true);
$enabled = $telnet->getDoGetRemainingData();

// Discard any unread but already-received data
$telnet->discardRemainingData();
```

### ANSI/Control Sequence Pruning

```php
// Remove ANSI escape/control sequences from lines returned by reads/exec
$telnet->setPruneCtrlSeq(true);
$enabled = $telnet->getPruneCtrlSeq();
```

### Host Resolution Info

```php
$hostname = $telnet->getHostname();  // original host argument
$ip = $telnet->getIpAddress();       // resolved IP after connect()
```

### Debugging and Protocol Helpers

```php
use SpanishForkCity\Telnet\TelnetClient;

TelnetClient::setDebug(true);

echo TelnetClient::getNvtPrintSpecialStr(TelnetClient::NVT_CR); // CR
echo TelnetClient::getCmdStr(TelnetClient::CMD_IAC);            // IAC
echo TelnetClient::getOptStr(TelnetClient::OPT_ECHO);           // Echo
```

## Standalone ANSI Parser Usage

```php
<?php

use SpanishForkCity\Telnet\Parser\AnsiAsciiControlParser;
use SpanishForkCity\Telnet\Parser\TextSequence;

$parser = new AnsiAsciiControlParser();
$parser->parse("hello\x1B[31m world\x1B[0m");

$textOnly = $parser->getTextString();  // "hello world"
$full = $parser->getFullString();      // includes control sequences

foreach ($parser->getSequenceList() as $sequence) {
	$raw = $sequence->getString();
	$isComplete = $sequence->isComplete();
	$isText = $sequence instanceof TextSequence;
}
```

## Exceptions

`TelnetClient` may throw:

- `SpanishForkCity\Telnet\Exceptions\NameResolutionException`
- `SpanishForkCity\Telnet\Exceptions\ConnectionException`
- `SpanishForkCity\Telnet\Exceptions\ConnectionTimeoutException`
- `SpanishForkCity\Telnet\Exceptions\LoginException`
- `SpanishForkCity\Telnet\Exceptions\UnimplementedException`
- `SpanishForkCity\Telnet\Exceptions\UnlikelyException`
- `InvalidArgumentException` for invalid method arguments

## Caveats and Limitations

- TELNET option negotiation is intentionally minimal and conservative.
  - Incoming `DO`/`DONT` are answered with `WONT`.
  - Incoming `WILL` is answered with `DONT`.
  - This can conflict with servers expecting negotiated `ECHO`, `SGA`, or `LINEMODE` behavior.
- Subnegotiation (`IAC SB ... IAC SE`) is currently parsed but dropped (not fully implemented).
- Line ending normalization (`<CR><LF>` to `"\n"`) assumes server behavior follows TELNET text-mode conventions.
- `setStreamTimeout()` is deprecated; use `setSocketTimeout()`.
- Internal low-level methods like `write()` and `waitPrompt()` are protected, so the public API is the supported integration path.

## Testing

The repository includes Pest tests with local fake TELNET server coverage.

```bash
composer run test
composer run test:coverage
```

## Project History

This library originated from work based on:

- https://github.com/ngharo/Random-PHP-Classes/blob/master/Telnet.class.php

It has since been rewritten and packaged for Composer.

