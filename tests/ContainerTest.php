<?php
/**
 * Created by PhpStorm.
 * Date: 2016/1/3
 * Time: 18:54
 */

use acgrid\DI\Container;
use acgrid\DI\ObjectPlaceholder;

class ConfigurableA
{
    const SPECIAL = 'special';
    public $data;

    public function __construct($config)
    {
        $this->data = $config;
    }

    public static function special()
    {
        return new self(self::SPECIAL);
    }
}

class ConfigurableB
{
    public $data;

    public function __construct($config = 'B')
    {
        $this->data = $config;
    }
}

class ClientClass
{
    public $a;
    public $b;
    public $extra;

    public function __construct(ConfigurableA $a, ConfigurableB $b)
    {
        $this->a = $a;
        $this->b = $b;
    }

    public static function normalized(Container $di, array $params)
    {
        $di->remove(get_called_class());
        return new self(new ConfigurableA(isset($params[0]) ? $params[0] : 'A'), new ConfigurableB());
    }
}

class MoreClass extends ClientClass
{
    public $extra;

    public function setExtra($extra)
    {
        $this->extra = $extra;
    }
}

function sampleA($a)
{
    return new ConfigurableA($a);
}

class FactoryC
{
    const METHOD = 'sample-method';
    const INVOKE = 'sample-invoke';

    public function getInstance($a)
    {
        return new ConfigurableA($a);
    }

    public function defaultValueTest($a = 'a', $b = 'b')
    {
        return new ClientClass(new ConfigurableA($a), new ConfigurableB($b));
    }

    public function objectTest(ConfigurableA $a, ConfigurableB $b)
    {
        return new ClientClass($a, $b);
    }

    public function __invoke($b)
    {
        return new ConfigurableB($b);
    }
}

class ContainerTest extends PHPUnit_Framework_TestCase
{
    public function testSimpleClass()
    {
        $di = new Container();
        $this->assertSame($di, $di->register('A', ConfigurableA::class));
        $this->assertSame(['A' => [Container::DEF_CLASS => 'ConfigurableA']], $di->getDefinitions());
        $this->assertTrue($di->has('A'));
        $this->assertFalse($di->has('B'));
        $data = 233;
        $this->assertEquals($data, $di->get('ConfigurableA', [$data])->data);
        $di->register('ConfigurableA', [Container::CFG_PARAM => [$data]]);
        /** @var ClientClass $client */
        $client = $di->get('ClientClass');
        $this->assertEquals($data, $client->a->data);
        $this->assertEquals('B', $client->b->data);
        $di->register('ConfigurableB', [++$data]);
        $client = $di->get('ClientClass');
        $this->assertEquals($data, $client->b->data);
    }

    public function testAlias()
    {
        $di = new Container();
        $a = 998;
        $b = 889;
        $di->registerSingleton('default-client',
            [Container::DEF_CLASS => 'ClientClass', Container::CFG_PARAM => [new ConfigurableA($a), new ConfigurableB($b)]]);
        /** @var ClientClass $client */
        $client = $di->get('default-client');
        $this->assertEquals($a, $client->a->data);
        $this->assertEquals($b, $client->b->data);
        $this->assertSame($client, $di->get('default-client'));
        $a = 233;
        $di->register('MyA', [Container::DEF_CLASS => 'ConfigurableA', Container::CFG_PARAM => [$a]]);
        $b = 486;
        $di->register('another-client', [
            Container::DEF_CLASS => 'ClientClass',
            Container::CFG_PARAM => [ObjectPlaceholder::factory('MyA'), new ConfigurableB($b)]]);
        $client = $di->get('another-client');
        $this->assertEquals($a, $client->a->data);
        $this->assertEquals($b, $client->b->data);
        $this->assertNotSame($client, $di->get('another-client'));
    }

    public function testObject()
    {
        $di = new Container();
        $di->register(ConfigurableA::class);
        $this->assertSame([ConfigurableA::class => [Container::DEF_CLASS => ConfigurableA::class]], $di->getDefinitions());
        $object = new ClientClass(new ConfigurableA(4), new ConfigurableB(6));
        $di->registerSingleton('pre-object-immutable', $object);
        $this->assertSame($object, $di->get('pre-object-immutable'));
        $di->register('pre-object', $object);
        $this->assertNotSame($object, $object2 = $di->get('pre-object'));
        /** @var ClientClass $object2 */
        $this->assertSame(4, $object2->a->data);
        $this->assertSame(6, $object2->b->data);
    }

