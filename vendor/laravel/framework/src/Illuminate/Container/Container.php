<?php

namespace Illuminate\Container;

use Closure;
use ArrayAccess;
use LogicException;
use ReflectionClass;
use ReflectionParameter;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container as ContainerContract;

class Container implements ArrayAccess, ContainerContract
{
    /**
     * 容器实例
     * The current globally available container (if any).
     *
     * @var static
     */
    protected static $instance;

    /**
     * 记录哪些抽象标识符已经被解析过了
     * An array of the types that have been resolved.
     *
     * @var array
     */
    protected $resolved = [];

    /**
     * 容器中注册的绑定关系(将抽象标识符映射到具体的实现)
     * The container's bindings.
     *
     * @var array
     */
    protected $bindings = [];

    /**
     * 容器中的方法绑定
     * The container's method bindings.
     *
     * @var array
     */
    protected $methodBindings = [];

    /**
     * 保存已解析的单例，容器中的抽象标识符的共享实例(单例)
     * The container's shared instances.
     *
     * @var array
     */
    protected $instances = [];

    /**
     * 注册的别名
     * The registered type aliases.
     *
     * @var array
     */
    protected $aliases = [];

    /**
     * 以抽象标识符为键形式的 别名数组
     * The registered aliases keyed by the abstract name.
     *
     * @var array
     */
    protected $abstractAliases = [];

    /**
     * 实现的扩展回调
     * The extension closures for services.
     *
     * @var array
     */
    protected $extenders = [];

    /**
     * 所有的标签
     * All of the registered tags.
     *
     * @var array
     */
    protected $tags = [];

    /**
     * 含义todo
     * The stack of concretions currently being built.
     *
     * @var array
     */
    protected $buildStack = [];

    /**
     * 构建时的参数？含义todo
     * The parameter override stack.
     *
     * @var array
     */
    protected $with = [];

    /**
     * 上下文绑定信息
     * 当某个类在不同的上下文中需要不同的实现时，容器如何管理这些绑定。$contextual数组来存储这些信息
     * The contextual binding map.
     *
     * @var array
     */
    public $contextual = [];

    /**
     * 所有已注册的重新绑定回调，通知实现发生了变化，需要进行处理
     * All of the registered rebound callbacks.
     *
     * @var array
     */
    protected $reboundCallbacks = [];

    /**
     * 所有全局解析回调
     * All of the global resolving callbacks.
     *
     * @var array
     */
    protected $globalResolvingCallbacks = [];

    /**
     * 所有全局解析后的回调
     * All of the global after resolving callbacks.
     *
     * @var array
     */
    protected $globalAfterResolvingCallbacks = [];

    /**
     * 所有抽象标识符绑定的解析时的回调
     * All of the resolving callbacks by class type.
     *
     * @var array
     */
    protected $resolvingCallbacks = [];

    /**
     * 所有抽象标识符绑定的的解析后回调
     * All of the after resolving callbacks by class type.
     *
     * @var array
     */
    protected $afterResolvingCallbacks = [];

    /**
     * 定义一个上下文绑定
     * Define a contextual binding.
     *
     * @param  string  $concrete
     * @return \Illuminate\Contracts\Container\ContextualBindingBuilder
     */
    public function when($concrete)
    {
        return new ContextualBindingBuilder($this, $this->getAlias($concrete));
    }

    /**
     * 判断一个抽象标识符是否已经被被绑定了：
     *  bind()注册过、单例注册过、或者取别名过
     * Determine if the given abstract type has been bound.
     *
     * @param  string  $abstract
     * @return bool
     */
    public function bound($abstract)
    {
        return isset($this->bindings[$abstract]) ||
            isset($this->instances[$abstract]) ||
            $this->isAlias($abstract);
    }

    /**
     *  {@inheritdoc}
     */
    public function has($id)
    {
        return $this->bound($id);
    }

    /**
     * 判断一个抽象标识符是否已经被解析过了
     * Determine if the given abstract type has been resolved.
     *
     * @param  string  $abstract
     * @return bool
     */
    public function resolved($abstract)
    {
        //如果传入的值是一个别名，则递归地找到最终关联地抽象标识符
        if ($this->isAlias($abstract)) {
            $abstract = $this->getAlias($abstract);
        }

        //根据最终地抽象标识符，判断是否被解析过或者被注册实例过
        return isset($this->resolved[$abstract]) ||
            isset($this->instances[$abstract]);
    }

    /**
     * 判断一个抽象标识符是否绑定的是共享实例
     * Determine if a given type is shared.
     *
     * @param  string  $abstract
     * @return bool
     */
    public function isShared($abstract)
    {
        return isset($this->instances[$abstract]) ||
            (isset($this->bindings[$abstract]['shared']) &&
                $this->bindings[$abstract]['shared'] === true);
    }

    /**
     * 判断是否是一个别名
     * Determine if a given string is an alias.
     *
     * @param  string  $name
     * @return bool
     */
    public function isAlias($name)
    {
        return isset($this->aliases[$name]);
    }

