<?php
// Plugin: filewatch.php
// Purpose: Detect changes to watched files based on hash diffing
error_log('FILEWATCH RUNNING: ' . date('c'));
$devRoot = dirname(__DIR__);
$logDir  = $devRoot . '/logs';
$watch_file = dirname(__DIR__) . '/.filewatch.json';
$watched_dirs = [
    dirname(__DIR__, 2) . '/modules/',
    dirname(__DIR__, 2) . '/themes/',
    dirname(__DIR__, 2) . '/app/',
    dirname(__DIR__, 2) . '/css/',
    dirname(__DIR__, 2) . '/includes/',
    // MVC Patterns
    dirname(__DIR__, 2) . '/public/',
    dirname(__DIR__, 2) . '/app/'
];

$hashes = [];
$previous = [];

if (file_exists($watch_file)) {
    $previous = json_decode(file_get_contents($watch_file), true) ?: [];
}

foreach ($watched_dirs as $dir) {
    if (!is_dir($dir)) {
        file_put_contents($log_dir . '/devbot_debug.log', "[" . date('c') . "] Skipped nonexistent directory: $dir\n", FILE_APPEND);
        continue;
    }

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
    } catch (Exception $e) {
        file_put_contents($log_dir . '/devbot_debug.log', "[" . date('c') . "] Failed to scan $dir: " . $e->getMessage() . "\n", FILE_APPEND);
        continue;
    }

    foreach ($iterator as $file) {
        if ($file->isFile() && pathinfo($file, PATHINFO_EXTENSION) === 'php') {
            $rel_path = str_replace(dirname(__DIR__, 2) . '/', '', $file->getPathname());
            $hashes[$rel_path] = sha1_file($file->getPathname());
        }
    }
}

$changes = [];
foreach ($hashes as $file => $hash) {
    if (!isset($previous[$file])) {
        $changes[] = "➕ Added: $file";
    } elseif ($previous[$file] !== $hash) {
        $changes[] = "✍ Modified: $file";
    }
}

foreach ($previous as $file => $hash) {
    if (!isset($hashes[$file])) {
        $changes[] = "🗑 Removed: $file";
    }
}

file_put_contents($watch_file, json_encode($hashes, JSON_PRETTY_PRINT));

if (!empty($changes)) {
    $todays_logs[] = "🧬 File Changes Detected:\n\n" . implode("\n", $changes);
    file_put_contents($log_dir . '/devbot_debug.log', "[" . date('c') . "] Filewatch found " . count($changes) . " change(s).\n", FILE_APPEND);
} else {
    file_put_contents($log_dir . 'devbot_debug.log', "[" . date('c') . "] Filewatch found no changes.\n", FILE_APPEND);
}
