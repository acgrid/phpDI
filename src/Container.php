<?php
/**
 * Created by PhpStorm.
 * Date: 2016/1/3
 * Time: 12:33
 */

namespace acgrid\DI;

use InvalidArgumentException;
use LogicException;

use Closure;
use ReflectionClass;
use ReflectionParameter;
use ReflectionMethod;
use ReflectionFunction;

/**
 * Class Container
 * This container implements the [dependency injection](http://en.wikipedia.org/wiki/Dependency_injection) thought.
 * It is mainly inspired by Yii's DI (yii\di\Container) but it can be used alone without binding on the framework,
 * and refine the support of callback and factory.
 *
 * Supports following ways of instantiating objects:
 * Constructor injection
 * Setter injection
 * Factory methods
 * Callback builder
 *
 * The *type* in the container is either full-qualified name(FQN) or client-defined alias of a class
 * The aliases are useful if you need more than one instance of a class
 *
 * @package acgrid\DI
 */
class Container
{
    /**
     * Constants DEF_*: Mandatory and exclusive key of non-empty definition
     * Choose either one type of instantiation.
     * The behaviour is not defined if you set more than one type.
     *
     * Constants CFG_*: Optional key of non-empty definition
     * Used on demand, refer to different instantiation type about detailed information
     *
     * DEF_CLASS indicate instantiate a object by constructor normally
     */
    const DEF_CLASS = 'class';
    /**
     * DEF_CALLBACK "normalized" callback, the function or method takes two parameters [Container, array] where array is
     * an indexed configuration array
     */
    const DEF_CALLBACK = 'callback';
    /**
     * DEF_FACTORY standard factory callback, the function or method takes any form of parameters.
     * The param array like [a, b, c] will invoke the callback like func(a, b, c)     *
     */
    const DEF_FACTORY = 'static';
    /**
     * CFG_PARAM is an indexed array consisted of constructor or callback function parameters
     * You can skip some of the param (i.e. use default value) by specify the index manually.
     * For example ['A', 2 => 'C', 'D'] where the second param uses the default value
     */
    const CFG_PARAM = 'param';
    /**
     * CFG_SETTER is an indexed array combined the method name and its parameter
     * It will be used soon after the object instantiation.
     * This key is either non-exist or an indexed array whose first element is the object's method name string.
     * The rest elements are all the parameter for the setter call by its sequence in array.
     */
    const CFG_SETTER = 'setter';

    /**
     * Type definitions
     * Key: Type
     * Value: *Type Definition* @see parseDefinition() for details
     * @var array
     */
    private $definitions = [];
    /**
     * The resolved parameters of constructor or callback function of this type
     * Key: Type
     * Value: indexed array @see saveDependencies()
     * @var array
     */
    private $dependencies = [];
    /**
     * Class Reflection object cache
     * Key: Class FQN
     * Value: ReflectionClass
     * @var array
     */
    private $reflections = [];
    /**
     * The container itself
     * Used for singletons only
     * Key: Type
     * Value: object
     * @var array
     */
    private $singletons = [];

    /**
     * Instantiate an object of specified type
     * The `$params` can override the instantiation params
     * If the type is singleton, the $params does not work.
     * The container can instantiate for a non-defined FQN by resolve its constructor as well and do the right thing.
     *
     * @param string $type
     * @param array $params
     * @throws \ReflectionException the requested class does not exist or can not be reflected
     * @throws LogicException internal data is not expected, maybe bugs
     * @return object
     */
    public function get($type, array $params = [])
    {
        // try singletons first, note null will make isset() returning false
        if(isset($this->singletons[$type])) return $this->singletons[$type];
        if(isset($this->definitions[$type])){
            $definition =& $this->definitions[$type];
            if(is_object($definition)){
                return $this->singletons[$type] = $definition;
            }elseif(is_array($definition)){
                if(isset($definition[self::CFG_PARAM])){
                    if(!is_array($definition[self::CFG_PARAM])) $definition[self::CFG_PARAM] = [$definition[self::CFG_PARAM]];
                    $params = $params + $definition[self::CFG_PARAM];
                }
                if(isset($definition[self::DEF_CLASS])){
                    $object = $definition[self::DEF_CLASS] === $type ? $this->newInstance($type, $params) : $this->get($definition[self::DEF_CLASS], $params);
                }elseif(isset($definition[self::DEF_CALLBACK])){
                    $object = $this->normalizedCallback($type, $params);
                }elseif(isset($definition[self::DEF_FACTORY])){
                    $object = $this->standardCallback($type, $params);
                }else{
                    throw new LogicException('DI: Definition array does not have recognizable approach.');
                }
                $this->doSetter($object, $definition);
                if(array_key_exists($type, $this->singletons)) $this->singletons[$type] = $object;
                return $object;
            }else{
                throw new LogicException('DI: Definition type is unexpected.');
            }
        }else{
            return $this->newInstance($type, $params);
        }
    }

