<?php
declare(strict_types=1);

return [
    "modules" => [
        "Laminas\\Router",
        "Laminas\\Db",
        "Laminas\\InputFilter",
        "Laminas\\Session",
        "Laminas\\Mvc\\Plugin\\FlashMessenger",
        "Application",
        "User",
        "Classroom",
        "Assignment",
        "Attendance",
        "Report",
    ],
    "module_listener_options" => [
        "module_paths" => ["./module", "./vendor"],
        "config_glob_paths" => ["config/autoload/{,*.}{global,local}.php"],
        "config_cache_enabled" => false,
        "cache_dir" => "data/cache/",
    ],
];
