<?php
declare(strict_types=1);

date_default_timezone_set('UTC');

const DB_FILE = __DIR__ . '/technosphere.sqlite';
const JSON_DB_FILE = __DIR__ . '/technosphere_fallback.json';
const BROWSER_COOKIE = 'technosphere_browser_id';

function respond_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function clamp(float|int $value, float $min, float $max): float
{
    return max($min, min($max, (float)$value));
}

function now_iso(): string
{
    return gmdate('c');
}

function sqlite_available(): bool
{
    return in_array('sqlite', PDO::getAvailableDrivers(), true);
}

function storage_mode(): string
{
    return sqlite_available() ? 'sqlite' : 'json';
}

function browser_token(): string
{
    static $token = null;

    if (is_string($token) && $token !== '') {
        return $token;
    }

    $incoming = $_COOKIE[BROWSER_COOKIE] ?? '';
    if (is_string($incoming) && preg_match('/^[a-f0-9]{32}$/', $incoming) === 1) {
        $token = $incoming;
        return $token;
    }

    try {
        $token = bin2hex(random_bytes(16));
    } catch (Throwable) {
        $token = hash('sha256', uniqid('tech-', true) . microtime(true));
        $token = substr($token, 0, 32);
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie(BROWSER_COOKIE, $token, [
        'expires' => time() + 60 * 60 * 24 * 365 * 2,
        'path' => '/',
        'secure' => $secure,
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[BROWSER_COOKIE] = $token;

    return $token;
}

function default_genes(): array
{
    return [
        'size' => 1.00,
        'speed' => 1.15,
        'sense' => 18.00,
        'hue' => 0.42,
        'limbs' => 4,
        'crest' => 0.30,
        'agility' => 0.72,
        'metabolism' => 1.00,
        'bodyNoise' => 0.15,
        'bodyLength' => 1.20,
        'headSize' => 1.00,
        'legLength' => 1.05,
        'tailLength' => 0.80,
    ];
}

function normalize_genes(array $genes): array
{
    $base = default_genes();

    foreach ($base as $key => $default) {
        if (!array_key_exists($key, $genes)) {
            $genes[$key] = $default;
        }
    }

    return [
        'size' => round(clamp((float)$genes['size'], 0.55, 2.40), 4),
        'speed' => round(clamp((float)$genes['speed'], 0.40, 3.30), 4),
        'sense' => round(clamp((float)$genes['sense'], 6.00, 52.00), 4),
        'hue' => round(clamp((float)$genes['hue'], 0.00, 1.00), 4),
        'limbs' => (int)round(clamp((float)$genes['limbs'], 2, 8)),
        'crest' => round(clamp((float)$genes['crest'], 0.00, 1.00), 4),
        'agility' => round(clamp((float)$genes['agility'], 0.10, 1.50), 4),
        'metabolism' => round(clamp((float)$genes['metabolism'], 0.55, 1.65), 4),
        'bodyNoise' => round(clamp((float)$genes['bodyNoise'], 0.00, 0.45), 4),
        'bodyLength' => round(clamp((float)$genes['bodyLength'], 0.70, 2.80), 4),
        'headSize' => round(clamp((float)$genes['headSize'], 0.60, 1.80), 4),
        'legLength' => round(clamp((float)$genes['legLength'], 0.60, 2.20), 4),
        'tailLength' => round(clamp((float)$genes['tailLength'], 0.00, 2.40), 4),
    ];
}

function creature_template(string $browserToken, string $name = 'Technosphere-01'): array
{
    $timestamp = now_iso();

    return [
        'id' => 0,
        'browser_token' => $browserToken,
        'name' => $name,
        'age' => 0.0,
        'generation' => 1,
        'energy' => 120.0,
        'x' => 0.0,
        'z' => 0.0,
        'rotation' => 0.0,
        'evolution_points' => 0.0,
        'foods_eaten' => 0,
        'last_evolved_age' => 0.0,
        'genes' => normalize_genes(default_genes()),
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ];
}

function materialize_creature_payload(array $payload, ?array $current = null): array
{
    $current ??= creature_template(browser_token());

    $genes = normalize_genes(is_array($payload['genes'] ?? null) ? $payload['genes'] : $current['genes']);
    $name = trim((string)($payload['name'] ?? $current['name']));
    if ($name === '') {
        $name = $current['name'];
    }

    return [
        'id' => (int)($current['id'] ?? 0),
        'browser_token' => (string)($current['browser_token'] ?? browser_token()),
        'name' => $name,
        'age' => round(clamp((float)($payload['age'] ?? $current['age']), 0, 100000), 4),
        'generation' => (int)round(clamp((float)($payload['generation'] ?? $current['generation']), 1, 99999)),
        'energy' => round(clamp((float)($payload['energy'] ?? $current['energy']), 0, 9999), 4),
        'x' => round(clamp((float)($payload['x'] ?? $current['x']), -50000, 50000), 4),
        'z' => round(clamp((float)($payload['z'] ?? $current['z']), -50000, 50000), 4),
        'rotation' => round((float)($payload['rotation'] ?? $current['rotation']), 6),
        'evolution_points' => round(clamp((float)($payload['evolution_points'] ?? $current['evolution_points']), 0, 999999), 4),
        'foods_eaten' => (int)round(clamp((float)($payload['foods_eaten'] ?? $current['foods_eaten']), 0, 999999)),
        'last_evolved_age' => round(clamp((float)($payload['last_evolved_age'] ?? $current['last_evolved_age']), 0, 100000), 4),
        'genes' => $genes,
        'created_at' => (string)($current['created_at'] ?? now_iso()),
        'updated_at' => now_iso(),
    ];
}

function creature_from_row(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'browser_token' => (string)$row['browser_token'],
        'name' => (string)$row['name'],
        'age' => (float)$row['age'],
        'generation' => (int)$row['generation'],
        'energy' => (float)$row['energy'],
        'x' => (float)$row['x'],
        'z' => (float)$row['z'],
        'rotation' => (float)$row['rotation'],
        'evolution_points' => (float)$row['evolution_points'],
        'foods_eaten' => (int)$row['foods_eaten'],
        'last_evolved_age' => (float)$row['last_evolved_age'],
        'genes' => normalize_genes(json_decode((string)$row['genes'], true) ?: []),
        'created_at' => (string)$row['created_at'],
        'updated_at' => (string)$row['updated_at'],
    ];
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!sqlite_available()) {
        throw new RuntimeException('SQLite support is not enabled in PHP.');
    }

    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS creatures (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            browser_token TEXT NOT NULL,
            name TEXT NOT NULL,
            age REAL NOT NULL DEFAULT 0,
            generation INTEGER NOT NULL DEFAULT 1,
            energy REAL NOT NULL DEFAULT 100,
            x REAL NOT NULL DEFAULT 0,
            z REAL NOT NULL DEFAULT 0,
            rotation REAL NOT NULL DEFAULT 0,
            evolution_points REAL NOT NULL DEFAULT 0,
            foods_eaten INTEGER NOT NULL DEFAULT 0,
            last_evolved_age REAL NOT NULL DEFAULT 0,
            genes TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    $columns = $pdo->query("PRAGMA table_info(creatures)")->fetchAll();
    $hasBrowserToken = false;
    foreach ($columns as $column) {
        if (($column['name'] ?? null) === 'browser_token') {
            $hasBrowserToken = true;
            break;
        }
    }

    if (!$hasBrowserToken) {
        $pdo->exec("ALTER TABLE creatures ADD COLUMN browser_token TEXT DEFAULT ''");
    }

    $pdo->exec("UPDATE creatures SET browser_token = 'legacy-' || id WHERE browser_token IS NULL OR browser_token = ''");
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_creatures_browser_token ON creatures(browser_token)');

    return $pdo;
}

function read_json_store(): array
{
    if (!is_file(JSON_DB_FILE)) {
        return ['creatures' => []];
    }

    $raw = file_get_contents(JSON_DB_FILE);
    if ($raw === false || trim($raw) === '') {
        return ['creatures' => []];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['creatures' => []];
    }

    if (isset($decoded['creatures']) && is_array($decoded['creatures'])) {
        return ['creatures' => $decoded['creatures']];
    }

    $legacy = materialize_creature_payload($decoded, creature_template(browser_token()));
    return ['creatures' => [browser_token() => $legacy]];
}

function write_json_store(array $store): void
{
    $json = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Could not encode fallback storage.');
    }

    $temp = JSON_DB_FILE . '.tmp';
    if (file_put_contents($temp, $json, LOCK_EX) === false) {
        throw new RuntimeException('Could not write fallback storage.');
    }

    if (!rename($temp, JSON_DB_FILE)) {
        @unlink($temp);
        throw new RuntimeException('Could not finalize fallback storage.');
    }
}

function sqlite_get_creature(string $browserToken): ?array
{
    $stmt = db()->prepare('SELECT * FROM creatures WHERE browser_token = :browser_token LIMIT 1');
    $stmt->execute([':browser_token' => $browserToken]);
    $row = $stmt->fetch();
    return $row ? creature_from_row($row) : null;
}

function sqlite_create_creature(string $browserToken, string $name = 'Technosphere-01'): array
{
    $creature = creature_template($browserToken, $name);

    $stmt = db()->prepare(
        'INSERT INTO creatures
        (browser_token, name, age, generation, energy, x, z, rotation, evolution_points, foods_eaten, last_evolved_age, genes, created_at, updated_at)
        VALUES
        (:browser_token, :name, :age, :generation, :energy, :x, :z, :rotation, :evolution_points, :foods_eaten, :last_evolved_age, :genes, :created_at, :updated_at)'
    );

    $stmt->execute([
        ':browser_token' => $creature['browser_token'],
        ':name' => $creature['name'],
        ':age' => $creature['age'],
        ':generation' => $creature['generation'],
        ':energy' => $creature['energy'],
        ':x' => $creature['x'],
        ':z' => $creature['z'],
        ':rotation' => $creature['rotation'],
        ':evolution_points' => $creature['evolution_points'],
        ':foods_eaten' => $creature['foods_eaten'],
        ':last_evolved_age' => $creature['last_evolved_age'],
        ':genes' => json_encode($creature['genes'], JSON_UNESCAPED_SLASHES),
        ':created_at' => $creature['created_at'],
        ':updated_at' => $creature['updated_at'],
    ]);

    return sqlite_get_creature($browserToken) ?? $creature;
}

function sqlite_save_creature(string $browserToken, array $payload): array
{
    $current = bootstrap_creature();
    $creature = materialize_creature_payload($payload, $current);

    $stmt = db()->prepare(
        'UPDATE creatures
         SET name = :name,
             age = :age,
             generation = :generation,
             energy = :energy,
             x = :x,
             z = :z,
             rotation = :rotation,
             evolution_points = :evolution_points,
             foods_eaten = :foods_eaten,
             last_evolved_age = :last_evolved_age,
             genes = :genes,
             updated_at = :updated_at
         WHERE browser_token = :browser_token'
    );

    $stmt->execute([
        ':browser_token' => $browserToken,
        ':name' => $creature['name'],
        ':age' => $creature['age'],
        ':generation' => $creature['generation'],
        ':energy' => $creature['energy'],
        ':x' => $creature['x'],
        ':z' => $creature['z'],
        ':rotation' => $creature['rotation'],
        ':evolution_points' => $creature['evolution_points'],
        ':foods_eaten' => $creature['foods_eaten'],
        ':last_evolved_age' => $creature['last_evolved_age'],
        ':genes' => json_encode($creature['genes'], JSON_UNESCAPED_SLASHES),
        ':updated_at' => $creature['updated_at'],
    ]);

    return sqlite_get_creature($browserToken) ?? $creature;
}

function sqlite_reset_creature(string $browserToken): array
{
    $stmt = db()->prepare('DELETE FROM creatures WHERE browser_token = :browser_token');
    $stmt->execute([':browser_token' => $browserToken]);

    return sqlite_create_creature($browserToken);
}

function json_get_creature(string $browserToken): ?array
{
    $store = read_json_store();
    $creatures = $store['creatures'] ?? [];
    if (!isset($creatures[$browserToken]) || !is_array($creatures[$browserToken])) {
        return null;
    }
    return materialize_creature_payload($creatures[$browserToken], $creatures[$browserToken]);
}

function json_create_creature(string $browserToken, string $name = 'Technosphere-01'): array
{
    $store = read_json_store();
    $creature = creature_template($browserToken, $name);
    $store['creatures'][$browserToken] = $creature;
    write_json_store($store);
    return $creature;
}

function json_save_creature(string $browserToken, array $payload): array
{
    $store = read_json_store();
    $current = bootstrap_creature();
    $creature = materialize_creature_payload($payload, $current);
    $store['creatures'][$browserToken] = $creature;
    write_json_store($store);
    return $creature;
}

function json_reset_creature(string $browserToken): array
{
    $store = read_json_store();
    unset($store['creatures'][$browserToken]);
    write_json_store($store);
    return json_create_creature($browserToken);
}

function get_creature(): ?array
{
    $browserToken = browser_token();
    return sqlite_available() ? sqlite_get_creature($browserToken) : json_get_creature($browserToken);
}

function create_creature(string $name = 'Technosphere-01'): array
{
    $browserToken = browser_token();
    return sqlite_available() ? sqlite_create_creature($browserToken, $name) : json_create_creature($browserToken, $name);
}

function bootstrap_creature(): array
{
    $creature = get_creature();
    return $creature ?? create_creature();
}

function save_creature(array $payload): array
{
    $browserToken = browser_token();
    return sqlite_available() ? sqlite_save_creature($browserToken, $payload) : json_save_creature($browserToken, $payload);
}

function reset_creature(): array
{
    $browserToken = browser_token();
    return sqlite_available() ? sqlite_reset_creature($browserToken) : json_reset_creature($browserToken);
}

$action = $_GET['action'] ?? null;

if ($action !== null) {
    try {
        if ($action === 'bootstrap') {
            $creature = bootstrap_creature();
            respond_json([
                'ok' => true,
                'creature' => $creature,
                'storageMode' => storage_mode(),
                'browserLinked' => true,
                'config' => [
                    'worldRadius' => 240,
                    'foodCount' => 320,
                    'treeCount' => 780,
                    'saveIntervalMs' => 2500,
                    'evolutionCooldown' => 22,
                ],
            ]);
        }

        if ($action === 'save') {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                respond_json(['ok' => false, 'error' => 'Use POST for save'], 405);
            }

            $raw = file_get_contents('php://input');
            $payload = json_decode($raw ?: '[]', true);

            if (!is_array($payload)) {
                respond_json(['ok' => false, 'error' => 'Invalid JSON body'], 400);
            }

            respond_json([
                'ok' => true,
                'creature' => save_creature($payload),
            ]);
        }

        if ($action === 'reset') {
            respond_json([
                'ok' => true,
                'creature' => reset_creature(),
            ]);
        }

        respond_json(['ok' => false, 'error' => 'Unknown action'], 404);
    } catch (Throwable $e) {
        respond_json([
            'ok' => false,
            'error' => $e->getMessage(),
        ], 500);
    }
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Technosphere Ecosystem</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            color-scheme: dark;
            --panel-bg: rgba(9, 13, 18, 0.76);
            --panel-border: rgba(255, 255, 255, 0.10);
            --text: #edf5ff;
            --muted: #9fb2c7;
            --accent: #81e7b8;
            --accent2: #7fd9ff;
        }
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            background: #05080d;
            color: var(--text);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        #app { position: fixed; inset: 0; }
        #hud, #logWrap, #editorWrap {
            position: fixed;
            background: var(--panel-bg);
            border: 1px solid var(--panel-border);
            border-radius: 20px;
            backdrop-filter: blur(12px);
            box-shadow: 0 18px 50px rgba(0,0,0,0.32);
            z-index: 20;
        }
        #hud {
            top: 16px;
            left: 16px;
            width: min(390px, calc(100vw - 32px));
            padding: 16px 16px 12px;
        }
        #hud h1, #editorWrap h2, #logTitle {
            margin: 0 0 10px;
            font-size: 0.98rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }
        #stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
            margin-bottom: 10px;
        }
        .stat, .gene {
            padding: 10px 12px;
            border-radius: 14px;
            background: rgba(255,255,255,0.045);
            border: 1px solid rgba(255,255,255,0.07);
        }
        .label {
            display: block;
            color: var(--muted);
            font-size: 0.75rem;
        }
        .value {
            display: block;
            margin-top: 3px;
            font-size: 1.04rem;
            font-weight: 700;
        }
        #genes {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
        }
        #controls {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 12px;
        }
        button {
            appearance: none;
            border: 0;
            border-radius: 999px;
            padding: 10px 14px;
            font-weight: 700;
            cursor: pointer;
            color: #031118;
            background: linear-gradient(135deg, var(--accent2), var(--accent));
        }
        button.secondary {
            color: var(--text);
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.10);
        }
        #logWrap {
            top: 16px;
            right: 16px;
            width: min(380px, calc(100vw - 32px));
            max-height: min(45vh, 440px);
            padding: 16px;
            display: flex;
            flex-direction: column;
        }
        #eventLog {
            overflow: auto;
            display: flex;
            flex-direction: column;
            gap: 8px;
            font-size: 0.89rem;
            line-height: 1.35;
        }
        .event {
            padding: 10px 12px;
            border-radius: 14px;
            background: rgba(255,255,255,0.045);
            border: 1px solid rgba(255,255,255,0.07);
        }
        .event strong { color: #9ef6bf; }
        #editorWrap {
            left: 16px;
            bottom: 66px;
            width: min(390px, calc(100vw - 32px));
            padding: 16px;
        }
        #editorGrid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px 12px;
        }
        .sliderRow label {
            display: block;
            font-size: 0.80rem;
            color: var(--muted);
            margin-bottom: 4px;
        }
        .sliderValue {
            float: right;
            color: var(--text);
        }
        .sliderRow input[type="range"] {
            width: 100%;
        }
        #editorButtons {
            display: flex;
            gap: 8px;
            margin-top: 12px;
            flex-wrap: wrap;
        }
        #footerHelp {
            position: fixed;
            left: 16px;
            bottom: 16px;
            padding: 10px 12px;
            border-radius: 16px;
            background: rgba(8,12,17,0.62);
            border: 1px solid rgba(255,255,255,0.08);
            color: var(--muted);
            z-index: 20;
            font-size: 0.88rem;
            backdrop-filter: blur(8px);
        }
        #toast {
            position: fixed;
            left: 50%;
            bottom: 22px;
            transform: translateX(-50%);
            background: rgba(6, 13, 19, 0.92);
            border: 1px solid rgba(129, 231, 184, 0.24);
            padding: 12px 16px;
            border-radius: 999px;
            opacity: 0;
            transition: opacity 0.18s ease;
            pointer-events: none;
            z-index: 25;
        }
        #toast.visible { opacity: 1; }
        #loading {
            position: fixed;
            inset: 0;
            display: grid;
            place-items: center;
            background: rgba(5,7,10,0.6);
            z-index: 40;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        @media (max-width: 980px) {
            #logWrap {
                top: auto;
                bottom: 290px;
                max-height: 28vh;
            }
            #genes { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 720px) {
            #hud, #logWrap, #editorWrap {
                width: calc(100vw - 24px);
                left: 12px;
                right: 12px;
            }
            #hud { top: 12px; }
            #logWrap { top: auto; bottom: 322px; }
            #editorWrap { bottom: 64px; }
            #footerHelp { left: 12px; bottom: 12px; }
        }
    </style>
