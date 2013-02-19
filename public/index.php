<?php
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../src/CloudControlController.php';
require_once __DIR__.'/../src/FacebookController.php';

use Silex\Provider\FormServiceProvider;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;
use Symfony\Component\HttpFoundation\Request;
use Mwfacebookapp\CloudControlController;
use Mwfacebookapp\FacebookController;

$app = new Silex\Application();

$app->register(new FormServiceProvider());
$app->register(new Silex\Provider\TranslationServiceProvider());
$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => __DIR__.'/../views',
));

$creds = CloudControlController::getCredentials('MYSQLS');

$app->register(new Silex\Provider\DoctrineServiceProvider(), array(
    'db.options' => array(
        'driver'   => 'pdo_mysql',
        'host'     => $creds["MYSQLS_HOSTNAME"],
        'user'     => $creds["MYSQLS_USERNAME"],
        'dbname'   => $creds["MYSQLS_DATABASE"],
        'password' => $creds["MYSQLS_PASSWORD"],
    ),
));

$app->register(new Silex\Provider\SessionServiceProvider());

$app['session.db_options'] = array(
    'db_table'      => 'session',
    'db_id_col'     => 'session_id',
    'db_data_col'   => 'session_value',
    'db_time_col'   => 'session_time',
);
$app['session.storage.handler'] = $app->share(function () use ($app) {
    return new PdoSessionHandler(
        $app['db']->getWrappedConnection(),
        $app['session.db_options'],
        $app['session.storage.options']
    );
});

$app->before(function () {
    $facebookController = new FacebookController();
    if(!$facebookController->loggedIn()){
        $response = $facebookController->login();
        $response->send();
        exit();
    }
});

$app->error(function (\Exception $e, $code) use ($app) {
    // write to cloudControl log
    file_put_contents('php://stderr', $e->getMessage());
    $parts = explode("\n", $e->getTraceAsString());
    foreach ($parts as $line){
        file_put_contents('php://stderr', $line);
    }
    return $app['twig']->render('error.twig', array(
        'exception' => $e,
        'code' => $code
    ));
});

$cloudControlController = new CloudControlController($app);

// facebook does initially a POST request on "/", all other request are use GET request method
$app->match('/', function () use ($cloudControlController) {
    return $cloudControlController->appList();
});

$app->get('/app/{applicationName}', function ($applicationName) use ($cloudControlController) {
    return $cloudControlController->appDetails($applicationName);
});

$app->get('/deployment/{applicationName}/{deploymentName}', function ($applicationName, $deploymentName) use ($cloudControlController) {
    return $cloudControlController->deploymentDetails($applicationName, $deploymentName);
});

$app->match('/login', function (Request $request) use ($cloudControlController) {
    return $cloudControlController->login($request);
});

$app->get('/logout', function (Request $request) use ($cloudControlController) {
    return $cloudControlController->logout();
});

$app->run();