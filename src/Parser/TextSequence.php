<?php

namespace SpanishForkCity\Telnet\Parser;

use SpanishForkCity\Telnet\Parser\Sequence;

class TextSequence extends Sequence
{
    //Always complete
    public function __construct($string, $complete = true)
    {
        parent::__construct($string, true);
    }
}
