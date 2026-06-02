<?php

use SpanishForkCity\Telnet\Parser\AnsiAsciiControlParser;
use SpanishForkCity\Telnet\Parser\ControlSequence;
use SpanishForkCity\Telnet\Parser\EscapeSequence;
use SpanishForkCity\Telnet\Parser\TextSequence;

it('can parse a string with ANSI escape codes from a sample payload', function () {
    $parser = new AnsiAsciiControlParser();
    $raw = "\x1B[32mConnected\x1B[0m\r\n"
        . "device01\x1B[31m error\x1B[0m\r\n"
        . "prompt$ ";
    $parser->parse($raw);
    $text = $parser->getTextString();

    expect($text)->not->toContain("\x1B[");
});

it('handles a simple string with no escape codes', function () {
    $parser = new AnsiAsciiControlParser();
    $parser->parse('hello world');
    $text = $parser->getTextString();
    $sequences = $parser->getSequenceList();

    expect($text)->toBe('hello world');
    expect($sequences)->toHaveCount(1);
    expect($sequences[0])->toBeInstanceOf(TextSequence::class);
});

it('handles a string with a single escape code', function () {
    $parser = new AnsiAsciiControlParser();
    $parser->parse("hello\x1B[31mworld");
    $text = $parser->getTextString();
    $sequences = $parser->getSequenceList();

    expect($text)->toBe('helloworld');
    expect($sequences)->toHaveCount(3);
    expect($sequences[0])->toBeInstanceOf(TextSequence::class)->getString()->toBe('hello');
    expect($sequences[1])->toBeInstanceOf(ControlSequence::class);
    expect($sequences[2])->toBeInstanceOf(TextSequence::class)->getString()->toBe('world');
});

it('handles a string with multiple escape codes', function () {
    $parser = new AnsiAsciiControlParser();
    $parser->parse("\x1B[1mhello\x1B[0m world");
    $text = $parser->getTextString();
    $sequences = $parser->getSequenceList();

    expect($text)->toBe('hello world');
    expect($sequences)->toHaveCount(4);
    expect($sequences[0])->toBeInstanceOf(ControlSequence::class);
    expect($sequences[1])->toBeInstanceOf(TextSequence::class)->getString()->toBe('hello');
    expect($sequences[2])->toBeInstanceOf(ControlSequence::class);
    expect($sequences[3])->toBeInstanceOf(TextSequence::class)->getString()->toBe(' world');
});

it('handles a string with an incomplete escape code', function () {
    $parser = new AnsiAsciiControlParser();
    $parser->parse("hello\x1B[31");
    $text = $parser->getTextString();
    $sequences = $parser->getSequenceList();

    expect($text)->toBe('hello');
    expect($sequences)->toHaveCount(2);
    expect($sequences[0])->toBeInstanceOf(TextSequence::class);
    $lastSequence = end($sequences);
    expect($lastSequence)->toBeInstanceOf(ControlSequence::class);
    expect($lastSequence->isComplete())->toBeFalse();
});

it('handles a string that starts with an escape code', function () {
    $parser = new AnsiAsciiControlParser();
    $parser->parse("\x1B[31mhello world");
    $text = $parser->getTextString();

    expect($text)->toBe('hello world');
});

it('handles a string that ends with an escape code', function () {
    $parser = new AnsiAsciiControlParser();
    $parser->parse("hello world\x1B[0m");
    $text = $parser->getTextString();

    expect($text)->toBe('hello world');
});

it('handles a simple escape sequence, not a control sequence', function () {
    $parser = new AnsiAsciiControlParser();
    $parser->parse("hello\x1B)0world");
    $text = $parser->getTextString();
    $sequences = $parser->getSequenceList();

    expect($text)->toBe('helloworld');
    expect($sequences)->toHaveCount(3);
    expect($sequences[1])->toBeInstanceOf(EscapeSequence::class);
});
