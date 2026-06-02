<?php

namespace SpanishForkCity\Telnet\Tests\Fakes;

class TelnetServer
{
    private $socket;
    private $client;

    public function __construct($port = 1024)
    {
        $this->socket = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
        if (!$this->socket) {
            throw new \Exception("Could not create socket: [$errno] $errstr");
        }

        stream_set_blocking($this->socket, false);
        $this->client = null;
    }

    public function __destruct()
    {
        if (is_resource($this->client)) {
            fclose($this->client);
        }
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    public function getPort()
    {
        $name = stream_socket_get_name($this->socket, false);
        return (int) substr(strrchr($name, ':'), 1);
    }

    public function accept($timeoutSeconds = 1.0)
    {
        $end = microtime(true) + $timeoutSeconds;
        do {
            $this->client = @stream_socket_accept($this->socket, 0);
            if (is_resource($this->client)) {
                stream_set_blocking($this->client, false);
                return $this->client;
            }

            usleep(1000);
        } while (microtime(true) < $end);

        throw new \RuntimeException('Timed out waiting for client connection');
    }

    public function readFromClient($length = 1024, $timeoutSeconds = 0.2)
    {
        if (!is_resource($this->client)) {
            throw new \RuntimeException('No connected client');
        }

        $end = microtime(true) + $timeoutSeconds;
        $buffer = '';

        do {
            $chunk = fread($this->client, $length);
            if ($chunk !== false && $chunk !== '') {
                $buffer .= $chunk;
                if (strlen($chunk) < $length) {
                    break;
                }
            } else {
                usleep(1000);
            }
        } while (microtime(true) < $end);

        return $buffer === '' ? false : $buffer;
    }

    public function readUntilContains(array $needles, $timeoutSeconds = 1.0)
    {
        $buffer = '';
        $end = microtime(true) + $timeoutSeconds;

        do {
            $chunk = $this->readFromClient(1024, 0.05);
            if ($chunk !== false) {
                $buffer .= $chunk;
                $allPresent = true;
                foreach ($needles as $needle) {
                    if (strpos($buffer, $needle) === false) {
                        $allPresent = false;
                        break;
                    }
                }

                if ($allPresent) {
                    return $buffer;
                }
            }

            usleep(1000);
        } while (microtime(true) < $end);

        throw new \RuntimeException('Timed out waiting for expected data from client');
    }

    public function writeToClient($data)
    {
        if (!is_resource($this->client)) {
            throw new \RuntimeException('No connected client');
        }

        $written = 0;
        $length = strlen($data);
        while ($written < $length) {
            $result = fwrite($this->client, substr($data, $written));
            if ($result === false || $result === 0) {
                usleep(1000);
                continue;
            }

            $written += $result;
        }

        return $this->client;
    }

    public function closeClient()
    {
        if (is_resource($this->client)) {
            fclose($this->client);
            $this->client = null;
        }
    }
}
