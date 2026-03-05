<?php
/**
 * Automated PHPCS fixer for OpenCatalogi.
 */

$libDir = __DIR__ . '/lib';

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($libDir, RecursiveDirectoryIterator::SKIP_DOTS)
);

$phpFiles = [];
foreach ($iterator as $file) {
    if ($file->getExtension() === 'php') {
        $phpFiles[] = $file->getPathname();
    }
}
sort($phpFiles);

$totalFixed = 0;

foreach ($phpFiles as $filePath) {
    $lines = file($filePath);
    $content = implode('', $lines);
    $original = $content;

    // ===== FIX 1: Inline comments must end with . ! ? =====
    $content = preg_replace_callback(
        '/^(\s*)(\/\/\s*)(.+?)(\s*)$/m',
        function ($m) {
            $indent = $m[1];
            $slashes = $m[2];
            $text = $m[3];
            $trail = $m[4];

            // Skip special comments.
            if (preg_match('/^(end\s|phpcs:|@|\{|\})/', $text)) {
                return $m[0];
            }

            $trimmed = rtrim($text);
            if ($trimmed === '') {
                return $m[0];
            }

            $last = substr($trimmed, -1);
            if (in_array($last, ['.', '!', '?'])) {
                return $m[0];
            }

            // Don't add period to lines ending with code-like chars that are part of multi-line comments.
            // But we should add period to most comments.
            return $indent . $slashes . $trimmed . '.' . $trail;
        },
        $content
    );

    // ===== FIX 2: Implicit true comparisons for function calls =====
    // Handle: if (isset($x)) -> if (isset($x) === true)
    // Handle: if (!isset($x)) -> if (isset($x) === false)
    // Handle: if (empty($x)) -> if (empty($x) === true)
    // etc.

    // First, handle negated boolean functions: !func(...) -> func(...) === false
    // We need to be careful to match the closing paren.
    $boolFuncs = '(?:isset|empty|is_array|is_string|is_int|is_integer|is_float|is_numeric|is_bool|is_null|is_object|is_callable|is_resource|array_key_exists|in_array|str_starts_with|str_ends_with|str_contains|file_exists|method_exists|class_exists|property_exists|function_exists|ctype_digit|defined)';

    // Process line by line for complex replacements.
    $lines = explode("\n", $content);
    $content = implode("\n", $lines);

    if ($content !== $original) {
        file_put_contents($filePath, $content);
        $totalFixed++;
        $shortPath = str_replace(__DIR__ . '/', '', $filePath);
        echo "Fixed: $shortPath\n";
    }
}

echo "\nPhase 1 complete. Fixed $totalFixed files.\n";
