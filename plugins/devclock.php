<?php
class devclock
{
    public static $logfile = __DIR__ . '/../dev/devclock.log';

    public static function tick()
    {
        $now = time();
        $dir = dirname(self::$logfile);
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        file_put_contents(self::$logfile, $now . PHP_EOL, FILE_APPEND);
    }

    public static function summarize()
    {
        $log = self::get_today_ticks();
        if (count($log) < 2) return '⏱️ Time spent in the forge: less than a cycle.';

        $total = 0;
        for ($i = 1; $i < count($log); $i++) {
            $diff = $log[$i] - $log[$i - 1];
            if ($diff < 3600) $total += $diff;
        }

        $h = floor($total / 3600);
        $m = floor(($total % 3600) / 60);
        return "⏱️ Time spent in the forge: {$h}h {$m}m.";
    }

    public static function get_today_ticks()
    {
        if (!file_exists(self::$logfile)) return [];
        $lines = file(self::$logfile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $today = date('Y-m-d');
        return array_values(array_filter(array_map('intval', $lines), function ($ts) use ($today) {
            return date('Y-m-d', $ts) === $today;
        }));
    }

    public static function purge_old($days = 30)
    {
        if (!file_exists(self::$logfile)) return;
        $lines = file(self::$logfile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $cutoff = time() - ($days * 86400);
        $filtered = array_filter($lines, fn($ts) => (int)$ts >= $cutoff);
        file_put_contents(self::$logfile, implode(PHP_EOL, $filtered) . PHP_EOL);
    }

    public static function heartbeat()
    {
        self::tick();
        self::purge_old();
    }
}

// Auto-log into DevBot
if (isset($todays_logs)) {
    $todays_logs[] = devclock::summarize();
}