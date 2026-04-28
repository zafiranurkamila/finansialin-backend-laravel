<?php

$basePath = dirname(__DIR__);
$envPath = $basePath . DIRECTORY_SEPARATOR . '.env';
$examplePath = $basePath . DIRECTORY_SEPARATOR . '.env.example';

if (!file_exists($envPath)) {
    if (file_exists($examplePath)) {
        copy($examplePath, $envPath);
        echo "[env] Created .env from .env.example\n";
    }

    exit(0);
}

$lines = file($envPath, FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    fwrite(STDERR, "[env] Unable to read .env\n");
    exit(1);
}

$changed = false;

foreach ($lines as &$line) {
    if (!str_starts_with($line, 'MAIL_PASSWORD=')) {
        continue;
    }

    $value = substr($line, strlen('MAIL_PASSWORD='));
    $trimmed = trim($value);

    if ($trimmed === '') {
        continue;
    }

    $isQuoted = preg_match('/^["\'].*["\']$/', $trimmed) === 1;
    $hasWhitespace = preg_match('/\s/', $trimmed) === 1;

    if ($isQuoted || !$hasWhitespace) {
        continue;
    }

    $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $trimmed);
    $line = 'MAIL_PASSWORD="' . $escaped . '"';
    $changed = true;
}
unset($line);

if ($changed) {
    file_put_contents($envPath, implode(PHP_EOL, $lines) . PHP_EOL);
    echo "[env] Normalized MAIL_PASSWORD in .env\n";
}
