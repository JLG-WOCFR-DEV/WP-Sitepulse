<?php
/**
 * Lightweight static scan to catch potentially unsafe patterns.
 */

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

$root = __DIR__ . '/..';
$searchDirs = [
    $root . '/sitepulse_FR',
    $root . '/tests',
];

$patterns = [
    'eval\\s*\\(' => 'eval() usage can be unsafe. Consider alternatives.',
    'base64_decode\\s*\\(' => 'Potential obfuscated payload via base64_decode().',
    'shell_exec\\s*\\(' => 'shell_exec() should be avoided or heavily guarded.',
    'passthru\\s*\\(' => 'passthru() should be avoided or heavily guarded.',
    'system\\s*\\(' => 'system() should be avoided or heavily guarded.',
    '\\$_(GET|POST|REQUEST)\\s*\\[\\s*[\\"\\\']\\w+[\\"\\\']\\s*\\]' => 'Unsanitized superglobal access detected.',
];

$issues = [];

foreach ($searchDirs as $dir) {
    if (!is_dir($dir)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if (!in_array($file->getExtension(), ['php', 'phtml'])) {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        foreach ($patterns as $pattern => $message) {
            if (preg_match("/{$pattern}/i", $contents, $matches, PREG_OFFSET_CAPTURE)) {
                $line = substr_count(substr($contents, 0, $matches[0][1]), "\n") + 1;
                $issues[] = [
                    'file' => $file->getPathname(),
                    'line' => $line,
                    'pattern' => $matches[0][0],
                    'message' => $message,
                ];
            }
        }
    }
}

if (empty($issues)) {
    echo "No high-risk patterns detected.\n";
    exit(0);
}

echo "Potentially unsafe patterns found:\n";
foreach ($issues as $issue) {
    $relativePath = ltrim(str_replace($root, '', $issue['file']), DIRECTORY_SEPARATOR);
    printf(
        "- %s:%d -> %s (%s)\n",
        $relativePath,
        $issue['line'],
        $issue['pattern'],
        $issue['message']
    );
}

exit(1);
