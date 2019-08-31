<?php
use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;
use Slim\Views\PhpRenderer;

require __DIR__ . '/../vendor/autoload.php';

$containerBuilder = new ContainerBuilder();

$containerBuilder->addDefinitions([
    'settings' => [
        'db' => [
            'connection'  => 'sqlite',
            'user'   => null,
            'pass'   => null,
            'dbname' => __DIR__.'/db.sqlite',
        ],
        'view' => [
            'template_path' => 'views',
        ],
    ],
]);

$containerBuilder->addDefinitions([
    'db' => function (ContainerInterface $c) {
        ['db' => [
            'connection' => $connection,
            'user' => $user,
            'pass' => $pass,
            'dbname' => $dbname,
        ]] = $c->get('settings');

        return new PDO("{$connection}:{$dbname}", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    },
]);

$containerBuilder->addDefinitions([
    'renderer' => function (ContainerInterface $c) {
        ['view' => ['template_path' => $templatePath]] = $c->get('settings');
        return new PhpRenderer($templatePath);
    },
]);

$container = $containerBuilder->build();

$container->get('db')->exec(
    'CREATE TABLE IF NOT EXISTS todos (
        id        INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
        text      VARCHAR NOT NULL,
        completed BOOLEAN NOT NULL
    );'
);

AppFactory::setContainer($container);
$app = AppFactory::create();

$app->addErrorMiddleware(true, true, true);

$app->get('/', function (Request $request, Response $response, $args) {
    return $this->get('renderer')->render($response, 'index.html');
});

$app->group('/api', function (RouteCollectorProxy $group) {
    $group->get('/list', function (Request $request, Response $response) {
        $response->getBody()
             ->write(json_encode($this->get('db')->query('SELECT * FROM todos')->fetchAll()));
        return $response;
    });
    
    $group->post('/add', function (Request $request, Response $response) {
        ['text' => $text] = $request->getParsedBody();
    
        $db = $this->get('db');
        $stmt = $db->prepare('INSERT INTO todos(text, completed) VALUES(?, ?)');
        $stmt->execute([$text, 0]);
        $response->getBody()->write(json_encode(['res' => 'ok']));
        return $response;
    });
    
    $group->post('/complete/{id}', function (Request $request, Response $response, array $args) {
        ['id' => $id] = $args;

        $db = $this->get('db');
        $stmt = $db->prepare('UPDATE todos SET completed = NOT(completed) WHERE id = ?');
        $stmt->execute([$id]);
        $response->getBody()->write(json_encode(['res' => 'ok']));
        return $response;
    });
})->add(function ($request, $handler) {
    return $handler->handle($request)
        ->withHeader('Content-Type', 'application/json');
});

$app->run();