    /**
     * Register a $type with its $definitions
     * @see `parseDefinition()` for details of definition
     * Note that if $definition is an object, this function is identical as `registerSingleton()`
     *
     * @param string $type
     * @param string|array|object $definitions
     * @return $this
     */
    public function register($type, $definitions = [])
    {
        $this->definitions[$type] = $this->parseDefinition($type, $definitions);
        unset($this->singletons[$type]);
        return $this;
    }

    /**
     * Register a singleton $type with its $definitions
     * For every call of `get()`, return the same object.
     *
     * @param string $type
     * @param array|string|object $definitions
     * @return $this
     */
    public function registerSingleton($type, $definitions = [])
    {
        $this->definitions[$type] = $this->parseDefinition($type, $definitions);
        $this->singletons[$type] = null;
        return $this;
    }

    /**
     * Get all defined definitions
     * @return array
     */
    public function getDefinitions()
    {
        return $this->definitions;
    }

    /**
     * Determine specified $type is defined already
     * This method does not check the $type is an FQN or the existence of classes.
     * @param $type
     * @return bool
     */
    public function has($type)
    {
        return isset($this->definitions[$type]);
    }

    /**
     * Remove the definitions and singleton of the specified $type
     * Return silently no matter the specified $type is really removed or not
     * @param $type
     * @return $this
     */
    public function remove($type)
    {
        unset($this->definitions[$type], $this->singletons[$type]);
        return $this;
    }

    /**
     * Logic of definition parsing
     * The final parsed result of definition is either:
     * `object` which uses as singleton as-is
     * `array` which contains a mandatory DEF_* key and optional CFG_* key(s)
     *
     * If the definition is empty, `$type` must be an FQN. The result is constructor-way no default params.
     * If the definition is a string, it must be an FQN. The result is constructor-way no default params.
     * If the definition is an object, this type is a singleton and the result is the object itself.
     * If the definition is an array with valid DEF_* key, The result is array itself.
     * If the definition is an array without a DEF_* key but the $type is an FQN, then normalize the definition array.
     * If none of above are met, throws an exception
     *
     * @param string $type
     * @param string|array|object $definition
     * @throws InvalidArgumentException
     * @return array
     */
    protected function parseDefinition($type, $definition)
    {
        if(empty($definition)){
            if(class_exists($type, true)){
                return [self::DEF_CLASS => $type];
            }else{
                throw new InvalidArgumentException("DI: Type '$type' is expected to be a class FQN when definition is empty.");
            }
        }else{
            if(is_string($definition)){
                return [self::DEF_CLASS => $definition];
            }elseif(is_object($definition)){
                return $definition;
            }elseif(is_array($definition)){
                if(isset($definition[self::DEF_CLASS])){
                    return $definition;
                }elseif(isset($definition[self::DEF_FACTORY]) && is_callable($definition[self::DEF_FACTORY], true)){
                    return $definition;
                }elseif(isset($definition[self::DEF_CALLBACK]) && is_callable($definition[self::DEF_CALLBACK], true)){
                    return $definition;
                }elseif(class_exists($type, true)){
                    return [self::DEF_CLASS => $type, self::CFG_PARAM => isset($definition[self::CFG_PARAM]) ? $definition[self::CFG_PARAM] : $definition];
                }else{
                    throw new InvalidArgumentException("DI: Definition array does not contain a class name or type is not a FQN.");
                }
            }else{
                throw new InvalidArgumentException('DI: Definition type ' . gettype($definition) . ' is not supported.');
            }
        }
    }

    /**
     * Do instantiation of `$class`(type) with overridden `$param`
     *
     * @param string $class FQN
     * @param array $param
     * @return object
     */
    protected function newInstance($class, $param)
    {
        $this->initClassDependencies($class);
        /** @var ReflectionClass $reflection */
        $reflection = $this->reflections[$class];
        $dependencies = $this->fillObjects($param + $this->dependencies[$class]);
        ksort($dependencies);
        return $reflection->newInstanceArgs($dependencies);
    }

    /**
     * Do instantiation callback of `$type` with overridden `$param`
     * The parameter sequence is fixed as ($this, $param).
     * The result is not guaranteed to be an object.
     *
     * @param string $type
     * @param array $param
     * @return object
     */
    protected function normalizedCallback($type, array $param = [])
    {
        $definition =& $this->definitions[$type][self::DEF_CALLBACK];
        $this->initCallbackDependencies($type, $definition);
        $dependencies = $this->fillObjects($param + $this->dependencies[$type]);
        ksort($dependencies);
        return call_user_func($definition, $this, $dependencies);
    }

