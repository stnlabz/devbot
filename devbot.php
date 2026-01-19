<?php
declare(strict_types=1);

/**
 * DevBot Main Runner
 *
 * Loads configuration, executes enabled plugins, aggregates signals into a
 * daily payload, and writes JSON/JSONL directly (no external writer class).
 *
 * @package STN-Labz\DevBot
 */

const DEVROOT = __DIR__;

/**
 * Load JSON safely as associative array.
 *
 * @param string $path Absolute JSON file path.
 * @return array<string,mixed>
 */
function devbot_load_json(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Simple logger (append) into /logs.
 *
 * @param string $msg Message to log.
 * @param string $file Relative file name under /logs.
 * @return void
 */
function devbot_log(string $msg, string $file = 'devbot_proc.log'): void
{
    $logDir = DEVROOT . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    @file_put_contents($logDir . '/' . $file, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Load and run an enabled plugin file which returns an array payload, or [].
 * Contract: plugin PHP returns array<string,mixed> on success, [] on no-op.
 *
 * @param string $pluginFile Absolute path to plugin php file.
 * @return array<string,mixed>
 */
function devbot_run_plugin(string $pluginFile): array
{
    try {
        /** @var array<string,mixed>|null $out */
        $out = (static function (string $__f): ?array {
            /** @noinspection PhpIncludeInspection */
            $result = include $__f;
            return is_array($result) ? $result : [];
        })($pluginFile);

        return $out ?? [];
    } catch (Throwable $e) {
        devbot_log('Plugin error in ' . basename($pluginFile) . ': ' . $e->getMessage(), 'devbot_debug.log');
        return [];
    }
}

/**
 * Validate minimal config and log warnings (does not exit).
 *
 * @param array<string,mixed> $cfg
 * @return void
 */
function devbot_validate_config(array $cfg): void
{
    $errors = [];

    $mode = $cfg['mode'] ?? 'core';
    if (!in_array($mode, ['lite', 'core'], true)) {
        $errors[] = 'mode must be "lite" or "core"';
    }

    $pluginsPath = $cfg['paths']['plugins'] ?? './plugins';
    $pluginsAbs  = str_starts_with($pluginsPath, '/')
        ? $pluginsPath
        : (DEVROOT . '/' . ltrim($pluginsPath, '/'));
    if (!is_dir($pluginsAbs)) {
        $errors[] = 'plugins path not found: ' . $pluginsAbs;
    }

    $fmt = $cfg['output']['format'] ?? 'json';
    if (!in_array($fmt, ['json', 'jsonl'], true)) {
        $errors[] = 'output.format must be json or jsonl';
    }

    if (!empty($errors)) {
        devbot_log('Config warnings: ' . implode('; ', $errors), 'devbot_debug.log');
    }
}

/**
 * Write payload to disk as JSON or JSONL based on config.
 *
 * Uses:
 *   output.mode   : auto|file|db   (db is stubbed; falls back to file)
 *   output.path   : logs dir (relative or absolute)
 *   output.format : json|jsonl
 * Also writes devbot_summary.json if summary.enabled && summary.write_json.
 *
 * @param array<string,mixed> $payload
 * @param array<string,mixed> $cfg
 * @return bool
 */
function devbot_write_output(array $payload, array $cfg): bool
{
    $mode   = $cfg['output']['mode']   ?? 'auto';   // auto|file|db
    $format = $cfg['output']['format'] ?? 'json';   // json|jsonl
    $dbOn   = (bool)($cfg['output']['db_enabled'] ?? false);

    $logsPath = $cfg['output']['path'] ?? ($cfg['paths']['logs'] ?? (DEVROOT . '/logs'));
    if (!str_starts_with($logsPath, '/')) {
        $logsPath = rtrim(DEVROOT . '/' . ltrim($logsPath, '/'), '/');
    }
    if (!is_dir($logsPath)) {
        if (!@mkdir($logsPath, 0755, true) && !is_dir($logsPath)) {
            return false;
        }
    }

    // DB path is a stub until Chaos CMS offers connectivity.
    if ($mode === 'db' || ($mode === 'auto' && $dbOn === true)) {
        // Intentionally not implemented; fall back to file write.
        devbot_log('DB output requested but not available; writing to file instead.', 'devbot_debug.log');
    }

    $daily = $logsPath . '/' . date('Y-m-d') . ($format === 'jsonl' ? '.jsonl' : '.json');

    if ($format === 'jsonl') {
        $line = json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
        $ok   = (bool)@file_put_contents($daily, $line, FILE_APPEND | LOCK_EX);
    } else {
        $ok   = (bool)@file_put_contents(
            $daily,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    // Optional single summary file alongside the daily file
    $wantSummary = (bool)($cfg['summary']['enabled'] ?? false)
                && (bool)($cfg['summary']['write_json'] ?? false);
    if ($wantSummary) {
        @file_put_contents(
            $logsPath . '/devbot_summary.json',
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    return $ok;
}

/* ---------------------------
 * Bootstrap
 * --------------------------*/
$cfgPath = DEVROOT . '/config/devbot_config.json';
$config  = devbot_load_json($cfgPath);

$mode        = $config['mode'] ?? 'core';
$pluginDir   = $config['paths']['plugins'] ?? (DEVROOT . '/plugins');
$enabledList = $config['plugins']['enabled'] ?? [];  // names (no .php)
$denyList    = $config['plugins']['disabled'] ?? []; // names (no .php)

devbot_validate_config($config);
devbot_log('DevBot starting (mode=' . $mode . ')');

/** Discover plugins */
$plugins = [];
$pluginAbsDir = str_starts_with($pluginDir, '/')
    ? $pluginDir
    : (DEVROOT . '/' . ltrim($pluginDir, '/'));

if (is_dir($pluginAbsDir)) {
    foreach (scandir($pluginAbsDir) ?: [] as $file) {
        if ($file === '.' || $file === '..' || !str_ends_with($file, '.php')) {
            continue;
        }
        $name = pathinfo($file, PATHINFO_FILENAME);
        if (!empty($enabledList) && !in_array($name, $enabledList, true)) {
            continue;
        }
        if (in_array($name, $denyList, true)) {
            continue;
        }
        $plugins[] = $pluginAbsDir . '/' . $file;
    }
}

/** Execute plugins */
$signals = [];
$stats   = ['plugins' => 0];

foreach ($plugins as $pf) {
    $pname = basename($pf, '.php');
    devbot_log('Running plugin: ' . $pname);
    $out = devbot_run_plugin($pf);
    if (!empty($out)) {
        $signals[$pname] = $out;
    }
    $stats['plugins']++;
}

/** Compose final payload */
$payload = [
    'summary' => [
        'date'    => date('Y-m-d'),
        'mode'    => $mode,
        'signals' => array_keys($signals),
    ],
    'devlog' => [
        'title'    => 'Daily DevLog for ' . date('Y-m-d'),
        'sections' => array_map(static fn($k) => strtoupper($k), array_keys($signals)),
    ],
    'signals' => $signals,
    'stats'   => $stats,
];

/** Write output directly */
$ok = devbot_write_output($payload, $config);
devbot_log('Output write: ' . ($ok ? 'OK' : 'FAILED'));

exit($ok ? 0 : 1);

