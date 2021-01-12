<?php

declare(strict_types=1);

namespace Codeception\Module;

use Codeception\Configuration;
use Codeception\Exception\ModuleConfigException;
use Codeception\Lib\Connector\Lumen as LumenConnector;
use Codeception\Lib\Framework;
use Codeception\Lib\Interfaces\ActiveRecord;
use Codeception\Lib\Interfaces\PartedModule;
use Codeception\Lib\ModuleContainer;
use Codeception\TestInterface;
use Codeception\Util\ReflectionHelper;
use Exception;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthContract;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\FactoryBuilder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Laravel\Lumen\Application;
use Laravel\Lumen\Routing\Router;
use ReflectionException;
use RuntimeException;
use Throwable;

/**
 *
 * This module allows you to run functional tests for Lumen.
 * Please try it and leave your feedback.
 *
 * ## Demo project
 * <https://github.com/codeception/lumen-module-tests>
 *
 *
 * ## Config
 *
 * * cleanup: `boolean`, default `true` - all database queries will be run in a transaction,
 *   which will be rolled back at the end of each test.
 * * bootstrap: `string`, default `bootstrap/app.php` - relative path to app.php config file.
 * * root: `string`, default `` - root path of the application.
 * * packages: `string`, default `workbench` - root path of application packages (if any).
 * * url: `string`, default `http://localhost` - the application URL
 *
 * ## API
 *
 * * app - `\Laravel\Lumen\Application`
 * * config - `array`
 *
 * ## Parts
 *
 * * `ORM`: Only include the database methods of this module:
 *     * dontSeeRecord
 *     * grabRecord
 *     * have
 *     * haveMultiple
 *     * haveRecord
 *     * make
 *     * makeMultiple
 *     * seeRecord
 *
 * See [WebDriver module](https://codeception.com/docs/modules/WebDriver#Loading-Parts-from-other-Modules)
 * for general information on how to load parts of a framework module.
 */
class Lumen extends Framework implements ActiveRecord, PartedModule
{
    /**
     * @var Application
     */
    public $app;

    /**
     * @var array
     */
    public $config = [];

    public function __construct(ModuleContainer $container, ?array $config = null)
    {
        $this->config = array_merge(
            [
                'cleanup' => true,
                'bootstrap' => 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php',
                'root' => '',
                'packages' => 'workbench',
                'url' => 'http://localhost',
            ],
            (array)$config
        );

        $projectDir = explode($this->config['packages'], Configuration::projectDir())[0];
        $projectDir .= $this->config['root'];

        $this->config['project_dir'] = $projectDir;
        $this->config['bootstrap_file'] = $projectDir . $this->config['bootstrap'];

        parent::__construct($container);
    }

    public function _parts(): array
    {
        return ['orm'];
    }

    /**
     * Initialize hook.
     *
     * @throws ModuleConfigException
     */
    public function _initialize()
    {
        $this->checkBootstrapFileExists();
        $this->registerAutoloaders();
    }

    /**
     * Before hook.
     *
     * @param TestInterface $test
     * @throws Throwable
     */
    public function _before(TestInterface $test)
    {
        $this->client = new LumenConnector($this);

        /** @var DatabaseManager $dbManager */
        $dbManager = $this->app['db'];
        if ($dbManager instanceof DatabaseManager && $this->config['cleanup']) {
            $dbManager->beginTransaction();
        }
    }

    /**
     * After hook.
     *
     * @param TestInterface $test
     * @throws Throwable
     */
    public function _after(TestInterface $test)
    {
        /** @var DatabaseManager $dbManager */
        if (!$dbManager = $this->app['db']) {
            return;
        }

        if ($this->config['cleanup']) {
            $dbManager->rollback();
        }

        // disconnect from DB to prevent "Too many connections" issue
        $dbManager->disconnect();
    }

    /**
     * Make sure the Lumen bootstrap file exists.
     *
     * @throws ModuleConfigException
     */
    protected function checkBootstrapFileExists()
    {
        $bootstrapFile = $this->config['bootstrap_file'];

        if (!file_exists($bootstrapFile)) {
            throw new ModuleConfigException(
                $this,
                "Lumen bootstrap file not found in $bootstrapFile.\n"
                . "Please provide a valid path using the 'bootstrap' config param. "
            );
        }
    }

    /**
     * Register autoloaders.
     */
    protected function registerAutoloaders()
    {
        require $this->config['project_dir'] . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    }

    /**
     * Provides access the Lumen application object.
     */
    public function getApplication(): Application
    {
        return $this->app;
    }

