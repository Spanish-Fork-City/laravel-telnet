<?php

namespace SpanishForkCity\Telnet\Parser;

class Sequence
{
    protected $string;
    protected $complete;


    public function __construct($string, $complete = true)
    {
        $this->string = $string;
        $this->complete = $complete;
    }


    public function getString()
    {
        return $this->string;
    }


    public function isComplete()
    {
        return $this->complete;
    }
}