    /**
     * 将抽象标识符和具体实现方式绑定，把绑定关系注册到容器内
     * Register a binding with the container.
     *
     * @param  string  $abstract
     * @param  \Closure|string|null  $concrete
     * @param  bool  $shared
     * @return void
     */
    public function bind($abstract, $concrete = null, $shared = false)
    {
        // If no concrete type was given, we will simply set the concrete type to the
        // abstract type. After that, the concrete type to be registered as shared
        // without being forced to state their classes in both of the parameters.
        //删除旧的实例和抽象标识符的别名
        $this->dropStaleInstances($abstract);

        //如果具体实现是空的，则具体实现为抽象标识符
        if (is_null($concrete)) {
            $concrete = $abstract;
        }

        // If the factory is not a Closure, it means it is just a class name which is
        // bound into this container to the abstract type and we will just wrap it
        // up inside its own Closure to give us more convenience when extending.
        /**
         * 如果具体实现不是闭包，则转换为闭包的形式
         *    如果实现===抽象标识符，return $this->build($concrete)
         *    如果实现!=抽象标识符,return $this->make($concrete,$parameters)
         */
        if (! $concrete instanceof Closure) {
            $concrete = $this->getClosure($abstract, $concrete);
        }
        //注册绑定关系到容器内
        $this->bindings[$abstract] = compact('concrete', 'shared');

        // If the abstract type was already resolved in this container we'll fire the
        // rebound listener so that any objects which have already gotten resolved
        // can have their copy of the object updated via the listener callbacks.
        /**
         * 如果抽象标识符已解析过(被解析或者被instance()实例化注册过)
         * 说明该抽象标识符之前已经被绑定过实现且被解析过，此时是在重新绑定覆盖已有的绑定，需要调用rebound来通知相关依赖需要更新
         * rebound函数(触发之前对抽象标识符关联的rebind回调函数，为了在重新绑定实现时，告诉使用该依赖的对象，需要更新)
         */
        if ($this->resolved($abstract)) {
            $this->rebound($abstract);
        }
    }

    /**
     * 获取一个构建抽象类型实现的闭包
     *  如果实现是抽象标识符自己，return $this->build($concrete)
     *  如果不是，return $this->make($concrete,$parameters)
     * Get the Closure to be used when building a type.
     *
     * @param  string  $abstract
     * @param  string  $concrete
     * @return \Closure
     */
    protected function getClosure($abstract, $concrete)
    {
        return function ($container, $parameters = []) use ($abstract, $concrete) {
            if ($abstract == $concrete) {
                return $container->build($concrete);
            }

            return $container->make($concrete, $parameters);
        };
    }

    /**
     * 判断容器中是否为一个方法绑定了回调函数(bindMethod函数中进行绑定)
     * Determine if the container has a method binding.
     *
     * @param  string  $method
     * @return bool
     */
    public function hasMethodBinding($method)
    {
        return isset($this->methodBindings[$method]);
    }

    /**
     * 绑定一个回调函数到某个方法(会在Container::call中使用到) todo
     * Bind a callback to resolve with Container::call.
     *
     * @param  array|string  $method
     * @param  \Closure  $callback
     * @return void
     */
    public function bindMethod($method, $callback)
    {
        $this->methodBindings[$this->parseBindMethod($method)] = $callback;
    }

    /**
     * 解析一个方法名称，返回class@method形式
     * Get the method to be bound in class@method format.
     *
     * @param  array|string $method
     * @return string
     */
    protected function parseBindMethod($method)
    {
        if (is_array($method)) {
            return $method[0].'@'.$method[1];
        }

        return $method;
    }

    /**
     * 调用方法绑定的方法
     * Get the method binding for the given method.
     *
     * @param  string  $method
     * @param  mixed  $instance
     * @return mixed
     */
    public function callMethodBinding($method, $instance)
    {
        return call_user_func($this->methodBindings[$method], $instance, $this);
    }

    /**
     * 添加一个上下文绑定
     * Add a contextual binding to the container.
     *
     * @param  string  $concrete
     * @param  string  $abstract
     * @param  \Closure|string  $implementation
     * @return void
     */
    public function addContextualBinding($concrete, $abstract, $implementation)
    {
        $this->contextual[$concrete][$this->getAlias($abstract)] = $implementation;
    }

    /**
     * 如果抽象标识符没有绑定，则进行绑定注册(bind)
     * Register a binding if it hasn't already been registered.
     *
     * @param  string  $abstract
     * @param  \Closure|string|null  $concrete
     * @param  bool  $shared
     * @return void
     */
    public function bindIf($abstract, $concrete = null, $shared = false)
    {
        if (! $this->bound($abstract)) {
            $this->bind($abstract, $concrete, $shared);
        }
    }

    /**
     * 以共享实例方式注册绑定
     * Register a shared binding in the container.
     *
     * @param  string  $abstract
     * @param  \Closure|string|null  $concrete
     * @return void
     */
    public function singleton($abstract, $concrete = null)
    {
        $this->bind($abstract, $concrete, true);
    }

