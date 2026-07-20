<?php

declare(strict_types=1);

use Report\Service\ReportHtmlSanitizer;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$sanitizer = new ReportHtmlSanitizer();
$cases = [
    '<p class="x" onclick="evil()">Xin <strong style="color:red">chào</strong></p>'
        => '<p>Xin <strong>chào</strong></p>',
    '<p>An toàn<script>alert(1)</script><img src=x onerror=evil></p>'
        => '<p>An toàn</p>',
    '<div><h3>Tiêu đề</h3><span>Nội dung</span></div>'
        => '<h3>Tiêu đề</h3>Nội dung',
];

foreach ($cases as $input => $expected) {
    $actual = $sanitizer->sanitize($input);
    if ($actual !== $expected) {
        throw new RuntimeException("Lọc HTML sai.\nExpected: {$expected}\nActual: {$actual}");
    }
}

if ($sanitizer->sanitize(['content']) !== '') {
    throw new RuntimeException('Dữ liệu content sai kiểu phải bị loại.');
}

echo "ReportHtmlSanitizer: OK\n";
