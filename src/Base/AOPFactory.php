<?php
/**
 * @desc: AOP类工厂
 * @author: leandre <niulingyun@camera360.com>
 * @date: 2017/3/28
 * @copyright All rights reserved.
 */

namespace PG\MSF\Base;

use PG\MSF\DataBase\CoroutineRedisHelp;
use PG\MSF\Memory\Pool;
use PG\MSF\Proxy\IProxy;
use PG\AOP\Factory;
use PG\AOP\Wrapper;

class AOPFactory extends Factory
{
    /**
     * @var array
     */
    protected static $reflections = [];

    /**
     * 获取协程redis
     * @param CoroutineRedisHelp $redisPoolCoroutine
     * @param Core $coreBase
     * @return Wrapper |CoroutineRedisHelp
     */
    public static function getRedisPoolCoroutine(CoroutineRedisHelp $redisPoolCoroutine, $coreBase)
    {
        $AOPRedisPoolCoroutine = new Wrapper($redisPoolCoroutine);
        $AOPRedisPoolCoroutine->registerOnBefore(function ($method, $arguments) use ($coreBase) {
            $context = $coreBase->getContext();
            array_unshift($arguments, $context);
            $data['method'] = $method;
            $data['arguments'] = $arguments;
            return $data;
        });
        return $AOPRedisPoolCoroutine;
    }

    /**
     * 获取redis proxy
     * @param $redisProxy
     * @param Core $coreBase
     * @return Wrapper|\Redis
     */
    public static function getRedisProxy(IProxy $redisProxy, $coreBase)
    {
        $redis = new Wrapper($redisProxy);
        $redis->registerOnBefore(function ($method, $arguments) use ($redisProxy, $coreBase) {
            $context = $coreBase->getContext();
            array_unshift($arguments, $context);
            $data['method'] = $method;
            $data['arguments'] = $arguments;
            $data['result'] = $redisProxy->handle($method, $arguments);
            return $data;
        });

        return $redis;
    }

    /**
     * 获取对象池实例
     * @param Pool $pool
     * @param Core $coreBase
     * @return Wrapper|Pool
     */
    public static function getObjectPool(Pool $pool, $coreBase)
    {
        $AOPPool = new Wrapper($pool);

        $AOPPool->registerOnBefore(function ($method, $arguments) use ($coreBase) {
            if ($method === 'push') {
                //判断是否还返还对象：使用时间超过2小时或者使用次数大于10000则不返还，直接销毁
                method_exists($arguments[0], 'destroy') && $arguments[0]->destroy();
                $class = get_class($arguments[0]);
                if (!empty(self::$reflections[$class]) && method_exists($arguments[0], 'resetProperties')) {
                    $arguments[0]->resetProperties(self::$reflections[$class]);
                }
                if (($arguments[0]->genTime + 7200) < time() || $arguments[0]->useCount > 10000) {
                    $data['result'] = false;
                    unset($arguments[0]);
                }
            }
            $data['method'] = $method;
            $data['arguments'] = $arguments;
            return $data;
        });

        $AOPPool->registerOnAfter(function ($method, $arguments, $result) use ($coreBase) {
            //取得对象后放入请求内部bucket
            if ($method === 'get' && is_object($result)) {
                //使用次数+1
                $result->useCount++;
                $coreBase->objectPoolBuckets[] = $result;
                $result->context = $coreBase->getContext();
                $class = get_class($result);
                if (!isset(self::$reflections[$class])) {
                    $reflection = new \ReflectionClass($class);
                    $default = $reflection->getDefaultProperties();
                    $ps = $reflection->getProperties(\ReflectionProperty::IS_PRIVATE | \ReflectionProperty::IS_STATIC);
                    foreach ($ps as $val) {
                        unset($default[$val->getName()]);
                    }
                    self::$reflections[$class] = $default;
                }
            }
            $data['method'] = $method;
            $data['arguments'] = $arguments;
            $data['result'] = $result;
            return $data;
        });

        return $AOPPool;
    }
}