    /**
     * 使用闭包对容器中的抽象标识符进行扩展，即对抽象标识符的具体实现进行扩展
     * "Extend" an abstract type in the container.
     *
     * @param  string    $abstract
     * @param  \Closure  $closure
     * @return void
     *
     * @throws \InvalidArgumentException
     */
    public function extend($abstract, Closure $closure)
    {
        $abstract = $this->getAlias($abstract);

        if (isset($this->instances[$abstract])) {
            $this->instances[$abstract] = $closure($this->instances[$abstract], $this);

            $this->rebound($abstract);
        } else {
            $this->extenders[$abstract][] = $closure;

            if ($this->resolved($abstract)) {
                $this->rebound($abstract);
            }
        }
    }

    /**
     *
     * 绑定一个已存在的实例到抽象标识符
     * Register an existing instance as shared in the container.
     *
     * @param  string  $abstract
     * @param  mixed   $instance
     * @return mixed
     */
    public function instance($abstract, $instance)
    {
        $this->removeAbstractAlias($abstract);

        $isBound = $this->bound($abstract);

        unset($this->aliases[$abstract]);

        // We'll check to determine if this type has been bound before, and if it has
        // we will fire the rebound callbacks registered with the container and it
        // can be updated with consuming classes that have gotten resolved here.
        $this->instances[$abstract] = $instance;

        if ($isBound) {
            $this->rebound($abstract);
        }

        return $instance;
    }

    /**
     * 移除别名，遍历所有抽象标识符的别名，删除指定的别名
     * Remove an alias from the contextual binding alias cache.
     *
     * @param  string  $searched
     * @return void
     */
    protected function removeAbstractAlias($searched)
    {
        if (! isset($this->aliases[$searched])) {
            return;
        }

        foreach ($this->abstractAliases as $abstract => $aliases) {
            foreach ($aliases as $index => $alias) {
                if ($alias == $searched) {
                    unset($this->abstractAliases[$abstract][$index]);
                }
            }
        }
    }

    /**
     * Assign a set of tags to a given binding.
     *
     * @param  array|string  $abstracts
     * @param  array|mixed   ...$tags
     * @return void
     */
    public function tag($abstracts, $tags)
    {
        $tags = is_array($tags) ? $tags : array_slice(func_get_args(), 1);

        foreach ($tags as $tag) {
            if (! isset($this->tags[$tag])) {
                $this->tags[$tag] = [];
            }

            foreach ((array) $abstracts as $abstract) {
                $this->tags[$tag][] = $abstract;
            }
        }
    }

    /**
     * Resolve all of the bindings for a given tag.
     *
     * @param  string  $tag
     * @return array
     */
    public function tagged($tag)
    {
        $results = [];

        if (isset($this->tags[$tag])) {
            foreach ($this->tags[$tag] as $abstract) {
                $results[] = $this->make($abstract);
            }
        }

        return $results;
    }

    /**
     * Alias a type to a different name.
     *
     * @param  string  $abstract
     * @param  string  $alias
     * @return void
     */
    public function alias($abstract, $alias)
    {
        $this->aliases[$alias] = $abstract;

        $this->abstractAliases[$abstract][] = $alias;
    }

    /**
     * 绑定一个rebind回调函数，会在抽象标识符被重新绑定时，触发调用。
     * 该回调函数接受参数：$app：容器实例, $instance：抽象标识符的具体实现实例
     * 使用场景：在开发过程中，替换某个服务的实现后，自动刷新相关依赖
     * 一般用法(比如在服务提供者中使用)：
     * $container->singleton('cache', function ($app) {
     *   return new CacheManager($app);
     * });
     *
     * $container->rebinding('cache', function ($container, $cache) {
     *   // 当缓存驱动被重新绑定时，重置相关服务
     *   $container->make('session')->setCache($cache);
     * });
     * Bind a new callback to an abstract's rebind event.
     *
     * @param  string    $abstract
     * @param  \Closure  $callback
     * @return mixed
     */
    public function rebinding($abstract, Closure $callback)
    {
        $this->reboundCallbacks[$abstract = $this->getAlias($abstract)][] = $callback;

        if ($this->bound($abstract)) {
            return $this->make($abstract);
        }
    }

    /**
     * Refresh an instance on the given target and method.
     *
     * @param  string  $abstract
     * @param  mixed   $target
     * @param  string  $method
     * @return mixed
     */
    public function refresh($abstract, $target, $method)
    {
        return $this->rebinding($abstract, function ($app, $instance) use ($target, $method) {
            $target->{$method}($instance);
        });
    }

