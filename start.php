<?php
declare(strict_types=1);

const APP_DIR = __DIR__ . '/app';
const STATE_FILE = __DIR__ . '/.technosphere-updater.json';
const LOCK_FILE = __DIR__ . '/.technosphere-updater.lock';
const REPO_OWNER = 'stevenwilkins';
const REPO_NAME = 'Technosphere';
const REPO_BRANCH = 'main';

const PRESERVE_PATHS = [
    'technosphere.sqlite',
    'technosphere_fallback.json',
];

main($argv);

function main(array $argv): void
{
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Run this file from the command line: php start.php\n";
        return;
    }

    [$address, $updateOnly] = parse_cli_arguments($argv);

    ensure_app_directory();

    $status = run_update();

    fwrite(STDOUT, $status['message'] . PHP_EOL);

    if ($updateOnly) {
        return;
    }

    $command = sprintf(
        '%s -S %s -t %s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($address),
        escapeshellarg(APP_DIR)
    );

    fwrite(STDOUT, 'Serving ' . APP_DIR . ' at http://' . $address . "/index.php\n");
    passthru($command, $exitCode);
    exit($exitCode);
}

function parse_cli_arguments(array $argv): array
{
    $address = 'localhost:8000';
    $updateOnly = false;

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--update-only') {
            $updateOnly = true;
            continue;
        }

        if (preg_match('/^[A-Za-z0-9.\-]+:\d+$/', $arg) === 1) {
            $address = $arg;
        }
    }

    return [$address, $updateOnly];
}

function ensure_app_directory(): void
{
    if (!is_dir(APP_DIR) && !mkdir(APP_DIR, 0777, true) && !is_dir(APP_DIR)) {
        throw new RuntimeException('Could not create app directory.');
    }
}

function run_update(): array
{
    $lock = fopen(LOCK_FILE, 'c+');
    if ($lock === false) {
        return ['ok' => false, 'message' => 'Updater lock file could not be opened. Starting with local files.'];
    }

    try {
        if (!flock($lock, LOCK_EX)) {
            return ['ok' => false, 'message' => 'Updater lock could not be acquired. Starting with local files.'];
        }

        $state = read_state();
        $latestSha = fetch_latest_commit_sha();

        if ($latestSha === null) {
            $lastSha = is_string($state['last_sha'] ?? null) ? $state['last_sha'] : null;
            if ($lastSha) {
                return ['ok' => false, 'message' => 'GitHub check failed. Continuing with local version at ' . short_sha($lastSha) . '.'];
            }
            return ['ok' => false, 'message' => 'GitHub check failed. Continuing with bundled local files.'];
        }

        $currentSha = is_string($state['last_sha'] ?? null) ? $state['last_sha'] : null;
        if ($currentSha === $latestSha) {
            return ['ok' => true, 'message' => 'Already up to date at ' . short_sha($latestSha) . '.'];
        }

        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'message' => 'ZipArchive is not available, so updates cannot be applied automatically.'];
        }

        $archivePath = download_repo_archive($latestSha);
        if ($archivePath === null) {
            return ['ok' => false, 'message' => 'Could not download the GitHub archive. Continuing with local files.'];
        }

        $extractRoot = extract_archive($archivePath);
        if ($extractRoot === null) {
            @unlink($archivePath);
            return ['ok' => false, 'message' => 'Could not extract the GitHub archive. Continuing with local files.'];
        }

        mirror_repo_tree($extractRoot, APP_DIR, '');

        write_state([
            'owner' => REPO_OWNER,
            'repo' => REPO_NAME,
            'branch' => REPO_BRANCH,
            'last_sha' => $latestSha,
            'updated_at' => gmdate('c'),
        ]);

        delete_tree(dirname($extractRoot));
        @unlink($archivePath);

        return ['ok' => true, 'message' => 'Updated app files to ' . short_sha($latestSha) . '.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Updater error: ' . $e->getMessage() . '. Continuing with local files.'];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function short_sha(string $sha): string
{
    return substr($sha, 0, 7);
}

function read_state(): array
{
    if (!is_file(STATE_FILE)) {
        return [];
    }

    $raw = file_get_contents(STATE_FILE);
    $data = json_decode($raw ?: '[]', true);

    return is_array($data) ? $data : [];
}

