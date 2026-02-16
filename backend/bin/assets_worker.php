<?php

declare(strict_types=1);

use WebAlbum\Assets\AssetPaths;
use WebAlbum\Assets\AssetSupport;
use WebAlbum\Assets\Jobs;
use WebAlbum\Db\Maria;
use WebAlbum\SystemTools;

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    spl_autoload_register(function (string $class) use ($root): void {
        if (!str_starts_with($class, 'WebAlbum\\')) {
            return;
        }
        $path = $root . '/src/' . str_replace('\\', '/', substr($class, 9)) . '.php';
        if (is_file($path)) {
            require $path;
        }
    });
}

$config = require $root . '/config/config.php';
$db = new Maria($config['mariadb']['dsn'], $config['mariadb']['user'], $config['mariadb']['pass']);

$once = in_array('--once', $argv, true);
$maxJobs = 0; // 0 = no limit (run until queue is empty)
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--max-jobs=')) {
        $maxJobs = max(0, (int)substr($arg, strlen('--max-jobs=')));
    }
}

$workerId = gethostname() . ':' . getmypid();
Jobs::recoverStaleLocks($db, 15);

$processed = 0;
while (true) {
    $job = Jobs::claimNext($db, $workerId);
    if ($job === null) {
        // Batch mode: stop when queue is currently empty.
        if ($once || $maxJobs > 0) {
            break;
        }
        usleep(300000);
        continue;
    }

    $processed++;
    $jobId = (int)$job['id'];
    $attempts = (int)$job['attempts'];

    try {
        processJob($db, $config, $job);
        Jobs::markDone($db, $jobId);
        echo "done job #{$jobId} ({$job['job_type']})\n";
    } catch (Throwable $e) {
        Jobs::markError($db, $jobId, $e->getMessage(), $attempts);
        echo "error job #{$jobId}: {$e->getMessage()}\n";
    }

    if ($once || ($maxJobs > 0 && $processed >= $maxJobs)) {
        break;
    }
}

function processJob(Maria $db, array $config, array $job): void
{
    $type = (string)$job['job_type'];
    $payload = is_array($job['payload']) ? $job['payload'] : [];
    $assetId = (int)($payload['asset_id'] ?? 0);
    if ($assetId < 1) {
        throw new RuntimeException('Missing asset_id in payload');
    }

    $rows = $db->query('SELECT id, rel_path, ext, type FROM wa_assets WHERE id = ?', [$assetId]);
    if ($rows === []) {
        throw new RuntimeException('Asset not found');
    }
    $asset = $rows[0];
    $relPath = (string)$asset['rel_path'];
    $photosRoot = (string)($config['photos']['root'] ?? '');
    $thumbRoot = (string)($config['thumbs']['root'] ?? '');
    $sourcePath = AssetPaths::joinInside($photosRoot, $relPath);
    if ($sourcePath === null || !is_file($sourcePath) || !is_readable($sourcePath)) {
        throw new RuntimeException('Source file is missing');
    }

    if (!is_dir($thumbRoot)) {
        @mkdir($thumbRoot, 0755, true);
    }

    if ($type === 'doc_pdf_preview') {
        buildPdfPreview($db, $config, $assetId, $asset, $sourcePath, $thumbRoot);
        return;
    }

    if ($type === 'doc_thumb') {
        buildDocThumb($db, $config, $assetId, $asset, $sourcePath, $thumbRoot);
        return;
    }

    throw new RuntimeException('Unsupported job_type: ' . $type);
}

function buildPdfPreview(Maria $db, array $config, int $assetId, array $asset, string $sourcePath, string $thumbRoot): void
{
    $ext = strtolower((string)$asset['ext']);
    if (!AssetSupport::isConvertibleToPdf($ext)) {
        throw new RuntimeException('pdf_preview job supports convertible documents only');
    }

    $tools = SystemTools::checkExternalTools($config);
    $soffice = $tools['tools']['soffice'] ?? null;
    if (!is_array($soffice) || !($soffice['available'] ?? false) || empty($soffice['path'])) {
        throw new RuntimeException('soffice not available');
    }

    $target = AssetPaths::derivativePath($thumbRoot, (string)$asset['rel_path'], '.wa-preview.pdf');
    if ($target === null) {
        throw new RuntimeException('Invalid derivative path');
    }
    ensureDir(dirname($target));

    $tmpDir = sys_get_temp_dir() . '/wa-soffice-' . bin2hex(random_bytes(6));
    ensureDir($tmpDir);

    $cmd = escapeshellarg((string)$soffice['path'])
        . ' --headless --nologo --nofirststartwizard --convert-to pdf --outdir '
        . escapeshellarg($tmpDir) . ' ' . escapeshellarg($sourcePath) . ' 2>&1';
    $output = [];
    $code = 0;
    exec($cmd, $output, $code);
    if ($code !== 0) {
        throw new RuntimeException('soffice conversion failed: ' . trim(implode("\n", $output)));
    }

    $converted = findFirstPdf($tmpDir);
    if ($converted === null || !is_file($converted)) {
        throw new RuntimeException('soffice did not produce PDF');
    }

    $tmpTarget = $target . '.tmp.' . getmypid();
    if (!@copy($converted, $tmpTarget)) {
        throw new RuntimeException('Failed to copy converted preview');
    }
    if (!is_file($tmpTarget) || (int)@filesize($tmpTarget) <= 0 || !is_readable($tmpTarget)) {
        @unlink($tmpTarget);
        throw new RuntimeException('Converted preview is invalid');
    }
    if (!@rename($tmpTarget, $target)) {
        @unlink($tmpTarget);
        throw new RuntimeException('Failed to publish preview');
    }

    $db->exec(
        "INSERT INTO wa_asset_derivatives (asset_id, kind, path, status, error_text, updated_at)\n" .
        "VALUES (?, 'pdf_preview', ?, 'ready', NULL, NOW())\n" .
        "ON DUPLICATE KEY UPDATE path = VALUES(path), status = 'ready', error_text = NULL, updated_at = NOW()",
        [$assetId, $target]
    );
}