</head>
<body>
<div id="app"></div>
<div id="loading">Booting technosphere ecosystem...</div>

<div id="hud">
    <h1>Technosphere Creature</h1>
    <div id="stats">
        <div class="stat"><span class="label">Name</span><span id="statName" class="value">...</span></div>
        <div class="stat"><span class="label">Generation</span><span id="statGeneration" class="value">0</span></div>
        <div class="stat"><span class="label">Age</span><span id="statAge" class="value">0s</span></div>
        <div class="stat"><span class="label">Energy</span><span id="statEnergy" class="value">0</span></div>
        <div class="stat"><span class="label">Food Eaten</span><span id="statFood" class="value">0</span></div>
        <div class="stat"><span class="label">State</span><span id="statBehavior" class="value">Pending</span></div>
        <div class="stat"><span class="label">Prey / Predators</span><span id="statFauna" class="value">0 / 0</span></div>
        <div class="stat"><span class="label">Browser Link</span><span id="statLinked" class="value">Pending</span></div>
    </div>
    <div id="genes"></div>
    <div id="controls">
        <button id="pauseBtn" class="secondary">Pause</button>
        <button id="renameBtn" class="secondary">Rename</button>
        <button id="focusBtn" class="secondary">Reset Camera</button>
        <button id="resetBtn">Reset Browser Creature</button>
    </div>
</div>

<div id="logWrap">
    <div id="logTitle">Ecosystem log</div>
    <div id="eventLog"></div>
</div>

<div id="editorWrap">
    <h2>Creature editor</h2>
    <div id="editorGrid"></div>
    <div id="editorButtons">
        <button id="applyCustomBtn">Apply body</button>
        <button id="randomizeBtn" class="secondary">Randomize</button>
    </div>
</div>

<div id="footerHelp">
    Smooth third-person camera follows your browser creature. Drag to look around. Scroll to zoom. The minimap tracks prey, predators, food, and your creature across the wider ecosystem.
</div>

<div id="toast"></div>

