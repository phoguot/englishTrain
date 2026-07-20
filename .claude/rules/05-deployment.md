# Rule — Cấu hình & triển khai

Quy mô nhỏ: 1 server PHP + 1 MySQL + 1 bucket R2. Không Docker, không CI/CD, không queue.
Deploy = pull code + chạy migration + kiểm tra tay.

## Cấu hình
- `config/autoload/local.php` chứa DB + credential R2. **Không commit** (đã có trong .gitignore).
- Thêm key cấu hình phụ thuộc môi trường/credential → phải cập nhật `config/autoload/local.php.dist`
  kèm giá trị mẫu và ghi chú ý nghĩa. Key cố định dùng chung mọi môi trường đặt ở `global.php` và cập nhật docs kiến trúc.
- Không đọc `getenv()` rải rác trong code. Cấu hình đi qua service container.
- Không hardcode `http://localhost:8080` — lấy base URL từ view helper `$this->url()`.

## Môi trường
- Dev: `composer serve` → http://localhost:8080, hiện lỗi đầy đủ.
- Prod: `config/autoload/development.local.php` **phải không tồn tại**;
  `display_errors = Off`, log vào file, user chỉ thấy trang lỗi chung.

## Checklist deploy
1. `git pull` trên server.
2. `composer install --no-dev --optimize-autoloader`.
3. Chạy migration mới trong `data/migrations/` theo thứ tự — **backup DB trước**.
4. Xóa `data/cache/*` (giữ `.gitkeep`).
5. Kiểm tay: đăng nhập 3 role, mở dashboard, xem 1 video đã upload.

## Backup
- MySQL dump hằng ngày. Video nằm trên R2 — không xóa object khi xóa submission,
  chỉ gỡ liên kết trong DB (mất bài của học sinh là mất thật, không khôi phục được).

## Cấm
- Không chạy `mysql` trực tiếp từ Claude (đã deny trong settings.json) — đưa SQL cho người thật chạy.
- Không `git push` tự động.
- Không sửa dữ liệu prod bằng script rời rạc; mọi thay đổi dữ liệu là một file migration có số.