    public function setApplication(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Opens web page using route name and parameters.
     *
     * ```php
     * <?php
     * $I->amOnRoute('homepage');
     * ```
     *
     * @param string $routeName
     * @param array $params
     */
    public function amOnRoute(string $routeName, $params = [])
    {
        $route = $this->getRouteByName($routeName);

        if (!$route) {
            $this->fail("Could not find route with name '$routeName'");
        }

        $url = $this->generateUrlForRoute($route, $params);
        $this->amOnPage($url);
    }

    /**
     * Get the route for a route name.
     *
     * @param string $routeName
     * @return array|null
     */
    private function getRouteByName(string $routeName): ?array
    {
        /** @var Router $router */
        $router = $this->app['router'];
        $routes = $router->getRoutes();

        foreach ($routes as $route) {
            if (isset($route['action']['as']) && $route['action']['as'] == $routeName) {
                return $route;
            }
        }
        $this->fail("Route with name '$routeName' does not exist");
        return null;
    }

    /**
     * Generate the URL for a route specification.
     * Replaces the route parameters from left to right with the parameters
     * passed in the $params array.
     *
     * @param array $route
     * @param array $params
     * @return string
     */
    private function generateUrlForRoute(array $route, array $params): string
    {
        $url = $route['uri'];

        while (count($params) > 0) {
            $param = array_shift($params);
            $url = preg_replace('/{.+?}/', $param, $url, 1);
        }

        return $url;
    }

    /**
     * Set the authenticated user for the next request.
     * This will not persist between multiple requests.
     *
     * @param Authenticatable $user
     * @param string|null $guardName The guard name
     */
    public function amLoggedAs(Authenticatable $user, ?string $guardName = null): void
    {
        $auth = $this->app['auth'];

        $guard = $auth->guard($guardName);

        $guard->setUser($user);
    }

    /**
     * Checks that user is authenticated.
     */
    public function seeAuthentication(): void
    {
        /** @var AuthContract $auth */
        $auth = $this->app['auth'];
        $this->assertTrue($auth->check(), 'User is not logged in');
    }
    /**
     * Check that user is not authenticated.
     */
    public function dontSeeAuthentication(): void
    {
        /** @var AuthContract $auth */
        $auth = $this->app['auth'];
        $this->assertFalse($auth->check(), 'User is logged in');
    }

    /**
     * Return an instance of a class from the IoC Container.
     *
     * Example
     * ``` php
     * <?php
     * // In Lumen
     * App::bind('foo', function($app)
     * {
     *     return new FooBar;
     * });
     *
     * // Then in test
     * $service = $I->grabService('foo');
     *
     * // Will return an instance of FooBar, also works for singletons.
     * ```
     *
     * @param string $class
     * @return mixed
     */
    public function grabService(string $class)
    {
        return $this->app[$class];
    }

    /**
     * Inserts record into the database.
     * If you pass the name of a database table as the first argument, this method returns an integer ID.
     * You can also pass the class name of an Eloquent model, in that case this method returns an Eloquent model.
     *
     * ``` php
     * <?php
     * $userId = $I->haveRecord('users', ['name' => 'Davert']); // returns integer
     * $user = $I->haveRecord('App\Models\User', ['name' => 'Davert']); // returns Eloquent model
     * ```
     *
     * @param string $table
     * @param array $attributes
     * @return integer|EloquentModel
     * @part orm
     */
    public function haveRecord($table, $attributes = [])
    {
        if (class_exists($table)) {
            $model = new $table;

            if (!$model instanceof EloquentModel) {
                throw new RuntimeException("Class $table is not an Eloquent model");
            }

            $model->fill($attributes)->save();

            return $model;
        }

        try {
            /** @var DatabaseManager $dbManager */
            $dbManager = $this->app['db'];
            return $dbManager->table($table)->insertGetId($attributes);
        } catch (Exception $e) {
            $this->fail("Could not insert record into table '$table':\n\n" . $e->getMessage());
        }
    }

    /**
     * Checks that record exists in database.
     * You can pass the name of a database table or the class name of an Eloquent model as the first argument.
     *
     * ``` php
     * <?php
     * $I->seeRecord('users', ['name' => 'Davert']);
     * $I->seeRecord('App\Models\User', ['name' => 'Davert']);
     * ?>
     * ```
     *
     * @param string $table
     * @param array $attributes
     * @part orm
     */
    public function seeRecord($table, $attributes = [])
    {
        if (class_exists($table)) {
            if (!$this->findModel($table, $attributes)) {
                $this->fail("Could not find $table with " . json_encode($attributes));
            }
        } elseif (!$this->findRecord($table, $attributes)) {
            $this->fail("Could not find matching record in table '$table'");
        }
    }

    /**
     * Checks that record does not exist in database.
     * You can pass the name of a database table or the class name of an Eloquent model as the first argument.
     *
     * ``` php
     * <?php
     * $I->dontSeeRecord('users', ['name' => 'davert']);
     * $I->dontSeeRecord('App\Models\User', ['name' => 'davert']);
     * ```
     *
     * @param string $table
     * @param array $attributes
     * @part orm
     */
    public function dontSeeRecord($table, $attributes = []): void
    {
        if (class_exists($table)) {
            if ($this->findModel($table, $attributes)) {
                $this->fail("Unexpectedly found matching $table with " . json_encode($attributes));
            }
        } elseif ($this->findRecord($table, $attributes)) {
            $this->fail("Unexpectedly found matching record in table '$table'");
        }
    }

    /**
     * Retrieves record from database
     * If you pass the name of a database table as the first argument, this method returns an array.
     * You can also pass the class name of an Eloquent model, in that case this method returns an Eloquent model.
     *
     * ``` php
     * <?php
     * $record = $I->grabRecord('users', ['name' => 'davert']); // returns array
     * $record = $I->grabRecord('App\Models\User', ['name' => 'davert']); // returns Eloquent model
     * ```
     *
     * @param string $table
     * @param array $attributes
     * @return array|EloquentModel
     * @part orm
     */
    public function grabRecord($table, $attributes = [])
    {
        if (class_exists($table)) {
            if (!$model = $this->findModel($table, $attributes)) {
                $this->fail("Could not find $table with " . json_encode($attributes));
            }

            return $model;
        }

        if (!$record = $this->findRecord($table, $attributes)) {
            $this->fail("Could not find matching record in table '$table'");
        }

        return $record;
    }

    /**
     * @param string $modelClass
     * @param array $attributes
     * @return EloquentModel|null
     */
    protected function findModel(string $modelClass, array $attributes = [])
    {
        $model = new $modelClass;

        if (!$model instanceof EloquentModel) {
            throw new RuntimeException("Class $modelClass is not an Eloquent model");
        }

        $query = $model->newQuery();
        foreach ($attributes as $key => $value) {
            $query->where($key, $value);
        }

        return $query->first();
    }

    protected function findRecord(string $table, array $attributes = []): array
    {
        /** @var DatabaseManager $dbManager */
        $dbManager = $this->app['db'];
        $query = $dbManager->table($table);
        foreach ($attributes as $key => $value) {
            $query->where($key, $value);
        }

        return (array)$query->first();
    }

    /**
     * Use Lumen's model factory to create a model.
     *
     * ``` php
     * <?php
     * $I->have('App\Models\User');
     * $I->have('App\Models\User', ['name' => 'John Doe']);
     * $I->have('App\Models\User', [], 'admin');
     * ```
     *
     * @see https://lumen.laravel.com/docs/master/testing#model-factories
     * @param string $model
     * @param array $attributes
     * @param string $name
     * @return mixed
     * @part orm
     */
    public function have(string $model, array $attributes = [], string $name = 'default')
    {
        try {
            return $this->modelFactory($model, $name)->create($attributes);
        } catch (Exception $e) {
            $this->fail("Could not create model: \n\n" . get_class($e) . "\n\n" . $e->getMessage());
        }
    }

    /**
     * Use Laravel model factory to create multiple models.
     *
     * ``` php
     * <?php
     * $I->haveMultiple('App\Models\User', 10);
     * $I->haveMultiple('App\Models\User', 10, ['name' => 'John Doe']);
     * $I->haveMultiple('App\Models\User', 10, [], 'admin');
     * ```
     *
     * @see https://lumen.laravel.com/docs/master/testing#model-factories
     * @param string $model
     * @param int $times
     * @param array $attributes
     * @param string $name
     * @return mixed
     * @part orm
     */
    public function haveMultiple(string $model, int $times, array $attributes = [], string $name = 'default')
    {
        try {
            return $this->modelFactory($model, $name, $times)->create($attributes);
        } catch (Exception $e) {
            $this->fail("Could not create model: \n\n" . get_class($e) . "\n\n" . $e->getMessage());
        }
    }


    /**
     * Use Lumen's model factory to make a model instance.
     *
     * ``` php
     * <?php
     * $I->make('App\Models\User');
     * $I->make('App\Models\User', ['name' => 'John Doe']);
     * $I->make('App\Models\User', [], 'admin');
     * ```
     *
     * @see https://lumen.laravel.com/docs/master/testing#model-factories
     * @param string $model
     * @param array $attributes
     * @param string $name
     * @return mixed
     * @part orm
     */
    public function make(string $model, array $attributes = [], string $name = 'default')
    {
        try {
            return $this->modelFactory($model, $name)->make($attributes);
        } catch (Exception $e) {
            $this->fail("Could not make model: \n\n" . get_class($e) . "\n\n" . $e->getMessage());
        }
    }

    /**
     * Use Laravel model factory to make multiple model instances.
     *
     * ``` php
     * <?php
     * $I->makeMultiple('App\Models\User', 10);
     * $I->makeMultiple('App\Models\User', 10, ['name' => 'John Doe']);
     * $I->makeMultiple('App\Models\User', 10, [], 'admin');
     * ```
     *
     * @see https://lumen.laravel.com/docs/master/testing#model-factories
     * @param string $model
     * @param int $times
     * @param array $attributes
     * @param string $name
     * @return mixed
     * @part orm
     */
    public function makeMultiple(string $model, int $times, array $attributes = [], string $name = 'default')
    {
        try {
            return $this->modelFactory($model, $name, $times)->make($attributes);
        } catch (Exception $e) {
            $this->fail("Could not make model: \n\n" . get_class($e) . "\n\n" . $e->getMessage());
        }
    }

    /**
     * @param string $model
     * @param string $name
     * @param int $times
     * @return FactoryBuilder
     */
    protected function modelFactory(string $model, string $name, int $times = 1)
    {
        if (function_exists('factory')) {
            return factory($model, $name, $times);
        }
        return $model::factory()->count($times);
    }

    /**
     * Returns a list of recognized domain names.
     * This elements of this list are regular expressions.
     *
     * @return array
     * @throws ReflectionException
     */
    protected function getInternalDomains(): array
    {
        $server = ReflectionHelper::readPrivateProperty($this->client, 'server');

        return ['/^' . str_replace('.', '\.', $server['HTTP_HOST']) . '$/'];
    }

    /**
     * Add a binding to the Laravel service container.
     * (https://laravel.com/docs/master/container)
     *
     * ``` php
     * <?php
     * $I->haveBinding('App\MyInterface', 'App\MyImplementation');
     * ```
     *
     * @param $abstract
     * @param $concrete
     */
    public function haveBinding($abstract, $concrete): void
    {
        $this->client->haveBinding($abstract, $concrete);
    }

    /**
     * Add a singleton binding to the Laravel service container.
     * (https://laravel.com/docs/master/container)
     *
     * ``` php
     * <?php
     * $I->haveSingleton('My\Interface', 'My\Singleton');
     * ```
     *
     * @param $abstract
     * @param $concrete
     */
    public function haveSingleton($abstract, $concrete): void
    {
        $this->client->haveBinding($abstract, $concrete, true);
    }

    /**
     * Add a contextual binding to the Laravel service container.
     * (https://laravel.com/docs/master/container)
     *
     * ``` php
     * <?php
     * $I->haveContextualBinding('App\MyClass', '$variable', 'value');
     *
     * // This is similar to the following in your Laravel application
     * $app->when('App\MyClass')
     *     ->needs('$variable')
     *     ->give('value');
     * ```
     *
     * @param $concrete
     * @param $abstract
     * @param $implementation
     */
    public function haveContextualBinding($concrete, $abstract, $implementation): void
    {
        $this->client->haveContextualBinding($concrete, $abstract, $implementation);
    }

    /**
     * Add an instance binding to the Laravel service container.
     * (https://laravel.com/docs/master/container)
     *
     * ``` php
     * <?php
     * $I->haveInstance('App\MyClass', new App\MyClass());
     * ```
     *
     * @param $abstract
     * @param $instance
     */
    public function haveInstance($abstract, $instance): void
    {
        $this->client->haveInstance($abstract, $instance);
    }

    /**
     * Register a handler than can be used to modify the Laravel application object after it is initialized.
     * The Laravel application object will be passed as an argument to the handler.
     *
     * ``` php
     * <?php
     * $I->haveApplicationHandler(function($app) {
     *     $app->make('config')->set(['test_value' => '10']);
     * });
     * ```
     *
     * @param $handler
     */
    public function haveApplicationHandler($handler): void
    {
        $this->client->haveApplicationHandler($handler);
    }

    /**
     * Clear the registered application handlers.
     *
     * ``` php
     * <?php
     * $I->clearApplicationHandlers();
     * ```
     *
     */
    public function clearApplicationHandlers(): void
    {
        $this->client->clearApplicationHandlers();
    }
}