    /**
     * 触发rebound回调
     * 当某个抽象（abstract）的绑定被重新注册时，会调用此函数触发与之关联的回调函数(rebinding函数注册的回调函数)，通常是用于更新依赖为该抽象标识符的新注册的具体实现的实例
     * Fire the "rebound" callbacks for the given abstract type.
     *
     * 使用场景：在开发过程中，替换某个服务的实现后，自动刷新相关依赖
     * 一般用法(比如在服务提供者中使用)：
     * $container->singleton('cache', function ($app) {
     *   return new CacheManager($app);
     * });
     *
     * $container->rebinding('cache', function ($container, $cache) {
     *   // 当缓存驱动cache被重新绑定时，更新session依赖的cache服务，通常session也是注册为单例，这样所有使用session对象的地方也都会得到更新
     *   $container->make('session')->setCache($cache);
     * });
     * @param  string  $abstract
     * @return void
     */
    protected function rebound($abstract)
    {
        //生成新绑定的实现的实例
        $instance = $this->make($abstract);
        //把新实例传给回调函数处理，通常用于更新依赖
        foreach ($this->getReboundCallbacks($abstract) as $callback) {
            call_user_func($callback, $this, $instance);
        }
    }

    /**
     * Get the rebound callbacks for a given type.
     *
     * @param  string  $abstract
     * @return array
     */
    protected function getReboundCallbacks($abstract)
    {
        if (isset($this->reboundCallbacks[$abstract])) {
            return $this->reboundCallbacks[$abstract];
        }

        return [];
    }

    /**
     * Wrap the given closure such that its dependencies will be injected when executed.
     *
     * @param  \Closure  $callback
     * @param  array  $parameters
     * @return \Closure
     */
    public function wrap(Closure $callback, array $parameters = [])
    {
        return function () use ($callback, $parameters) {
            return $this->call($callback, $parameters);
        };
    }

    /**
     * 调用一个闭包/class@method形式的方法，并且注入它的依赖
     * 比如：在Application中，注册完服务提供者后，会调用服务提供者的boot()函数，就是使用Container::call()方法来调用的，会自动注入boot函数需要的依赖
     * Call the given Closure / class@method and inject its dependencies.
     *
     * @param  callable|string  $callback
     * @param  array  $parameters
     * @param  string|null  $defaultMethod
     * @return mixed
     */
    public function call($callback, array $parameters = [], $defaultMethod = null)
    {
        return BoundMethod::call($this, $callback, $parameters, $defaultMethod);
    }

    /**
     * 获取一个闭包，即返回make函数，解析指定抽象标识符
     * Get a closure to resolve the given type from the container.
     *
     * @param  string  $abstract
     * @return \Closure
     */
    public function factory($abstract)
    {
        return function () use ($abstract) {
            return $this->make($abstract);
        };
    }

    /**
     * make函数的别名函数
     * An alias function name for make().
     *
     * @param  string  $abstract
     * @param  array  $parameters
     * @return mixed
     */
    public function makeWith($abstract, array $parameters = [])
    {
        return $this->make($abstract, $parameters);
    }

    /**
     * 解析指定抽象标识符，实际调用resolve函数
     * Resolve the given type from the container.
     *
     * @param  string  $abstract
     * @param  array  $parameters
     * @return mixed
     */
    public function make($abstract, array $parameters = [])
    {
        return $this->resolve($abstract, $parameters);
    }

    /**
     * 根据抽象标识符获取解析的实现实例，实现的是psr的容器接口
     *  {@inheritdoc}
     */
    public function get($id)
    {
        if ($this->has($id)) {
            return $this->resolve($id);
        }

        throw new EntryNotFoundException;
    }

