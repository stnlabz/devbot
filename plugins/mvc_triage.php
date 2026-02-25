<?php
declare(strict_types=1);

/**
 * DevBot Plugin: mvc_triage (schema_version 1.0.0)
 *
 * Immutable contract:
 * - Scope: public/index.php, app/core/router.php, app/controllers/*.php, app/views/**/*.php
 * - Token-based parsing only (no includes, no reflection, no execution)
 * - Full inventory every run
 * - Recon: new/modified/removed via sha1 hashes
 * - Router issues are CRITICAL
 * - Continue scanning after contact; always report full battlefield
 *
 * @package STN-Labz\DevBot
 */

const MVC_TRIAGE_SCHEMA_VERSION = '1.0.0';

/**
 * Entry point for DevBot plugin contract.
 *
 * @return array<string,mixed>
 */
return (static function (): array {
    $devbotRoot = dirname(__DIR__);                 // .../devbot
    $projectRoot = dirname($devbotRoot);            // .../(web root containing app/ and public/)

    $entryRel = 'public/index.php';
    $routerRel = 'app/core/router.php';
    $controllersRel = 'app/controllers';
    $viewsRel = 'app/views';

    $entryAbs = $projectRoot . '/' . $entryRel;
    $routerAbs = $projectRoot . '/' . $routerRel;
    $controllersAbs = $projectRoot . '/' . $controllersRel;
    $viewsAbs = $projectRoot . '/' . $viewsRel;

    $stateDir = $devbotRoot . '/state';
    $inventoryRel = 'devbot/state/mvc_inventory.json';
    $inventoryAbs = $stateDir . '/mvc_inventory.json';

    $nowUtc = gmdate('c');
    $runId = gmdate('Ymd') . '-' . substr(sha1((string) microtime(true)), 0, 6);

    $criticals = [];
    $warnings = [];

    $report = [
        'meta' => [
            'scan' => 'mvc_triage',
            'schema_version' => MVC_TRIAGE_SCHEMA_VERSION,
            'timestamp_utc' => $nowUtc,
            'entry' => $entryRel,
            'router' => $routerRel,
            'inventory_path' => $inventoryRel,
            'run_id' => $runId,
        ],
        'summary' => [
            'status' => 'clean',
            'critical' => 0,
            'warning' => 0,
            'controllers_total' => 0,
            'methods_total' => 0,
            'views_total' => 0,
            'recon_new' => 0,
            'recon_modified' => 0,
            'recon_removed' => 0,
        ],
        'recon' => [
            'new' => [],
            'modified' => [],
            'removed' => [],
        ],
        'router' => [
            'entry_loads_router' => false,
            'router_file_present' => false,
            'silent_fallback_detected' => false,
            'hard_404_detected' => false,
            'dispatch_validation_detected' => false,
            'evidence' => [],
            'issues' => [],
        ],
        'inventory' => [
            'controllers' => [],
            'views' => [],
        ],
        'criticals' => [],
        'warnings' => [],
    ];

    /**
     * @param array<int,array{type:string,path:string,hash?:string,previous_hash?:string}> $bucket
     * @param string $type
     * @param string $path
     * @param string|null $hash
     * @param string|null $previousHash
     * @return array<int,array{type:string,path:string,hash?:string,previous_hash?:string}>
     */
    $addRecon = static function (array $bucket, string $type, string $path, ?string $hash, ?string $previousHash): array {
        $row = [
            'type' => $type,
            'path' => $path,
        ];
        if ($hash !== null) {
            $row['hash'] = $hash;
        }
        if ($previousHash !== null) {
            $row['previous_hash'] = $previousHash;
        }
        $bucket[] = $row;
        return $bucket;
    };

    /**
     * @param string $path
     * @return string|null
     */
    $safeRead = static function (string $path): ?string {
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        return $raw === false ? null : $raw;
    };

    /**
     * @param string $path
     * @return string|null
     */
    $safeHash = static function (string $path): ?string {
        if (!is_file($path)) {
            return null;
        }
        $h = sha1_file($path);
        return $h === false ? null : $h;
    };

    /**
     * @param string $path
     * @return array<string,mixed>|null
     */
    $loadJson = static function (string $path): ?array {
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    };

    /**
     * @param array<int,array<string,mixed>> $list
     * @param array<string,mixed> $finding
     * @return array<int,array<string,mixed>>
     */
    $pushFinding = static function (array $list, array $finding): array {
        $list[] = $finding;
        return $list;
    };

    /**
     * @param string $haystack
     * @param int $offset
     * @return int
     */
    $offsetToLine = static function (string $haystack, int $offset): int {
        if ($offset <= 0) {
            return 1;
        }
        $prefix = substr($haystack, 0, $offset);
        return substr_count($prefix, "\n") + 1;
    };

    /**
     * Scan directory for files (non-recursive).
     *
     * @param string $dir
     * @param string $suffix
     * @return array<int,string> absolute paths
     */
    $scanDir = static function (string $dir, string $suffix): array {
        if (!is_dir($dir)) {
            return [];
        }

        $out = [];
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir . '/' . $f;
            if (is_file($p) && str_ends_with($f, $suffix)) {
                $out[] = $p;
            }
        }

        sort($out);
        return $out;
    };

    /**
     * Scan directory recursively for files.
     *
     * @param string $dir
     * @param string $suffix
     * @return array<int,string> absolute paths
     */
    $scanDirRecursive = static function (string $dir, string $suffix): array {
        if (!is_dir($dir)) {
            return [];
        }

        $out = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $name = $file->getFilename();
            if (!str_ends_with($name, $suffix)) {
                continue;
            }
            $out[] = $file->getPathname();
        }

        sort($out);
        return $out;
    };

    // ---------------------------
    // Phase: Load previous inventory
    // ---------------------------
    $previous = null;
    $invMissing = !is_file($inventoryAbs);

    if ($invMissing) {
        $warnings = $pushFinding($warnings, [
            'type' => 'inventory_missing',
            'severity' => 'warning',
            'file' => $inventoryRel,
            'line' => 0,
            'message' => 'Inventory file not found. Treating as initial baseline run.',
        ]);
        $previous = [
            'controllers' => [],
            'views' => [],
            'router' => [],
            'entry' => [],
        ];
    } else {
        $previous = $loadJson($inventoryAbs);
        if ($previous === null) {
            $criticals = $pushFinding($criticals, [
                'type' => 'inventory_corrupted',
                'severity' => 'critical',
                'file' => $inventoryRel,
                'line' => 0,
                'message' => 'Inventory file unreadable or invalid JSON.',
                'impact' => 'Recon diff integrity compromised for this run.',
            ]);
            $previous = [
                'controllers' => [],
                'views' => [],
                'router' => [],
                'entry' => [],
            ];
        }
    }

    // ---------------------------
    // Phase: Scan filesystem + hash snapshot
    // ---------------------------
    $entryHash = $safeHash($entryAbs);
    $routerHash = $safeHash($routerAbs);

    $controllerFiles = $scanDir($controllersAbs, '.php');
    $viewFiles = $scanDirRecursive($viewsAbs, '.php');

    $currentState = [
        'controllers' => [],
        'views' => [],
        'router' => [
            $routerRel => $routerHash,
        ],
        'entry' => [
            $entryRel => $entryHash,
        ],
        'last_scan' => $nowUtc,
    ];

    foreach ($controllerFiles as $abs) {
        $rel = str_replace($projectRoot . '/', '', $abs);
        $currentState['controllers'][$rel] = $safeHash($abs);
    }

    foreach ($viewFiles as $abs) {
        $rel = str_replace($projectRoot . '/', '', $abs);
        $currentState['views'][$rel] = $safeHash($abs);
    }

    // ---------------------------
    // Phase: Recon diff (new/modified/removed)
    // ---------------------------
    $prevControllers = (array)($previous['controllers'] ?? []);
    $prevViews = (array)($previous['views'] ?? []);
    $prevRouter = (array)($previous['router'] ?? []);
    $prevEntry = (array)($previous['entry'] ?? []);

    $diffBucketNew = [];
    $diffBucketMod = [];
    $diffBucketRem = [];

    $currentControllers = (array)($currentState['controllers'] ?? []);
    $currentViews = (array)($currentState['views'] ?? []);
    $currentRouter = (array)($currentState['router'] ?? []);
    $currentEntry = (array)($currentState['entry'] ?? []);

    foreach ($currentEntry as $path => $hash) {
        $prev = $prevEntry[$path] ?? null;
        if ($prev === null) {
            $diffBucketNew = $addRecon($diffBucketNew, 'entry', $path, $hash, null);
        } elseif ($prev !== $hash) {
            $diffBucketMod = $addRecon($diffBucketMod, 'entry', $path, $hash, $prev);
        }
    }
    foreach ($prevEntry as $path => $hash) {
        if (!array_key_exists($path, $currentEntry)) {
            $diffBucketRem = $addRecon($diffBucketRem, 'entry', $path, null, (string) $hash);
        }
    }

    foreach ($currentRouter as $path => $hash) {
        $prev = $prevRouter[$path] ?? null;
        if ($prev === null) {
            $diffBucketNew = $addRecon($diffBucketNew, 'router', $path, $hash, null);
        } elseif ($prev !== $hash) {
            $diffBucketMod = $addRecon($diffBucketMod, 'router', $path, $hash, $prev);
        }
    }
    foreach ($prevRouter as $path => $hash) {
        if (!array_key_exists($path, $currentRouter)) {
            $diffBucketRem = $addRecon($diffBucketRem, 'router', $path, null, (string) $hash);
        }
    }

    foreach ($currentControllers as $path => $hash) {
        $prev = $prevControllers[$path] ?? null;
        if ($prev === null) {
            $diffBucketNew = $addRecon($diffBucketNew, 'controller', $path, $hash, null);
        } elseif ($prev !== $hash) {
            $diffBucketMod = $addRecon($diffBucketMod, 'controller', $path, $hash, $prev);
        }
    }
    foreach ($prevControllers as $path => $hash) {
        if (!array_key_exists($path, $currentControllers)) {
            $diffBucketRem = $addRecon($diffBucketRem, 'controller', $path, null, (string) $hash);
        }
    }

    foreach ($currentViews as $path => $hash) {
        $prev = $prevViews[$path] ?? null;
        if ($prev === null) {
            $diffBucketNew = $addRecon($diffBucketNew, 'view', $path, $hash, null);
        } elseif ($prev !== $hash) {
            $diffBucketMod = $addRecon($diffBucketMod, 'view', $path, $hash, $prev);
        }
    }
    foreach ($prevViews as $path => $hash) {
        if (!array_key_exists($path, $currentViews)) {
            $diffBucketRem = $addRecon($diffBucketRem, 'view', $path, null, (string) $hash);
        }
    }

    $report['recon']['new'] = $diffBucketNew;
    $report['recon']['modified'] = $diffBucketMod;
    $report['recon']['removed'] = $diffBucketRem;

    // ---------------------------
    // Phase: Router audit (entry + router)
    // ---------------------------
    $entrySrc = $safeRead($entryAbs);
    $routerSrc = $safeRead($routerAbs);

    $report['router']['router_file_present'] = is_file($routerAbs);

    if ($entrySrc === null) {
        $criticals = $pushFinding($criticals, [
            'type' => 'bootstrap_entry_missing',
            'severity' => 'critical',
            'file' => $entryRel,
            'line' => 0,
            'message' => 'Entry file public/index.php is missing or unreadable.',
            'impact' => 'Routing cannot be trusted without a valid entry point.',
        ]);
    }

    if ($routerSrc === null) {
        $criticals = $pushFinding($criticals, [
            'type' => 'bootstrap_router_missing',
            'severity' => 'critical',
            'file' => $routerRel,
            'line' => 0,
            'message' => 'Router file app/core/router.php is missing or unreadable.',
            'impact' => 'Routing cannot function without router class logic.',
        ]);
    }

    // Entry loads router detection + entry override detection.
    if ($entrySrc !== null) {
        $entryTokens = token_get_all($entrySrc);

        $routerLoadLine = null;
        $routerLoadOffset = null;

        $foundRouterInclude = false;
        $foundBootstrapOverride = false;
        $foundPrematureOutput = false;

        $currentLine = 1;

        for ($i = 0, $max = count($entryTokens); $i < $max; $i++) {
            $tok = $entryTokens[$i];
            if (is_array($tok)) {
                $currentLine = $tok[2];
            }

            // Find include/require of router
            if (is_array($tok) && in_array($tok[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) {
                // Look ahead for a string literal that contains router.php
                for ($j = $i + 1; $j < min($i + 20, $max); $j++) {
                    $t2 = $entryTokens[$j];
                    if (!is_array($t2)) {
                        continue;
                    }
                    if ($t2[0] === T_CONSTANT_ENCAPSED_STRING) {
                        $lit = trim($t2[1], '\'"');
                        if (str_contains($lit, 'app/core/router.php') || str_ends_with($lit, 'router.php')) {
                            $foundRouterInclude = true;
                            $routerLoadLine = $currentLine;
                            $routerLoadOffset = strpos($entrySrc, $t2[1]);
                            break;
                        }
                    }
                }
            }

            if ($routerLoadLine === null) {
                // Detect suspicious behavior before router is loaded
                if (is_array($tok) && $tok[0] === T_NEW) {
                    $foundBootstrapOverride = true;
                }
                if (is_array($tok) && in_array($tok[0], [T_ECHO, T_PRINT], true)) {
                    $foundPrematureOutput = true;
                }
                if (is_array($tok) && $tok[0] === T_STRING && $tok[1] === 'call_user_func_array') {
                    $foundBootstrapOverride = true;
                }
                if (is_array($tok) && $tok[0] === T_STRING && $tok[1] === 'var_dump') {
                    $foundPrematureOutput = true;
                }
            }
        }

        $report['router']['entry_loads_router'] = $foundRouterInclude;

        if (!$foundRouterInclude) {
            $criticals = $pushFinding($criticals, [
                'type' => 'bootstrap_router_missing',
                'severity' => 'critical',
                'file' => $entryRel,
                'line' => 0,
                'message' => 'Entry does not appear to load app/core/router.php.',
                'impact' => 'Routing logic may be bypassed or incomplete.',
            ]);
        }

        if ($foundBootstrapOverride) {
            $criticals = $pushFinding($criticals, [
                'type' => 'bootstrap_route_override',
                'severity' => 'critical',
                'file' => $entryRel,
                'line' => $routerLoadLine ?? 0,
                'message' => 'Possible route override detected in entry point before router load.',
                'impact' => 'Entry point may bypass dynamic router behavior.',
            ]);
        }

        if ($foundPrematureOutput) {
            $criticals = $pushFinding($criticals, [
                'type' => 'bootstrap_premature_output',
                'severity' => 'critical',
                'file' => $entryRel,
                'line' => $routerLoadLine ?? 0,
                'message' => 'Output detected in entry point before router load.',
                'impact' => 'Headers/routing may be corrupted by premature output.',
            ]);
        }

        if ($routerLoadLine !== null) {
            $report['router']['evidence'][] = [
                'file' => $entryRel,
                'line' => $routerLoadLine,
                'snippet' => 'Router include/require detected in entry.',
            ];
        }
    }

    // Router strictness detection
    if ($routerSrc !== null) {
        $report['router']['router_file_present'] = true;

        $hard404 =
            str_contains($routerSrc, 'http_response_code(404') ||
            str_contains($routerSrc, 'HTTP/1.1 404') ||
            str_contains($routerSrc, '404 Not Found');

        $report['router']['hard_404_detected'] = $hard404;

        // Detect call_user_func_array
        $hasCallUserFuncArray = str_contains($routerSrc, 'call_user_func_array');

        // Detect basic dispatch validation evidence (Reflection usage)
        $dispatchValidated =
            str_contains($routerSrc, 'ReflectionMethod') ||
            str_contains($routerSrc, 'getNumberOfRequiredParameters') ||
            str_contains($routerSrc, 'getNumberOfParameters');

        $report['router']['dispatch_validation_detected'] = $dispatchValidated;

        // Detect silent fallback: $method = 'index' (or "index")
        $silentFallbackDetected = false;
        $routerTokens = token_get_all($routerSrc);

        $currentLine = 1;
        for ($i = 0, $max = count($routerTokens); $i < $max; $i++) {
            $tok = $routerTokens[$i];
            if (is_array($tok)) {
                $currentLine = $tok[2];
            }

            if (is_array($tok) && $tok[0] === T_VARIABLE && $tok[1] === '$method') {
                // Look ahead for = 'index'
                $j = $i + 1;
                while ($j < $max) {
                    $t2 = $routerTokens[$j];
                    if (is_array($t2) && $t2[0] === T_WHITESPACE) {
                        $j++;
                        continue;
                    }
                    if ($t2 === '=') {
                        $j++;
                        break;
                    }
                    break;
                }

                while ($j < $max) {
                    $t3 = $routerTokens[$j];
                    if (is_array($t3) && $t3[0] === T_WHITESPACE) {
                        $j++;
                        continue;
                    }
                    if (is_array($t3) && $t3[0] === T_CONSTANT_ENCAPSED_STRING) {
                        $lit = trim($t3[1], '\'"');
                        if ($lit === 'index') {
                            $silentFallbackDetected = true;
                            $report['router']['evidence'][] = [
                                'file' => $routerRel,
                                'line' => $currentLine,
                                'snippet' => '$method assignment to index detected.',
                            ];
                        }
                    }
                    break;
                }
            }
        }

        $report['router']['silent_fallback_detected'] = $silentFallbackDetected;

        if ($silentFallbackDetected) {
            $criticals = $pushFinding($criticals, [
                'type' => 'router_silent_fallback',
                'severity' => 'critical',
                'file' => $routerRel,
                'line' => 0,
                'message' => 'Router silently falls back to index() when method not found.',
                'impact' => 'Invalid routes resolve without failure. Dispatch errors masked.',
            ]);
            $report['router']['issues'][] = [
                'type' => 'router_silent_fallback',
                'severity' => 'critical',
                'file' => $routerRel,
                'line' => 0,
                'message' => 'Router silently falls back to index() when method not found.',
                'impact' => 'Invalid routes resolve without failure. Dispatch errors masked.',
            ];
        }

        if (!$hard404) {
            $criticals = $pushFinding($criticals, [
                'type' => 'router_missing_404',
                'severity' => 'critical',
                'file' => $routerRel,
                'line' => 0,
                'message' => 'No explicit 404 response detected in router failure branches.',
                'impact' => 'Invalid controller/method requests may not terminate properly.',
            ]);
            $report['router']['issues'][] = [
                'type' => 'router_missing_404',
                'severity' => 'critical',
                'file' => $routerRel,
                'line' => 0,
                'message' => 'No explicit 404 response detected in router failure branches.',
                'impact' => 'Invalid controller/method requests may not terminate properly.',
            ];
        }

        if ($hasCallUserFuncArray && !$dispatchValidated) {
            $criticals = $pushFinding($criticals, [
                'type' => 'router_dispatch_unvalidated',
                'severity' => 'critical',
                'file' => $routerRel,
                'line' => 0,
                'message' => 'Dispatch uses call_user_func_array without detectable parameter validation.',
                'impact' => 'Method/parameter mismatches may cause silent stalls or incorrect dispatch.',
            ]);
            $report['router']['issues'][] = [
                'type' => 'router_dispatch_unvalidated',
                'severity' => 'critical',
                'file' => $routerRel,
                'line' => 0,
                'message' => 'Dispatch uses call_user_func_array without detectable parameter validation.',
                'impact' => 'Method/parameter mismatches may cause silent stalls or incorrect dispatch.',
            ];
        }
    }

    // ---------------------------
    // Phase: Controller token parsing + structural checks
    // ---------------------------
    $controllersInventory = [];
    $controllerMethodsForViewMap = []; // controllerKey => list of ['name'=>string,'line'=>int]
    $controllerSources = [];           // controllerKey => raw source

    foreach ($controllerFiles as $abs) {
        $rel = str_replace($projectRoot . '/', '', $abs);
        $base = pathinfo($abs, PATHINFO_FILENAME);
        $hash = $safeHash($abs);

        $src = $safeRead($abs);
        $controllerSources[$base] = $src ?? '';

        $className = null;
        $classLine = 0;
        $extendsName = null;
        $extendsLine = 0;

        $methods = [];
        $methodIndex = []; // name => line (for view line mapping)

        $methodCountPublic = 0;
        $methodCountTotal = 0;

        if ($src === null) {
            $criticals = $pushFinding($criticals, [
                'type' => 'controller_unreadable',
                'severity' => 'critical',
                'file' => $rel,
                'line' => 0,
                'message' => 'Controller file unreadable.',
                'impact' => 'Controller inventory cannot be trusted.',
            ]);

            $controllersInventory[$base] = [
                'path' => $rel,
                'hash' => $hash,
                'class_name_detected' => null,
                'filename_expected_class' => $base,
                'class_matches_filename' => false,
                'extends_base' => false,
                'base_class_detected' => null,
                'method_count_public' => 0,
                'method_count_total' => 0,
                'methods' => [],
            ];
            continue;
        }

        $tokens = token_get_all($src);

        $lastVisibility = null; // public|protected|private
        $lastStatic = false;

        $currentLine = 1;

        for ($i = 0, $max = count($tokens); $i < $max; $i++) {
            $tok = $tokens[$i];
            if (is_array($tok)) {
                $currentLine = $tok[2];
            }

            if (is_array($tok) && $tok[0] === T_CLASS) {
                $classLine = $currentLine;

                // next T_STRING is class name
                for ($j = $i + 1; $j < $max; $j++) {
                    $t2 = $tokens[$j];
                    if (is_array($t2) && $t2[0] === T_WHITESPACE) {
                        continue;
                    }
                    if (is_array($t2) && $t2[0] === T_STRING) {
                        $className = $t2[1];
                    }
                    break;
                }

                // look for extends
                for ($j = $i + 1; $j < min($i + 80, $max); $j++) {
                    $t2 = $tokens[$j];
                    if (!is_array($t2)) {
                        continue;
                    }
                    if ($t2[0] === T_EXTENDS) {
                        $extendsLine = $t2[2];
                        for ($k = $j + 1; $k < $max; $k++) {
                            $t3 = $tokens[$k];
                            if (is_array($t3) && $t3[0] === T_WHITESPACE) {
                                continue;
                            }
                            if (is_array($t3) && $t3[0] === T_STRING) {
                                $extendsName = $t3[1];
                            }
                            break;
                        }
                        break;
                    }
                }
            }

            if (is_array($tok)) {
                if ($tok[0] === T_PUBLIC) {
                    $lastVisibility = 'public';
                } elseif ($tok[0] === T_PROTECTED) {
                    $lastVisibility = 'protected';
                } elseif ($tok[0] === T_PRIVATE) {
                    $lastVisibility = 'private';
                } elseif ($tok[0] === T_STATIC) {
                    $lastStatic = true;
                } elseif ($tok[0] === T_FUNCTION) {
                    $methodCountTotal++;

                    $fnLine = $currentLine;
                    $fnName = null;

                    // Find function name
                    for ($j = $i + 1; $j < $max; $j++) {
                        $t2 = $tokens[$j];
                        if (is_array($t2) && $t2[0] === T_WHITESPACE) {
                            continue;
                        }
                        if ($t2 === '&') {
                            continue;
                        }
                        if (is_array($t2) && $t2[0] === T_STRING) {
                            $fnName = $t2[1];
                        }
                        break;
                    }

                    if ($fnName === null) {
                        // anonymous function; reset modifiers and continue
                        $lastVisibility = null;
                        $lastStatic = false;
                        continue;
                    }

                    $visibility = $lastVisibility ?? 'public';
                    $isStatic = $lastStatic;

                    if ($visibility === 'public') {
                        $methodCountPublic++;
                    }

                    // Count parameters (required + total)
                    $required = 0;
                    $total = 0;

                    // Move to opening '(' after function name
                    $j = $i;
                    $foundParen = false;
                    for (; $j < $max; $j++) {
                        $t2 = $tokens[$j];
                        if ($t2 === '(') {
                            $foundParen = true;
                            break;
                        }
                    }

                    if ($foundParen) {
                        $depth = 0;
                        $inParams = false;

                        $seenVarInParam = false;
                        $paramHasDefault = false;

                        for ($k = $j; $k < $max; $k++) {
                            $t3 = $tokens[$k];

                            if ($t3 === '(') {
                                $depth++;
                                if ($depth === 1) {
                                    $inParams = true;
                                    continue;
                                }
                            }

                            if ($t3 === ')') {
                                if ($inParams && $depth === 1) {
                                    // finalize last param
                                    if ($seenVarInParam) {
                                        $total++;
                                        if (!$paramHasDefault) {
                                            $required++;
                                        }
                                    }
                                    break;
                                }
                                $depth--;
                            }

                            if (!$inParams || $depth !== 1) {
                                continue;
                            }

                            if (is_array($t3) && $t3[0] === T_VARIABLE) {
                                // start new param (or overwrite)
                                $seenVarInParam = true;
                                $paramHasDefault = false;
                            }

                            if ($t3 === '=') {
                                if ($seenVarInParam) {
                                    $paramHasDefault = true;
                                }
                            }

                            if ($t3 === ',') {
                                if ($seenVarInParam) {
                                    $total++;
                                    if (!$paramHasDefault) {
                                        $required++;
                                    }
                                }
                                $seenVarInParam = false;
                                $paramHasDefault = false;
                            }
                        }
                    }

                    $methods[$fnName] = [
                        'visibility' => $visibility,
                        'static' => $isStatic,
                        'required_params' => $required,
                        'total_params' => $total,
                        'line_declared' => $fnLine,
                        'notes' => [],
                    ];

                    $methodIndex[] = [
                        'name' => $fnName,
                        'line' => $fnLine,
                    ];

                    // Reset modifiers after a function
                    $lastVisibility = null;
                    $lastStatic = false;
                }
            }
        }

        usort($methodIndex, static fn(array $a, array $b): int => $a['line'] <=> $b['line']);
        $controllerMethodsForViewMap[$base] = $methodIndex;

        // Structural checks (CRITICAL)
        if ($className === null) {
            $criticals = $pushFinding($criticals, [
                'type' => 'controller_class_missing',
                'severity' => 'critical',
                'file' => $rel,
                'line' => $classLine,
                'message' => 'No class declaration detected in controller file.',
                'impact' => 'Dynamic controller resolution may fail.',
            ]);
        }

        $classMatches = $className !== null && $className === $base;
        if ($className !== null && !$classMatches) {
            $criticals = $pushFinding($criticals, [
                'type' => 'controller_class_mismatch',
                'severity' => 'critical',
                'file' => $rel,
                'line' => $classLine,
                'message' => 'Declared class name does not match filename.',
                'impact' => 'Dynamic controller resolution may fail on case-sensitive systems.',
            ]);
        }

        $extendsBase = $extendsName !== null && $extendsName === 'controller';
        if (!$extendsBase) {
            $criticals = $pushFinding($criticals, [
                'type' => 'controller_not_extending_base',
                'severity' => 'critical',
                'file' => $rel,
                'line' => $extendsLine ?: $classLine,
                'message' => 'Controller does not extend base controller.',
                'impact' => 'Controller may lack required MVC contract methods.',
            ]);
        }

        $controllersInventory[$base] = [
            'path' => $rel,
            'hash' => $hash,
            'class_name_detected' => $className,
            'filename_expected_class' => $base,
            'class_matches_filename' => $classMatches,
            'extends_base' => $extendsBase,
            'base_class_detected' => $extendsName,
            'method_count_public' => $methodCountPublic,
            'method_count_total' => $methodCountTotal,
            'methods' => $methods,
        ];
    }

    $report['inventory']['controllers'] = $controllersInventory;

    // ---------------------------
    // Phase: View audit (references + orphans + missing)
    // ---------------------------
    $viewsInventory = [];
    $viewRefs = []; // viewRel => referenced_by[]
    $knownViews = [];

    foreach ($viewFiles as $abs) {
        $rel = str_replace($projectRoot . '/', '', $abs);
        if (!str_starts_with($rel, $viewsRel . '/')) {
            continue;
        }
        $viewRel = substr($rel, strlen($viewsRel) + 1); // relative under app/views
        $knownViews[$viewRel] = [
            'path' => $rel,
            'hash' => $safeHash($abs),
        ];
    }

    // Parse view calls inside controllers; map to nearest preceding method by line.
    foreach ($controllerSources as $controllerKey => $src) {
        if ($src === '') {
            continue;
        }

        preg_match_all(
            '/->\s*view\s*\(\s*(["\'])([^"\']+)\1\s*/',
            $src,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        if (empty($matches[2])) {
            continue;
        }

        $methodIndex = $controllerMethodsForViewMap[$controllerKey] ?? [];

        foreach ($matches[2] as $idx => $m) {
            $rawPath = (string) $m[0];
            $offset = (int) ($matches[2][$idx][1] ?? 0);
            $line = $offsetToLine($src, $offset);

            $viewRel = ltrim($rawPath, '/');
            if (!str_ends_with($viewRel, '.php')) {
                $viewRel .= '.php';
            }

            // Resolve "controller::method" by nearest method start line
            $resolvedMethod = '?';
            foreach ($methodIndex as $mi) {
                if (($mi['line'] ?? 0) <= $line) {
                    $resolvedMethod = (string) ($mi['name'] ?? '?');
                    continue;
                }
                break;
            }

            $ref = $controllerKey . '::' . $resolvedMethod;

            if (!isset($viewRefs[$viewRel])) {
                $viewRefs[$viewRel] = [];
            }
            if (!in_array($ref, $viewRefs[$viewRel], true)) {
                $viewRefs[$viewRel][] = $ref;
            }

            // Missing view warning
            if (!isset($knownViews[$viewRel])) {
                $warnings = $pushFinding($warnings, [
                    'type' => 'view_missing',
                    'severity' => 'warning',
                    'file' => 'app/controllers/' . $controllerKey . '.php',
                    'line' => $line,
                    'message' => 'Referenced view file does not exist: ' . $viewsRel . '/' . $viewRel,
                    'evidence' => $ref,
                ]);
            }
        }
    }

    // Build views inventory + orphan warnings
    foreach ($knownViews as $viewRel => $info) {
        $refs = $viewRefs[$viewRel] ?? [];
        $orphan = empty($refs);

        $viewsInventory[$viewRel] = [
            'path' => $info['path'],
            'hash' => $info['hash'],
            'referenced_by' => $refs,
            'orphan' => $orphan,
        ];

        if ($orphan) {
            $warnings = $pushFinding($warnings, [
                'type' => 'view_orphaned',
                'severity' => 'warning',
                'file' => $info['path'],
                'line' => 0,
                'message' => 'View exists but is not referenced by any controller method.',
                'evidence' => $viewRel,
            ]);
        }
    }

    ksort($viewsInventory);
    $report['inventory']['views'] = $viewsInventory;

    // ---------------------------
    // Phase: Compile summary + status
    // ---------------------------
    $controllersTotal = count($report['inventory']['controllers']);
    $methodsTotal = 0;
    foreach ($report['inventory']['controllers'] as $c) {
        $methodsTotal += (int)($c['method_count_total'] ?? 0);
    }
    $viewsTotal = count($report['inventory']['views']);

    $report['summary']['controllers_total'] = $controllersTotal;
    $report['summary']['methods_total'] = $methodsTotal;
    $report['summary']['views_total'] = $viewsTotal;

    $report['summary']['recon_new'] = count($report['recon']['new']);
    $report['summary']['recon_modified'] = count($report['recon']['modified']);
    $report['summary']['recon_removed'] = count($report['recon']['removed']);

    $report['criticals'] = $criticals;
    $report['warnings'] = $warnings;

    $report['summary']['critical'] = count($criticals);
    $report['summary']['warning'] = count($warnings);

    if ($report['summary']['critical'] > 0) {
        $report['summary']['status'] = 'failed';
    } elseif ($report['summary']['warning'] > 0) {
        $report['summary']['status'] = 'clean_with_warnings';
    } else {
        $report['summary']['status'] = 'clean';
    }

    // ---------------------------
    // Phase: Write inventory (atomic replace)
    // ---------------------------
    if (!is_dir($stateDir)) {
        @mkdir($stateDir, 0755, true);
    }

    $inventoryWrite = [
        'controllers' => $currentState['controllers'],
        'views' => $currentState['views'],
        'router' => $currentState['router'],
        'entry' => $currentState['entry'],
        'last_scan' => $currentState['last_scan'],
    ];

    $tmp = $inventoryAbs . '.tmp';
    $json = json_encode($inventoryWrite, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (is_string($json)) {
        @file_put_contents($tmp, $json . PHP_EOL, LOCK_EX);
        @rename($tmp, $inventoryAbs);
    } else {
        $criticals = $pushFinding($criticals, [
            'type' => 'inventory_write_failed',
            'severity' => 'critical',
            'file' => $inventoryRel,
            'line' => 0,
            'message' => 'Failed to encode inventory JSON.',
            'impact' => 'Recon baseline cannot be persisted.',
        ]);
        $report['criticals'] = $criticals;
        $report['summary']['critical'] = count($criticals);
        $report['summary']['status'] = 'failed';
    }

    return $report;
})();
