<?php

declare(strict_types=1);

/**
 * Hook PostToolUse: chạy sau mỗi lần Claude Edit/Write một file.
 * Chỉ kiểm file .php. Exit code 2 = chặn và báo lỗi ngược lại cho Claude.
 *
 * Kiểm 3 thứ tương ứng quy ước trong CLAUDE.md:
 *   1. Cú pháp PHP (php -l)
 *   2. Có declare(strict_types=1)
 *   3. Không đụng $_POST/$_GET thô (phải validate qua Filter class — laminas-inputfilter)
 */

$payload = json_decode((string) stream_get_contents(STDIN), true);
$path    = $payload['tool_input']['file_path'] ?? '';

if (!is_string($path) || $path === '' || !is_file($path)) {
    exit(0);
}
if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php') {
    exit(0);
}
// Không tự kiểm chính mình: file hook bắt buộc chứa chuỗi "$_POST" trong regex/comment.
if (str_contains(str_replace('\\', '/', $path), '/.claude/hooks/')) {
    exit(0);
}

$loi = [];

exec(sprintf('php -l %s 2>&1', escapeshellarg($path)), $output, $code);
if ($code !== 0) {
    $loi[] = 'Lỗi cú pháp: ' . implode(' ', $output);
}

$src = (string) file_get_contents($path);

if (!preg_match('/^\s*declare\(\s*strict_types\s*=\s*1\s*\);/m', $src)) {
    $loi[] = 'Thiếu declare(strict_types=1); — bắt buộc với mọi file PHP (CLAUDE.md).';
}

if (preg_match('/\$_(POST|GET|REQUEST)\b/', $src, $m)) {
    $loi[] = sprintf(
        'Dùng $_%s thô. Dữ liệu vào phải validate qua Filter class (src/Filter/) — xem .claude/rules/01-bao-mat.md.',
        $m[1]
    );
}

if ($loi !== []) {
    fwrite(STDERR, sprintf(
        "[EnglishTrain] %s\n- %s\n",
        $path,
        implode("\n- ", $loi)
    ));
    exit(2);
}

exit(0);
