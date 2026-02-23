#!/usr/bin/env php
<?php

declare(strict_types=1);

// Dev-only regression check for rotate persistence + thumb refresh.
// Usage:
// php backend/bin/rotate_regression_check.php --id=123 --base-url=https://localhost:8445 --cookie-file=/tmp/wa.cookies [--turns=1]

$options = getopt('', ['id:', 'base-url:', 'cookie-file:', 'turns::']);
$id = isset($options['id']) ? (int)$options['id'] : 0;
$baseUrl = rtrim((string)($options['base-url'] ?? ''), '/');
$cookieFile = (string)($options['cookie-file'] ?? '');
$turns = isset($options['turns']) ? (int)$options['turns'] : 1;
if ($id < 1 || $baseUrl === '' || $cookieFile === '') {
    fwrite(STDERR, "Usage: php backend/bin/rotate_regression_check.php --id=123 --base-url=https://localhost:8445 --cookie-file=/tmp/wa.cookies [--turns=1]\n");
    exit(2);
}

$config = require __DIR__ . '/../config/config.php';
$sqlitePath = (string)($config['sqlite']['path'] ?? '');
$thumbRoot = (string)($config['thumbs']['root'] ?? '');
if ($sqlitePath === '' || !is_file($sqlitePath)) {
    fwrite(STDERR, "SQLite path invalid: {$sqlitePath}\n");
    exit(2);
}

$pdo = new PDO('sqlite:' . $sqlitePath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$rowStmt = $pdo->prepare('SELECT id, path, rel_path, type FROM files WHERE id = ?');
$rowStmt->execute([$id]);
$row = $rowStmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    fwrite(STDERR, "Media id {$id} not found\n");
    exit(2);
}
if (($row['type'] ?? '') !== 'image') {
    fwrite(STDERR, "Media id {$id} is type={$row['type']} (image required)\n");
    exit(2);
}

$path = (string)$row['path'];
$relPath = (string)$row['rel_path'];
if (!is_file($path)) {
    fwrite(STDERR, "Original file missing: {$path}\n");
    exit(2);
}

$thumbPath = '';
if ($thumbRoot !== '' && $relPath !== '') {
    $joined = rtrim($thumbRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $relPath);
    $info = pathinfo($joined);
    if (isset($info['dirname'], $info['filename'])) {
        $thumbPath = (string)$info['dirname'] . DIRECTORY_SEPARATOR . (string)$info['filename'] . '.jpg';
    }
}

function runCurl(string $cmd): array {
    $out = [];
    $code = 0;
    exec($cmd, $out, $code);
    return [implode("\n", $out), $code];
}

function shaOrEmpty(string $path): string {
    return is_file($path) ? (string)sha1_file($path) : '';
}

$origHashBefore = shaOrEmpty($path);
$origMtimeBefore = (int)@filemtime($path);
$thumbHashBefore = $thumbPath !== '' ? shaOrEmpty($thumbPath) : '';
$thumbMtimeBefore = $thumbPath !== '' ? (int)@filemtime($thumbPath) : 0;

$tmpBefore = tempnam(sys_get_temp_dir(), 'wa-thumb-before-');
$thumbUrl = $baseUrl . '/api/thumb?id=' . $id;
[$_o1, $curl1] = runCurl('curl -k -s -o ' . escapeshellarg($tmpBefore) . ' -b ' . escapeshellarg($cookieFile) . ' ' . escapeshellarg($thumbUrl));
if ($curl1 !== 0) {
    fwrite(STDERR, "Failed to fetch thumb before rotate\n");
    exit(1);
}
$thumbRespHashBefore = shaOrEmpty($tmpBefore);

$rotatePayload = json_encode(['quarter_turns' => $turns], JSON_UNESCAPED_SLASHES);
$rotateCmd = 'curl -k -s -w "\\n%{http_code}" -X POST ' . escapeshellarg($baseUrl . '/api/media/' . $id . '/rotate')
    . ' -H ' . escapeshellarg('Content-Type: application/json')
    . ' -b ' . escapeshellarg($cookieFile)
    . ' --data ' . escapeshellarg((string)$rotatePayload);
[$rotateOut, $rotateCode] = runCurl($rotateCmd);
if ($rotateCode !== 0) {
    fwrite(STDERR, "Rotate request failed\n");
    exit(1);
}
$parts = explode("\n", trim($rotateOut));
$httpCode = (int)array_pop($parts);
$body = implode("\n", $parts);
if ($httpCode < 200 || $httpCode >= 300) {
    fwrite(STDERR, "Rotate HTTP {$httpCode}: {$body}\n");
    exit(1);
}

clearstatcache(true, $path);
if ($thumbPath !== '') {
    clearstatcache(true, $thumbPath);
}

$tmpAfter = tempnam(sys_get_temp_dir(), 'wa-thumb-after-');
$thumbUrlAfter = $thumbUrl . '&v=' . time();
[$_o2, $curl2] = runCurl('curl -k -s -o ' . escapeshellarg($tmpAfter) . ' -b ' . escapeshellarg($cookieFile) . ' ' . escapeshellarg($thumbUrlAfter));
if ($curl2 !== 0) {
    fwrite(STDERR, "Failed to fetch thumb after rotate\n");
    exit(1);
}

$origHashAfter = shaOrEmpty($path);
$origMtimeAfter = (int)@filemtime($path);
$thumbHashAfter = $thumbPath !== '' ? shaOrEmpty($thumbPath) : '';
$thumbMtimeAfter = $thumbPath !== '' ? (int)@filemtime($thumbPath) : 0;
$thumbRespHashAfter = shaOrEmpty($tmpAfter);

$result = [
    'id' => $id,
    'path' => $path,
    'thumb_path' => $thumbPath,
    'rotate_http' => $httpCode,
    'original_changed' => $origHashBefore !== '' && $origHashAfter !== '' && $origHashBefore !== $origHashAfter,
    'original_mtime_changed' => $origMtimeAfter > $origMtimeBefore,
    'thumb_changed_on_disk' => $thumbHashBefore !== '' && $thumbHashAfter !== '' && $thumbHashBefore !== $thumbHashAfter,
    'thumb_mtime_changed' => $thumbMtimeAfter > $thumbMtimeBefore,
    'thumb_response_changed' => $thumbRespHashBefore !== '' && $thumbRespHashAfter !== '' && $thumbRespHashBefore !== $thumbRespHashAfter,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

@unlink($tmpBefore);
@unlink($tmpAfter);
