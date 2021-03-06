<?php
use Builder\App;
use Builder\SchRouter;
use Monolog\Logger as Logger;
use Monolog\Handler\StreamHandler;

if (!isset($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = 'builds.nebo15.com';
}
if (!isset($_SERVER['REQUEST_URI'])) {
    $_SERVER['REQUEST_URI'] = '/';
}
# Project CONST
if (!defined('PROJECT_DIR')) {
    define('PROJECT_DIR', realpath(__DIR__ . '/../') . "/");
}
if (!defined('APPLICATION_ENV')) {
    define('APPLICATION_ENV', $_SERVER['APPLICATION_ENV']);
}

$loader = require_once(PROJECT_DIR . '/vendor/autoload.php');

$app = App::i();
$app->service('loader', $loader);
$app->mode(APPLICATION_ENV);
$app->var_dir(PROJECT_DIR . '/var/');

ini_set('error_log', PROJECT_DIR . '/var/log/main.log');

$config = require_once(PROJECT_DIR . '/settings/config.php');
$config_env = PROJECT_DIR . '/settings/config.override.php';
if (is_file($config_env)) {
    $config = array_merge($config, require($config_env));
}
$app->config(
    function () use ($config) {
        return (object) $config;
    }
);

date_default_timezone_set($app->config()->timezone);

$app->guard(
    function (\Exception $e) use ($app) {
        $response = [
            'meta' => [
                'code' => 500,
                'error_type' => get_class($e),
                'error_message' => $e->getMessage()
            ]
        ];

        if ('production' != App::i()->mode()) {
            $response['debug'] = [
                'trace' => array_slice($e->getTrace(), 0, 5)
            ];
        }

        if ($logger = App::i()->logger()) {
            $logger->addError($e->getMessage(), [$_SERVER['REQUEST_URI']]);
        } else {
            error_log('bootstrap error: ' . $e->getMessage());
        }

        http_response_code(500);
        if (!headers_sent()) {
            header('Content-Type', 'application/json');
        }
        echo json_encode($response, JSON_PRETTY_PRINT);
        die();
    }
);

$app->router(
    function () use ($app) {
        return new SchRouter();
    }
);

$app->logger(
    function () {
        $logger = new Logger('main');
        $logger->pushHandler(new StreamHandler(PROJECT_DIR . '/var/log/main.log'));

        return $logger;
    }
);

$app->requests_logger(
    function () {
        $logger = new Logger('net');
        $logger->pushHandler(new StreamHandler(PROJECT_DIR . '/var/log/requests.log'));

        return $logger;
    }
);

$app->view(
    function () use ($app) {
        $view = new Twig_Environment(
            new Twig_Loader_Filesystem(PROJECT_DIR . 'app/views'),
            array(
                'cache' => PROJECT_DIR . '/var/cache/templates',
                'debug' => true
            )
        );
        $view->addGlobal('main_host', $app->config()->host);
        $view->addGlobal('app', $app);
        $view->addExtension(new Twig_Extension_Debug());

        return $view;
    }
);

$app->db(
    function () use ($app) {
        return (new MongoClient())->selectDB($app->config()->db['database']);
    }
);

$app->build_table(
    function () use ($app) {
        return new \Builder\Model\BuildTable($app->db(), $app->config());
    }
);

$app->acl(
    function ($args) use ($app) {
        $request = $args[0];
        $response = $args[1];
        $layer = $args[2];

        $auth_id = $request->server()->get('PHP_AUTH_USER');
        $auth_secret = $request->server()->get('PHP_AUTH_PW');

        if (!$auth_id || !$auth_secret) {
            $response->header('WWW-Authenticate', 'Basic realm="Not so quickly!"');
            return $response->jsonError(401, "Unauthorized");
        }

        if ($layer == "api") {
            $auhorized_clients = $app->config()->api_keys;
        } elseif ($layer == "web") {
            $auhorized_clients = $app->config()->users;
        } else {
            throw new \Exception("Can't find credentials for '{$layer}' application access layer");

            $response->header('WWW-Authenticate', 'Basic realm="Not so quickly!"');
            return $response->jsonError(401, "Unauthorized");
        }

        if (array_key_exists($auth_id, $auhorized_clients)) {
            if ($auhorized_clients[$auth_id] == $auth_secret) {
                return true;
            } else {
                $response->header('WWW-Authenticate', 'Basic realm="Not so quickly!"');
                return $response->jsonError(403, "Unauthorized");
            }
        } else {
            $response->header('WWW-Authenticate', 'Basic realm="Not so quickly!"');
            return $response->jsonError(401, "Unauthorized");
        }
    }
);

/** Controllers */
$controllers = [
    'history',
    'upload',
];

foreach ($controllers as $controller) {
    include(PROJECT_DIR . "app/controllers/{$controller}.php");
}

/** Errors */
$app->router()->respond(
    '404',
    function ($request, \Builder\SchResponse $response) {
        $response->jsonError(404, "Route not found", "RouteNotFound");
    }
);

$app->router()->respond(
    '405',
    function ($request, \Builder\SchResponse $response) {
        $response->jsonError(405, "Method Not Allowed", "MethodNotAllowed");
    }
);

$response = $app->router()->response();

return $app;
