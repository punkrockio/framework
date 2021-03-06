<?php

namespace Arc\Http;

use Arc\Application;
use Arc\Exceptions\Handler;
use Arc\Routing\Router;
use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Pipeline\Pipeline;
use Symfony\Component\Debug\Exception\FatalThrowableError;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class Kernel implements KernelContract
{
    /**
     * The application implementation.
     *
     * @var \Illuminate\Contracts\Foundation\Application
     */
    protected $app;

    /**
     * The router instance.
     *
     * @var \Illuminate\Routing\Router
     */
    protected $router;

    /**
     * The bootstrap classes for the application.
     *
     * @var array
     */
    protected $bootstrappers = [
        \Arc\Bootstrap\LoadEnvironmentVariables::class,
        \Arc\Bootstrap\LoadConfiguration::class,
        \Arc\Bootstrap\HandleExceptions::class,
        \Arc\Bootstrap\RegisterFacades::class,
        \Arc\Bootstrap\RegisterProviders::class,
        \Arc\Bootstrap\BindWordpressAdapters::class,
        \Arc\Bootstrap\BootProviders::class,
    ];

    /**
     * The application's middleware stack.
     *
     * @var array
     */
    protected $middleware = [];

    /**
     * The application's route middleware.
     *
     * @var array
     */
    protected $routeMiddleware = [];

    public function __construct(Application $plugin, Router $router)
    {
        $this->app = $plugin;
        $this->router = $router;

        foreach ($this->routeMiddleware as $key => $middleware) {
            $router->middleware($key, $middleware);
        }
    }

    public function bootstrap()
    {
        if (!$this->app->hasBeenBootstrapped()) {
            $this->app->bootstrapWith($this->bootstrappers());
        }
    }

    /**
     * Get the bootstrap classes for the application.
     *
     * @return array
     */
    protected function bootstrappers()
    {
        return $this->bootstrappers;
    }

    public function getApplication()
    {
        return $this->app;
    }

    /**
     * Handle the request and return a response.
     *
     * @param $request
     *
     * @return Illuminate\Http\Response
     **/
    public function handle($request)
    {
        try {
            $request->enableHttpMethodParameterOverride();

            $response = $this->sendRequestThroughRouter($request);

            if (isset($response->exception)) {
                throw $response->exception;
            }
        } catch (NotFoundHttpException $e) {
            $response = new DeferToWordpress();
        } catch (MethodNotAllowedHttpException $e) {
            $response = new DeferToWordpress();
        } catch (\Exception $e) {
            $this->reportException($e);
            $response = $this->renderException($request, $e);
        } catch (Throwable $e) {
            $e = new FatalThrowableError($e);
            $this->reportException($e);
            $response = $this->renderException($request, $e);
        }

        $response = $this->filterOutNotFound($response);

        return $response;
    }

    public function terminate($request, $response)
    {
        if (method_exists($response, 'shouldBeHandledByWordpress')) {
            if ($response->shouldBeHandledByWordpress()) {
                return;
            }
        }

        $middlewares = $this->app->shouldSkipMiddleware() ? [] : array_merge(
            $this->gatherRouteMiddlewares($request),
            $this->middleware
        );
        foreach ($middlewares as $middleware) {
            list($name, $parameters) = $this->parseMiddleware($middleware);
            $instance = $this->app->make($name);
            if (method_exists($instance, 'terminate')) {
                $instance->terminate($request, $response);
            }
        }

        if (!defined('ARC_TESTING')) {
            die();
        }
    }

    /**
     * Send the given request through the middleware / router.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    protected function sendRequestThroughRouter($request)
    {
        $this->app->instance('request', $request);

        $this->bootstrap();

        return (new Pipeline($this->app))
                    ->send($request)
                    ->through($this->app->shouldSkipMiddleware() ? [] : $this->middleware)
                    ->then($this->dispatchToRouter());
    }

    /**
     * Get the route dispatcher callback.
     *
     * @return \Closure
     */
    protected function dispatchToRouter()
    {
        return function ($request) {
            $this->app->instance('request', $request);

            return $this->router->dispatch($request);
        };
    }

    public function gatherRouteMiddlewares()
    {
        return $this->routeMiddleware;
    }

    public function renderException($request, \Exception $e)
    {
        return $this->app->make(Handler::class)->render($request, $e);
    }

    public function reportException(\Exception $e)
    {
        return $this->app->make(Handler::class)->report($e);
    }

    protected function filterOutNotFound($response)
    {
        if (!isset($response->exception)) {
            return $response;
        }

        if (!is_a($response->exception, NotFoundException::class)) {
            return $response;
        }

        return new DeferToWordpress();
    }
}
