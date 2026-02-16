<?php

declare(strict_types=1);

namespace WebAlbum\Http\Controllers;

use WebAlbum\Assets\AssetPaths;
use WebAlbum\Db\Maria;
use WebAlbum\Db\SqliteIndex;
use WebAlbum\UserContext;
use WebAlbum\Security\PathGuard;
use ZipArchive;

final class DownloadController
{
    private string $configPath;

    public function __construct(string $configPath)
    {
        $this->configPath = $configPath;
    }

    public function handle(): void
    {
        try {
            $body = file_get_contents("php://input");
            $data = json_decode($body ?: "", true, 512, JSON_THROW_ON_ERROR);

            $ids = $data["ids"] ?? null;
            if (!is_array($ids)) {
                throw new \InvalidArgumentException("ids must be an array");
            }
            if (count($ids) === 0) {
                $this->json(["error" => "Please select files first (max 20)"], 400);
                return;
            }
            if (count($ids) > 20) {
                $this->json(["error" => "More than 20 files selected, please unselect some"], 400);
                return;
            }

            $idList = [];
            foreach ($ids as $id) {
                if (!is_int($id) && !ctype_digit((string)$id)) {
                    throw new \InvalidArgumentException("All ids must be integers");
                }
                $idList[] = (int)$id;
            }
            $idList = array_values(array_unique($idList));

            $config = require $this->configPath;
            $maria = new Maria(
                $config["mariadb"]["dsn"],
                $config["mariadb"]["user"],
                $config["mariadb"]["pass"]
            );
            $user = UserContext::currentUser($maria);
            if ($user === null) {
                $this->json(["error" => "Not authenticated"], 401);
                return;
            }
            $photosRoot = (string)($config["photos"]["root"] ?? "");
            $db = new SqliteIndex($config["sqlite"]["path"]);

            $mediaIds = [];
            $assetIds = [];
            foreach ($idList as $id) {
                if ($id > 0) {
                    $mediaIds[] = $id;
                } elseif ($id < 0) {
                    $assetIds[] = abs($id);
                }
            }

            $files = [];

            if ($mediaIds !== []) {
                $placeholders = implode(",", array_fill(0, count($mediaIds), "?"));
                $rows = $db->query(
                    "SELECT id, path, rel_path, type FROM files WHERE id IN (" . $placeholders . ")",
                    $mediaIds
                );
                if (count($rows) !== count($mediaIds)) {
                    $this->json(["error" => "Some selected media files were not found"], 400);
                    return;
                }

                $relPaths = [];
                foreach ($rows as $row) {
                    $rel = trim((string)($row["rel_path"] ?? ""));
                    if ($rel !== "") {
                        $relPaths[$rel] = true;
                    }
                }
                if ($relPaths !== []) {
                    $trashRows = $maria->query(
                        "SELECT rel_path FROM wa_media_trash WHERE status = 'trashed' AND rel_path IN (" .
                        implode(",", array_fill(0, count($relPaths), "?")) . ")",
                        array_keys($relPaths)
                    );
                    if ($trashRows !== []) {
                        $this->json(["error" => "Trashed media cannot be downloaded"], 400);
                        return;
                    }
                }

                foreach ($rows as $row) {
                    $type = (string)($row["type"] ?? "");
                    if ($type !== "image" && $type !== "video") {
                        $this->json(["error" => "Only image/video media files are supported"], 400);
                        return;
                    }
                    $path = $row["path"] ?? "";
                    if (!is_string($path) || !is_file($path)) {
                        $this->json(["error" => "File not found"], 400);
                        return;
                    }

                    try {
                        $path = PathGuard::assertInsideRoot($path, $photosRoot);
                    } catch (\Throwable $e) {
                        $this->json(["error" => "File outside configured photos root"], 400);
                        return;
                    }

                    $files[] = [
                        "id" => (int)$row["id"],
                        "path" => $path,
                        "type" => $type,
                    ];
                }
            }

            if ($assetIds !== []) {
                $placeholders = implode(",", array_fill(0, count($assetIds), "?"));
                $rows = $maria->query(
                    "SELECT id, rel_path, type FROM wa_assets WHERE id IN (" . $placeholders . ")",
                    $assetIds
                );
                if (count($rows) !== count($assetIds)) {
                    $this->json(["error" => "Some selected assets were not found"], 400);
                    return;
                }

                foreach ($rows as $row) {
                    $type = (string)($row["type"] ?? "");
                    if ($type !== "audio" && $type !== "doc") {
                        $this->json(["error" => "Only audio/document assets are supported"], 400);
                        return;
                    }
                    $relPath = (string)($row["rel_path"] ?? "");
                    $path = AssetPaths::joinInside($photosRoot, $relPath);
                    if ($path === null || !is_file($path)) {
                        $this->json(["error" => "Asset file not found"], 400);
                        return;
                    }
                    try {
                        $path = PathGuard::assertInsideRoot($path, $photosRoot);
                    } catch (\Throwable $e) {
                        $this->json(["error" => "Asset outside configured photos root"], 400);
                        return;
                    }

                    $files[] = [
                        "id" => -((int)$row["id"]),
                        "path" => $path,
                        "type" => $type,
                    ];
                }
            }

            if ($files === []) {
                $this->json(["error" => "No files selected"], 400);
                return;
            }

            $tmp = tempnam(sys_get_temp_dir(), "webalbum_zip_");
            if ($tmp === false) {
                throw new \RuntimeException("Unable to create temp file");
            }

            $zip = new ZipArchive();
            if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException("Unable to create zip");
            }

            $usedNames = [];
            foreach ($files as $file) {
                $base = basename($file["path"]);
                $name = $file["id"] . "_" . $base;
                if (isset($usedNames[$name])) {
                    $name = $file["id"] . "_" . uniqid("", false) . "_" . $base;
                }
                $usedNames[$name] = true;
                $zip->addFile($file["path"], $name);
            }
            $zip->close();

            $filename = "webalbum-selected-" . date("Ymd-His") . ".zip";
            header("Content-Type: application/zip");
            header("Content-Disposition: attachment; filename=\"" . $filename . "\"");
            header("Content-Length: " . (string)filesize($tmp));

            register_shutdown_function(function () use ($tmp): void {
                if (is_file($tmp)) {
                    unlink($tmp);
                }
            });

            readfile($tmp);
        } catch (\JsonException $e) {
            $this->json(["error" => "Invalid JSON"], 400);
        } catch (\Throwable $e) {
            $this->json(["error" => $e->getMessage()], 400);
        }
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode($payload);
    }
}
