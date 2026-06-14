<?php
declare(strict_types=1);

$baseDir = realpath(__DIR__);
$result = '';
$error = '';
$pathInput = $_POST['scan_path'] ?? '';
$recursive = isset($_POST['recursive']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pathInput = trim($pathInput);

    if ($pathInput === '') {
        $error = 'Please enter a file or directory path.';
    } else {
        $resolvedPath = resolvePath($pathInput, $baseDir);

        if ($resolvedPath === false || !file_exists($resolvedPath)) {
            $error = 'The specified path does not exist or is not allowed.';
        } elseif (!isAllowedPath($resolvedPath, $baseDir)) {
            $error = 'Path is outside the allowed base directory.';
        } else {
            try {
                $files = collectPhpFiles($resolvedPath, $recursive);
                $strings = [];
                $usedKeys = [];

                foreach ($files as $file) {
                    $content = @file_get_contents($file);

                    if ($content === false) {
                        continue;
                    }

                    extractStringsFromPhp($content, $strings, $usedKeys);
                }

                $result = formatPhpArray($strings);

                if ($result === "[\n];") {
                    $error = 'No matching hardcoded strings were found.';
                    $result = '';
                }
            } catch (Throwable $e) {
                $error = 'Error while scanning: ' . $e->getMessage();
            }
        }
    }
}

function resolvePath(string $input, string $baseDir)
{
    // Absolute path?
    if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $input) || str_starts_with($input, DIRECTORY_SEPARATOR)) {
        return realpath($input);
    }

    // Relative to current script directory
    return realpath($baseDir . DIRECTORY_SEPARATOR . $input);
}

function isAllowedPath(string $path, string $baseDir): bool
{
    $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    $baseDir = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $baseDir);

    return str_starts_with($path, $baseDir);
}

function collectPhpFiles(string $path, bool $recursive): array
{
    $files = [];

    if (is_file($path)) {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php') {
            $files[] = $path;
        }
        return $files;
    }

    if (!is_dir($path)) {
        return $files;
    }

    if ($recursive) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile() && strtolower($fileInfo->getExtension()) === 'php') {
                $files[] = $fileInfo->getPathname();
            }
        }
    } else {
        $items = scandir($path);
        if ($items !== false) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                $fullPath = $path . DIRECTORY_SEPARATOR . $item;
                if (is_file($fullPath) && strtolower(pathinfo($fullPath, PATHINFO_EXTENSION)) === 'php') {
                    $files[] = $fullPath;
                }
            }
        }
    }

    sort($files);
    return $files;
}

function extractStringsFromPhp(string $content, array &$strings, array &$usedKeys): void
{
    $tokens = token_get_all($content);
    $inHeredoc = false;

    foreach ($tokens as $token) {
        if (is_array($token)) {
            [$tokenId, $tokenText] = $token;

            switch ($tokenId) {
                case T_CONSTANT_ENCAPSED_STRING:
                    $value = decodePhpStringLiteral($tokenText);
                    addCandidateString($value, $strings, $usedKeys);
                    break;

                case T_INLINE_HTML:
                    extractVisibleTextFromHtmlChunk($tokenText, $strings, $usedKeys);
                    break;

                case T_START_HEREDOC:
                    $inHeredoc = true;
                    break;

                case T_ENCAPSED_AND_WHITESPACE:
                    if ($inHeredoc) {
                        addCandidateString($tokenText, $strings, $usedKeys);
                    }
                    break;

                case T_END_HEREDOC:
                    $inHeredoc = false;
                    break;
            }
        }
    }
}

function decodePhpStringLiteral(string $literal): string
{
    $quote = $literal[0] ?? '';
    $body = substr($literal, 1, -1);

    if ($quote === "'") {
        $body = str_replace(["\\\\", "\\'"], ["\\", "'"], $body);
    } elseif ($quote === '"') {
        $body = stripcslashes($body);
    }

    return normalizeText($body);
}

function extractVisibleTextFromHtmlChunk(string $html, array &$strings, array &$usedKeys): void
{
    $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Strip scripts/styles just in case
    $decoded = preg_replace('#<script\b[^>]*>.*?</script>#is', ' ', $decoded);
    $decoded = preg_replace('#<style\b[^>]*>.*?</style>#is', ' ', $decoded);

    $text = strip_tags($decoded);
    $parts = preg_split('/[\r\n]+/', $text);

    if (!$parts) {
        return;
    }

    foreach ($parts as $part) {
        addCandidateString($part, $strings, $usedKeys);
    }
}

