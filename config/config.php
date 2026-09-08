<?php declare(strict_types=1);

/**
 * Base config. Values come from the environment (docker-compose sets them);
 * config/local.php may override any of these and is gitignored.
 */
$config = [
    'env'   => getenv('APP_ENV') ?: 'dev',

    // Opt in, never inherit. Set APP_DEBUG=1 to have API errors carry exception text
    // and the health endpoint carry the database error; leave it unset and callers get
    // neither. 'prod' refuses it outright, so a forgotten APP_DEBUG in an environment
    // file cannot turn a deployment into a debugging session.
    'debug' => Dansk\Support\Config::debugFromEnv(getenv('APP_ENV') ?: null, getenv('APP_DEBUG') ?: null),

    'db' => [
        'host'    => getenv('DB_HOST') ?: '127.0.0.1',
        'port'    => (int) (getenv('DB_PORT') ?: 3306),
        'name'    => getenv('DB_NAME') ?: 'dansk',
        'user'    => getenv('DB_USER') ?: 'dansk',
        'pass'    => getenv('DB_PASS') ?: 'dansk',
        'charset' => 'utf8mb4',
    ],

    'admin' => [
        // Deliberately null. A committed default is a working credential the moment
        // the repository is public, and a comment telling the reader to change it
        // protects nothing. With no password configured, admin login refuses every
        // attempt -- set ADMIN_PASSWORD in the environment or admin.password in
        // config/local.php (gitignored).
        'password' => getenv('ADMIN_PASSWORD') ?: null,
    ],

    'quiz' => [
        'questions_per_round' => 10,
        'options_per_question' => 4,
        'default_lang' => 'ru',
    ],

    'import' => [
        // Entries at or above this score are published without human review.
        // Start conservative; lower it once the first review pass shows the
        // real confidence distribution.
        'auto_accept_threshold' => 0.85,
        'review_threshold'      => 0.50,
        'parser_version'        => 1,
    ],
];

$localFile = __DIR__ . '/local.php';
if (is_file($localFile)) {
    $config = array_replace_recursive($config, require $localFile);
}

return $config;
