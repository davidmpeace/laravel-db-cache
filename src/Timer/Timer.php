<?php
namespace Eloquent\Cache\Timer;

class Timer
{
    private $startTime = null;

    public function __construct()
    {
        $this->start();
    }

    public static function microtimeNow()
    {
        list($usec, $sec) = explode(" ", microtime());
        return ((float)$usec + (float)$sec);
    }

    public function getStartTime()
    {
        return $this->startTime;
    }

    public function start()
    {
        $this->startTime = static::microtimeNow();
    }

    public function elapsed()
    {
        $elapsed = false;
        if( $startTime = $this->startTime ) {
            $endTime = static::microtimeNow();
            $elapsed = $endTime - $startTime;
        }

        return $elapsed;
    }
}