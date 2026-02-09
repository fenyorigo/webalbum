<?php

declare(strict_types=1);

namespace WebAlbum\Http\Controllers;

use WebAlbum\Db\SqliteIndex;
use WebAlbum\Query\Model;
use WebAlbum\Query\Runner;

final class SearchController
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

            $query = Model::validateSearch($data);

            $config = require $this->configPath;
            $db = new SqliteIndex($config["sqlite"]["path"]);

            $runner = new Runner($db);
            $result = $runner->run($query);

            if ($this->isDebug()) {
                $this->json([
                    "rows" => $result["rows"],
                    "total" => $result["total"],
                    "debug" => [
                        "sql" => $result["sql"],
                        "params" => $result["params"],
                    ],
                ]);
                return;
            }

            $this->json([
                "rows" => $result["rows"],
                "total" => $result["total"],
            ]);
        } catch (\JsonException $e) {
            $this->json(["error" => "Invalid JSON"], 400);
        } catch (\Throwable $e) {
            $this->json(["error" => $e->getMessage()], 400);
        }
    }

    private function isDebug(): bool
    {
        if (getenv("WEBALBUM_DEBUG_SQL") === "1") {
            return true;
        }
        $uri = $_SERVER["REQUEST_URI"] ?? "";
        $query = parse_url($uri, PHP_URL_QUERY) ?: "";
        parse_str($query, $params);
        return isset($params["debug"]) && $params["debug"] === "1";
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode($payload);
    }
}
