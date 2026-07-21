<?php
declare(strict_types=1);

return [
    "app" => [
        "timezone" => "Asia/Bangkok",
    ],
    "session_config" => [
        "cookie_samesite" => "Lax",
        "cookie_httponly" => true,
        "use_strict_mode" => true,
    ],
    "db" => [
        "driver" => "Pdo_Mysql",
        "charset" => "utf8mb4",
    ],
];
