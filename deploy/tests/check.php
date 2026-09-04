<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$temp = sys_get_temp_dir().'/link-release-test-'.bin2hex(random_bytes(6));
mkdir($temp, 0700);
mkdir($temp.'/private', 0700);
$checks = 0;
function runCommand(array $command, string $cwd): array {
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $output .= stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), $output];
}
function check(bool $condition, string $name): void {
    global $checks;
    if (!$condition) throw new RuntimeException($name);
    $checks++;
}
function clearFixture(string $path): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (new FilesystemIterator($path) as $item) clearFixture($item->getPathname());
        rmdir($path);
    } else { unlink($path); }
}
try {
    $env = $temp.'/production.env';
    $settings = [
        'APP_ENV' => 'production', 'APP_DEBUG' => 'false',
        'APP_KEY' => 'base64:'.base64_encode(str_repeat('k', 32)),
        'APP_VISITOR_HASH_KEY' => str_repeat('h', 32),
        'APP_VISITOR_TOKEN_KEY' => base64_encode(str_repeat('v', 32)),
        'APP_FEEDBACK_HASH_KEY' => str_repeat('f', 32),
        'APP_URL' => 'https://example.test', 'PUBLIC_ORIGIN' => 'https://example.test',
        'ALLOWED_SHARE_HOSTS' => 'example.test', 'QUEUE_CONNECTION' => 'redis',
        'CACHE_STORE' => 'redis', 'SESSION_DRIVER' => 'file',
        'FEEDBACK_PRIVATE_ROOT' => $temp.'/private',
        'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1',
        'DB_DATABASE' => 'release_fixture', 'DB_USERNAME' => 'fixture', 'DB_PASSWORD' => 'fixture-only',
        'REDIS_HOST' => '127.0.0.1', 'REDIS_PORT' => '1',
    ];
    $writeEnv = function (array $overrides = []) use ($settings, $env): void {
        $lines = [];
        foreach (array_replace($settings, $overrides) as $key => $value) $lines[] = $key.'="'.$value.'"';
        file_put_contents($env, implode("\n", $lines)."\n"); chmod($env, 0600);
    };
    $writeEnv();
    $before = hash_file('sha256', $env);
    [$exit, $output] = runCommand(['bash', $root.'/deploy.sh', '--env', $env, '--config-only'], $temp);
    check($exit === 0 && str_contains($output, 'PREFLIGHT_CONFIG=PASS'), 'valid configuration-only preflight works from another directory');
    check(hash_file('sha256', $env) === $before, 'preflight does not change env');
    foreach (['APP_DEBUG' => 'true', 'APP_KEY' => 'invalid', 'APP_FEEDBACK_HASH_KEY' => '', 'PUBLIC_ORIGIN' => 'http://example.test', 'ALLOWED_SHARE_HOSTS' => 'other.test', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array'] as $key => $value) {
        $writeEnv([$key => $value]);
        [$exit] = runCommand(['bash', $root.'/deploy.sh', '--env', $env, '--config-only'], $temp);
        check($exit !== 0, 'reject invalid '.$key);
    }
    symlink($root.'/serve/public', $temp.'/public-link');
    foreach ([$root.'/serve/public', $temp.'/public-link'] as $unsafeStorage) {
        $writeEnv(['FEEDBACK_PRIVATE_ROOT' => $unsafeStorage]);
        [$exit] = runCommand(['bash', $root.'/deploy.sh', '--env', $env, '--config-only'], $temp);
        check($exit !== 0, 'reject private attachments in public root or its alias');
    }
    $writeEnv(); chmod($env, 0644);
    [$exit] = runCommand(['bash', $root.'/deploy.sh', '--env', $env, '--config-only'], $temp);
    check($exit !== 0, 'reject publicly readable env');
    chmod($env, 0600);
    [$exit] = runCommand(['bash', $root.'/deploy.sh', '--env', $env], $temp);
    check($exit !== 0, 'full preflight cannot pass with unavailable MySQL');
    [$exit] = runCommand(['bash', $root.'/deploy.sh', '--apply', '--env', $env], $temp);
    check($exit !== 0, 'no misleading apply mode');

    $source = $temp.'/source';
    mkdir($source.'/deploy', 0700, true); mkdir($source.'/admin/dist', 0700, true);
    mkdir($source.'/serve/sub', 0700, true); mkdir($source.'/serve/bootstrap/cache', 0700, true);
    copy($root.'/deploy/package-release.sh', $source.'/deploy/package-release.sh');
    file_put_contents($source.'/admin/dist/index.html', '<html>release fixture</html>');
    file_put_contents($source.'/admin/dist/config.js', "window.config = {url: '/api'};");
    file_put_contents($source.'/README.md', 'Fixture README');
    file_put_contents($source.'/LICENSE', 'Fixture LICENSE');
    file_put_contents($source.'/serve/artisan', '<?php');
    file_put_contents($source.'/serve/sub/.env', 'DO_NOT_PACKAGE');
    file_put_contents($source.'/serve/leak.pem', 'DO_NOT_PACKAGE');
    file_put_contents($source.'/serve/bootstrap/cache/config.php', 'DO_NOT_PACKAGE');
    runCommand(['git', 'init', '--quiet'], $source);
    runCommand(['git', 'add', '.'], $source);
    runCommand(['git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', 'commit', '--quiet', '-m', 'fixture'], $source);
    $out = $temp.'/package';
    [$exit] = runCommand(['bash', $source.'/deploy/package-release.sh', $out], $temp);
    check($exit === 0, 'package command succeeds');
    check(is_file($out.'/admin/dist/index.html') && is_file($out.'/LICENSE'), 'package includes compiled admin and license');
    check(!file_exists($out.'/serve/sub/.env') && !file_exists($out.'/serve/leak.pem') && !file_exists($out.'/serve/bootstrap/cache/config.php'), 'package excludes secrets and cached config');
    $moved = $temp.'/moved'; rename($out, $moved);
    [$exit] = runCommand(['shasum', '-a', '256', '-c', 'MANIFEST'], $moved);
    check($exit === 0, 'manifest verifies after moving package');
    file_put_contents($moved.'/KEEP', 'keep');
    [$exit] = runCommand(['bash', $source.'/deploy/package-release.sh', $moved], $temp);
    check($exit !== 0 && file_get_contents($moved.'/KEEP') === 'keep', 'existing output is refused without deletion');
    file_put_contents($source.'/admin/dist/index.html', 'uncommitted build');
    [$exit] = runCommand(['bash', $source.'/deploy/package-release.sh', $temp.'/different-build'], $temp);
    check($exit !== 0 && !file_exists($temp.'/different-build'), 'reject a build that does not belong to the recorded commit');
    echo "DEPLOY_TESTS=PASS checks=$checks\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'DEPLOY_TESTS=FAIL '.$error->getMessage()."\n");
    exit(1);
} finally {
    clearFixture($temp);
}