    /**
     * 根据抽象标识符和参数，解析抽象标识符的实现
     * 1、先取出最终抽象标识符
     * 2、判断是否有上下文绑定
     * 3、如果存在抽象标识符的实例(绑定为共享实例，或绑定为单例) && 没有参数覆盖 && 没有上下文绑定，则返回缓存的实例
     * 4、如果不满足条件，则根据抽象标识符创建实例
     *    4.1、获取抽象标识符绑定的实现
     *    4.2、实例化实现
     *    4.3、判断是否进行了绑定扩展，如果有，则调用扩展函数
     *    4.4、如果注册为共享实例，则缓存实例
     *    4.5、调用resolving()和afterResolving()绑定的解析回调函数
     *    4.6、标识该抽象标识符为已解析
     * Resolve the given type from the container.
     *
     * @param  string  $abstract
     * @param  array  $parameters
     * @return mixed
     */
    protected function resolve($abstract, $parameters = [])
    {
        //获取别名绑定的最终抽象标识符
        $abstract = $this->getAlias($abstract);

        /**
         * 判断是否需要上下文参数构建
         *  1、判断是否参数为空
         *  2、判断是否添加过上下文绑定参数：addContextualBinding函数调用，实际是由ContextualBindingBuilder->give函数调用
         *  通常的添加方式是：$container->when()->needs()->give()
         */
        $needsContextualBuild = ! empty($parameters) || ! is_null(
                $this->getContextualConcrete($abstract)
            );

        // If an instance of the type is currently being managed as a singleton we'll
        // just return an existing instance instead of instantiating new instances
        // so the developer can keep using the same objects instance every time.
        /**
         * 如果抽象标识符绑定了单例，且不需要上下文参数构建，则直接返回单例实例
         */
        if (isset($this->instances[$abstract]) && ! $needsContextualBuild) {
            return $this->instances[$abstract];
        }

        $this->with[] = $parameters;

        /**
         * 获取抽象标识符绑定的实现:
         * 优先使用上下文绑定的实现  when($class)->needs($abstract)->give($implementation)，这里为抽象标识符abstract绑定了实现$implementation
         * 再判断如果没有上下文绑定，则使用bind()中对抽象标识符绑定的实现
         * 如果没有bind()则返回自己，即没有绑定到任何实现，自己就是具体的实现
         */
        $concrete = $this->getConcrete($abstract);

        // We're ready to instantiate an instance of the concrete type registered for
        // the binding. This will instantiate the types, as well as resolve any of
        // its "nested" dependencies recursively until all have gotten resolved.
        /**
         * 实例化抽象标识符对应的实现
         *  1、如果是可直接构建的，则调用build构建实例
         *      可直接构建：即实现是闭包，或者抽象标识符绑定的实现就是自己
         *  2、如果不是可直接构建的，继续递归，调用make构建实例
         */
        if ($this->isBuildable($concrete, $abstract)) {
            $object = $this->build($concrete);
        } else {
            $object = $this->make($concrete);
        }

        // If we defined any extenders for this type, we'll need to spin through them
        // and apply them to the object being built. This allows for the extension
        // of services, such as changing configuration or decorating the object.
        /**
         * 判断是否需要对实例后的对象进一步扩展：
         *  查看是否extend()注册了扩展闭包，如果有则调用闭包对对象进行扩展
         */
        foreach ($this->getExtenders($abstract) as $extender) {
            $object = $extender($object, $this);
        }

        // If the requested type is registered as a singleton we'll want to cache off
        // the instances in "memory" so we can return it later without creating an
        // entirely new instance of an object on each subsequent request for it.
        /**
         * 判断是否是共享单例模式(bind函数shared为true或者调用instance绑定单例实现)且没有需要上下文参数构建：
         *  如果是，则将实例单例化(缓存起来)
         */
        if ($this->isShared($abstract) && ! $needsContextualBuild) {
            $this->instances[$abstract] = $object;
        }

        //调用resolving()和afterResolving()绑定的解析回调函数
        $this->fireResolvingCallbacks($abstract, $object);

        // Before returning, we will also set the resolved flag to "true" and pop off
        // the parameter overrides for this build. After those two things are done
        // we will be ready to return back the fully constructed class instance.
        $this->resolved[$abstract] = true;

        array_pop($this->with);

        return $object;
    }

    /**
     * 获取抽象标识符绑定的实现：
     *  优先使用上下文绑定的实现  when($class)->needs($abstract)->give($implementation)，这里为抽象标识符abstract绑定了实现$implementation
     *  再判断如果没有上下文绑定，则使用bind()中对抽象标识符绑定的实现
     *  如果没有bind()则返回自己，即没有绑定到任何实现，自己就是具体的实现
     * Get the concrete type for a given abstract.
     *
     * @param  string  $abstract
     * @return mixed   $concrete
     */
    protected function getConcrete($abstract)
    {
        if (! is_null($concrete = $this->getContextualConcrete($abstract))) {
            return $concrete;
        }

        // If we don't have a registered resolver or concrete for the type, we'll just
        // assume each type is a concrete name and will attempt to resolve it as is
        // since the container should be able to resolve concretes automatically.
        if (isset($this->bindings[$abstract])) {
            return $this->bindings[$abstract]['concrete'];
        }

        return $abstract;
    }

    /**
     * 获取抽象标识符绑定的上下文绑定
     *  1、先直接获取抽象标识符的上下文绑定
     *  2、再获取抽象标识符关联的别名的上下文绑定
     * Get the contextual concrete binding for the given abstract.
     *
     * @param  string  $abstract
     * @return string|null
     */
    protected function getContextualConcrete($abstract)
    {
        if (! is_null($binding = $this->findInContextualBindings($abstract))) {
            return $binding;
        }

        // Next we need to see if a contextual binding might be bound under an alias of the
        // given abstract type. So, we will need to check if any aliases exist with this
        // type and then spin through them and check for contextual bindings on these.
        if (empty($this->abstractAliases[$abstract])) {
            return;
        }

        foreach ($this->abstractAliases[$abstract] as $alias) {
            if (! is_null($binding = $this->findInContextualBindings($alias))) {
                return $binding;
            }
        }
    }

    /**
     * Find the concrete binding for the given abstract in the contextual binding array.
     *
     * @param  string  $abstract
     * @return string|null
     */
    protected function findInContextualBindings($abstract)
    {
        if (isset($this->contextual[end($this->buildStack)][$abstract])) {
            return $this->contextual[end($this->buildStack)][$abstract];
        }
    }

    /**
     * Determine if the given concrete is buildable.
     *
     * @param  mixed   $concrete
     * @param  string  $abstract
     * @return bool
     */
    protected function isBuildable($concrete, $abstract)
    {
        return $concrete === $abstract || $concrete instanceof Closure;
    }

