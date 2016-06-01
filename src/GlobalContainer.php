<?php


namespace acgrid\DI;

/**
 * Class GlobalContainer
 * @package acgrid\DI
 */
class GlobalContainer
{
    protected static $instance;

    /**
     * @return Container
     */
    public static function instance()
    {
        if(static::$instance === null) static::$instance = new Container();
        return static::$instance;
    }
}