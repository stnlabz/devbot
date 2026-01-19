<?php
class ghostnote
{
    public static $pattern = '/\/\/@devbot:(.*)/i';

    public static function scan($path)
    {
        $notes = [];
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
        foreach ($rii as $file) {
            if (!$file->isDir() && pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                $lines = file($file->getPathname());
                foreach ($lines as $i => $line) {
                    if (preg_match(self::$pattern, $line, $m)) {
                        $notes[] = [
                            'file' => str_replace(getcwd() . '/', '', $file->getPathname()),
                            'line' => $i + 1,
                            'note' => trim($m[1])
                        ];
                    }
                }
            }
        }
        return $notes;
    }
}

// Auto-log into DevBot
if (isset($todays_logs)) {
    $notes = ghostnote::scan(__DIR__ . '/../../');
    if (!empty($notes)) {
        $todays_logs[] = '👻 **Ghostnotes**';
        foreach ($notes as $note) {
            $todays_logs[] = "- {$note['file']} @ line {$note['line']}: {$note['note']}";
        }
    }
}