    /**
     * 根据实现，进行实例化
     * Instantiate a concrete instance of the given type.
     *
     * @param  string  $concrete
     * @return mixed
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function build($concrete)
    {
        // If the concrete type is actually a Closure, we will just execute it and
        // hand back the results of the functions, which allows functions to be
        // used as resolvers for more fine-tuned resolution of these objects.
        //如果实现是闭包，则调用闭包返回实例，传参通过getLastParameterOverride()获取，实际参数是在make()中传入的
        if ($concrete instanceof Closure) {
            return $concrete($this, $this->getLastParameterOverride());
        }

        //如果实现不是闭包，则通过反射进行实例化
        $reflector = new ReflectionClass($concrete);

        // If the type is not instantiable, the developer is attempting to resolve
        // an abstract type such as an Interface of Abstract Class and there is
        // no binding registered for the abstractions so we need to bail out.
        //如果不可实例化，则抛出异常
        if (! $reflector->isInstantiable()) {
            return $this->notInstantiable($concrete);
        }

        $this->buildStack[] = $concrete;
        //获取类的构造函数
        $constructor = $reflector->getConstructor();

        // If there are no constructors, that means there are no dependencies then
        // we can just resolve the instances of the objects right away, without
        // resolving any other types or dependencies out of these containers.
        //如果没有构造函数，则直接实例化对象，并返回
        if (is_null($constructor)) {
            array_pop($this->buildStack);

            return new $concrete;
        }
        //获取构造函数的参数列表
        $dependencies = $constructor->getParameters();

        // Once we have all the constructor's parameters we can create each of the
        // dependency instances and then use the reflection instances to make a
        // new instance of this class, injecting the created dependencies in.
        //解析依赖的所有参数
        $instances = $this->resolveDependencies(
            $dependencies
        );

        array_pop($this->buildStack);

        return $reflector->newInstanceArgs($instances);
    }

    /**
     * 解析反射获取的构造函数参数依赖，$dependencies是ReflectionParameter对象的数组
     * Resolve all of the dependencies from the ReflectionParameters.
     *
     * @param  array  $dependencies
     * @return array
     */
    protected function resolveDependencies(array $dependencies)
    {
        $results = [];

        foreach ($dependencies as $dependency) {
            // If this dependency has a override for this particular build we will use
            // that instead as the value. Otherwise, we will continue with this run
            // of resolutions and let reflection attempt to determine the result.
            //
            /**
             * 如果有参数覆盖，则使用覆盖值，参数覆盖是在make()函数中传入的$parameters参数
             * 比如：app()->make(AnotherService::class, ['age' => 18])，对age参数进行了参数覆盖
             */
            if ($this->hasParameterOverride($dependency)) {
                $results[] = $this->getParameterOverride($dependency);

                continue;
            }

            // If the class is null, it means the dependency is a string or some other
            // primitive type which we can not resolve since it is not a class and
            // we will just bomb out with an error since we have no-where to go.
            //
            /**
             * 如果参数是声明了类型的，则调用make进行解析生成对象
             * 如果参数没有声明类型，则返回上下文中绑定的该值，如果没有上下文绑定，则返回参数默认值，如果没有默认值抛出异常
             */
            $results[] = is_null($dependency->getClass())
                ? $this->resolvePrimitive($dependency)
                : $this->resolveClass($dependency);
        }

        return $results;
    }

    /**
     * Determine if the given dependency has a parameter override.
     *
     * @param  \ReflectionParameter  $dependency
     * @return bool
     */
    protected function hasParameterOverride($dependency)
    {
        return array_key_exists(
            $dependency->name, $this->getLastParameterOverride()
        );
    }

    /**
     * Get a parameter override for a dependency.
     *
     * @param  \ReflectionParameter  $dependency
     * @return mixed
     */
    protected function getParameterOverride($dependency)
    {
        return $this->getLastParameterOverride()[$dependency->name];
    }

    /**
     * Get the last parameter override.
     *
     * @return array
     */
    protected function getLastParameterOverride()
    {
        return count($this->with) ? end($this->with) : [];
    }

    /**
     * 解析一个非类依赖
     *  如果变量名在上下文绑定中有，则返回上下文绑定中声明的值
     *  如果变量名在上下文绑定中没有，则根据参数的默认值进行实例化，如果参数没有默认值，则抛出异常
     * Resolve a non-class hinted primitive dependency.
     *
     * @param  \ReflectionParameter  $parameter
     * @return mixed
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    protected function resolvePrimitive(ReflectionParameter $parameter)
    {
        /**
         * 如果参数在上下文绑定中有，则返回上下文绑定中声明的值或者闭包结果
         * 比如：
         * AnotherService的构造函数为
         * public function __construct($config)
         *   {
         *   $this->config = $config;
         *   }
         *进行了上下文绑定 $config参数为['key' => 'value']
         * $container->when('App\Services\AnotherService')
         *   ->needs('$config')
         *   ->give(['key' => 'value']);
         * 则resolvePrimitive方法返回的是['key' => 'value']
         *
         *进行了上下文绑定 $config参数为闭包
         * $container->when('App\Services\AnotherService')
         *   ->needs('$config')
         *   ->give(function ($container) {
         *       return 'USD';
         *   });
         * 则resolvePrimitive方法返回的是['key' => 'value']
         */
        if (! is_null($concrete = $this->getContextualConcrete('$'.$parameter->name))) {
            return $concrete instanceof Closure ? $concrete($this) : $concrete;
        }

