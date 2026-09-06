<?php declare(strict_types=1);

/**
 * Base config. Values come from the environment (docker-compose sets them);
 * config/local.php may override any of these and is gitignored.
 */
$config = [
    'env'   => getenv('APP_ENV') ?: 'dev',
    'debug' => (getenv('APP_ENV') ?: 'dev') !== 'prod',

    'db' => [
        'host'    => getenv('DB_HOST') ?: '127.0.0.1',
        'port'    => (int) (getenv('DB_PORT') ?: 3306),
        'name'    => getenv('DB_NAME') ?: 'dansk',
        'user'    => getenv('DB_USER') ?: 'dansk',
        'pass'    => getenv('DB_PASS') ?: 'dansk',
        'charset' => 'utf8mb4',
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