function addCandidateString(string $text, array &$strings, array &$usedKeys): void
{
    $text = normalizeText($text);

    if (!isLikelyUserFacingString($text)) {
        return;
    }

    $key = generateKey($text);
    if ($key === '') {
        return;
    }

    $baseKey = $key;
    $suffix = 2;

    while (isset($usedKeys[$key]) && $strings[$key] !== $text) {
        $key = $baseKey . '_' . $suffix;
        $suffix++;
    }

    $usedKeys[$key] = true;
    $strings[$key] = $text;
}

function normalizeText(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim($text);
}

function isLikelyUserFacingString(string $text): bool
{
    if ($text === '') {
        return false;
    }

    if (mb_strlen($text) < 2) {
        return false;
    }

    if (preg_match('/^[[:punct:]\s]+$/u', $text)) {
        return false;
    }

    // Skip obvious code-ish fragments
    $codePatterns = [
        '/^\$[A-Za-z_][A-Za-z0-9_]*$/',                  // variable names
        '/^[A-Za-z_][A-Za-z0-9_]*\(\)$/',               // function-like
        '/^(SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER|DROP)\b/i', // SQL
        '/^(http|https|ftp):\/\//i',                    // URLs
        '/^[A-Za-z0-9_\/\.-]+\.(php|js|css|png|jpg|jpeg|gif|svg|ico)$/i', // file paths
        '/^[A-Za-z0-9_\-]+$/',                          // single code identifier
        '/[{};$<>]/',                                   // likely code fragment
    ];

    foreach ($codePatterns as $pattern) {
        if (preg_match($pattern, $text)) {
            return false;
        }
    }

    // Prefer strings with letters and maybe spaces/punctuation
    if (!preg_match('/\p{L}/u', $text)) {
        return false;
    }

    return true;
}

function generateKey(string $text): string
{
    $key = mb_strtolower($text, 'UTF-8');
    $key = preg_replace("/['’]/u", '', $key);
    $key = preg_replace('/[^\p{L}\p{N}]+/u', '_', $key);
    $key = trim($key, '_');

    return $key;
}

function formatPhpArray(array $strings): string
{
    $output = "[\n";
    foreach ($strings as $key => $value) {
        $output .= "    '" . addslashes($key) . "' => '" . addslashes($value) . "',\n";
    }
    $output .= "];";

    return $output;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP Hardcoded String Scanner</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f6f8;
            margin: 0;
            padding: 40px;
            color: #222;
        }

        .container {
            max-width: 1100px;
            margin: auto;
            background: #fff;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 4px 18px rgba(0,0,0,0.08);
        }

        h1 {
            margin-top: 0;
        }

        form {
            display: grid;
            gap: 12px;
            margin-bottom: 20px;
        }

        .row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }

        input[type="text"] {
            flex: 1;
            min-width: 320px;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 16px;
        }

        button {
            padding: 12px 18px;
            border: none;
            border-radius: 8px;
            background: #0d6efd;
            color: white;
            font-size: 16px;
            cursor: pointer;
        }

        button:hover {
            background: #0b5ed7;
        }

        .checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .error {
            background: #fde2e2;
            color: #a30000;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
        }

        textarea {
            width: 100%;
            min-height: 500px;
            padding: 14px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-family: Consolas, monospace;
            font-size: 14px;
            resize: vertical;
            box-sizing: border-box;
        }

        .note {
            margin-top: 16px;
            font-size: 14px;
            color: #555;
            line-height: 1.5;
        }

        .label {
            font-weight: bold;
            margin-bottom: 8px;
            display: block;
        }

        code {
            background: #f1f3f5;
            padding: 2px 6px;
            border-radius: 4px;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>PHP Hardcoded String Scanner</h1>
    <p>Enter a local PHP file or folder path. The extracted strings will appear below as a PHP associative array.</p>

    <form method="post">
        <div class="row">
            <input
                type="text"
                name="scan_path"
                placeholder="Example: settings.php or pages/settings or C:\xampp\htdocs\myapp"
                value="<?php echo htmlspecialchars($pathInput, ENT_QUOTES, 'UTF-8'); ?>"
                required
            >
            <button type="submit">Scan</button>
        </div>

        <label class="checkbox">
            <input type="checkbox" name="recursive" <?php echo $recursive ? 'checked' : ''; ?>>
            Scan subfolders recursively
        </label>
    </form>

    <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($result): ?>
        <label class="label">Generated Associative Array:</label>
        <textarea readonly><?php echo htmlspecialchars($result, ENT_QUOTES, 'UTF-8'); ?></textarea>
    <?php endif; ?>

    <div class="note">
        <strong>Notes:</strong><br>
        - This scans <code>.php</code> files using PHP tokens, not remote URLs.<br>
        - It is restricted to this script’s folder and its subfolders for safety.<br>
        - It tries to skip obvious code fragments, SQL, URLs, and file names, but you may still want to review the results before using them for localization.
    </div>
</div>
</body>
</html>