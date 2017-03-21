<?php

namespace Arc;

use Arc\Activation\ActivationHooks;
use Arc\Admin\AdminMenus;
use Arc\Assets\Assets;
use Arc\Exceptions\Handler;
use Arc\Config\Config;
use Arc\Config\WPOptions;
use Arc\Contracts\Mail\Mailer as MailerContract;
use Arc\Cron\CronSchedules;
use Arc\Http\ValidatesRequests;
use Arc\Mail\Mailer;
use Arc\Providers\Providers;
use Arc\Routing\Router;
use Arc\Shortcodes\Shortcodes;
use Dotenv\Dotenv;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\MySqlBuilder;

class BasePlugin extends Container
{
    public $filename;
    public $path;
    public $slug;

    protected $activationHooks;
    protected $adminMenus;
    protected $assets;
    protected $cronSchedules;
    protected $env;
    protected $providers;
    protected $router;
    protected $shortcodes;
    protected $validator;

    /**
     * Instantiate the class
     **/
    public function __construct($pluginFilename)
    {
        $this->filename = $pluginFilename;
        $this->path = $this->env('PLUGIN_PATH', dirname($this->filename) . '/');
        $this->slug = $this->env('PLUGIN_SLUG', pathinfo($this->filename, PATHINFO_FILENAME));

        // Bind BasePlugin object instance
        $this->instance(BasePlugin::class, $this);

        // Bind config object
        $this->singleton('configuration', function() {
            return $this->make(Config::class);
        });

        // Bind WPOptions object
        $this->singleton(WPOptions::class, function() {
            return $this->make(WPOptions::class);
        });

        // Bind Exception Handler
        $this->singleton(
            ExceptionHandler::class,
            Handler::class
        );

        // Bind HTTP Request validator
        $this->validator = $this->make(ValidatesRequests::class);
        $this->instance(
            ValidatesRequests::class,
            $this->validator
        );

        // Bind filesystem
        $this->bind(
            \Illuminate\Contracts\Filesystem\Filesystem::class,
            \Illuminate\Filesystem\Filesystem::class
        );

        $this->bind('blade', function() {
            return new \Arc\View\Blade(config('plugin_path') . '/assets/views', config('plugin_path') . '/cache');
        });

        $this->capsule = $this->make(Capsule::class);
        $this->adminMenus = $this->make(AdminMenus::class);
        $this->assets = $this->make(Assets::class);
        $this->cronSchedules = $this->make(CronSchedules::class);
        $this->providers = $this->make(Providers::class);
        $this->router = $this->make(Router::class);
        $this->shortcodes = $this->make(Shortcodes::class);
        $this->bind('pluginFilename', function() use ($pluginFilename) {
            return $pluginFilename;
        });
        $this->pluginFilename = $pluginFilename;
    }

    /**
     * Boots the plugin
     **/
    public function boot()
    {
        // Bind version
        $this->make()->bind('version', function() {
            return get_plugin_data($this->pluginFilename)['Version'];
        });

        global $wpdb;

        $this->capsule->addConnection([
            'driver' => 'mysql',
            'database' => DB_NAME,
            'username' => DB_USER,
            'password' => DB_PASSWORD,
            'host' => '127.0.0.1',
            'prefix' => $wpdb->base_prefix,
            'collation' => !empty(DB_COLLATE) ? DB_COLLATE : 'utf8_unicode_ci'
        ]);

        $this->capsule->getContainer()->singleton(
            ExceptionHandler::class,
            Handler::class
        );
        $this->capsule->bootEloquent();
        $this->capsule->setAsGlobal();
        // Bind schema instance
        $this->schema = $this->capsule->schema();
        $this->instance(MySqlBuilder::class, $this->schema);

        // Bind Mailer concretion
        $this->bind(MailerContract::class, Mailer::class);

        $this->providers->register();

        $this->cronSchedules->register();
        $this->shortcodes->register();
        $this->adminMenus->register();
        $this->assets->enqueue();
        $this->router->boot();
    }
}