        //如果参数在上下文绑定中没有，则根据参数的默认值进行实例化，如果参数没有默认值，则抛出异常
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }
        //抛出异常
        $this->unresolvablePrimitive($parameter);
    }

    /**
     * Resolve a class based dependency from the container.
     *
     * @param  \ReflectionParameter  $parameter
     * @return mixed
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    protected function resolveClass(ReflectionParameter $parameter)
    {
        try {
            return $this->make($parameter->getClass()->name);
        }

            // If we can not resolve the class instance, we will check to see if the value
            // is optional, and if it is we will return the optional parameter value as
            // the value of the dependency, similarly to how we do this with scalars.
        catch (BindingResolutionException $e) {
            if ($parameter->isOptional()) {
                return $parameter->getDefaultValue();
            }

            throw $e;
        }
    }

    /**
     * 抛出一个不可实例化异常
     * Throw an exception that the concrete is not instantiable.
     *
     * @param  string  $concrete
     * @return void
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    protected function notInstantiable($concrete)
    {
        if (! empty($this->buildStack)) {
            $previous = implode(', ', $this->buildStack);

            $message = "Target [$concrete] is not instantiable while building [$previous].";
        } else {
            $message = "Target [$concrete] is not instantiable.";
        }

        throw new BindingResolutionException($message);
    }

    /**
     * Throw an exception for an unresolvable primitive.
     *
     * @param  \ReflectionParameter  $parameter
     * @return void
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    protected function unresolvablePrimitive(ReflectionParameter $parameter)
    {
        $message = "Unresolvable dependency resolving [$parameter] in class {$parameter->getDeclaringClass()->getName()}";

        throw new BindingResolutionException($message);
    }

    /**
     * 注册解析时回调函数，会在resolve()函数中调用注册的回调函数
     * Register a new resolving callback.
     *
     * @param  \Closure|string  $abstract
     * @param  \Closure|null  $callback
     * @return void
     */
    public function resolving($abstract, Closure $callback = null)
    {
        if (is_string($abstract)) {
            $abstract = $this->getAlias($abstract);
        }

        if (is_null($callback) && $abstract instanceof Closure) {
            $this->globalResolvingCallbacks[] = $abstract;
        } else {
            $this->resolvingCallbacks[$abstract][] = $callback;
        }
    }

    /**
     * 注册解析后回调，会在resolve()函数中调用注册的回调函数
     * Register a new after resolving callback for all types.
     *
     * @param  \Closure|string  $abstract
     * @param  \Closure|null  $callback
     * @return void
     */
    public function afterResolving($abstract, Closure $callback = null)
    {
        if (is_string($abstract)) {
            $abstract = $this->getAlias($abstract);
        }

        if ($abstract instanceof Closure && is_null($callback)) {
            $this->globalAfterResolvingCallbacks[] = $abstract;
        } else {
            $this->afterResolvingCallbacks[$abstract][] = $callback;
        }
    }

    /**
     * 调用所有的解析回调函数
     * Fire all of the resolving callbacks.
     *
     * @param  string  $abstract
     * @param  mixed   $object
     * @return void
     */
    protected function fireResolvingCallbacks($abstract, $object)
    {
        //调用全局解析回调函数，resolving()函数注册
        $this->fireCallbackArray($object, $this->globalResolvingCallbacks);
        //调用抽象标识符的解析回调函数，resolving()函数注册
        $this->fireCallbackArray(
            $object, $this->getCallbacksForType($abstract, $object, $this->resolvingCallbacks)
        );

        $this->fireAfterResolvingCallbacks($abstract, $object);
    }

    /**
     * Fire all of the after resolving callbacks.
     *
     * @param  string  $abstract
     * @param  mixed   $object
     * @return void
     */
    protected function fireAfterResolvingCallbacks($abstract, $object)
    {
        //调用全局解析后回调函数
        $this->fireCallbackArray($object, $this->globalAfterResolvingCallbacks);
        //调用抽象标识符的解析后回调函数，afterResolving()函数注册
        $this->fireCallbackArray(
            $object, $this->getCallbacksForType($abstract, $object, $this->afterResolvingCallbacks)
        );
    }

    /**
     * Get all callbacks for a given type.
     *
     * @param  string  $abstract
     * @param  object  $object
     * @param  array   $callbacksPerType
     *
     * @return array
     */
    protected function getCallbacksForType($abstract, $object, array $callbacksPerType)
    {
        $results = [];

        foreach ($callbacksPerType as $type => $callbacks) {
            if ($type === $abstract || $object instanceof $type) {
                $results = array_merge($results, $callbacks);
            }
        }

        return $results;
    }

    /**
     * 调用回调函数
     * Fire an array of callbacks with an object.
     *
     * @param  mixed  $object
     * @param  array  $callbacks
     * @return void
     */
    protected function fireCallbackArray($object, array $callbacks)
    {
        foreach ($callbacks as $callback) {
            $callback($object, $this);
        }
    }

    /**
     * 获取容器中注册的所有绑定关系
     * Get the container's bindings.
     *
     * @return array
     */
    public function getBindings()
    {
        return $this->bindings;
    }

    /**
     * 返回别名绑定的最终抽象标识符(存在多级别名的情况，会一直递归取最终的抽象标识符)
     * Get the alias for an abstract if available.
     *
     * @param  string  $abstract
     * @return string
     *
     * @throws \LogicException
     */
    public function getAlias($abstract)
    {
        if (! isset($this->aliases[$abstract])) {
            return $abstract;
        }

        if ($this->aliases[$abstract] === $abstract) {
            throw new LogicException("[{$abstract}] is aliased to itself.");
        }

        return $this->getAlias($this->aliases[$abstract]);
    }

    /**
     * 获取在extend()中注册扩展闭包函数
     * Get the extender callbacks for a given type.
     *
     * @param  string  $abstract
     * @return array
     */
    protected function getExtenders($abstract)
    {
        $abstract = $this->getAlias($abstract);

        if (isset($this->extenders[$abstract])) {
            return $this->extenders[$abstract];
        }

        return [];
    }

    /**
     * 移除抽象标识符注册的扩展闭包函数
     * Remove all of the extender callbacks for a given type.
     *
     * @param  string  $abstract
     * @return void
     */
    public function forgetExtenders($abstract)
    {
        unset($this->extenders[$this->getAlias($abstract)]);
    }

    /**
     * 删除抽象标识符的所有实例和别名
     * Drop all of the stale instances and aliases.
     *
     * @param  string  $abstract
     * @return void
     */
    protected function dropStaleInstances($abstract)
    {
        unset($this->instances[$abstract], $this->aliases[$abstract]);
    }

    /**
     * 移除抽象标识符的共享实例
     * Remove a resolved instance from the instance cache.
     *
     * @param  string  $abstract
     * @return void
     */
    public function forgetInstance($abstract)
    {
        unset($this->instances[$abstract]);
    }

    /**
     * 移除容器中所有共享实例
     * Clear all of the instances from the container.
     *
     * @return void
     */
    public function forgetInstances()
    {
        $this->instances = [];
    }

    /**
     * 移除容器中所有的绑定关系、别名、解析激励、共享实例
     * Flush the container of all bindings and resolved instances.
     *
     * @return void
     */
    public function flush()
    {
        $this->aliases = [];
        $this->resolved = [];
        $this->bindings = [];
        $this->instances = [];
        $this->abstractAliases = [];
    }

    /**
     * 返回容器实例
     * Set the globally available instance of the container.
     *
     * @return static
     */
    public static function getInstance()
    {
        if (is_null(static::$instance)) {
            static::$instance = new static;
        }

        return static::$instance;
    }

    /**
     * 设置容器实例
     * Set the shared instance of the container.
     *
     * @param  \Illuminate\Contracts\Container\Container|null  $container
     * @return \Illuminate\Contracts\Container\Container|static
     */
    public static function setInstance(ContainerContract $container = null)
    {
        return static::$instance = $container;
    }

    /**
     * 实现ArrayAccess，判断是否绑定到容器内(别名、抽象标识符都算)
     * Determine if a given offset exists.
     *
     * @param  string  $key
     * @return bool
     */
    public function offsetExists($key)
    {
        return $this->bound($key);
    }

    /**
     * 实现ArrayAccess，根据标识符获取实例
     * Get the value at a given offset.
     *
     * @param  string  $key
     * @return mixed
     */
    public function offsetGet($key)
    {
        return $this->make($key);
    }

    /**
     * 实现ArrayAccess，调用bind()，注册绑定关系
     * Set the value at a given offset.
     *
     * @param  string  $key
     * @param  mixed   $value
     * @return void
     */
    public function offsetSet($key, $value)
    {
        $this->bind($key, $value instanceof Closure ? $value : function () use ($value) {
            return $value;
        });
    }

    /**
     * 实现ArrayAccess，删除绑定关系、实例、解析记录
     * Unset the value at a given offset.
     *
     * @param  string  $key
     * @return void
     */
    public function offsetUnset($key)
    {
        unset($this->bindings[$key], $this->instances[$key], $this->resolved[$key]);
    }

    /**
     * Dynamically access container services.
     *
     * @param  string  $key
     * @return mixed
     */
    public function __get($key)
    {
        return $this[$key];
    }

    /**
     * Dynamically set container services.
     *
     * @param  string  $key
     * @param  mixed   $value
     * @return void
     */
    public function __set($key, $value)
    {
        $this[$key] = $value;
    }
}
