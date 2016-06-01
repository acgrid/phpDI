<?php
/**
 * Created by PhpStorm.
 * Date: 2016/1/3
 * Time: 15:52
 */

namespace acgrid\DI;

/**
 * Class ObjectPlaceholder
 * A placeholder for defining managed instances for constructing params in DI\Container
 * Keep in mind that wherever you need a object, use self::factory('FQN or ID registered yet in container')
 *
 * @package U2M\DI
 */
class ObjectPlaceholder
{
    public $id;

    protected function __construct($id)
    {
        $this->id = $id;
    }

    /**
     * Factory method
     * Can be reused for the same $id
     * @param string $id
     * @return static
     */
    public static function factory($id)
    {
        return new static($id);
    }

    /**
     * Get referenced real object from specific container
     * @param Container $container
     * @return object
     */
    public function actual(Container $container)
    {
        if(!isset($this->id)) throw new \RuntimeException('Try to get instance which is not configured yet.');
        return $container->get($this->id);
    }

}