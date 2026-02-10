<?php

declare(strict_types=1);

namespace WebAlbum\Http\Controllers;

use WebAlbum\Db\Maria;
use WebAlbum\Db\SqliteIndex;
use WebAlbum\UserContext;
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
                $this->json(["error" => "Please select images first (max 20)"], 400);
                return;
            }
            if (count($ids) > 20) {
                $this->json(["error" => "More than 20 images selected, please unselect some"], 400);
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
            $db = new SqliteIndex($config["sqlite"]["path"]);

            $placeholders = implode(",", array_fill(0, count($idList), "?"));
            $rows = $db->query(
                "SELECT id, path, type FROM files WHERE id IN (" . $placeholders . ")",
                $idList
            );
            if (count($rows) !== count($idList)) {
                $this->json(["error" => "Some files were not found"], 400);
                return;
            }

            $files = [];
            foreach ($rows as $row) {
                if (($row["type"] ?? "") !== "image") {
                    $this->json(["error" => "Only images are supported"], 400);
                    return;
                }
                $path = $row["path"] ?? "";
                if (!is_string($path) || !is_file($path)) {
                    $this->json(["error" => "File not found"], 400);
                    return;
                }
                $files[] = [
                    "id" => (int)$row["id"],
                    "path" => $path,
                ];
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