    /**
     * Do instantiation callback of `$type` with overridden `$param`
     * The parameter sequence is flexible as the `$param`.
     * The result is not guaranteed to be an object.
     *
     * @param string $type
     * @param array $param
     * @return object
     */
    protected function standardCallback($type, array $param = [])
    {
        $definition =& $this->definitions[$type][self::DEF_FACTORY];
        $this->initCallbackDependencies($type, $definition);
        $dependencies = $this->fillObjects($param + $this->dependencies[$type]);
        ksort($dependencies);
        return call_user_func_array($definition, $dependencies);
    }

    /**
     * Search setter-injection on provided `$definition` and apply it on `$object`
     *
     * @param object $object
     * @param array $definition
     */
    protected function doSetter($object, array &$definition)
    {
        if(isset($definition[self::CFG_SETTER]) && is_array($definition[self::CFG_SETTER]) && count($definition[self::CFG_SETTER])){
            $setter = $definition[self::CFG_SETTER];
            $method = array_shift($setter);
            $setter = $this->fillObjects($setter);
            ksort($setter);
            call_user_func_array([$object, $method], $setter);
        }
    }

    /**
     * Save dependencies on $this->dependencies
     * `$parameters` must be an array of `ReflectionParameter` which got from ReflectionFunctionAbstract
     * For each iteration:
     * * If the parameter has a default value, copy it
     * * If the parameter has type hint of a class, set a ObjectPlaceholder instance as object dummy for replacement
     * * `null` for other situations which need external params injection
     *
     * @param string $class
     * @param array $parameters array of ReflectionParameter
     */
    protected function saveDependencies($class, array $parameters)
    {
        foreach($parameters as $parameter){
            /** @var ReflectionParameter $parameter */
            if($parameter->isDefaultValueAvailable()){
                $this->dependencies[$class][] = $parameter->getDefaultValue();
            }elseif(($typeHit = $parameter->getClass()) instanceof ReflectionClass){
                $this->dependencies[$class][] = ObjectPlaceholder::factory($typeHit->getName());
            }else{
                $this->dependencies[$class][] = null;
            }
        }
    }

    /**
     * Prepare reflection on a class for calling `saveDependencies()`
     * @param string $class
     */
    protected function initClassDependencies($class)
    {
        if(isset($this->dependencies[$class])) return;
        $reflection = new ReflectionClass($class);
        $this->reflections[$class] = $reflection;
        $this->dependencies[$class] = [];
        $constructor = $reflection->getConstructor();
        if($constructor === null) return;
        $this->saveDependencies($class, $constructor->getParameters());
    }

    /**
     * Prepare reflection on a `callable` variable for calling `saveDependencies()`
     * Support mainline types [http://php.net/manual/en/language.types.callable.php] as following:
     * 'function_name'
     * 'ClassName::StaticMethodName'
     * ['ClassName', 'StaticMethodName']
     * [$object, 'MethodName']
     * $Closure = function(){};
     * $invokableObject = new class Invokable{ function __invoke(){} }
     * Recommend using FQN for class and function name to eliminate problems.
     *
     * @param string $type
     * @param string|array|object $definition
     */
    protected function initCallbackDependencies($type, &$definition)
    {
        if(isset($this->dependencies[$type]) || !isset($this->definitions[$type])) return;
        if(is_string($definition)){
            if($pos = strpos($definition, '::')){
                $reflector = new ReflectionMethod(substr($definition, 0, $pos), substr($definition, $pos + 2));
            }else{
                $reflector = new ReflectionFunction($definition);
            }
        }elseif(is_array($definition) && count($definition) === 2 && is_string($definition[1])){
            if(is_string($definition[0])){
                $reflector = new ReflectionMethod($definition[0], $definition[1]);
            }elseif(is_object($definition[0])){
                $reflector = new ReflectionMethod(get_class($definition[0]), $definition[1]);
            }else{
                throw new InvalidArgumentException('DI: Invalid callback array, first element is not class name or object.');
            }
        }elseif(is_object($definition)){
            if($definition instanceof Closure){
                $reflector = new ReflectionFunction($definition);
            }elseif(method_exists($definition, '__invoke')){
                $reflector = new ReflectionMethod(get_class($definition), '__invoke');
            }else{
                throw new InvalidArgumentException('DI: Invalid callback object, closure or invokable object are expected.');
            }
        }else{
            throw new InvalidArgumentException('DI: Callback structure is not recognizable.');
        }
        $this->dependencies[$type] = [];
        $this->reflections[$type] = $reflector;
        $this->saveDependencies($type, $reflector->getParameters());
    }

    /**
     * Iterate the dependency item and replace the dummy object with actual one.
     *
     * @param array $dependencies
     * @return array
     */
    protected function fillObjects(array $dependencies)
    {
        foreach($dependencies as $index => &$dependency){
            if($dependency instanceof ObjectPlaceholder){
                $dependencies[$index] = $dependency->actual($this);
            }
        }
        return $dependencies;
    }
}