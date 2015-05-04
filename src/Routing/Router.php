<?php

namespace Dingo\Api\Routing;

use Closure;
use RuntimeException;
use Dingo\Api\Http\Request;
use Dingo\Api\Http\Response;
use Dingo\Api\Http\ResponseBuilder;
use Illuminate\Container\Container;
use Dingo\Api\Http\Parser\AcceptParser;
use Dingo\Api\Routing\Adapter\AdapterInterface;
use Laravel\Lumen\Application as LumenApplication;
use Illuminate\Http\Response as IlluminateResponse;

class Router
{
    /**
     * Routing adapter instance.
     *
     * @var \Dingo\Api\Routing\Adapter\AdapterInterface
     */
    protected $adapter;

    /**
     * Accept parser instance.
     *
     * @var \Dingo\Api\Http\Parser\AcceptParser
     */
    protected $accept;

    /**
     * Application container instance.
     *
     * @var \Illuminate\Container\Container
     */
    protected $container;

    /**
     * Group stack array.
     *
     * @var array
     */
    protected $groupStack = [];

    /**
     * Indicates if the request is conditional.
     *
     * @var bool
     */
    protected $conditionalRequest;

    /**
     * Create a new router instance.
     *
     * @param \Dingo\Api\Routing\Adapter\AdapterInterface $adapter
     * @param \Dingo\Api\Http\Parser\AcceptParser         $accept
     * @param \Illuminate\Container\Container             $container
     *
     * @return void
     */
    public function __construct(AdapterInterface $adapter, AcceptParser $accept, Container $container)
    {
        $this->adapter = $adapter;
        $this->accept = $accept;
        $this->container = $container;
    }

    /**
     * An alias for calling the group method, allows a more fluent API
     * for registering a new API version group with optional
     * attributes and a required callback.
     *
     * This method can be called without the third parameter, however,
     * the callback should always be the last paramter.
     *
     * @param string         $version
     * @param array|callable $second
     * @param callable       $third
     *
     * @return void
     */
    public function version($version, $second, $third = null)
    {
        if (func_num_args() == 2) {
            list($version, $callback, $attributes) = array_merge(func_get_args(), [[]]);
        } else {
            list($version, $attributes, $callback) = func_get_args();
        }

        $attributes = array_merge($attributes, ['version' => $version]);

        $this->group($attributes, $callback);
    }

    /**
     * Create a new route group.
     *
     * @param array    $attributes
     * @param callable $callback
     *
     * @return void
     */
    public function group(array $attributes, $callback)
    {
        $attributes = $this->mergeLastGroupAttributes($attributes);

        if (! isset($attributes['version'])) {
            throw new RuntimeException('A version is required for an API group definition.');
        } else {
            $attributes['version'] = (array) $attributes['version'];
        }

        $this->groupStack[] = $attributes;

        call_user_func($callback, $this);

        array_pop($this->groupStack);
    }

    /**
     * Create a new GET route.
     *
     * @param string                $uri
     * @param array|string|callable $action
     *
     * @return mixed
     */
    public function get($uri, $action)
    {
        return $this->addRoute(['GET', 'HEAD'], $uri, $action);
    }

    /**
     * Create a new POST route.
     *
     * @param string                $uri
     * @param array|string|callable $action
     *
     * @return mixed
     */
    public function post($uri, $action)
    {
        return $this->addRoute('POST', $uri, $action);
    }

    /**
     * Create a new PUT route.
     *
     * @param string                $uri
     * @param array|string|callable $action
     *
     * @return mixed
     */
    public function put($uri, $action)
    {
        return $this->addRoute('PUT', $uri, $action);
    }

    /**
     * Create a new PATCH route.
     *
     * @param string                $uri
     * @param array|string|callable $action
     *
     * @return mixed
     */
    public function patch($uri, $action)
    {
        return $this->addRoute('PATCH', $uri, $action);
    }

    /**
     * Create a new DELETE route.
     *
     * @param string                $uri
     * @param array|string|callable $action
     *
     * @return mixed
     */
    public function delete($uri, $action)
    {
        return $this->addRoute('DELETE', $uri, $action);
    }

    /**
     * Create a new OPTIONS route.
     *
     * @param string                $uri
     * @param array|string|callable $action
     *
     * @return mixed
     */
    public function options($uri, $action)
    {
        return $this->addRoute('OPTIONS', $uri, $action);
    }

