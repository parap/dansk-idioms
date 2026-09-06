<?php declare(strict_types=1);

use Dansk\Http\Response;
use Dansk\Support\Config;
use Dansk\Support\Db;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Dependencies not installed. Run: docker-compose exec app composer install\n");
}
require_once $autoload;

$dispatcher = FastRoute\simpleDispatcher(static function (RouteCollector $r): void {
    $r->addRoute('GET', '/api/v1/health', 'health');
    $r->addRoute('GET', '/api/v1/stats',  'stats');
});

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

$route = $dispatcher->dispatch($method, $uri);

if ($route[0] === Dispatcher::NOT_FOUND) {
    // Anything that is not an API route falls through to the SPA shell.
    if (!str_starts_with($uri, '/api/')) {
        readfile(__DIR__ . '/app.html');
        return;
    }
    Response::error('not_found', 'No such endpoint.', 404);
    return;
}

if ($route[0] === Dispatcher::METHOD_NOT_ALLOWED) {
    Response::error('method_not_allowed', 'Method not allowed.', 405);
    return;
}

try {
    switch ($route[1]) {
        case 'health':
            $dbOk = false;
            $dbError = null;
            try {
                $dbOk = Db::fetchValue('SELECT 1') == 1;
            } catch (Throwable $e) {
                $dbError = $e->getMessage();
            }
            Response::json([
                'status'  => $dbOk ? 'ok' : 'degraded',
                'php'     => PHP_VERSION,
                'env'     => Config::get('env'),
                'db'      => $dbOk ? 'up' : 'down',
                'db_error'=> Config::get('debug') ? $dbError : null,
                'time'    => gmdate('c'),
            ], $dbOk ? 200 : 503);
            break;

        case 'stats':
            Response::json([
                'idioms_published' => (int) Db::fetchValue(
                    'SELECT COUNT(*) FROM idioms WHERE is_published = 1'
                ),
                'idioms_total' => (int) Db::fetchValue('SELECT COUNT(*) FROM idioms'),
                'translations' => (int) Db::fetchValue('SELECT COUNT(*) FROM idiom_translations'),
                'examples'     => (int) Db::fetchValue(
                    'SELECT COUNT(*) FROM examples WHERE is_reviewed = 1'
                ),
                'needs_review' => (int) Db::fetchValue(
                    "SELECT COUNT(*) FROM raw_entries WHERE status = 'needs_review'"
                ),
            ]);
            break;
    }
} catch (Throwable $e) {
    error_log((string) $e);
    Response::error(
        'server_error',
        Config::get('debug') ? $e->getMessage() : 'Internal error.',
        500
    );
}