<script>
(() => {
const app = document.getElementById('app');
const canvas = document.createElement('canvas');
canvas.style.width = '100%';
canvas.style.height = '100%';
canvas.style.display = 'block';
app.appendChild(canvas);

const ctx = canvas.getContext('2d');
if (!ctx) {
    document.getElementById('loading').textContent = 'Error: Canvas 2D is not available in this browser.';
    return;
}

const UI = {
    loading: document.getElementById('loading'),
    pauseBtn: document.getElementById('pauseBtn'),
    renameBtn: document.getElementById('renameBtn'),
    focusBtn: document.getElementById('focusBtn'),
    resetBtn: document.getElementById('resetBtn'),
    applyCustomBtn: document.getElementById('applyCustomBtn'),
    randomizeBtn: document.getElementById('randomizeBtn'),
    eventLog: document.getElementById('eventLog'),
    editorGrid: document.getElementById('editorGrid'),
    genes: document.getElementById('genes'),
    toast: document.getElementById('toast'),
    statName: document.getElementById('statName'),
    statGeneration: document.getElementById('statGeneration'),
    statAge: document.getElementById('statAge'),
    statEnergy: document.getElementById('statEnergy'),
    statFood: document.getElementById('statFood'),
    statBehavior: document.getElementById('statBehavior'),
    statFauna: document.getElementById('statFauna'),
    statLinked: document.getElementById('statLinked'),
};

const geneLabels = {
    size: 'Size',
    speed: 'Speed',
    sense: 'Sense',
    hue: 'Hue',
    limbs: 'Limbs',
    crest: 'Crest',
    agility: 'Agility',
    metabolism: 'Metabolism',
    bodyNoise: 'Noise',
    bodyLength: 'Body',
    headSize: 'Head',
    legLength: 'Legs',
    tailLength: 'Tail',
};

const geneRanges = {
    size: [0.55, 2.40],
    speed: [0.40, 3.30],
    sense: [6.00, 52.00],
    hue: [0.00, 1.00],
    limbs: [2, 8],
    crest: [0.00, 1.00],
    agility: [0.10, 1.50],
    metabolism: [0.55, 1.65],
    bodyNoise: [0.00, 0.45],
    bodyLength: [0.70, 2.80],
    headSize: [0.60, 1.80],
    legLength: [0.60, 2.20],
    tailLength: [0.00, 2.40],
};

const editableGeneKeys = ['limbs', 'size', 'bodyLength', 'headSize', 'legLength', 'tailLength', 'hue', 'crest'];
const behaviourLabels = {
    wander: 'Wandering',
    forage: 'Foraging',
    flee: 'Fleeing',
    hunt: 'Hunting',
    rest: 'Resting',
    sleep: 'Sleeping',
    mate: 'Mating',
    graze: 'Grazing',
};

const state = {
    ready: false,
    paused: false,
    saveAccumulator: 0,
    toastTimer: null,
    config: {
        worldRadius: 420,
        foodCount: 95,
        treeCount: 1400,
        preyCount: 22,
        predatorCount: 7,
        saveIntervalMs: 2500,
        evolutionCooldown: 24,
    },
    creature: null,
    creatureRuntime: null,
    displayGenes: null,
    worldTime: 0,
    wanderAngle: 0,
    eventHistory: [],
    nearestFoodCache: null,
    browserLinked: false,
    editorInputs: {},
};

const foods = [];
const trees = [];
const stars = [];
const preyAgents = [];
const predatorAgents = [];
const terrainCache = new Map();

const camera = {
    yaw: Math.PI,
    pitch: 0.44,
    distance: 18,
    yawOffset: 0,
    pitchOffset: 0,
    distanceOffset: 0,
    target: { x: 0, y: 2, z: 0 },
    pos: { x: 0, y: 0, z: 0 },
    basis: null,
};

const pointer = {
    active: false,
    lastX: 0,
    lastY: 0,
};

let screen = { width: 1, height: 1, cx: 0.5, cy: 0.5, focal: 700 };
let lastTime = performance.now();
let nextAgentId = 1;

function clamp(value, min, max) { return Math.max(min, Math.min(max, value)); }
function lerp(a, b, t) { return a + (b - a) * t; }
function smoothstep(a, b, x) {
    const t = clamp((x - a) / (b - a), 0, 1);
    return t * t * (3 - 2 * t);
}
function rnd(min, max) { return min + Math.random() * (max - min); }
function chance(p) { return Math.random() < p; }
function wrapAngle(angle) {
    while (angle > Math.PI) angle -= Math.PI * 2;
    while (angle < -Math.PI) angle += Math.PI * 2;
    return angle;
}
function vec3(x = 0, y = 0, z = 0) { return { x, y, z }; }
function sub(a, b) { return { x: a.x - b.x, y: a.y - b.y, z: a.z - b.z }; }
function dot(a, b) { return a.x * b.x + a.y * b.y + a.z * b.z; }
function cross(a, b) { return { x: a.y * b.z - a.z * b.y, y: a.z * b.x - a.x * b.z, z: a.x * b.y - a.y * b.x }; }
function length(v) { return Math.hypot(v.x, v.y, v.z); }
function normalize(v) {
    const len = length(v) || 1;
    return { x: v.x / len, y: v.y / len, z: v.z / len };
}
function dist2d(a, b) { return Math.hypot(a.x - b.x, a.z - b.z); }
function unitFromAngle(a) { return { x: Math.sin(a), z: Math.cos(a) }; }
function angleTo(dx, dz) { return Math.atan2(dx, dz); }
function headingTo(from, to) { return angleTo(to.x - from.x, to.z - from.z); }
function hsl(h, s, l, a = 1) {
    return `hsla(${((h % 1 + 1) % 1) * 360}, ${s * 100}%, ${l * 100}%, ${a})`;
}
function hash2(x, z) {
    const s = Math.sin(x * 127.1 + z * 311.7) * 43758.5453123;
    return s - Math.floor(s);
}
function valueNoise(x, z) {
    const xi = Math.floor(x);
    const zi = Math.floor(z);
    const xf = x - xi;
    const zf = z - zi;
    const u = xf * xf * (3 - 2 * xf);
    const v = zf * zf * (3 - 2 * zf);
    const n00 = hash2(xi, zi);
    const n10 = hash2(xi + 1, zi);
    const n01 = hash2(xi, zi + 1);
    const n11 = hash2(xi + 1, zi + 1);
    return lerp(lerp(n00, n10, u), lerp(n01, n11, u), v);
}
function fractalNoise(x, z, octaves = 4) {
    let total = 0;
    let amp = 1;
    let freq = 1;
    let norm = 0;
    for (let i = 0; i < octaves; i++) {
        total += valueNoise(x * freq, z * freq) * amp;
        norm += amp;
        amp *= 0.5;
        freq *= 2.03;
    }
    return total / norm;
}
function terrainKey(x, z) { return `${Math.round(x * 10) / 10}|${Math.round(z * 10) / 10}`; }

function terrainHeightAt(x, z) {
    const key = terrainKey(x, z);
    if (terrainCache.has(key)) return terrainCache.get(key);
    const continent = (fractalNoise(x * 0.0032, z * 0.0032, 5) - 0.46) * 30;
    const hills = (fractalNoise(x * 0.011, z * 0.011, 4) - 0.5) * 18;
    const ridges = Math.abs(fractalNoise(x * 0.022, z * 0.022, 4) - 0.5) * 12;
    const dryValleys = (fractalNoise(x * 0.007 + 13, z * 0.007 + 13, 3) - 0.5) * -10;
    const ripples = Math.sin(x * 0.028) * 1.4 + Math.cos(z * 0.024) * 1.1;
    const y = continent + hills + ridges * 0.7 + dryValleys + ripples;
    terrainCache.set(key, y);
    return y;
}
function terrainNormal(x, z) {
    const e = 1.35;
    const hL = terrainHeightAt(x - e, z);
    const hR = terrainHeightAt(x + e, z);
    const hD = terrainHeightAt(x, z - e);
    const hU = terrainHeightAt(x, z + e);
    return normalize(vec3(hL - hR, 2 * e, hD - hU));
}
function biomeColor(x, z, y) {
    const wet = fractalNoise(x * 0.008 + 10, z * 0.008 + 10, 3);
    if (y > 18) return hsl(0.10, 0.16, 0.50 + smoothstep(18, 32, y) * 0.12, 1);
    if (y < -4) return hsl(0.13, 0.28, 0.22 + wet * 0.06, 1);
    return hsl(lerp(0.25, 0.37, wet), 0.42, 0.23 + smoothstep(-4, 18, y) * 0.20, 1);
}
function randomWorldPosition(margin = 18) {
    const radius = rnd(14, state.config.worldRadius - margin);
    const angle = Math.random() * Math.PI * 2;
    return { x: Math.cos(angle) * radius, z: Math.sin(angle) * radius };
}
function findLandPosition(maxTries = 70) {
    for (let i = 0; i < maxTries; i++) {
        const pos = randomWorldPosition(12);
        const y = terrainHeightAt(pos.x, pos.z);
        const normal = terrainNormal(pos.x, pos.z);
        if (normal.y > 0.72 && y > -10 && y < 26) return pos;
    }
    return { x: 0, z: 0 };
}

function defaultGenes() {
    return {
        size: 1.00,
        speed: 1.15,
        sense: 18.00,
        hue: 0.42,
        limbs: 4,
        crest: 0.30,
        agility: 0.72,
        metabolism: 1.00,
        bodyNoise: 0.15,
        bodyLength: 1.20,
        headSize: 1.00,
        legLength: 1.05,
        tailLength: 0.80,
    };
}
function applyRange(key, value) {
    const [min, max] = geneRanges[key];
    return clamp(value, min, max);
}
function normalizeGenes(genes) {
    const result = {};
    const base = defaultGenes();
    for (const [key, [min, max]] of Object.entries(geneRanges)) {
        let value = genes[key];
        if (value === undefined || Number.isNaN(Number(value))) value = base[key];
        result[key] = clamp(Number(value), min, max);
    }
    result.limbs = Math.round(result.limbs);
    return result;
}
function geneValueDisplay(key, value) {
    if (key === 'limbs') return String(Math.round(value));
    return Number(value).toFixed(2);
}
function showToast(text) {
    UI.toast.textContent = text;
    UI.toast.classList.add('visible');
    clearTimeout(state.toastTimer);
    state.toastTimer = setTimeout(() => UI.toast.classList.remove('visible'), 1800);
}
function logEvent(html) {
    state.eventHistory.unshift({ html, createdAt: Date.now() });
    state.eventHistory = state.eventHistory.slice(0, 16);
    UI.eventLog.innerHTML = state.eventHistory.map(item => `<div class="event">${item.html}</div>`).join('');
}

function buildFoods() {
    foods.length = 0;
    for (let i = 0; i < state.config.foodCount; i++) {
        const pos = findLandPosition(90);
        foods.push({ x: pos.x, z: pos.z, bob: Math.random() * Math.PI * 2, alive: true, cooldown: 0 });
    }
}
function respawnFood(food) {
    const pos = findLandPosition(90);
    food.x = pos.x;
    food.z = pos.z;
    food.alive = true;
    food.cooldown = 0;
    food.bob = Math.random() * Math.PI * 2;
}
function buildTrees() {
    trees.length = 0;
    let attempts = 0;
    while (trees.length < state.config.treeCount && attempts < state.config.treeCount * 8) {
        attempts += 1;
        const pos = randomWorldPosition(10);
        const y = terrainHeightAt(pos.x, pos.z);
        const normal = terrainNormal(pos.x, pos.z);
        if (normal.y < 0.78 || y > 24 || y < -7) continue;
        const density = fractalNoise(pos.x * 0.016 + 7, pos.z * 0.016 + 7, 3);
        if (density < 0.34) continue;
        trees.push({
            x: pos.x,
            z: pos.z,
            y,
            trunkHeight: rnd(4.8, 10.8) * lerp(0.8, 1.35, density),
            crown: rnd(2.1, 4.8),
            tilt: rnd(-0.18, 0.18),
            hue: rnd(0.27, 0.37),
            trunkHue: rnd(0.06, 0.10),
        });
    }
}
function buildStars() {
    stars.length = 0;
    for (let i = 0; i < 1000; i++) {
        const radius = 220 + Math.random() * 220;
        const theta = Math.random() * Math.PI * 2;
        const phi = Math.random() * Math.PI * 0.76;
        stars.push({
            x: Math.cos(theta) * Math.sin(phi) * radius,
            y: Math.cos(phi) * radius + 110,
            z: Math.sin(theta) * Math.sin(phi) * radius,
            size: rnd(0.7, 1.8),
        });
    }
}
function createWildGenes(kind) {
    if (kind === 'predator') {
        return normalizeGenes({
            size: rnd(0.95, 1.55),
            speed: rnd(1.15, 1.95),
            sense: rnd(20, 34),
            hue: rnd(0.03, 0.12),
            limbs: chance(0.2) ? 6 : 4,
            crest: rnd(0.05, 0.28),
            agility: rnd(0.75, 1.25),
            metabolism: rnd(0.95, 1.25),
            bodyNoise: rnd(0.04, 0.18),
            bodyLength: rnd(1.5, 2.5),
            headSize: rnd(1.05, 1.55),
            legLength: rnd(1.0, 1.8),
            tailLength: rnd(0.8, 2.0),
        });
    }
    return normalizeGenes({
        size: rnd(0.75, 1.25),
        speed: rnd(0.9, 1.6),
        sense: rnd(16, 30),
        hue: rnd(0.22, 0.48),
        limbs: chance(0.28) ? 6 : 4,
        crest: rnd(0.08, 0.38),
        agility: rnd(0.65, 1.2),
        metabolism: rnd(0.78, 1.12),
        bodyNoise: rnd(0.02, 0.16),
        bodyLength: rnd(1.2, 2.2),
        headSize: rnd(0.78, 1.10),
        legLength: rnd(0.9, 1.9),
        tailLength: rnd(0.4, 1.8),
    });
}
function createAgent(kind, pos = null) {
    const genes = createWildGenes(kind);
    const p = pos || findLandPosition(80);
    const adultAge = kind === 'predator' ? rnd(30, 45) : rnd(20, 34);
    return {
        id: nextAgentId++,
        kind,
        sex: chance(0.5) ? 'F' : 'M',
        x: p.x,
        z: p.z,
        rotation: Math.random() * Math.PI * 2,
        age: rnd(adultAge * 0.4, adultAge * 2.1),
        adultAge,
        energy: rnd(70, 150),
        genes,
        behavior: 'wander',
        stateTimer: rnd(2, 8),
        sleepDrive: rnd(0, 80),
        sleepThreshold: rnd(90, 125),
        mateCooldown: rnd(0, 30),
        mateTargetId: null,
        alive: true,
    };
}
function buildFauna() {
    preyAgents.length = 0;
    predatorAgents.length = 0;
    nextAgentId = 1;
    for (let i = 0; i < state.config.preyCount; i++) preyAgents.push(createAgent('prey'));
    for (let i = 0; i < state.config.predatorCount; i++) predatorAgents.push(createAgent('predator'));
}

function ensureCreatureRuntime() {
    if (!state.creature) return;
    if (!state.creatureRuntime) {
        state.creatureRuntime = {
            id: 1000000 + Number(state.creature.id || 0),
            sex: ((Number(state.creature.id || 0) % 2) === 0) ? 'F' : 'M',
            adultAge: 24,
            behavior: 'wander',
            stateTimer: rnd(3, 9),
            sleepDrive: rnd(10, 70),
            sleepThreshold: rnd(100, 125),
            mateCooldown: rnd(10, 40),
            mateTargetId: null,
        };
    }
}

function setupEditor() {
    UI.editorGrid.innerHTML = editableGeneKeys.map(key => {
        const [min, max] = geneRanges[key];
        const step = key === 'limbs' ? 1 : 0.01;
        return `<div class="sliderRow">
            <label>${geneLabels[key]} <span class="sliderValue" id="editorValue_${key}"></span></label>
            <input id="editor_${key}" type="range" min="${min}" max="${max}" step="${step}" />
        </div>`;
    }).join('');
    for (const key of editableGeneKeys) {
        const input = document.getElementById(`editor_${key}`);
        const valueEl = document.getElementById(`editorValue_${key}`);
        state.editorInputs[key] = { input, valueEl };
        input.addEventListener('input', () => {
            let v = Number(input.value);
            if (key === 'limbs') v = Math.round(v);
            valueEl.textContent = key === 'limbs' ? String(v) : v.toFixed(2);
        });
    }
}
function syncEditorFromCreature() {
    if (!state.creature) return;
    for (const key of editableGeneKeys) {
        const pair = state.editorInputs[key];
        if (!pair) continue;
        const val = state.creature.genes[key];
        pair.input.value = String(val);
        pair.valueEl.textContent = key === 'limbs' ? String(Math.round(val)) : Number(val).toFixed(2);
    }
}
function collectEditorGenes() {
    const next = { ...state.creature.genes };
    for (const key of editableGeneKeys) {
        const pair = state.editorInputs[key];
        let v = Number(pair.input.value);
        if (key === 'limbs') v = Math.round(v);
        next[key] = applyRange(key, v);
    }
    return normalizeGenes(next);
}

function updateThirdPersonCamera(dt) {
    if (!state.creature) return;

    const c = state.creature;
    const g = state.displayGenes || c.genes;
    const forward = unitFromAngle(c.rotation);
    const moveBias = clamp(g.speed / 3.3, 0, 1);

    camera.yawOffset = lerp(camera.yawOffset, 0, Math.min(1, dt * 0.45));
    camera.pitchOffset = lerp(camera.pitchOffset, 0, Math.min(1, dt * 0.28));

    const desiredYaw = wrapAngle(c.rotation + Math.PI + camera.yawOffset);
    camera.yaw = steerTowardsAngle(camera.yaw, desiredYaw, Math.min(1, dt * (3.2 + g.agility * 1.1)));

    const desiredPitch = clamp(0.34 + g.size * 0.07 + g.legLength * 0.09 + camera.pitchOffset, 0.24, 0.88);
    camera.pitch = lerp(camera.pitch, desiredPitch, Math.min(1, dt * 3.2));

    const baseDistance = 11.5 + g.bodyLength * 3.0 + g.tailLength * 1.1 + g.legLength * 1.3 + moveBias * 2.2;
    const desiredDistance = clamp(baseDistance + camera.distanceOffset, 9, 34);
    camera.distance = lerp(camera.distance, desiredDistance, Math.min(1, dt * 2.8));

    const lookAhead = 1.3 + g.bodyLength * 0.9 + moveBias * 1.5;
    const desiredTarget = {
        x: c.x + forward.x * lookAhead,
        y: terrainHeightAt(c.x, c.z) + 1.5 + g.size * 0.55 + g.legLength * 0.45,
        z: c.z + forward.z * lookAhead,
    };

    camera.target.x = lerp(camera.target.x, desiredTarget.x, Math.min(1, dt * 4.2));
    camera.target.y = lerp(camera.target.y, desiredTarget.y, Math.min(1, dt * 4.0));
    camera.target.z = lerp(camera.target.z, desiredTarget.z, Math.min(1, dt * 4.2));
}
function updateCamera() {
    const cp = Math.cos(camera.pitch);
    camera.pos.x = camera.target.x + Math.sin(camera.yaw) * cp * camera.distance;
    camera.pos.y = camera.target.y + Math.sin(camera.pitch) * camera.distance;
    camera.pos.z = camera.target.z + Math.cos(camera.yaw) * cp * camera.distance;
    const forward = normalize(sub(camera.target, camera.pos));
    let right = normalize(cross(forward, vec3(0, 1, 0)));
    if (length(right) < 1e-6) right = vec3(1, 0, 0);
    const up = normalize(cross(right, forward));
    camera.basis = { forward, right, up };
}
function projectPoint(p) {
    const rel = sub(p, camera.pos);
    const x = dot(rel, camera.basis.right);
    const y = dot(rel, camera.basis.up);
    const z = dot(rel, camera.basis.forward);
    if (z <= 0.12) return null;
    const scale = screen.focal / z;
    return { x: screen.cx + x * scale, y: screen.cy - y * scale, z, scale };
}
function pushPolygon(commands, points, fillStyle, strokeStyle = null, alpha = 1) {
    const projected = [];
    let depth = 0;
    for (const point of points) {
        const p = projectPoint(point);
        if (!p) return;
        projected.push(p);
        depth += p.z;
    }
    commands.push({ depth: depth / projected.length, type: 'polygon', points: projected, fillStyle, strokeStyle, alpha });
}
function pushLine(commands, a, b, width, color, alpha = 1) {
    const pa = projectPoint(a);
    const pb = projectPoint(b);
    if (!pa || !pb) return;
    commands.push({
        depth: (pa.z + pb.z) * 0.5,
        type: 'line',
        ax: pa.x, ay: pa.y, bx: pb.x, by: pb.y,
        width: Math.max(1, width * ((pa.scale + pb.scale) * 0.5)),
        color, alpha,
    });
}
function pushSphere(commands, center, radius, fillStyle, strokeStyle = null, alpha = 1) {
    const p = projectPoint(center);
    if (!p) return;
    commands.push({ depth: p.z, type: 'sphere', x: p.x, y: p.y, r: Math.max(1, radius * p.scale), fillStyle, strokeStyle, alpha });
}
function pushRing(commands, center, radius, color, alpha = 0.7, lineWidth = 1.2) {
    const p = projectPoint(center);
    if (!p) return;
    commands.push({ depth: p.z + 0.01, type: 'ring', x: p.x, y: p.y, r: Math.max(1, radius * p.scale), color, alpha, width: Math.max(1, lineWidth * p.scale * 0.2) });
}
function localToWorld(local, origin, yaw) {
    const s = Math.sin(yaw);
    const c = Math.cos(yaw);
    return { x: origin.x + local.x * c + local.z * s, y: origin.y + local.y, z: origin.z + local.z * c - local.x * s };
}

function nearestFood(x, z) {
    let best = null;
    let bestDistSq = Infinity;
    for (const food of foods) {
        if (!food.alive) continue;
        const dx = food.x - x;
        const dz = food.z - z;
        const distSq = dx * dx + dz * dz;
        if (distSq < bestDistSq) {
            bestDistSq = distSq;
            best = food;
        }
    }
    state.nearestFoodCache = best;
    return best;
}
function nearestFoodForAgent(agent) {
    let best = null;
    let bestDistSq = Infinity;
    for (const food of foods) {
        if (!food.alive) continue;
        const dx = food.x - agent.x;
        const dz = food.z - agent.z;
        const distSq = dx * dx + dz * dz;
        if (distSq < bestDistSq) {
            bestDistSq = distSq;
            best = food;
        }
    }
    return best;
}
function nearestAgent(list, subject, maxDist = Infinity, filterFn = null) {
    let best = null;
    let bestDist = maxDist;
    for (const agent of list) {
        if (!agent.alive || agent.id === subject.id) continue;
        if (filterFn && !filterFn(agent)) continue;
        const d = dist2d(agent, subject);
        if (d < bestDist) {
            bestDist = d;
            best = agent;
        }
    }
    return best;
}
function getNeighbors(list, subject, radius, filterFn = null) {
    const out = [];
    for (const agent of list) {
        if (!agent.alive || agent.id === subject.id) continue;
        if (filterFn && !filterFn(agent)) continue;
        if (dist2d(agent, subject) <= radius) out.push(agent);
    }
    return out;
}
function behaviorLabel(value) {
    return behaviourLabels[value] || value;
}
function adult(agent) { return agent.age >= agent.adultAge; }
function canMate(agent) {
    return adult(agent) && agent.energy >= 75 && agent.mateCooldown <= 0 && agent.behavior !== 'sleep';
}
function setAgentBehavior(agent, value, timer = null) {
    agent.behavior = value;
    if (timer != null) agent.stateTimer = timer;
}
function steerTowardsAngle(current, target, amount) {
    const delta = wrapAngle(target - current);
    return current + delta * amount;
}
function boundPosition(agent) {
    const dist = Math.hypot(agent.x, agent.z);
    const maxRadius = state.config.worldRadius - 8;
    if (dist > maxRadius) {
        const s = maxRadius / dist;
        agent.x *= s;
        agent.z *= s;
        agent.rotation += Math.PI * 0.8;
    }
}
function terrainSafeMove(agent, nextX, nextZ) {
    const normal = terrainNormal(nextX, nextZ);
    if (normal.y < 0.58) return false;
    const y = terrainHeightAt(nextX, nextZ);
    return y > -15 && y < 34;
}
function spawnChild(kind, a, b) {
    const genes = {};
    const keys = Object.keys(geneRanges);
    for (const key of keys) {
        const mix = lerp(a.genes[key], b.genes[key], rnd(0.35, 0.65));
        const amplitude = key === 'limbs' ? 1 : (key === 'hue' ? 0.06 : 0.12);
        genes[key] = mix + rnd(-amplitude, amplitude);
    }
    const child = {
        id: nextAgentId++,
        kind,
        sex: chance(0.5) ? 'F' : 'M',
        x: lerp(a.x, b.x, 0.5) + rnd(-2, 2),
        z: lerp(a.z, b.z, 0.5) + rnd(-2, 2),
        rotation: Math.random() * Math.PI * 2,
        age: 0,
        adultAge: kind === 'predator' ? rnd(30, 45) : rnd(20, 34),
        energy: rnd(45, 70),
        genes: normalizeGenes(genes),
        behavior: 'rest',
        stateTimer: rnd(3, 6),
        sleepDrive: rnd(0, 18),
        sleepThreshold: rnd(95, 125),
        mateCooldown: rnd(30, 50),
        mateTargetId: null,
        alive: true,
    };
    if (kind === 'predator') predatorAgents.push(child); else preyAgents.push(child);
}
function tryInitiateMating(agent, population) {
    if (!canMate(agent) || agent.behavior === 'mate') return false;
    const partner = nearestAgent(population, agent, 26, other =>
        canMate(other) &&
        other.sex !== agent.sex &&
        other.behavior !== 'mate'
    );
    if (!partner) return false;
    agent.mateTargetId = partner.id;
    partner.mateTargetId = agent.id;
    setAgentBehavior(agent, 'mate', 7);
    setAgentBehavior(partner, 'mate', 7);
    return true;
}

function mutateCreature() {
    const g = { ...state.creature.genes };
    const before = { ...g };
    const drift = (key, amount) => { g[key] = applyRange(key, g[key] + rnd(-amount, amount)); };
    drift('size', 0.18);
    drift('speed', 0.20);
    drift('sense', 2.8);
    drift('hue', 0.08);
    drift('crest', 0.15);
    drift('agility', 0.14);
    drift('metabolism', 0.08);
    drift('bodyNoise', 0.06);
    drift('bodyLength', 0.18);
    drift('headSize', 0.14);
    drift('legLength', 0.16);
    drift('tailLength', 0.18);
    if (Math.random() < 0.45) g.limbs = Math.round(applyRange('limbs', g.limbs + (Math.random() < 0.5 ? -1 : 1)));
    g.limbs = Math.round(g.limbs);
    g.hue = ((g.hue % 1) + 1) % 1;
    state.creature.genes = normalizeGenes(g);
    state.creature.generation += 1;
    state.creature.last_evolved_age = state.creature.age;
    state.creature.energy = clamp(state.creature.energy - 14, 18, 999);
    state.creature.evolution_points = Math.max(0, state.creature.evolution_points - 18);
    logEvent(
        `<strong>Generation ${state.creature.generation}</strong> emerged: ` +
        `body ${state.creature.genes.bodyLength.toFixed(2)}, legs ${state.creature.genes.legLength.toFixed(2)}, tail ${state.creature.genes.tailLength.toFixed(2)}.`
    );
    showToast(`Evolved to generation ${state.creature.generation}`);
    syncEditorFromCreature();
}
function canEvolve() {
    const c = state.creature;
    return c.age - c.last_evolved_age >= state.config.evolutionCooldown &&
        c.evolution_points >= 18 + c.generation * 4 &&
        c.energy >= 45;
}

function updateFood(dt) {
    for (const food of foods) {
        if (food.alive) {
            food.bob += dt * 2;
        } else {
            food.cooldown -= dt;
            if (food.cooldown <= 0) respawnFood(food);
        }
    }
}

function updateCreatureRuntime(dt) {
    ensureCreatureRuntime();
    const c = state.creature;
    const rt = state.creatureRuntime;
    const g = c.genes;
    rt.stateTimer -= dt;
    rt.mateCooldown = Math.max(0, rt.mateCooldown - dt);
    rt.sleepDrive += dt * (1.8 + g.metabolism * 0.4);

    if (rt.behavior === 'sleep') {
        c.energy = clamp(c.energy + dt * 6.5, 0, 200);
        rt.stateTimer -= dt * 0.2;
        if (rt.stateTimer <= 0 || c.energy > 150) {
            rt.sleepDrive = 0;
            setAgentBehavior(rt, 'wander', rnd(4, 8));
            logEvent(`<strong>${c.name}</strong> woke up and rejoined the technosphere.`);
        }
        return;
    }

    if (rt.sleepDrive > rt.sleepThreshold && rt.behavior !== 'mate' && rt.behavior !== 'flee') {
        setAgentBehavior(rt, 'sleep', rnd(7, 12));
        logEvent(`<strong>${c.name}</strong> curled up to sleep.`);
        return;
    }

    if (rt.behavior === 'rest') {
        c.energy = clamp(c.energy + dt * 2.0, 0, 200);
        if (rt.stateTimer <= 0) setAgentBehavior(rt, 'wander', rnd(5, 10));
        return;
    }

    const energyDrain = (0.9 + g.speed * 0.26 + g.size * 0.15 + g.limbs * 0.035) * g.metabolism;
    c.age += dt;
    c.energy = clamp(c.energy - energyDrain * dt + 0.14 * dt, 0, 200);
    c.evolution_points = clamp(c.evolution_points + 0.10 * dt, 0, 99999);

    const nearestPredator = nearestAgent(predatorAgents, c, 34);
    const userCanMate = c.age >= rt.adultAge && c.energy > 82 && rt.mateCooldown <= 0 && rt.behavior !== 'sleep';
    const mateSubject = { id: rt.id, x: c.x, z: c.z };
    const nearestMate = userCanMate ? nearestAgent(preyAgents, mateSubject, 26, p => canMate(p) && p.sex !== rt.sex) : null;

    let target = null;
    let desiredBehaviour = 'wander';

    if (nearestPredator) {
        const dx = c.x - nearestPredator.x;
        const dz = c.z - nearestPredator.z;
        target = { x: c.x + dx * 2.6, z: c.z + dz * 2.6 };
        desiredBehaviour = 'flee';
    } else if (nearestMate && dist2d(c, nearestMate) < 18 && c.age >= rt.adultAge && c.energy > 82 && rt.mateCooldown <= 0) {
        target = { x: nearestMate.x, z: nearestMate.z };
        desiredBehaviour = 'mate';
        if (dist2d(c, nearestMate) < 3.2) {
            c.energy = clamp(c.energy - 18, 0, 200);
            c.evolution_points = clamp(c.evolution_points + 10, 0, 99999);
            rt.mateCooldown = rnd(70, 95);
            setAgentBehavior(rt, 'rest', rnd(3, 6));
            spawnChild('prey', {
                x: c.x, z: c.z, genes: c.genes
            }, nearestMate);
            logEvent(`<strong>${c.name}</strong> mated with the herd and a calf joined the plain.`);
        }
    } else {
        const food = nearestFood(c.x, c.z);
        if (food && dist2d(c, food) <= g.sense) {
            target = { x: food.x, z: food.z };
            desiredBehaviour = 'forage';
        } else {
            if (chance(0.008)) state.wanderAngle += rnd(-1.5, 1.5);
            target = { x: c.x + Math.cos(state.wanderAngle) * 18, z: c.z + Math.sin(state.wanderAngle) * 18 };
        }
    }

    if (rt.stateTimer <= 0 && chance(0.12) && desiredBehaviour === 'wander') {
        setAgentBehavior(rt, 'rest', rnd(2, 5));
        return;
    }

    rt.behavior = desiredBehaviour;
    const desiredAngle = headingTo(c, target);
    c.rotation = steerTowardsAngle(c.rotation, desiredAngle, Math.min(1, dt * (1.5 + g.agility * 1.4)));
    const moveSpeed = g.speed * (desiredBehaviour === 'flee' ? 3.0 : desiredBehaviour === 'mate' ? 1.5 : 2.15) * dt * (0.4 + c.energy / 200);
    let nextX = c.x + Math.sin(c.rotation) * moveSpeed;
    let nextZ = c.z + Math.cos(c.rotation) * moveSpeed;
    if (!terrainSafeMove(c, nextX, nextZ)) {
        c.rotation += rnd(-1.2, 1.2);
    } else {
        c.x = nextX;
        c.z = nextZ;
    }
    boundPosition(c);

    for (const food of foods) {
        if (!food.alive) continue;
        if (dist2d(c, food) < 1.1 + g.size * 0.45) {
            food.alive = false;
            food.cooldown = 8 + Math.random() * 12;
            c.energy = clamp(c.energy + 16 + g.size * 2.2, 0, 200);
            c.evolution_points = clamp(c.evolution_points + 9 + g.agility * 2, 0, 99999);
            c.foods_eaten += 1;
            if (c.foods_eaten % 4 === 0) logEvent(`<strong>${c.name}</strong> located scarce nutrient nodes and now has ${c.foods_eaten}.`);
            break;
        }
    }

    if (canEvolve()) mutateCreature();

}

function updatePreyAgent(agent, dt) {
    if (!agent.alive) return;
    agent.age += dt * 0.35;
    agent.energy = clamp(agent.energy - dt * (0.5 + agent.genes.speed * 0.22 + agent.genes.metabolism * 0.2), 0, 180);
    agent.stateTimer -= dt;
    agent.mateCooldown = Math.max(0, agent.mateCooldown - dt);
    agent.sleepDrive += dt * (1.15 + agent.genes.metabolism * 0.25);

    if (agent.energy <= 0) {
        agent.alive = false;
        return;
    }

    if (agent.behavior === 'sleep') {
        agent.energy = clamp(agent.energy + dt * 4.2, 0, 180);
        if (agent.stateTimer <= 0 || agent.energy > 120) {
            agent.sleepDrive = 0;
            setAgentBehavior(agent, 'wander', rnd(4, 8));
        }
        return;
    }

    const threats = getNeighbors(predatorAgents, agent, 40);
    if (agent.sleepDrive > agent.sleepThreshold && threats.length === 0 && agent.behavior !== 'mate') {
        setAgentBehavior(agent, 'sleep', rnd(8, 14));
        return;
    }

    if (agent.behavior === 'rest') {
        agent.energy = clamp(agent.energy + dt * 1.6, 0, 180);
        if (agent.stateTimer <= 0) setAgentBehavior(agent, 'wander', rnd(4, 9));
        return;
    }

    if (agent.behavior !== 'mate' && canMate(agent)) {
        tryInitiateMating(agent, preyAgents);
    }

    const neighbors = getNeighbors(preyAgents, agent, 24);
    let steerX = 0;
    let steerZ = 0;

    if (threats.length > 0) {
        setAgentBehavior(agent, 'flee', 2.2);
        for (const predator of threats) {
            const dx = agent.x - predator.x;
            const dz = agent.z - predator.z;
            const d = Math.max(0.1, Math.hypot(dx, dz));
            steerX += (dx / d) * 2.2;
            steerZ += (dz / d) * 2.2;
        }
    } else if (agent.behavior === 'mate' && agent.mateTargetId != null) {
        const partner = preyAgents.find(p => p.id === agent.mateTargetId && p.alive);
        if (!partner || !canMate(partner)) {
            agent.mateTargetId = null;
            setAgentBehavior(agent, 'wander', rnd(4, 8));
        } else {
            steerX += partner.x - agent.x;
            steerZ += partner.z - agent.z;
            if (dist2d(agent, partner) < 2.5 && agent.sex === 'F') {
                spawnChild('prey', agent, partner);
                agent.mateCooldown = rnd(80, 120);
                partner.mateCooldown = rnd(80, 120);
                agent.energy = clamp(agent.energy - 26, 0, 180);
                partner.energy = clamp(partner.energy - 20, 0, 180);
                setAgentBehavior(agent, 'rest', rnd(3, 6));
                setAgentBehavior(partner, 'rest', rnd(3, 6));
                agent.mateTargetId = null;
                partner.mateTargetId = null;
                logEvent('<strong>Prey mated</strong>: a calf joined the herd.');
                return;
            }
        }
    } else {
        const food = nearestFoodForAgent(agent);
        if (food && dist2d(agent, food) < agent.genes.sense && (agent.energy < 115 || chance(0.5))) {
            setAgentBehavior(agent, 'graze', 4);
            steerX += (food.x - agent.x) * 1.3;
            steerZ += (food.z - agent.z) * 1.3;
            if (dist2d(agent, food) < 1.0 + agent.genes.size * 0.35) {
                food.alive = false;
                food.cooldown = 8 + Math.random() * 12;
                agent.energy = clamp(agent.energy + 20, 0, 180);
            }
        } else {
            setAgentBehavior(agent, 'wander', 3);
        }

        if (neighbors.length > 0) {
            let cx = 0, cz = 0, sx = 0, sz = 0, sepX = 0, sepZ = 0;
            for (const other of neighbors) {
                cx += other.x; cz += other.z;
                const dir = unitFromAngle(other.rotation);
                sx += dir.x; sz += dir.z;
                const dx = agent.x - other.x;
                const dz = agent.z - other.z;
                const d = Math.max(0.1, Math.hypot(dx, dz));
                if (d < 6) {
                    sepX += dx / d;
                    sepZ += dz / d;
                }
            }
            cx /= neighbors.length;
            cz /= neighbors.length;
            steerX += (cx - agent.x) * 0.12 + sx * 0.75 + sepX * 1.1;
            steerZ += (cz - agent.z) * 0.12 + sz * 0.75 + sepZ * 1.1;
        } else if (chance(0.015)) {
            steerX += rnd(-1, 1);
            steerZ += rnd(-1, 1);
        }
    }

    if (agent.stateTimer <= 0 && agent.behavior !== 'flee' && agent.behavior !== 'mate' && chance(0.12)) {
        setAgentBehavior(agent, 'rest', rnd(2, 5));
        return;
    }

    if (Math.abs(steerX) + Math.abs(steerZ) < 0.001) {
        const dir = unitFromAngle(agent.rotation);
        steerX = dir.x;
        steerZ = dir.z;
    }

    const desired = angleTo(steerX, steerZ);
    agent.rotation = steerTowardsAngle(agent.rotation, desired, Math.min(1, dt * (1.3 + agent.genes.agility)));
    const speedMult = agent.behavior === 'flee' ? 2.8 : agent.behavior === 'mate' ? 1.3 : 1.9;
    const moveSpeed = agent.genes.speed * speedMult * dt;
    const nextX = agent.x + Math.sin(agent.rotation) * moveSpeed;
    const nextZ = agent.z + Math.cos(agent.rotation) * moveSpeed;
    if (terrainSafeMove(agent, nextX, nextZ)) {
        agent.x = nextX;
        agent.z = nextZ;
    } else {
        agent.rotation += rnd(-1.4, 1.4);
    }
    boundPosition(agent);
}

function updatePredatorAgent(agent, dt) {
    if (!agent.alive) return;
    agent.age += dt * 0.28;
    agent.energy = clamp(agent.energy - dt * (0.65 + agent.genes.speed * 0.25 + agent.genes.metabolism * 0.24), 0, 220);
    agent.stateTimer -= dt;
    agent.mateCooldown = Math.max(0, agent.mateCooldown - dt);
    agent.sleepDrive += dt * (1.0 + agent.genes.metabolism * 0.22);

    if (agent.energy <= 0) {
        agent.alive = false;
        return;
    }

    if (agent.behavior === 'sleep') {
        agent.energy = clamp(agent.energy + dt * 4.0, 0, 220);
        if (agent.stateTimer <= 0 || agent.energy > 150) {
            agent.sleepDrive = 0;
            setAgentBehavior(agent, 'wander', rnd(5, 9));
        }
        return;
    }

    if (agent.sleepDrive > agent.sleepThreshold && agent.behavior !== 'hunt' && agent.behavior !== 'mate') {
        setAgentBehavior(agent, 'sleep', rnd(8, 13));
        return;
    }

    if (agent.behavior === 'rest') {
        agent.energy = clamp(agent.energy + dt * 1.8, 0, 220);
        if (agent.stateTimer <= 0) setAgentBehavior(agent, 'wander', rnd(4, 8));
        return;
    }

    if (agent.behavior !== 'mate' && canMate(agent)) {
        tryInitiateMating(agent, predatorAgents);
    }

    let steerX = 0;
    let steerZ = 0;
    if (agent.behavior === 'mate' && agent.mateTargetId != null) {
        const partner = predatorAgents.find(p => p.id === agent.mateTargetId && p.alive);
        if (!partner || !canMate(partner)) {
            agent.mateTargetId = null;
            setAgentBehavior(agent, 'wander', rnd(4, 8));
        } else {
            steerX += partner.x - agent.x;
            steerZ += partner.z - agent.z;
            if (dist2d(agent, partner) < 2.8 && agent.sex === 'F') {
                spawnChild('predator', agent, partner);
                agent.mateCooldown = rnd(95, 130);
                partner.mateCooldown = rnd(95, 130);
                agent.energy = clamp(agent.energy - 30, 0, 220);
                partner.energy = clamp(partner.energy - 22, 0, 220);
                setAgentBehavior(agent, 'rest', rnd(4, 7));
                setAgentBehavior(partner, 'rest', rnd(4, 7));
                agent.mateTargetId = null;
                partner.mateTargetId = null;
                logEvent('<strong>Predators mated</strong>: a cub was born in the brush.');
                return;
            }
        }
    } else {
        const prey = nearestAgent(preyAgents, agent, agent.genes.sense + 24, p => p.behavior !== 'sleep');
        if (prey && (agent.energy < 170 || chance(0.7))) {
            setAgentBehavior(agent, 'hunt', 4);
            steerX += prey.x - agent.x;
            steerZ += prey.z - agent.z;
            if (dist2d(agent, prey) < 1.3 + agent.genes.size * 0.45) {
                prey.alive = false;
                agent.energy = clamp(agent.energy + 50, 0, 220);
                setAgentBehavior(agent, 'rest', rnd(2.5, 5));
                if (chance(0.35)) logEvent('<strong>Predator strike</strong>: a hunter brought down prey.');
                return;
            }
        } else {
            setAgentBehavior(agent, 'wander', 3);
            if (chance(0.012)) {
                steerX += rnd(-1, 1);
                steerZ += rnd(-1, 1);
            } else {
                const dir = unitFromAngle(agent.rotation);
                steerX += dir.x;
                steerZ += dir.z;
            }
        }
    }

    if (agent.stateTimer <= 0 && agent.behavior !== 'hunt' && agent.behavior !== 'mate' && chance(0.10)) {
        setAgentBehavior(agent, 'rest', rnd(2, 4));
        return;
    }

    const desired = angleTo(steerX, steerZ);
    agent.rotation = steerTowardsAngle(agent.rotation, desired, Math.min(1, dt * (1.4 + agent.genes.agility)));
    const speedMult = agent.behavior === 'hunt' ? 2.7 : agent.behavior === 'mate' ? 1.2 : 1.8;
    const moveSpeed = agent.genes.speed * speedMult * dt;
    const nextX = agent.x + Math.sin(agent.rotation) * moveSpeed;
    const nextZ = agent.z + Math.cos(agent.rotation) * moveSpeed;
    if (terrainSafeMove(agent, nextX, nextZ)) {
        agent.x = nextX;
        agent.z = nextZ;
    } else {
        agent.rotation += rnd(-1.4, 1.4);
    }
    boundPosition(agent);
}

function pruneFauna() {
    for (let i = preyAgents.length - 1; i >= 0; i--) if (!preyAgents[i].alive) preyAgents.splice(i, 1);
    for (let i = predatorAgents.length - 1; i >= 0; i--) if (!predatorAgents[i].alive) predatorAgents.splice(i, 1);
    while (preyAgents.length < state.config.preyCount) preyAgents.push(createAgent('prey'));
    while (predatorAgents.length < state.config.predatorCount) predatorAgents.push(createAgent('predator'));
    while (preyAgents.length > state.config.preyCount + 12) preyAgents.splice(0, preyAgents.length - (state.config.preyCount + 12));
    while (predatorAgents.length > state.config.predatorCount + 4) predatorAgents.splice(0, predatorAgents.length - (state.config.predatorCount + 4));
}

function updateDisplayGenes(dt) {
    if (!state.displayGenes) return;
    for (const key of Object.keys(state.displayGenes)) {
        const target = state.creature.genes[key];
        const current = state.displayGenes[key];
        state.displayGenes[key] = key === 'limbs'
            ? lerp(current, target, Math.min(1, dt * 2.8))
            : lerp(current, target, Math.min(1, dt * 1.6));
    }
}

function updateUI() {
    const c = state.creature;
    if (!c) return;
    UI.statName.textContent = c.name;
    UI.statGeneration.textContent = String(c.generation);
    UI.statAge.textContent = `${c.age.toFixed(1)}s`;
    UI.statEnergy.textContent = c.energy.toFixed(1);
    UI.statFood.textContent = String(c.foods_eaten);
    UI.statBehavior.textContent = behaviorLabel(state.creatureRuntime?.behavior || 'wander');
    UI.statFauna.textContent = `${preyAgents.length} / ${predatorAgents.length}`;
    UI.statLinked.textContent = state.browserLinked ? 'Cookie linked' : 'Pending';
    UI.genes.innerHTML = Object.entries(c.genes).map(([key, value]) =>
        `<div class="gene"><div class="label">${geneLabels[key]}</div><div class="value">${geneValueDisplay(key, value)}</div></div>`
    ).join('');
    UI.pauseBtn.textContent = state.paused ? 'Resume' : 'Pause';
}

function drawBackground() {
    const sky = ctx.createLinearGradient(0, 0, 0, screen.height);
    sky.addColorStop(0, '#081223');
    sky.addColorStop(0.45, '#122742');
    sky.addColorStop(0.74, '#193957');
    sky.addColorStop(1, '#091017');
    ctx.fillStyle = sky;
    ctx.fillRect(0, 0, screen.width, screen.height);

    for (const star of stars) {
        const p = projectPoint(star);
        if (!p) continue;
        const alpha = clamp(1.2 - p.z / 520, 0.05, 0.8);
        const size = Math.max(0.6, star.size * p.scale * 0.012);
        ctx.fillStyle = `rgba(255,255,255,${alpha})`;
        ctx.fillRect(p.x, p.y, size, size);
    }

    const haze = ctx.createRadialGradient(screen.cx, screen.cy * 0.1, 20, screen.cx, screen.cy * 0.16, screen.height);
    haze.addColorStop(0, 'rgba(150,190,255,0.15)');
    haze.addColorStop(1, 'rgba(0,0,0,0)');
    ctx.fillStyle = haze;
    ctx.fillRect(0, 0, screen.width, screen.height);
}

function renderCreatureModel(commands, actor, runtime, isUser = false) {
    const g = actor.genes;
    const baseY = terrainHeightAt(actor.x, actor.z);
    const bounce = runtime?.behavior === 'sleep' ? 0 : Math.sin(state.worldTime * (2.0 + g.speed * 0.45) + actor.id * 0.3) * 0.10;
    const root = { x: actor.x, y: baseY + 1.0 + g.legLength * 0.75 + bounce, z: actor.z };
    const yaw = actor.rotation;

    const bodyColor = isUser
        ? hsl(g.hue, 0.66, 0.57, 0.98)
        : actor.kind === 'predator'
            ? hsl(g.hue, 0.56, 0.42, 0.97)
            : hsl(g.hue, 0.50, 0.48, 0.96);
    const accentColor = isUser
        ? hsl(g.hue + 0.18, 0.74, 0.62, 0.96)
        : actor.kind === 'predator'
            ? hsl(g.hue + 0.04, 0.48, 0.56, 0.94)
            : hsl(g.hue + 0.12, 0.56, 0.58, 0.94);

    const bodySegments = Math.max(2, Math.round(2 + g.bodyLength * 1.2));
    const bodyRadius = 0.42 + g.size * 0.42;
    const bodyLen = 0.85 + g.bodyLength * 1.15;
    const segmentPoints = [];

    for (let i = 0; i < bodySegments; i++) {
        const t = bodySegments === 1 ? 0.5 : i / (bodySegments - 1);
        const localZ = lerp(-bodyLen * 0.5, bodyLen * 0.5, t);
        const localY = Math.sin(t * Math.PI) * 0.08;
        const point = localToWorld({ x: 0, y: localY, z: localZ }, root, yaw);
        segmentPoints.push(point);
        const taper = 1 - Math.abs(t - 0.5) * 0.35;
        pushSphere(commands, point, bodyRadius * taper, bodyColor, 'rgba(0,0,0,0.18)', 0.98);
    }

    const neckBase = localToWorld({ x: 0, y: 0.08, z: bodyLen * 0.56 }, root, yaw);
    const head = localToWorld({ x: 0, y: 0.14 + g.headSize * 0.08, z: bodyLen * 0.9 }, root, yaw);
    pushLine(commands, neckBase, head, 0.15 + g.headSize * 0.05, accentColor, 0.96);
    pushSphere(commands, head, (0.24 + g.headSize * 0.28) * g.size * 0.9, bodyColor, null, 0.98);

    const muzzle = localToWorld({ x: 0, y: 0.08, z: bodyLen * 1.12 }, root, yaw);
    pushSphere(commands, muzzle, 0.12 + g.headSize * 0.10, accentColor, null, 0.94);

    const eyeX = 0.12 + g.headSize * 0.10;
    const eyeY = 0.08;
    const eyeZ = bodyLen * 0.98;
    const eyeL = localToWorld({ x: -eyeX, y: eyeY, z: eyeZ }, root, yaw);
    const eyeR = localToWorld({ x: eyeX, y: eyeY, z: eyeZ }, root, yaw);
    pushSphere(commands, eyeL, 0.08, 'rgba(255,255,255,0.95)', null, 1);
    pushSphere(commands, eyeR, 0.08, 'rgba(255,255,255,0.95)', null, 1);

    const crestPos = localToWorld({ x: 0, y: 0.42 + g.crest * 0.35, z: 0.05 }, root, yaw);
    pushSphere(commands, crestPos, 0.12 + g.crest * 0.30, accentColor, null, 0.94);

    const tailBase = localToWorld({ x: 0, y: 0.0, z: -bodyLen * 0.62 }, root, yaw);
    let lastTail = tailBase;
    const tailSegments = Math.max(1, Math.round(1 + g.tailLength * 2.2));
    for (let i = 1; i <= tailSegments; i++) {
        const t = i / tailSegments;
        const point = localToWorld({
            x: 0,
            y: 0.1 * Math.sin(state.worldTime * 2 + actor.id * 0.2 + t * 2),
            z: -bodyLen * 0.62 - g.tailLength * (0.45 + t * 0.55),
        }, root, yaw);
        pushLine(commands, lastTail, point, 0.12 * (1 - t * 0.45), accentColor, 0.94);
        pushSphere(commands, point, 0.06 * (1 - t * 0.45) + 0.02, accentColor, null, 0.9);
        lastTail = point;
    }

    const limbs = Math.round(g.limbs);
    const rows = Math.max(1, Math.ceil(limbs / 2));
    for (let i = 0; i < limbs; i++) {
        const side = i % 2 === 0 ? -1 : 1;
        const row = Math.floor(i / 2);
        const rowT = rows === 1 ? 0.5 : row / (rows - 1);
        const stride = runtime?.behavior === 'sleep' ? 0 : Math.sin(state.worldTime * (3.0 + g.speed * 0.95) + i * 0.9 + actor.id * 0.1) * 0.26;
        const hip = localToWorld({
            x: side * (0.28 + g.size * 0.2),
            y: -0.12,
            z: lerp(bodyLen * 0.45, -bodyLen * 0.42, rowT),
        }, root, yaw);
        const knee = localToWorld({
            x: side * (0.38 + g.size * 0.25),
            y: -0.36 - g.legLength * 0.45,
            z: lerp(bodyLen * 0.42, -bodyLen * 0.4, rowT) + stride * 0.22,
        }, root, yaw);
        const footGuess = localToWorld({
            x: side * (0.52 + g.size * 0.25),
            y: -0.72 - g.legLength * 0.95 + Math.abs(stride) * 0.12,
            z: lerp(bodyLen * 0.40, -bodyLen * 0.38, rowT) + stride * 0.4,
        }, root, yaw);
        footGuess.y = Math.max(terrainHeightAt(footGuess.x, footGuess.z) + 0.06, root.y - 0.95 - g.legLength * 0.95 + Math.abs(stride) * 0.12);
        pushLine(commands, hip, knee, 0.11 + g.size * 0.04, accentColor, 0.96);
        pushLine(commands, knee, footGuess, 0.10 + g.size * 0.035, accentColor, 0.96);
        pushSphere(commands, footGuess, 0.08 + g.size * 0.025, bodyColor, null, 0.94);
    }

    pushRing(commands, vec3(root.x, terrainHeightAt(actor.x, actor.z) + 0.05, root.z), 1.0 + g.size * 0.85 + g.bodyLength * 0.35, 'rgba(0,0,0,0.20)', 0.42, 1.2);

    if (runtime?.behavior === 'sleep') {
        pushSphere(commands, vec3(root.x, root.y + 0.9, root.z), 0.14, 'rgba(255,255,255,0.18)', null, 0.5);
    }
}
function renderMiniMap() {
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    const size = Math.round(clamp(Math.min(screen.width, screen.height) * 0.18, 170 * dpr, 250 * dpr));
    const margin = Math.round(20 * dpr);
    const x = screen.width - size - margin;
    const y = screen.height - size - margin;
    const panelRadius = 18 * dpr;
    const inset = 14 * dpr;
    const mapX = x + inset;
    const mapY = y + inset;
    const mapSize = size - inset * 2;
    const cx = mapX + mapSize * 0.5;
    const cy = mapY + mapSize * 0.5;
    const r = mapSize * 0.48;
    const worldRadius = state.config.worldRadius || 1;

    const projectMap = (wx, wz) => ({
        x: cx + (wx / worldRadius) * r,
        y: cy + (wz / worldRadius) * r,
    });

    const roundedRect = (px, py, w, h, radius) => {
        ctx.beginPath();
        ctx.moveTo(px + radius, py);
        ctx.lineTo(px + w - radius, py);
        ctx.quadraticCurveTo(px + w, py, px + w, py + radius);
        ctx.lineTo(px + w, py + h - radius);
        ctx.quadraticCurveTo(px + w, py + h, px + w - radius, py + h);
        ctx.lineTo(px + radius, py + h);
        ctx.quadraticCurveTo(px, py + h, px, py + h - radius);
        ctx.lineTo(px, py + radius);
        ctx.quadraticCurveTo(px, py, px + radius, py);
        ctx.closePath();
    };

    ctx.save();
    roundedRect(x, y, size, size, panelRadius);
    ctx.fillStyle = 'rgba(7, 12, 18, 0.74)';
    ctx.fill();
    ctx.strokeStyle = 'rgba(255,255,255,0.10)';
    ctx.lineWidth = 1.2 * dpr;
    ctx.stroke();

    ctx.fillStyle = 'rgba(226,238,255,0.92)';
    ctx.font = `${11 * dpr}px system-ui, sans-serif`;
    ctx.textAlign = 'left';
    ctx.textBaseline = 'top';
    ctx.fillText('MINIMAP', x + 12 * dpr, y + 10 * dpr);

    ctx.save();
    ctx.beginPath();
    ctx.arc(cx, cy, r, 0, Math.PI * 2);
    ctx.clip();

    const bg = ctx.createRadialGradient(cx, cy, r * 0.15, cx, cy, r * 1.1);
    bg.addColorStop(0, 'rgba(48,82,62,0.92)');
    bg.addColorStop(1, 'rgba(10,18,16,0.98)');
    ctx.fillStyle = bg;
    ctx.fillRect(mapX, mapY, mapSize, mapSize);

    ctx.strokeStyle = 'rgba(255,255,255,0.10)';
    ctx.lineWidth = 1 * dpr;
    for (let i = 1; i <= 3; i++) {
        ctx.beginPath();
        ctx.arc(cx, cy, (r * i) / 3, 0, Math.PI * 2);
        ctx.stroke();
    }
    ctx.beginPath();
    ctx.moveTo(cx - r, cy);
    ctx.lineTo(cx + r, cy);
    ctx.moveTo(cx, cy - r);
    ctx.lineTo(cx, cy + r);
    ctx.stroke();

    const drawDot = (wx, wz, radius, fillStyle, alpha = 1) => {
        const p = projectMap(wx, wz);
        if (Math.hypot(p.x - cx, p.y - cy) > r - radius * 0.5) return;
        ctx.globalAlpha = alpha;
        ctx.beginPath();
        ctx.arc(p.x, p.y, radius, 0, Math.PI * 2);
        ctx.fillStyle = fillStyle;
        ctx.fill();
    };

    for (const food of foods) {
        if (!food.alive) continue;
        drawDot(food.x, food.z, 1.1 * dpr, 'rgba(160,245,190,0.55)', 0.72);
    }
    for (const prey of preyAgents) {
        if (!prey.alive) continue;
        drawDot(prey.x, prey.z, 1.9 * dpr, prey.behavior === 'sleep' ? 'rgba(123,182,255,0.82)' : 'rgba(127,234,255,0.92)', 0.95);
    }
    for (const predator of predatorAgents) {
        if (!predator.alive) continue;
        drawDot(predator.x, predator.z, 2.4 * dpr, predator.behavior === 'hunt' ? 'rgba(255,108,108,0.96)' : 'rgba(255,150,110,0.90)', 0.96);
    }

    if (state.creature) {
        const p = projectMap(state.creature.x, state.creature.z);
        const heading = state.creature.rotation;
        const markerSize = 6.4 * dpr;
        ctx.globalAlpha = 1;
        ctx.fillStyle = 'rgba(114,255,176,0.98)';
        ctx.beginPath();
        ctx.arc(p.x, p.y, 3.2 * dpr, 0, Math.PI * 2);
        ctx.fill();

        ctx.beginPath();
        ctx.moveTo(p.x + Math.sin(heading) * markerSize, p.y + Math.cos(heading) * markerSize);
        ctx.lineTo(p.x + Math.sin(heading + 2.45) * markerSize * 0.58, p.y + Math.cos(heading + 2.45) * markerSize * 0.58);
        ctx.lineTo(p.x + Math.sin(heading - 2.45) * markerSize * 0.58, p.y + Math.cos(heading - 2.45) * markerSize * 0.58);
        ctx.closePath();
        ctx.fillStyle = 'rgba(210,255,228,0.94)';
        ctx.fill();

        const camHeading = wrapAngle(camera.yaw + Math.PI);
        ctx.strokeStyle = 'rgba(120,210,255,0.80)';
        ctx.lineWidth = 1.2 * dpr;
        ctx.beginPath();
        ctx.moveTo(p.x, p.y);
        ctx.lineTo(p.x + Math.sin(camHeading) * markerSize * 1.5, p.y + Math.cos(camHeading) * markerSize * 1.5);
        ctx.stroke();
    }

    ctx.globalAlpha = 1;
    ctx.restore();

    ctx.strokeStyle = 'rgba(125, 230, 220, 0.46)';
    ctx.lineWidth = 1.5 * dpr;
    ctx.beginPath();
    ctx.arc(cx, cy, r, 0, Math.PI * 2);
    ctx.stroke();

    ctx.fillStyle = 'rgba(192,208,228,0.82)';
    ctx.font = `${10 * dpr}px system-ui, sans-serif`;
    ctx.textAlign = 'left';
    ctx.textBaseline = 'alphabetic';
    ctx.fillText('food', x + 14 * dpr, y + size - 16 * dpr);
    ctx.fillStyle = 'rgba(160,245,190,0.90)';
    ctx.fillRect(x + 14 * dpr, y + size - 28 * dpr, 8 * dpr, 3 * dpr);
    ctx.fillStyle = 'rgba(127,234,255,0.92)';
    ctx.fillRect(x + 52 * dpr, y + size - 28 * dpr, 8 * dpr, 3 * dpr);
    ctx.fillStyle = 'rgba(192,208,228,0.82)';
    ctx.fillText('prey', x + 52 * dpr, y + size - 16 * dpr);
    ctx.fillStyle = 'rgba(255,108,108,0.92)';
    ctx.fillRect(x + 92 * dpr, y + size - 28 * dpr, 8 * dpr, 3 * dpr);
    ctx.fillStyle = 'rgba(192,208,228,0.82)';
    ctx.fillText('pred', x + 92 * dpr, y + size - 16 * dpr);
    ctx.restore();
}

function renderScene() {
    updateCamera();
    drawBackground();

    const commands = [];
    const terrainRange = clamp(175 + camera.distance * 2.7, 170, 270);
    const terrainStep = camera.distance < 28 ? 12 : 16;
    const baseX = Math.floor((camera.target.x - terrainRange) / terrainStep) * terrainStep;
    const baseZ = Math.floor((camera.target.z - terrainRange) / terrainStep) * terrainStep;

    for (let z = baseZ; z < camera.target.z + terrainRange; z += terrainStep) {
        for (let x = baseX; x < camera.target.x + terrainRange; x += terrainStep) {
            const centerX = x + terrainStep * 0.5;
            const centerZ = z + terrainStep * 0.5;
            if (Math.hypot(centerX - camera.target.x, centerZ - camera.target.z) > terrainRange + 12) continue;

            const p0 = vec3(x, terrainHeightAt(x, z), z);
            const p1 = vec3(x + terrainStep, terrainHeightAt(x + terrainStep, z), z);
            const p2 = vec3(x + terrainStep, terrainHeightAt(x + terrainStep, z + terrainStep), z + terrainStep);
            const p3 = vec3(x, terrainHeightAt(x, z + terrainStep), z + terrainStep);
            const avgY = (p0.y + p1.y + p2.y + p3.y) * 0.25;
            pushPolygon(commands, [p0, p1, p2, p3], biomeColor(centerX, centerZ, avgY), null, 0.9);
        }
    }

    const ringSegments = 140;
    for (let i = 0; i < ringSegments; i++) {
        const a0 = (i / ringSegments) * Math.PI * 2;
        const a1 = ((i + 1) / ringSegments) * Math.PI * 2;
        const r = state.config.worldRadius;
        pushLine(commands,
            vec3(Math.cos(a0) * r, terrainHeightAt(Math.cos(a0) * r, Math.sin(a0) * r) + 0.35, Math.sin(a0) * r),
            vec3(Math.cos(a1) * r, terrainHeightAt(Math.cos(a1) * r, Math.sin(a1) * r) + 0.35, Math.sin(a1) * r),
            0.07,
            'rgba(110,200,220,0.24)',
            0.55
        );
    }

    for (const tree of trees) {
        const dx = tree.x - camera.target.x;
        const dz = tree.z - camera.target.z;
        if (dx * dx + dz * dz > (terrainRange + 28) * (terrainRange + 28)) continue;
        const base = vec3(tree.x, tree.y, tree.z);
        const top = vec3(tree.x + tree.tilt, tree.y + tree.trunkHeight, tree.z - tree.tilt * 0.3);
        pushLine(commands, base, top, 0.20, hsl(tree.trunkHue, 0.34, 0.26, 1), 1);
        pushSphere(commands, vec3(top.x, top.y + tree.crown * 0.2, top.z), tree.crown * 0.88, hsl(tree.hue, 0.50, 0.33, 0.95), 'rgba(0,0,0,0.14)', 0.95);
        pushSphere(commands, vec3(top.x - tree.crown * 0.35, top.y - 0.18, top.z), tree.crown * 0.64, hsl(tree.hue + 0.02, 0.54, 0.30, 0.95), null, 0.92);
        pushSphere(commands, vec3(top.x + tree.crown * 0.34, top.y - 0.04, top.z + 0.18), tree.crown * 0.58, hsl(tree.hue - 0.01, 0.51, 0.32, 0.95), null, 0.9);
    }

    for (const food of foods) {
        if (!food.alive) continue;
        const y = terrainHeightAt(food.x, food.z) + 0.95 + Math.sin(food.bob + state.worldTime * 2.0) * 0.12;
        pushSphere(commands, vec3(food.x, y, food.z), 0.34, 'rgba(160,245,190,0.96)', 'rgba(220,255,230,0.20)', 0.98);
        pushRing(commands, vec3(food.x, y, food.z), 0.48, 'rgba(130,220,180,0.42)', 0.6, 1);
    }

    for (const prey of preyAgents) {
        if (!prey.alive) continue;
        renderCreatureModel(commands, prey, prey, false);
    }
    for (const predator of predatorAgents) {
        if (!predator.alive) continue;
        renderCreatureModel(commands, predator, predator, false);
    }

    if (state.creature && state.displayGenes) {
        const actor = {
            id: Number(state.creature.id || 0),
            kind: 'prey',
            x: state.creature.x,
            z: state.creature.z,
            rotation: state.creature.rotation,
            genes: state.displayGenes,
        };
        renderCreatureModel(commands, actor, state.creatureRuntime, true);
    }

    commands.sort((a, b) => b.depth - a.depth);
    for (const cmd of commands) {
        ctx.save();
        ctx.globalAlpha = cmd.alpha ?? 1;
        if (cmd.type === 'polygon') {
            ctx.beginPath();
            ctx.moveTo(cmd.points[0].x, cmd.points[0].y);
            for (let i = 1; i < cmd.points.length; i++) ctx.lineTo(cmd.points[i].x, cmd.points[i].y);
            ctx.closePath();
            ctx.fillStyle = cmd.fillStyle;
            ctx.fill();
            if (cmd.strokeStyle) {
                ctx.strokeStyle = cmd.strokeStyle;
                ctx.lineWidth = 1;
                ctx.stroke();
            }
        } else if (cmd.type === 'line') {
            ctx.strokeStyle = cmd.color;
            ctx.lineWidth = cmd.width;
            ctx.lineCap = 'round';
            ctx.beginPath();
            ctx.moveTo(cmd.ax, cmd.ay);
            ctx.lineTo(cmd.bx, cmd.by);
            ctx.stroke();
        } else if (cmd.type === 'sphere') {
            const gradient = ctx.createRadialGradient(cmd.x - cmd.r * 0.3, cmd.y - cmd.r * 0.35, cmd.r * 0.12, cmd.x, cmd.y, cmd.r);
            gradient.addColorStop(0, 'rgba(255,255,255,0.22)');
            gradient.addColorStop(0.28, cmd.fillStyle);
            gradient.addColorStop(1, 'rgba(0,0,0,0.26)');
            ctx.fillStyle = gradient;
            ctx.beginPath();
            ctx.arc(cmd.x, cmd.y, cmd.r, 0, Math.PI * 2);
            ctx.fill();
            if (cmd.strokeStyle) {
                ctx.strokeStyle = cmd.strokeStyle;
                ctx.lineWidth = 1;
                ctx.stroke();
            }
        } else if (cmd.type === 'ring') {
            ctx.strokeStyle = cmd.color;
            ctx.lineWidth = cmd.width || 1.2;
            ctx.beginPath();
            ctx.ellipse(cmd.x, cmd.y, cmd.r, cmd.r * 0.38, 0, 0, Math.PI * 2);
            ctx.stroke();
        }
        ctx.restore();
    }

    const vignette = ctx.createRadialGradient(screen.cx, screen.cy, Math.min(screen.width, screen.height) * 0.25, screen.cx, screen.cy, Math.max(screen.width, screen.height) * 0.8);
    vignette.addColorStop(0, 'rgba(0,0,0,0)');
    vignette.addColorStop(1, 'rgba(0,0,0,0.36)');
    ctx.fillStyle = vignette;
    ctx.fillRect(0, 0, screen.width, screen.height);

    renderMiniMap();
}

async function loadGame() {
    const response = await fetch('?action=bootstrap', { cache: 'no-store', credentials: 'same-origin' });
    const data = await response.json();
    if (!data.ok) throw new Error(data.error || 'Could not bootstrap game');

    state.config = { ...state.config, ...data.config };
    state.creature = data.creature;
    state.creature.genes = normalizeGenes(state.creature.genes);
    state.displayGenes = { ...state.creature.genes };
    state.browserLinked = !!data.browserLinked;
    ensureCreatureRuntime();
    buildFoods();
    buildTrees();
    buildStars();
    buildFauna();
    syncEditorFromCreature();

    camera.target.x = state.creature.x;
    camera.target.y = terrainHeightAt(state.creature.x, state.creature.z) + 2.0;
    camera.target.z = state.creature.z;
    camera.yaw = state.creature.rotation + Math.PI;

    logEvent(`<strong>${state.creature.name}</strong> entered the wide dry technosphere at generation ${state.creature.generation}.`);
    logEvent('<strong>Ecosystem active</strong>: prey now herd, predators hunt, and fauna can rest, sleep, and mate.');
    if (state.browserLinked) logEvent('<strong>Browser cookie active</strong>: this browser tracks its own creature lineage.');

    updateUI();
    state.ready = true;
    UI.loading.style.display = 'none';
}

async function saveGame() {
    if (!state.ready || !state.creature) return;
    const payload = {
        name: state.creature.name,
        age: Number(state.creature.age.toFixed(4)),
        generation: state.creature.generation,
        energy: Number(state.creature.energy.toFixed(4)),
        x: Number(state.creature.x.toFixed(4)),
        z: Number(state.creature.z.toFixed(4)),
        rotation: Number(state.creature.rotation.toFixed(6)),
        evolution_points: Number(state.creature.evolution_points.toFixed(4)),
        foods_eaten: state.creature.foods_eaten,
        last_evolved_age: Number(state.creature.last_evolved_age.toFixed(4)),
        genes: state.creature.genes,
    };
    const response = await fetch('?action=save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(payload),
        keepalive: true,
    });
    const data = await response.json();
    if (data.ok && data.creature) {
        state.creature = data.creature;
        state.creature.genes = normalizeGenes(state.creature.genes);
    }
}

async function resetGame() {
    const response = await fetch('?action=reset', { cache: 'no-store', credentials: 'same-origin' });
    const data = await response.json();
    if (!data.ok) {
        showToast('Reset failed');
        return;
    }
    state.creature = data.creature;
    state.creature.genes = normalizeGenes(state.creature.genes);
    state.displayGenes = { ...state.creature.genes };
    state.creatureRuntime = null;
    ensureCreatureRuntime();
    syncEditorFromCreature();
    buildFoods();
    buildFauna();
    logEvent(`<strong>Browser creature reset</strong>: ${state.creature.name} restarted from generation 1.`);
    showToast('Browser creature reset');
}

UI.pauseBtn.addEventListener('click', () => {
    state.paused = !state.paused;
    updateUI();
});
UI.focusBtn.addEventListener('click', () => {
    camera.yawOffset = 0;
    camera.pitchOffset = 0;
    camera.distanceOffset = 0;
    camera.yaw = state.creature.rotation + Math.PI;
    showToast('Third-person camera reset');
});
UI.renameBtn.addEventListener('click', async () => {
    const name = prompt('New creature name:', state.creature.name);
    if (!name) return;
    state.creature.name = name.trim().slice(0, 48) || state.creature.name;
    updateUI();
    await saveGame();
    showToast('Creature renamed');
});
UI.resetBtn.addEventListener('click', async () => {
    await resetGame();
    updateUI();
});
UI.randomizeBtn.addEventListener('click', () => {
    const next = normalizeGenes({
        ...state.creature.genes,
        limbs: Math.round(rnd(2, 8)),
        size: rnd(0.6, 2.2),
        bodyLength: rnd(0.8, 2.6),
        headSize: rnd(0.7, 1.6),
        legLength: rnd(0.7, 2.1),
        tailLength: rnd(0.0, 2.3),
        hue: rnd(0.0, 1.0),
        crest: rnd(0.0, 1.0),
    });
    state.creature.genes = next;
    syncEditorFromCreature();
    showToast('Editor randomized');
});
UI.applyCustomBtn.addEventListener('click', async () => {
    state.creature.genes = collectEditorGenes();
    syncEditorFromCreature();
    updateUI();
    await saveGame();
    showToast('Body saved');
});

function resize() {
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    screen.width = Math.max(1, Math.floor(window.innerWidth * dpr));
    screen.height = Math.max(1, Math.floor(window.innerHeight * dpr));
    screen.cx = screen.width * 0.5;
    screen.cy = screen.height * 0.5;
    screen.focal = screen.height * 0.88;
    canvas.width = screen.width;
    canvas.height = screen.height;
}
window.addEventListener('resize', resize);
resize();
setupEditor();

canvas.addEventListener('pointerdown', (event) => {
    pointer.active = true;
    pointer.lastX = event.clientX;
    pointer.lastY = event.clientY;
    canvas.setPointerCapture(event.pointerId);
});
canvas.addEventListener('pointermove', (event) => {
    if (!pointer.active) return;
    const dx = event.clientX - pointer.lastX;
    const dy = event.clientY - pointer.lastY;
    pointer.lastX = event.clientX;
    pointer.lastY = event.clientY;
    camera.yawOffset = clamp(camera.yawOffset - dx * 0.0058, -1.9, 1.9);
    camera.pitchOffset = clamp(camera.pitchOffset + dy * 0.0042, -0.28, 0.34);
});
canvas.addEventListener('pointerup', () => { pointer.active = false; });
canvas.addEventListener('pointercancel', () => { pointer.active = false; });
canvas.addEventListener('wheel', (event) => {
    event.preventDefault();
    camera.distanceOffset = clamp(camera.distanceOffset + event.deltaY * 0.018, -5, 12);
}, { passive: false });

window.addEventListener('beforeunload', () => {
    if (state.ready) {
        navigator.sendBeacon?.('?action=save', new Blob([JSON.stringify({
            name: state.creature.name,
            age: Number(state.creature.age.toFixed(4)),
            generation: state.creature.generation,
            energy: Number(state.creature.energy.toFixed(4)),
            x: Number(state.creature.x.toFixed(4)),
            z: Number(state.creature.z.toFixed(4)),
            rotation: Number(state.creature.rotation.toFixed(6)),
            evolution_points: Number(state.creature.evolution_points.toFixed(4)),
            foods_eaten: state.creature.foods_eaten,
            last_evolved_age: Number(state.creature.last_evolved_age.toFixed(4)),
            genes: state.creature.genes,
        })], { type: 'application/json' }));
    }
});

function animate(time) {
    requestAnimationFrame(animate);
    const dt = Math.min(0.05, (time - lastTime) / 1000 || 0.016);
    lastTime = time;

    if (state.ready && !state.paused) {
        state.worldTime += dt;
        updateFood(dt);
        updateCreatureRuntime(dt);
        for (const prey of preyAgents) updatePreyAgent(prey, dt);
        for (const predator of predatorAgents) updatePredatorAgent(predator, dt);
        pruneFauna();
        updateDisplayGenes(dt);
        updateThirdPersonCamera(dt);
        updateUI();

        state.saveAccumulator += dt * 1000;
        if (state.saveAccumulator >= state.config.saveIntervalMs) {
            state.saveAccumulator = 0;
            saveGame().catch(console.error);
        }
    } else if (state.ready) {
        updateDisplayGenes(dt);
        updateThirdPersonCamera(dt);
    }

    renderScene();
}

loadGame()
    .catch((err) => {
        console.error(err);
        UI.loading.textContent = `Error: ${err.message}`;
    })
    .finally(() => {
        requestAnimationFrame(animate);
    });
})();
</script>
</body>
</html>