    /**
     * Add a route to the routing adapter.
     *
     * @param string|array          $methods
     * @param string                $uri
     * @param string|array|callable $action
     *
     * @return mixed
     */
    public function addRoute($methods, $uri, $action)
    {
        if (is_string($action)) {
            $action = ['uses' => $action];
        } elseif ($action instanceof Closure) {
            $action = [$action];
        }

        $action = $this->mergeLastGroupAttributes($action);

        $uri = $uri === '/' ? $uri : '/'.trim($uri, '/');

        // To trick the container router into thinking the route exists we'll
        // need to register a dummy action with the router. This ensures
        // that the router processes the middleware and allows the API
        // router to be booted and used as the dispatcher.
        $this->registerRouteWithContainerRouter($methods, $uri, null);

        return $this->adapter->addRoute((array) $methods, $action['version'], $uri, $action);
    }

    /**
     * Merge the last groups attributes.
     *
     * @param array $attributes
     *
     * @return array
     */
    protected function mergeLastGroupAttributes(array $attributes)
    {
        if (empty($this->groupStack)) {
            return $attributes;
        }

        return $this->mergeGroup($attributes, end($this->groupStack));
    }

    /**
     * Merge the given group attributes.
     *
     * @param array $new
     * @param array $old
     *
     * @return array
     */
    protected function mergeGroup(array $new, array $old)
    {
        $new['namespace'] = $this->formatNamespace($new, $old);

        $new['prefix'] = $this->formatPrefix($new, $old);

        if (isset($new['domain'])) {
            unset($old['domain']);
        }

        if (isset($new['version'])) {
            unset($old['version']);
        }

        if (isset($new['uses'])) {
            $new['uses'] = $this->formatUses($new);
        }

        $new['where'] = array_merge(array_get($old, 'where', []), array_get($new, 'where', []));

        return array_merge_recursive(array_except($old, array('namespace', 'prefix', 'where')), $new);
    }

    /**
     * Format the uses key in a route action.
     *
     * @param array $new
     *
     * @return string
     */
    protected function formatUses(array $new)
    {
        if (isset($new['namespace']) && is_string($new['uses']) && strpos($new['uses'], '\\') === false) {
            return $new['namespace'].'\\'.$new['uses'];
        }

        return $new['uses'];
    }

    /**
     * Format the namespace for the new group attributes.
     *
     * @param array $new
     * @param array $old
     *
     * @return string
     */
    protected function formatNamespace(array $new, array $old)
    {
        if (isset($new['namespace']) && isset($old['namespace'])) {
            return trim($old['namespace'], '\\').'\\'.trim($new['namespace'], '\\');
        } elseif (isset($new['namespace'])) {
            return trim($new['namespace'], '\\');
        }

        return array_get($old, 'namespace');
    }

    /**
     * Format the prefix for the new group attributes.
     *
     * @param  array  $new
     * @param  array  $old
     * @return string
     */
    protected function formatPrefix($new, $old)
    {
        if (isset($new['prefix'])) {
            return trim(array_get($old, 'prefix'), '/').'/'.trim($new['prefix'], '/');
        }

        return array_get($old, 'prefix');
    }

    /**
     * Register a route with the container router.
     *
     * @param string|array          $methods
     * @param string                $uri
     * @param string|array|callable $action
     *
     * @return void
     */
    protected function registerRouteWithContainerRouter($methods, $uri, $action)
    {
        $router = ($this->container instanceof LumenApplication) ? $this->container : $this->container['router'];

        foreach ((array) $methods as $method) {
            if ($method != 'HEAD') {
                $this->container->{$method}($uri, $action);
            }
        }
    }

    /**
     * Dispatch a request via the adapter.
     *
     * @param \Dingo\Api\Http\Request $request
     *
     * @return \Dingo\Api\Http\Response
     */
    public function dispatch(Request $request)
    {
        $accept = $this->accept->parse($request);

        return $this->prepareResponse(
            $this->adapter->dispatch($request, $accept['version']),
            $accept['format']
        );
    }

    /**
     * Prepare a response by transforming and formatting it correctly.
     *
     * @param \Illuminate\Http\Response $response
     * @param string                    $format
     *
     * @return \Dingo\Api\Http\Response
     */
    protected function prepareResponse(IlluminateResponse $response, $format)
    {
        if ($response instanceof ResponseBuilder) {
            $response = $response->build();
        } elseif (! $response instanceof Response) {
            $response = Response::makeFromExisting($response);
        }

        $response = $response->morph($format);

        if ($response->isSuccessful() && $this->requestIsConditional()) {
            if (! $response->headers->has('ETag')) {
                $response->setEtag(md5($response->getContent()));
            }

            $response->isNotModified($request);
        }

        return $response;
    }

    /**
     * Determine if the request is conditional.
     *
     * @return bool
     */
    protected function requestIsConditional()
    {
        return $this->conditionalRequest;
    }

    /**
     * Set the conditional request.
     *
     * @param bool $conditionalRequest
     *
     * @return void
     */
    public function setConditionalRequest($conditionalRequest)
    {
        $this->conditionalRequest = $conditionalRequest;
    }
}
