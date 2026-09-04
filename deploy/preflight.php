<?php
declare(strict_types=1);

// Read-only: parse Laravel dotenv as data, never source it as shell commands.
$root = dirname(__DIR__);
$envFile = getenv('APP_ENV_FILE') ?: $root.'/serve/.env';
$configOnly = false;
for ($index = 1; $index < $argc; $index++) {
    if ($argv[$index] === '--env' && isset($argv[$index + 1])) $envFile = $argv[++$index];
    elseif ($argv[$index] === '--config-only') $configOnly = true;
    elseif ($argv[$index] === '--preflight') continue;
    elseif (in_array($argv[$index], ['--help', '-h'], true)) {
        echo "Usage: bash deploy.sh [--env FILE] [--config-only]\n";
        echo "Read-only configuration/runtime checks. Default also checks MySQL, Redis and writable storage.\n";
        echo "No migrations, key generation, service restarts or production activation.\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown or incomplete option; use --help.\n"); exit(64);
    }
}
function requireReady(bool $condition, string $reason): void {
    if (!$condition) throw new RuntimeException($reason);
}
$stage = 'configuration';
try {
    requireReady(PHP_VERSION_ID >= 80300, 'PHP 8.3 or newer is required');
    requireReady(is_file($envFile) && is_readable($envFile) && basename($envFile) !== '.env.example', 'controlled env file is missing');
    requireReady(in_array(fileperms($envFile) & 0777, [0600, 0640], true), 'env permissions must be 0600 or 0640');
    requireReady(is_file($root.'/serve/vendor/autoload.php'), 'install locked Composer dependencies first');
    require $root.'/serve/vendor/autoload.php';
    $env = Dotenv\Dotenv::parse(file_get_contents($envFile));
    requireReady(($env['APP_ENV'] ?? '') === 'production', 'APP_ENV must be production');
    requireReady(($env['APP_DEBUG'] ?? '') === 'false', 'APP_DEBUG must be false');
    $key = (string) ($env['APP_KEY'] ?? '');
    requireReady(str_starts_with($key, 'base64:') && strlen((string) base64_decode(substr($key, 7), true)) === 32, 'invalid APP_KEY');
    foreach (['APP_VISITOR_HASH_KEY', 'APP_FEEDBACK_HASH_KEY'] as $name) requireReady(strlen((string) ($env[$name] ?? '')) >= 32, 'invalid '.$name);
    requireReady(strlen((string) base64_decode($env['APP_VISITOR_TOKEN_KEY'] ?? '', true)) === 32, 'invalid APP_VISITOR_TOKEN_KEY');
    requireReady(($env['QUEUE_CONNECTION'] ?? '') === 'redis', 'first release requires QUEUE_CONNECTION=redis');
    requireReady(in_array($env['CACHE_STORE'] ?? $env['CACHE_DRIVER'] ?? '', ['redis', 'file', 'database'], true), 'configure persistent cache');
    requireReady(in_array($env['SESSION_DRIVER'] ?? 'file', ['redis', 'file', 'database'], true), 'configure persistent sessions');
    $origin = rtrim($env['PUBLIC_ORIGIN'] ?? '', '/');
    $parts = parse_url($origin);
    requireReady(is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host']) && !isset($parts['user'], $parts['pass']), 'invalid PUBLIC_ORIGIN');
    requireReady(!isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment']) && empty($parts['path']) && ($parts['port'] ?? 443) === 443, 'PUBLIC_ORIGIN must be an HTTPS origin');
    requireReady(rtrim($env['APP_URL'] ?? '', '/') === $origin, 'APP_URL must match PUBLIC_ORIGIN');
    $hosts = array_map('strtolower', array_map('trim', explode(',', $env['ALLOWED_SHARE_HOSTS'] ?? '')));
    requireReady(in_array(strtolower($parts['host']), $hosts, true), 'canonical host missing from ALLOWED_SHARE_HOSTS');
    foreach (['pdo_mysql', 'bcmath', 'zip', 'mbstring', 'fileinfo', 'curl', 'dom', 'xml'] as $extension) requireReady(extension_loaded($extension), 'missing PHP extension '.$extension);
    requireReady(($env['DB_CONNECTION'] ?? 'mysql') === 'mysql', 'first release requires MySQL');
    foreach (['DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'REDIS_HOST'] as $name) requireReady(!empty($env[$name]), 'missing '.$name);
    requireReady(!str_ends_with($env['DB_DATABASE'], '_test'), 'test database is not a production target');
    $privateRoot = realpath(($env['FEEDBACK_PRIVATE_ROOT'] ?? '') ?: $root.'/serve/storage/app/private/feedback');
    $publicRoot = realpath($root.'/serve/public');
    requireReady($privateRoot !== false && $publicRoot !== false, 'private storage directory is missing');
    requireReady($privateRoot !== $publicRoot && !str_starts_with($privateRoot, $publicRoot.DIRECTORY_SEPARATOR), 'private attachments must not be stored inside the public web root');
    if ($configOnly) {
        echo "PREFLIGHT_CONFIG=PASS CONNECTIONS=SKIPPED\n";
        exit(0);
    }
    $stage = 'MySQL connection';
    $database = new PDO('mysql:host='.$env['DB_HOST'].';port='.($env['DB_PORT'] ?? '3306').';dbname='.$env['DB_DATABASE'], $env['DB_USERNAME'], $env['DB_PASSWORD'], [PDO::ATTR_TIMEOUT => 5, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    requireReady((int) $database->query('SELECT 1')->fetchColumn() === 1, 'MySQL check failed');
    $stage = 'Redis connection';
    $redisConfig = ['scheme' => $env['REDIS_SCHEME'] ?? 'tcp', 'host' => $env['REDIS_HOST'], 'port' => (int) ($env['REDIS_PORT'] ?? 6379), 'timeout' => 5, 'read_write_timeout' => 5];
    if (!empty($env['REDIS_PASSWORD']) && $env['REDIS_PASSWORD'] !== 'null') $redisConfig['password'] = $env['REDIS_PASSWORD'];
    if (!empty($env['REDIS_USERNAME'])) $redisConfig['username'] = $env['REDIS_USERNAME'];
    $redis = new Predis\Client($redisConfig);
    requireReady((string) $redis->ping() === 'PONG', 'Redis check failed');
    $redis->disconnect();
    $stage = 'storage permissions';
    foreach ([$root.'/serve/storage', $root.'/serve/bootstrap/cache', $privateRoot] as $directory) requireReady(is_dir($directory) && is_writable($directory), 'required storage directory is missing or not writable by this user');
    echo "PREFLIGHT_CONFIG=PASS MYSQL=PASS REDIS=PASS STORAGE=PASS\n";
    echo "Verify PHP-FPM user permissions, Composer platform requirements, TLS, backups and worker/scheduler operation before activation.\n";
} catch (Throwable $error) {
    // Connection/parse exceptions may contain credentials or input; never echo them.
    $message = $stage === 'configuration' && get_class($error) === RuntimeException::class ? $error->getMessage() : $stage.' check failed';
    fwrite(STDERR, "PREFLIGHT=FAIL $message\n"); exit(1);
}