function buildDocThumb(Maria $db, array $config, int $assetId, array $asset, string $sourcePath, string $thumbRoot): void
{
    $ext = strtolower((string)$asset['ext']);
    $sourcePdf = null;
    if ($ext === 'pdf') {
        $sourcePdf = $sourcePath;
    } elseif (AssetSupport::isConvertibleToPdf($ext)) {
        $previewRows = $db->query(
            "SELECT path, status FROM wa_asset_derivatives WHERE asset_id = ? AND kind = 'pdf_preview' LIMIT 1",
            [$assetId]
        );
        if ($previewRows === [] || (string)$previewRows[0]['status'] !== 'ready' || !is_file((string)$previewRows[0]['path'])) {
            Jobs::enqueue($db, 'doc_pdf_preview', ['asset_id' => $assetId]);
            throw new RuntimeException('PDF preview not ready yet');
        }
        $sourcePdf = (string)$previewRows[0]['path'];
    } else {
        throw new RuntimeException('thumb job supports doc assets only');
    }

    $target = AssetPaths::derivativePath($thumbRoot, (string)$asset['rel_path'], '.wa-thumb.jpg');
    if ($target === null) {
        throw new RuntimeException('Invalid thumb path');
    }
    ensureDir(dirname($target));

    $tmpTarget = $target . '.tmp.' . getmypid();
    @unlink($tmpTarget);

    $tools = SystemTools::checkExternalTools($config);
    $gs = $tools['tools']['gs'] ?? null;
    if (!is_array($gs) || !($gs['available'] ?? false) || empty($gs['path'])) {
        throw new RuntimeException('ghostscript (gs) not available for document thumbnail rendering');
    }

    if (!renderPdfThumb($config, $sourcePdf, $tmpTarget)) {
        throw new RuntimeException('Failed to render document thumbnail');
    }
    if (!is_file($tmpTarget) || (int)@filesize($tmpTarget) <= 0 || !is_readable($tmpTarget)) {
        @unlink($tmpTarget);
        throw new RuntimeException('Generated thumb is invalid');
    }
    if (!@rename($tmpTarget, $target)) {
        @unlink($tmpTarget);
        throw new RuntimeException('Failed to publish thumbnail');
    }

    $db->exec(
        "INSERT INTO wa_asset_derivatives (asset_id, kind, path, status, error_text, updated_at)\n" .
        "VALUES (?, 'thumb', ?, 'ready', NULL, NOW())\n" .
        "ON DUPLICATE KEY UPDATE path = VALUES(path), status = 'ready', error_text = NULL, updated_at = NOW()",
        [$assetId, $target]
    );
}

function renderPdfThumb(array $config, string $pdfPath, string $destJpeg): bool
{
    $max = (int)($config['thumbs']['max'] ?? 256);
    $quality = (int)($config['thumbs']['quality'] ?? 75);

    if (class_exists(Imagick::class)) {
        try {
            $im = new Imagick();
            $im->setResolution(150, 150);
            $im->readImage($pdfPath . '[0]');
            $im->setImageBackgroundColor('white');
            $im = $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            $im->setImageFormat('jpeg');
            $im->thumbnailImage($max, $max, true, true);
            $im->setImageCompressionQuality($quality);
            $im->stripImage();
            $ok = $im->writeImage($destJpeg);
            $im->clear();
            $im->destroy();
            if ($ok) {
                return true;
            }
        } catch (Throwable $e) {
            // fallback to ffmpeg
        }
    }

    $tools = SystemTools::checkExternalTools($config);
    $ffmpeg = $tools['tools']['ffmpeg'] ?? null;
    if (!is_array($ffmpeg) || !($ffmpeg['available'] ?? false) || empty($ffmpeg['path'])) {
        return false;
    }

    $cmd = escapeshellarg((string)$ffmpeg['path'])
        . ' -v error -y -i ' . escapeshellarg($pdfPath)
        . ' -frames:v 1 -vf ' . escapeshellarg('scale=' . $max . ':-1')
        . ' -q:v 3 ' . escapeshellarg($destJpeg) . ' 2>&1';
    $out = [];
    $code = 0;
    exec($cmd, $out, $code);
    return $code === 0 && is_file($destJpeg) && (int)@filesize($destJpeg) > 0;
}

function ensureDir(string $dir): void
{
    if (is_dir($dir)) {
        return;
    }
    if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Failed to create directory: ' . $dir);
    }
}

function findFirstPdf(string $dir): ?string
{
    $files = glob(rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.pdf');
    if (!is_array($files) || $files === []) {
        return null;
    }
    sort($files);
    return $files[0] ?? null;
}
