<?php declare(strict_types=1);

use Dansk\Controller\AdminController;
use Dansk\Controller\AuthController;
use Dansk\Controller\QuizController;
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
    $r->addRoute('GET',  '/api/v1/health', 'health');
    $r->addRoute('GET',  '/api/v1/stats',  'stats');

    $r->addRoute('POST', '/api/v1/auth/register', 'auth.register');
    $r->addRoute('POST', '/api/v1/auth/login',    'auth.login');
    $r->addRoute('POST', '/api/v1/auth/logout',   'auth.logout');
    $r->addRoute('GET',  '/api/v1/me',            'auth.me');

    $r->addRoute('POST', '/api/v1/quiz/sessions', 'quiz.start');
    $r->addRoute('GET',  '/api/v1/quiz/sessions/{sid:[0-9A-Z]{26}}/questions/{pos:\d+}', 'quiz.question');
    $r->addRoute('POST', '/api/v1/quiz/sessions/{sid:[0-9A-Z]{26}}/answers', 'quiz.answer');
    $r->addRoute('GET',  '/api/v1/quiz/sessions/{sid:[0-9A-Z]{26}}/result',  'quiz.result');
    $r->addRoute('POST', '/api/v1/quiz/sessions/{sid:[0-9A-Z]{26}}/questions/{pos:\d+}/report', 'quiz.report');

    $r->addRoute('POST', '/api/v1/admin/login',  'admin.login');
    $r->addRoute('POST', '/api/v1/admin/logout', 'admin.logout');
    $r->addRoute('GET',  '/api/v1/admin/review', 'admin.queue');
    $r->addRoute('POST', '/api/v1/admin/entries/{id:\d+}/accept', 'admin.accept');
    $r->addRoute('POST', '/api/v1/admin/entries/{id:\d+}/reject', 'admin.reject');
});

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$route  = $dispatcher->dispatch($method, $uri);

if ($route[0] === Dispatcher::NOT_FOUND) {
    if (!str_starts_with($uri, '/api/')) {
        // The document must always be revalidated. Serving a stale shell means an
        // interface change never reaches an existing visitor -- which is exactly
        // what a cache-first service worker did before.
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate');
        readfile(__DIR__ . (str_starts_with($uri, '/admin') ? '/admin.html' : '/app.html'));
        return;
    }
    Response::error('not_found', 'No such endpoint.', 404);
    return;
}
if ($route[0] === Dispatcher::METHOD_NOT_ALLOWED) {
    Response::error('method_not_allowed', 'Method not allowed.', 405);
    return;
}

$handler = $route[1];
$vars    = $route[2] ?? [];
$body    = [];
if (in_array($method, ['POST', 'PATCH', 'PUT'], true)) {
    $raw  = file_get_contents('php://input') ?: '';
    $body = $raw === '' ? [] : (json_decode($raw, true) ?? []);
}

// Everything under admin/ except login requires the session.
if (str_starts_with($handler, 'admin.') && $handler !== 'admin.login'
    && !AdminController::isAuthenticated()) {
    Response::error('unauthorized', 'Log in first.', 401);
    return;
}

try {
    $admin = new AdminController();
    $quiz  = new QuizController();
    $auth  = new AuthController();

    match ($handler) {
        'health' => (function (): void {
            $ok = false; $err = null;
            try { $ok = Db::fetchValue('SELECT 1') == 1; }
            catch (Throwable $e) { $err = $e->getMessage(); }
            Response::json([
                'status' => $ok ? 'ok' : 'degraded', 'php' => PHP_VERSION,
                'env' => Config::get('env'), 'db' => $ok ? 'up' : 'down',
                'db_error' => Config::get('debug') ? $err : null, 'time' => gmdate('c'),
            ], $ok ? 200 : 503);
        })(),

        'stats' => Response::json([
            'idioms_published' => (int) Db::fetchValue('SELECT COUNT(*) FROM idioms WHERE is_published = 1'),
            'idioms_total'     => (int) Db::fetchValue('SELECT COUNT(*) FROM idioms'),
            'translations'     => (int) Db::fetchValue('SELECT COUNT(*) FROM idiom_translations'),
            'quiz_usable'      => (int) Db::fetchValue('SELECT COUNT(*) FROM idiom_translations WHERE quiz_usable = 1'),
            'examples'         => (int) Db::fetchValue('SELECT COUNT(*) FROM examples WHERE is_reviewed = 1'),
            'needs_review'     => (int) Db::fetchValue("SELECT COUNT(*) FROM raw_entries WHERE status = 'needs_review'"),
        ]),

        'auth.register' => $auth->register($body),
        'auth.login'    => $auth->login($body),
        'auth.logout'   => $auth->logout(),
        'auth.me'       => $auth->me(),

        'quiz.start'    => $quiz->start($body),
        'quiz.question' => $quiz->question($vars['sid'], (int) $vars['pos']),
        'quiz.answer'   => $quiz->answer($vars['sid'], $body),
        'quiz.result'   => $quiz->result($vars['sid']),
        'quiz.report'   => $quiz->report($vars['sid'], (int) $vars['pos'], $body),

        'admin.login'  => $admin->login($body),
        'admin.logout' => $admin->logout(),
        'admin.queue'  => $admin->queue(),
        'admin.accept' => $admin->accept((int) $vars['id'], $body),
        'admin.reject' => $admin->reject((int) $vars['id']),
    };
} catch (Throwable $e) {
    error_log((string) $e);
    Response::error('server_error', Config::get('debug') ? $e->getMessage() : 'Internal error.', 500);
}