function write_state(array $state): void
{
    file_put_contents(STATE_FILE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function github_headers(): array
{
    return [
        'User-Agent: Technosphere-Updater',
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
    ];
}

function fetch_latest_commit_sha(): ?string
{
    $url = sprintf(
        'https://api.github.com/repos/%s/%s/commits/%s',
        rawurlencode(REPO_OWNER),
        rawurlencode(REPO_NAME),
        rawurlencode(REPO_BRANCH)
    );

    $response = http_get($url, github_headers());
    if ($response === null) {
        return null;
    }

    $json = json_decode($response, true);
    $sha = $json['sha'] ?? null;

    return is_string($sha) && preg_match('/^[a-f0-9]{40}$/', $sha) === 1 ? $sha : null;
}

function download_repo_archive(string $sha): ?string
{
    $tempPath = tempnam(sys_get_temp_dir(), 'techzip-');
    if ($tempPath === false) {
        return null;
    }

    $zipPath = $tempPath . '.zip';
    @unlink($tempPath);

    $url = sprintf(
        'https://api.github.com/repos/%s/%s/zipball/%s',
        rawurlencode(REPO_OWNER),
        rawurlencode(REPO_NAME),
        rawurlencode(REPO_BRANCH)
    );

    $bytes = http_download($url, github_headers(), $zipPath);
    if (!$bytes || !is_file($zipPath)) {
        $fallbackUrl = sprintf(
            'https://github.com/%s/%s/archive/refs/heads/%s.zip',
            rawurlencode(REPO_OWNER),
            rawurlencode(REPO_NAME),
            rawurlencode(REPO_BRANCH)
        );
        $bytes = http_download($fallbackUrl, ['User-Agent: Technosphere-Updater'], $zipPath);
    }

    if (!$bytes || !is_file($zipPath)) {
        @unlink($zipPath);
        return null;
    }

    return $zipPath;
}

function extract_archive(string $archivePath): ?string
{
    $tempDir = sys_get_temp_dir() . '/technosphere-update-' . bin2hex(random_bytes(6));
    if (!mkdir($tempDir, 0777, true) && !is_dir($tempDir)) {
        return null;
    }

    $zip = new ZipArchive();
    if ($zip->open($archivePath) !== true) {
        delete_tree($tempDir);
        return null;
    }

    if (!$zip->extractTo($tempDir)) {
        $zip->close();
        delete_tree($tempDir);
        return null;
    }

    $zip->close();

    $entries = array_values(array_filter(scandir($tempDir) ?: [], static fn($item) => $item !== '.' && $item !== '..'));
    if (count($entries) !== 1) {
        delete_tree($tempDir);
        return null;
    }

    $root = $tempDir . DIRECTORY_SEPARATOR . $entries[0];
    return is_dir($root) ? $root : null;
}

function mirror_repo_tree(string $sourceDir, string $destDir, string $relativePath): void
{
    if (!is_dir($destDir) && !mkdir($destDir, 0777, true) && !is_dir($destDir)) {
        throw new RuntimeException('Could not create destination directory: ' . $destDir);
    }

    $sourceEntries = array_values(array_filter(scandir($sourceDir) ?: [], static fn($item) => $item !== '.' && $item !== '..'));
    $destEntries = array_values(array_filter(scandir($destDir) ?: [], static fn($item) => $item !== '.' && $item !== '..'));

    $sourceLookup = array_fill_keys($sourceEntries, true);

    foreach ($destEntries as $entry) {
        $childRel = ltrim($relativePath . '/' . $entry, '/');
        if (is_preserved_path($childRel)) {
            continue;
        }

        if (!isset($sourceLookup[$entry])) {
            delete_tree($destDir . DIRECTORY_SEPARATOR . $entry);
        }
    }

    foreach ($sourceEntries as $entry) {
        $sourcePath = $sourceDir . DIRECTORY_SEPARATOR . $entry;
        $destPath = $destDir . DIRECTORY_SEPARATOR . $entry;
        $childRel = ltrim($relativePath . '/' . $entry, '/');

        if (is_preserved_path($childRel)) {
            continue;
        }

        if (is_dir($sourcePath)) {
            mirror_repo_tree($sourcePath, $destPath, $childRel);
            continue;
        }

        $shouldCopy = true;
        if (is_file($destPath)) {
            $sourceHash = hash_file('sha256', $sourcePath);
            $destHash = hash_file('sha256', $destPath);
            $shouldCopy = ($sourceHash !== $destHash);
        } else {
            $parent = dirname($destPath);
            if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
                throw new RuntimeException('Could not create directory: ' . $parent);
            }
        }

        if ($shouldCopy && !copy($sourcePath, $destPath)) {
            throw new RuntimeException('Could not copy file: ' . $childRel);
        }
    }
}

function is_preserved_path(string $relativePath): bool
{
    $normalized = str_replace('\\', '/', ltrim($relativePath, '/'));
    foreach (PRESERVE_PATHS as $preserved) {
        if ($normalized === $preserved) {
            return true;
        }
    }
    return false;
}

function delete_tree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }

    $items = array_values(array_filter(scandir($path) ?: [], static fn($item) => $item !== '.' && $item !== '..'));
    foreach ($items as $item) {
        delete_tree($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}

function http_get(string $url, array $headers): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
        ]);

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($status >= 200 && $status < 300 && is_string($body)) {
            return $body;
        }

        return null;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'timeout' => 20,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if (!is_string($body)) {
        return null;
    }

    $status = http_status_from_response_headers($http_response_header ?? []);
    return ($status >= 200 && $status < 300) ? $body : null;
}

function http_download(string $url, array $headers, string $targetPath): int
{
    if (function_exists('curl_init')) {
        $fp = fopen($targetPath, 'wb');
        if ($fp === false) {
            return 0;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            fclose($fp);
            return 0;
        }

        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 60,
        ]);

        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($fp);

        if ($status >= 200 && $status < 400 && is_file($targetPath)) {
            return (int)filesize($targetPath);
        }

        @unlink($targetPath);
        return 0;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'follow_location' => 1,
            'ignore_errors' => true,
            'timeout' => 60,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if (!is_string($body)) {
        return 0;
    }

    $status = http_status_from_response_headers($http_response_header ?? []);
    if ($status >= 200 && $status < 400 && file_put_contents($targetPath, $body) !== false) {
        return (int)filesize($targetPath);
    }

    @unlink($targetPath);
    return 0;
}

function http_status_from_response_headers(array $headers): int
{
    foreach ($headers as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
            return (int)$matches[1];
        }
    }
    return 0;
}
