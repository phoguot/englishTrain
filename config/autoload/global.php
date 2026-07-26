<?php
declare(strict_types=1);

return [
    "app" => [
        "timezone" => "Asia/Bangkok",
    ],
    "auth" => [
        // Cookie "ghi nhớ đăng nhập" sống bao lâu, tách khỏi session (session vẫn hết hạn theo
        // session_config.gc_maxlifetime). 180 ngày để học sinh/phụ huynh lớn tuổi trên máy cá nhân
        // không phải đăng nhập lại nhiều lần — xem module/User/CLAUDE.md.
        "remember_me_days" => 180,
    ],
    "session_config" => [
        "cookie_samesite" => "Lax",
        "cookie_httponly" => true,
        "use_strict_mode" => true,
        // Mặc định PHP (~24 phút) quá ngắn, đá session giữa chừng khi user ngồi đọc lâu.
        // Cookie "ghi nhớ đăng nhập" (auth.remember_me_days) lo phần đăng nhập lại sau khi
        // đóng trình duyệt hoặc hết hạn này — hai cơ chế bổ sung nhau, xem module/User/CLAUDE.md.
        "gc_maxlifetime" => 28800, // 8 giờ
    ],
    "db" => [
        "driver" => "Pdo_Mysql",
        "charset" => "utf8mb4",
    ],
];
