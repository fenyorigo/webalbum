<?php

declare(strict_types=1);

namespace WebAlbum\Http\Controllers;

use WebAlbum\Db\Maria;
use WebAlbum\UserContext;

final class SavedSearchesController
{
    private string $configPath;

    public function __construct(string $configPath)
    {
        $this->configPath = $configPath;
    }

    public function list(): void
    {
        $db = $this->connect();
        $user = UserContext::currentUser($db);
        if ($user === null) {
            $this->json(["error" => "Not authenticated"], 401);
            return;
        }
        $rows = $db->query(
            "SELECT id, name, created_at, updated_at FROM wa_saved_searches\n" .
            "WHERE user_id = ? ORDER BY name ASC",
            [$user["id"]]
        );
        $this->json($rows);
    }

    public function get(int $id): void
    {
        $db = $this->connect();
        $user = UserContext::currentUser($db);
        if ($user === null) {
            $this->json(["error" => "Not authenticated"], 401);
            return;
        }
        $rows = $db->query(
            "SELECT id, name, query_json, created_at, updated_at FROM wa_saved_searches WHERE id = ? AND user_id = ?",
            [$id, $user["id"]]
        );
        if ($rows === []) {
            $this->json(["error" => "Not Found"], 404);
            return;
        }
        $row = $rows[0];
        $decoded = json_decode((string)$row["query_json"], true);
        $row["query_json"] = $decoded;
        $row["where"] = is_array($decoded) && isset($decoded["where"]) ? $decoded["where"] : null;
        $this->json($row);
    }

    public function create(): void
    {
        try {
            $db = $this->connect();
            $user = UserContext::currentUser($db);
            if ($user === null) {
                $this->json(["error" => "Not authenticated"], 401);
                return;
            }
            $body = file_get_contents("php://input");
            $data = json_decode($body ?: "", true, 512, JSON_THROW_ON_ERROR);
            $name = isset($data["name"]) && is_string($data["name"]) ? trim($data["name"]) : "";
            if ($name === "" || strlen($name) > 255) {
                $this->json(["error" => "Invalid name"], 400);
                return;
            }
            if (!isset($data["query"]) || !is_array($data["query"])) {
                $this->json(["error" => "query must be an object"], 400);
                return;
            }
            $query = $data["query"];
            if (!$this->hasWhere($query)) {
                $this->json(["error" => "query.where is required"], 400);
                return;
            }
            $replace = !empty($data["replace"]);
            $existing = $db->query(
                "SELECT id, name FROM wa_saved_searches WHERE user_id = ? AND name = ?",
                [$user["id"], $name]
            );
            $json = json_encode($query, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($existing !== []) {
                if (!$replace) {
                    $this->json([
                        "error" => "exists",
                        "message" => "Saved search already exists",
                        "existing" => $existing[0],
                    ], 409);
                    return;
                }
                $db->exec(
                    "UPDATE wa_saved_searches SET query_json = ? WHERE id = ? AND user_id = ?",
                    [$json, $existing[0]["id"], $user["id"]]
                );
                $this->json(["id" => (int)$existing[0]["id"], "name" => $name]);
                return;
            }
            $db->exec(
                "INSERT INTO wa_saved_searches (user_id, name, query_json) VALUES (?, ?, ?)",
                [$user["id"], $name, $json]
            );
            $idRow = $db->query("SELECT LAST_INSERT_ID() AS id");
            $this->json(["id" => (int)$idRow[0]["id"], "name" => $name]);
        } catch (\JsonException $e) {
            $this->json(["error" => "Invalid JSON"], 400);
        } catch (\Throwable $e) {
            $this->json(["error" => $e->getMessage()], 500);
        }
    }

    public function update(int $id): void
    {
        try {
            $db = $this->connect();
            $user = UserContext::currentUser($db);
            if ($user === null) {
                $this->json(["error" => "Not authenticated"], 401);
                return;
            }
            $body = file_get_contents("php://input");
            $data = json_decode($body ?: "", true, 512, JSON_THROW_ON_ERROR);
            $fields = [];
            $params = [];

            if (isset($data["name"])) {
                if (!is_string($data["name"])) {
                    $this->json(["error" => "Invalid name"], 400);
                    return;
                }
                $name = trim($data["name"]);
                if ($name === "" || strlen($name) > 255) {
                    $this->json(["error" => "Invalid name"], 400);
                    return;
                }
                $exists = $db->query(
                    "SELECT id FROM wa_saved_searches WHERE user_id = ? AND name = ? AND id <> ?",
                    [$user["id"], $name, $id]
                );
                if ($exists !== []) {
                    $this->json(["error" => "exists", "message" => "Saved search already exists"], 409);
                    return;
                }
                $fields[] = "name = ?";
                $params[] = $name;
            }

            if (isset($data["query"])) {
                if (!is_array($data["query"]) || !$this->hasWhere($data["query"])) {
                    $this->json(["error" => "Invalid query"], 400);
                    return;
                }
                $fields[] = "query_json = ?";
                $params[] = json_encode($data["query"], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            if ($fields === []) {
                $this->json(["error" => "No updates"], 400);
                return;
            }

            $params[] = $id;
            $params[] = $user["id"];
            $db->exec(
                "UPDATE wa_saved_searches SET " . implode(", ", $fields) . " WHERE id = ? AND user_id = ?",
                $params
            );
            $this->json(["ok" => true]);
        } catch (\JsonException $e) {
            $this->json(["error" => "Invalid JSON"], 400);
        } catch (\Throwable $e) {
            $this->json(["error" => $e->getMessage()], 500);
        }
    }

    public function delete(int $id): void
    {
        $db = $this->connect();
        $user = UserContext::currentUser($db);
        if ($user === null) {
            $this->json(["error" => "Not authenticated"], 401);
            return;
        }
        $count = $db->exec(
            "DELETE FROM wa_saved_searches WHERE id = ? AND user_id = ?",
            [$id, $user["id"]]
        );
        if ($count === 0) {
            $this->json(["error" => "Not Found"], 404);
            return;
        }
        $this->json(["ok" => true]);
    }

    private function hasWhere(array $query): bool
    {
        return isset($query["where"]) && is_array($query["where"]);
    }

    private function connect(): Maria
    {
        $config = require $this->configPath;
        return new Maria(
            $config["mariadb"]["dsn"],
            $config["mariadb"]["user"],
            $config["mariadb"]["pass"]
        );
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode($payload);
    }
}