    public function testSetter()
    {
        $di = new Container();
        /** @var MoreClass $client */
        $client = $di->register('ConfigurableA', [1])->register('ConfigurableB', [2])
            ->register('special', [Container::DEF_CLASS => 'MoreClass', Container::CFG_SETTER => ['setExtra', 3]])
            ->get('special');
        $this->assertEquals(1, $client->a->data);
        $this->assertEquals(2, $client->b->data);
        $this->assertEquals(3, $client->extra);
        /** @var ClientClass $client */
        $client = $di->register('MyA', [Container::DEF_CLASS => 'ConfigurableA', Container::CFG_PARAM => ['A1']])
            ->register('MyB', [Container::DEF_CLASS => 'ConfigurableB', Container::CFG_PARAM => ['B2']])
            ->register('ClientClass', [ObjectPlaceholder::factory('MyA'), ObjectPlaceholder::factory('MyB')])
            ->get('ClientClass');
        $this->assertEquals('A1', $client->a->data);
        $this->assertEquals('B2', $client->b->data);
    }

    public function testFactory()
    {
        $di = new Container();
        /** @var ConfigurableA $client */
        $client = $di->register('static-method-version', [Container::DEF_FACTORY => 'ConfigurableA::special'])->get('static-method-version');
        $this->assertEquals(ConfigurableA::SPECIAL, $client->data);
        $client = $di->register('static-method-version2', [Container::DEF_FACTORY => ['ConfigurableA', 'special']])->get('static-method-version2');
        $this->assertEquals(ConfigurableA::SPECIAL, $client->data);
        $client = $di->register('function-version', [Container::DEF_FACTORY => 'sampleA', Container::CFG_PARAM => ['func']])->get('function-version');
        $this->assertEquals('func', $client->data);
        $client = $di->register('closure-version', [Container::DEF_FACTORY => function($a) {
            return new ConfigurableA($a);
        }, Container::CFG_PARAM => 'closure'])->get('closure-version');
        $this->assertEquals('closure', $client->data);
        $factory = new FactoryC();
        $client = $di->register('object-version', [Container::DEF_FACTORY => [$factory, 'getInstance'], Container::CFG_PARAM => [FactoryC::METHOD]])->get('object-version');
        $this->assertEquals(FactoryC::METHOD, $client->data);
        $this->assertInstanceOf('ConfigurableA', $client);
        /** @var ClientClass $client2 */
        $client2 = $di->register('default-test', [Container::DEF_FACTORY => [$factory, 'defaultValueTest'], Container::CFG_PARAM => [1 => 'BB']])->get('default-test');
        $this->assertInstanceOf('ClientClass', $client2);
        $this->assertEquals('a', $client2->a->data);
        $this->assertEquals('BB', $client2->b->data);
        $client = $di->register('invoke-version', [Container::DEF_FACTORY => $factory, Container::CFG_PARAM => [FactoryC::INVOKE]])->get('invoke-version');
        $this->assertInstanceOf('ConfigurableB', $client);
        $this->assertEquals(FactoryC::INVOKE, $client->data);
        $client2 = $di->
            register('MyA', [Container::DEF_CLASS => 'ConfigurableA', Container::CFG_PARAM => ['A0']])->
            register('MyB', [Container::DEF_CLASS => 'ConfigurableB', Container::CFG_PARAM => ['B0']])->
            register('instance-test', [Container::DEF_FACTORY => [$factory, 'objectTest'],
            Container::CFG_PARAM => [ObjectPlaceholder::factory('MyA'), ObjectPlaceholder::factory('MyB')]])->
            get('instance-test');
        $this->assertInstanceOf('ClientClass', $client2);
        $this->assertEquals('A0', $client2->a->data);
        $this->assertEquals('B0', $client2->b->data);
    }

    public function testNormalizedCallback()
    {
        $di = new Container();
        /** @var ClientClass $client */
        $client = $di->register('normalized', [Container::DEF_CALLBACK => ['ClientClass', 'normalized'], Container::CFG_PARAM => ['AA']])->get('normalized');
        $this->assertEquals('AA', $client->a->data);
        $this->assertEquals('B', $client->b->data);
    }

    public function testNotExist()
    {
        $di = new Container();
        try{
            $di->get('foo');
        }catch(ReflectionException $e){
            return;
        }
        $this->fail('Exception is expected due to non-exist class and non-defined alias.');
    }

    public function testNotClass()
    {
        $this->setExpectedException(InvalidArgumentException::class, "DI: Type 'X' is expected to be a class FQN when definition is empty.");
        $di = new Container();
        $di->register('X');
    }

}
