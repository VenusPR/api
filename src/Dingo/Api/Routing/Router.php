<?php namespace Dingo\Api\Routing;

use Closure;
use RuntimeException;
use BadMethodCallException;
use Illuminate\Http\Request;
use Dingo\Api\Http\Response;
use Dingo\Api\ExceptionHandler;
use Dingo\Api\Http\InternalRequest;
use Dingo\Api\Exception\ResourceException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class Router extends \Illuminate\Routing\Router {

	/**
	 * API collections.
	 * 
	 * @param array
	 */
	protected $apiCollections = [];

	/**
	 * Indicates if newly added routes are API routes.
	 * 
	 * @var bool
	 */
	protected $apiRouting = false;

	/**
	 * The default API version.
	 * 
	 * @var string
	 */
	protected $defaultApiVersion;

	/**
	 * The API vendor.
	 * 
	 * @var string
	 */
	protected $apiVendor;

	/**
	 * Exception handler instance.
	 * 
	 * @var \Dingo\Api\ExceptionHandler
	 */
	protected $exceptionHandler;

	/**
	 * Register an API group.
	 * 
	 * @param  array  $options
	 * @param  \Closure  $callback
	 * @return void
	 */
	public function api($options, Closure $callback)
	{
		if ( ! isset($options['version']))
		{
			throw new BadMethodCallException('Unable to register API without an API version.');
		}

		$this->enableApiRouting();

		// Once we have the version from the options we'll check to see if an API
		// collection already exists for this verison. If it doesn't then we'll
		// create a new collection.
		$version = $options['version'];

		if ( ! isset($this->apiCollections[$version]))
		{
			$this->apiCollections[$version] = $this->newApiCollection($version, array_except($options, ['version']));
		}
		
		$this->group($options, $callback);

		$this->disableApiRouting();
	}

	/**
	 * Instantiate a new \Dingo\Api\Routing\ApiCollection instance.
	 * 
	 * @return \Dingo\Api\Routing\ApiCollection
	 */
	protected function newApiCollection($version, array $options)
	{
		return new ApiCollection($version, $options);
	}

	/**
	 * Dispatch the request to the application and return either a regular response
	 * or an API response.
	 * 
	 * @param  \Illuminate\Http\Request  $request
	 * @return \Illuminate\Http\Response|\Dingo\Api\Http\Response
	 */
	public function dispatch(Request $request)
	{
		try
		{
			$response = parent::dispatch($request);
		}
		catch (HttpExceptionInterface $exception)
		{
			// If an exception is caught and we are currently routing an API request then
			// we'll handle this exception by building a new response from it. This
			// allows the API to gracefully handle its own exceptions.
			if (($this->routingForApi() or $this->requestTargettingApi($request)) and ! $request instanceof InternalRequest)
			{
				$response = $this->handleException($exception);
			}

			// If the request was an internal request then we will rethrow the exception
			// so that developers can easily catch them and adjust ther esponse
			// themselves. We also disable API routing so that the parent
			// request isn't treated as an API request.
			else
			{
				$this->disableApiRouting();

				throw $exception;
			}
		}

		if ($this->routingForApi() or $this->requestTargettingApi($request))
		{
			$response = Response::makeFromExisting($response)->morph();

			// If the request that was dispatched is an internal request then we need to
			// disable the API routing so that the parent request is not treated in
			// the same way. This prevents it from generating an Api Response for
			// the parent request. Another internal request will still result
			// in API routing being enabled.
			if ($request instanceof InternalRequest)
			{
				$this->disableApiRouting();
			}
		}

		return $response;
	}

	/**
	 * Handle exception thrown when dispatching a request.
	 * 
	 * @param  \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface  $exception
	 * @return \Dingo\Api\Http\Response
	 */
	public function handleException(HttpExceptionInterface $exception)
	{
		// If the exception handler will handle the given exception then we'll fire
		// the callback registered to the handler and return the response.
		if ($this->exceptionHandler->willHandle($exception))
		{
			$response = $this->exceptionHandler->handle($exception);

			return Response::makeFromExisting($response);
		}

		if ( ! $message = $exception->getMessage())
		{
			$message = sprintf('%d %s', $exception->getStatusCode(), Response::$statusTexts[$exception->getStatusCode()]);
		}

		if ($exception instanceof ResourceException)
		{
			$message = ['message' => $message];

			if ($exception->hasErrors()) $message['errors'] = $exception->errors();
		}

		return new Response($message, $exception->getStatusCode());
	}

	/**
	 * Add a new route to either the routers collection or the API collection.
	 * 
	 * @param  array|string  $methods
	 * @param  string  $uri
	 * @param  \Closure|array|string  $action
	 * @return \Illuminate\Routing\Route
	 */
	protected function addRoute($methods, $uri, $action)
	{
		$route = $this->createRoute($methods, $uri, $action);

		if ($this->routingForApi())
		{
			$route->before('api');

			$version = array_get(last($this->groupStack), 'version', '');

			// Since the groups action gets merged with the routes we need to make
			// sure that if the route supplied its own protection that we grab
			// that protection status from the array after the merge.
			$action = $route->getAction();

			if (count($this->groupStack) > 0 and isset($action['protected']))
			{
				$action['protected'] = is_array($action['protected']) ? last($action['protected']) : $action['protected'];

				$route->setAction($action);
			}

			return $this->getApiCollection($version)->add($route);
		}

		return $this->routes->add($route);
	}

	/**
	 * Create a new route instance.
	 *
	 * @param  array|string  $methods
	 * @param  string  $uri
	 * @param  mixed   $action
	 * @return \Illuminate\Routing\Route
	 */
	protected function createRoute($methods, $uri, $action)
	{
		$route = parent::createRoute($methods, $uri, $action);

		// If we are routing for the API and routing to a controller we'll check to
		// see if the controller is one of the API controllers. If it is then we
		// need to check if the method is protected and get any scopes
		// associated with the method.
		if ($this->routingForApi() and $this->routingToController($action))
		{
			list ($class, $method) = explode('@', $route->getActionName());

			$controller = $this->container->make($class);

			if ($controller instanceof \Dingo\Api\Routing\Controller)
			{
				$route = $this->controllerMethodProtected($route, $controller, $method);

				$route = $this->controllerMethodScopes($route, $controller, $method);
			}
		}

		return $route;
	}

	/**
	 * Adjust the scopes of a controller method. Scopes defined
	 * on the controller are merged with those defined
	 * in the route definition.
	 * 
	 * @param  \Illuminate\Routing\Route  $route
	 * @param  \Dingo\Api\Routing\Controller  $controller
	 * @param  string  $method
	 * @return \Illuminate\Routing\Route
	 */
	protected function controllerMethodScopes($route, $controller, $method)
	{
		$action = $route->getAction();

		if ( ! isset($action['scopes']))
		{
			$action['scopes'] = [];
		}

		$action['scopes'] = (array) $action['scopes'];

		$scopedMethods = $controller->getScopedMethods();

		// A wildcard can be used to attach scopes to all controller methods so
		// we'll merge any scopes here
		if (isset($scopedMethods['*']))
		{
			$action['scopes'] = array_merge($action['scopes'], $scopedMethods['*']);
		}

		if (isset($scopedMethods[$method]))
		{
			$action['scopes'] = array_merge($action['scopes'], $scopedMethods[$method]);
		}

		$route->setAction($action);

		return $route;
	}

	/**
	 * Adjust the protected state of a controller method.
	 * 
	 * @param  \Illuminate\Routing\Route  $route
	 * @param  \Dingo\Api\Routing\Controller  $controller
	 * @param  string  $method
	 * @return \Illuminate\Routing\Route
	 */
	protected function controllerMethodProtected($route, $controller, $method)
	{
		$action = $route->getAction();

		if (in_array($method, $controller->getProtectedMethods()))
		{
			$action['protected'] = true;
		}
		elseif (in_array($method, $controller->getUnprotectedMethods()))
		{
			$action['protected'] = false;
		}

		$route->setAction($action);

		return $route;
	}

	/**
	 * Find a route either from the routers collection or the API collection.
	 * 
	 * @param  \Illuminate\Http\Request  $request
	 * @return \Illuminate\Routing\Route
	 */
	protected function findRoute($request)
	{
		if ($this->routingForApi() or $this->requestTargettingApi($request))
		{
			$version = $this->getRequestVersion($request);

			try
			{
				$this->current = $route = $this->getApiCollection($version)->match($request);

				return $this->substituteBindings($route);
			}
			catch (NotFoundHttpException $exception)
			{
				// We won't do anything with the exception, we'll just gracefully fallback
				// to the default route collection to see if there's a match there.
			}
		}

		return parent::findRoute($request);
	}

	/**
	 * Determine if the current request is targetting an API.
	 * 
	 * @param  \Illuminate\Http\Request  $request
	 * @return bool
	 */
	public function requestTargettingApi($request)
	{
		if (empty($this->apiCollections)) return false;

		return array_first($this->apiCollections, function($key, $value) use ($request)
		{
			return $value->matches($request);
		}, false);
	}

	/**
	 * Get the version from the request.
	 * 
	 * @param  \Illuminate\Http\Request  $request
	 * @return string
	 */
	protected function getRequestVersion($request)
	{
		if (preg_match('#application/vnd\.'.$this->apiVendor.'.(v\d)\+(json)#', $request->header('accept'), $matches))
		{
			list ($accept, $version, $format) = $matches;

			return $version;
		}

		return $this->defaultApiVersion;
	}

	/**
	 * Get an API collection for a given version.
	 * 
	 * @param  string  $version
	 * @return \Dingo\Api\Routing\ApiCollection
	 */
	public function getApiCollection($version)
	{
		if ( ! isset($this->apiCollections[$version]))
		{
			throw new RuntimeException('There is no API collection for the version "'.$version.'".');
		}

		return $this->apiCollections[$version];
	}

	/**
	 * Determine if the current request is an API request.
	 * 
	 * @return bool
	 */
	public function routingForApi()
	{
		return $this->apiRouting;
	}

	/**
	 * Enable API request.
	 * 
	 * @return \Dingo\Api\Routing\Router
	 */
	public function enableApiRouting()
	{
		$this->apiRouting = true;

		return $this;
	}

	/**
	 * Disable API request.
	 * 
	 * @return \Dingo\Api\Routing\Router
	 */
	public function disableApiRouting()
	{
		$this->apiRouting = false;

		return $this;
	}

	/**
	 * Set the exception handler instance.
	 * 
	 * @param  \Dingo\Api\ExceptionHandler
	 * @return void
	 */
	public function setExceptionHandler(ExceptionHandler $exceptionHandler)
	{
		$this->exceptionHandler = $exceptionHandler;
	}

	/**
	 * Get the exception handler instance.
	 * 
	 * @return \Dingo\Api\ExceptionHandler
	 */
	public function getExceptionHandler()
	{
		return $this->exceptionHandler;
	}

	/**
	 * Set the default API version.
	 * 
	 * @param  string  $defaultApiVersion
	 * @return void
	 */
	public function setDefaultApiVersion($defaultApiVersion)
	{
		$this->defaultApiVersion = $defaultApiVersion;
	}

	/**
	 * Get the default API version.
	 * 
	 * @return string
	 */
	public function getDefaultApiVersion()
	{
		return $this->defaultApiVersion;
	}

	/**
	 * Set the API vendor.
	 * 
	 * @param  string  $apiVendor
	 * @return void
	 */
	public function setApiVendor($apiVendor)
	{
		$this->apiVendor = $apiVendor;
	}

	/**
	 * Get the API vendor.
	 * 
	 * @return string
	 */
	public function getApiVendor()
	{
		return $this->apiVendor;
	}

	/**
	 * Get a controller inspector instance.
	 *
	 * @return \Dingo\Api\Routing\ControllerInspector
	 */
	public function getInspector()
	{
		return $this->inspector ?: $this->inspector = new ControllerInspector;
	}

}