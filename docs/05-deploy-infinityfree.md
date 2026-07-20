# 05 — Deploy lên InfinityFree

Hướng dẫn deploy EnglishTrain lên InfinityFree (free hosting: FTP + VistaPanel, **không SSH,
không composer trên server**).

> **Đánh số**: file này là `05` chứ không phải `04` — `docs/04-contracts.md` đã chiếm số 04.

## Tóm tắt tính khả thi (đã đo, không phải phỏng đoán)

| Vấn đề | Kết luận |
|---|---|
| PHP | InfinityFree free chạy **PHP 8.3**; dự án cần `>=8.2` → **được** |
| PHP DOM | Module Report dùng `DOMDocument` để lọc HTML; kiểm `ext-dom`/PHP XML có bật trước khi upload |
| Giới hạn inode | Hạn mức **30.000 file/tài khoản**. `vendor/` đo thật = **5.408 file / 7.473 inode / 70MB** → **lọt**, còn dư nhiều |
| Cắt bớt vendor | Bật `removeUnusedServices` + chỉ giữ S3 → **2.390 file / 20MB** (giảm 56%). Đo thật, S3 vẫn chạy. Xem §1 |
| `open_basedir` | PHP **không đọc được gì phía trên `htdocs/`** → `vendor/`, `config/`, `module/` **bắt buộc** nằm trong `htdocs/` → sinh rủi ro bảo mật, xem §4 |
| Đổi document root | **Không đổi được** sang `public/` → phải rewrite bằng `.htaccess`, xem §4 |
| Upload video R2 | **Chưa triển khai trong code hiện tại**. Hạ tầng có thể dùng presign offline sau khi bổ sung code và cấu hình CORS, xem §6 |
| Gọi API ra ngoài từ PHP | Free hosting **chặn/không ổn định**. Dự án không cần → không sao. Đừng thêm tính năng gọi API ngoài |

---

## §1. Chuẩn bị vendor ở máy local

Server không có composer → phải build `vendor/` ở máy rồi upload.

**Trước tiên, cắt bớt AWS SDK.** Mặc định `aws/aws-sdk-php` kéo theo định nghĩa của ~420 dịch vụ
AWS, dự án chỉ dùng S3 (cho R2). Thêm vào `composer.json`:

```json
"scripts": {
    "pre-autoload-dump": "Aws\\Script\\Composer\\Composer::removeUnusedServices"
},
"extra": {
    "aws/aws-sdk-php": ["S3"]
}
```

Rồi build:

```bash
composer install --no-dev --optimize-autoloader
```

Kết quả đo thật trên dự án này:

| | Trước | Sau khi cắt |
|---|---|---|
| File trong `vendor/` | 5.408 | **2.390** |
| Dung lượng | 70 MB | **20 MB** |
| Riêng `aws/` | 3.448 file | **458 file** |

Không bắt buộc, nhưng **3.000 file ít hơn qua FTP** là tiết kiệm rất nhiều thời gian.

⚠️ Nếu sau này cần dịch vụ AWS khác, phải thêm vào mảng `extra` rồi build lại,
không thì lỗi "class not found" **chỉ xuất hiện trên server**.

## §2. Cấu trúc thư mục trên server

Vì `open_basedir` chặn mọi thứ trên `htdocs/`, **cả dự án phải nằm trong `htdocs/`**:

```
htdocs/
  .htaccess              # rewrite mọi request vào public/   (§4)
  public/
    .htaccess            # front controller Laminas          (§4)
    index.php
    css/  js/
  config/
    .htaccess            # Require all denied                (§4)
    autoload/local.php   # DB + R2 — TUYỆT ĐỐI không để lộ
  module/
    .htaccess            # Require all denied
  vendor/
    .htaccess            # Require all denied
  data/
    .htaccess            # Require all denied
```

`public/index.php` của Laminas gọi `chdir(dirname(__DIR__))` → ra `htdocs/`, vẫn nằm trong
`open_basedir` → **không cần sửa `index.php`**.

## §3. Upload qua FTP

Lấy thông tin FTP trong VistaPanel → mục **FTP Accounts** (host `ftpupload.net`, user `if0_xxxxxxx`).

Dùng FileZilla:
1. Kết nối, vào thư mục `htdocs/`.
2. Upload: `public/`, `config/`, `module/`, `vendor/`, `data/`.
3. **Không** upload: `.git/`, `docs/`, `.claude/`, `composer.json`, `composer.lock`, `README.md`.
   Chúng vô dụng trên server và chỉ tốn inode.

Mẹo cho `vendor/` (2.390 file — FTP từng file rất chậm):
- Đặt FileZilla **Transfer → Maximum simultaneous transfers = 8..10**.
- Bật lại truyền nếu đứt: **Transfer → Failed transfers → Reset and requeue**.
- Upload xong **đếm lại số file** ở phía server, so với local. FTP đứt giữa chừng làm thiếu file
  âm thầm, và lỗi hiện ra là "class not found" khó lần.

## §4. Cấu hình .htaccess

**`htdocs/.htaccess`** — đẩy mọi request vào `public/`:

```apache
RewriteEngine On
RewriteCond %{REQUEST_URI} !^/public/
RewriteRule ^(.*)$ public/$1 [L]
```

Rewrite này **vô điều kiện** (không có `RewriteCond !-f`) là **có chủ đích**: nhờ vậy request tới
`/config/autoload/local.php` bị đẩy thành `/public/config/autoload/local.php` → 404, thay vì
trả về file thật chứa credential R2.

**`htdocs/public/.htaccess`** — front controller Laminas:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} -s [OR]
RewriteCond %{REQUEST_FILENAME} -l [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^.*$ - [NC,L]
RewriteRule ^.*$ index.php [NC,L]
```

**`.htaccess` trong `config/`, `module/`, `vendor/`, `data/`** — mỗi thư mục một file:

```apache
Require all denied
```

Đây là **lớp phòng thủ thứ hai**. Lớp một (rewrite ở trên) đã chặn rồi, nhưng file này chứa
credential R2 và DB — một lớp là không đủ. Nếu server báo lỗi 500 với `Require all denied`
(Apache quá cũ), đổi thành:

```apache
Order deny,allow
Deny from all
```

**Kiểm tra bắt buộc sau khi deploy** — mở trình duyệt vào:
- `https://<tên-miền>/config/autoload/local.php`
- `https://<tên-miền>/vendor/autoload.php`

Cả hai **phải** ra 403 hoặc 404. Nếu thấy nội dung file hoặc trang trắng, **dừng lại ngay**:
credential R2 và mật khẩu DB đang lộ ra internet.

## §5. Tạo database trong VistaPanel

1. VistaPanel → **MySQL Databases** → đặt tên (ví dụ `english_train`).
2. Panel tự thêm tiền tố → tên thật thành `if0_xxxxxxx_english_train`. **Dùng tên đầy đủ có tiền tố.**
3. User DB trùng tên tài khoản (`if0_xxxxxxx`), mật khẩu = mật khẩu tài khoản InfinityFree.
4. Hostname **không phải** `localhost` — panel hiện dạng `sqlXXX.epizy.com` / `sqlXXX.infinityfree.com`.
   **Đọc đúng giá trị trong panel**, số server khác nhau theo tài khoản, đừng chép từ hướng dẫn nào cả.

Chạy migration: không có SSH → VistaPanel → **phpMyAdmin** → chọn DB → tab **Import** →
chạy **từng file** trong `data/migrations/` **theo đúng thứ tự số**. Đừng gộp, lỗi sẽ khó lần.

Kiểm tra charset: DB phải `utf8mb4_unicode_ci`, nếu không tiếng Việt sẽ hỏng dấu.

## §6. Chuẩn bị credential R2 cho giai đoạn triển khai video sau này

> Code hiện tại chưa có upload/xem video. Không cần điền R2 để nghiệm thu các nghiệp vụ đang phát hành.

Copy `config/autoload/local.php.dist` → `local.php`, điền. **Tên key phải đúng như `.dist`**
(`driver` và `charset` đã nằm ở `config/autoload/global.php`, không lặp lại ở đây;
endpoint R2 code tự dựng từ `account_id`):

```php
<?php
// local.php KHONG commit len git.
declare(strict_types=1);

return [
    "db" => [
        "database" => "if0_xxxxxxx_english_train", // có tiền tố tài khoản
        "username" => "if0_xxxxxxx",
        "password" => "...",
        "hostname" => "sqlXXX.epizy.com",          // đọc đúng giá trị trong VistaPanel
    ],
    "r2" => [
        "account_id"    => "...",                  // Cloudflare dashboard → R2
        "access_key"    => "...",                  // R2 API token
        "secret_key"    => "...",
        "bucket"        => "english-train",
        "max_upload_mb" => 200,
    ],
];
```

**Vì sao R2 chạy được dù InfinityFree chặn kết nối ra ngoài**: tạo presigned URL là phép **ký
bằng thuật toán ở local**, PHP không hề gọi mạng. Trình duyệt học sinh mới là bên PUT thẳng lên R2.
→ Đừng thêm code gọi API R2 từ PHP (liệt kê object, xóa object) — sẽ chết trên free hosting.

**CORS trên R2 (bắt buộc, không có là upload hỏng)**. Cloudflare dashboard → R2 → bucket →
Settings → CORS policy:

```json
[
  {
    "AllowedOrigins": ["https://<tên-miền-của-bạn>"],
    "AllowedMethods": ["PUT", "GET"],
    "AllowedHeaders": ["content-type"],
    "MaxAgeSeconds": 3600
  }
]
```

`AllowedOrigins` phải khớp **chính xác** origin thật (có `https://`, không dấu `/` cuối).
Sai → browser chặn, hiện lỗi CORS, PHP không thấy gì.

Bucket vẫn **để private** — xem video qua presigned GET (`.claude/rules/01-bao-mat.md`).

## §7. Nghiệm thu sau deploy

1. Chạy `composer check-platform-reqs` ở máy build, xác nhận `ext-dom` đạt; nếu thiếu, bật/cài PHP XML.
2. `https://<tên-miền>/config/autoload/local.php` → **phải 403/404** (§4). Sai là hỏng hết, sửa ngay.
3. Đăng nhập được bằng cả 3 role: admin / teacher / student.
4. Dashboard lên đúng dữ liệu.
5. Tạo bài tập essay → nộp → chấm điểm.
6. Điểm danh một buổi, lưu cả bảng.
7. Xác nhận bài video hiện hiển thị "Chưa hỗ trợ"; chỉ kiểm upload/xem sau khi backlog R2 được triển khai.
8. Thử đổi `:id` trên URL sang lớp của giáo viên khác → **phải 403**.

## Ràng buộc còn lại của free hosting

- **Không cron job** → không tự động đóng bài quá hạn. Trạng thái `closed` phải tính lúc đọc
  (so `deadline_at` với hiện tại), không dựa vào job chạy nền. Code hiện tại đã theo hướng này.
- **Browser Security System** của InfinityFree đòi request đến từ trình duyệt thật. `fetch()` từ
  trang đã mở thì có cookie nên chạy được — nhưng gọi endpoint JSON bằng curl/Postman từ ngoài
  sẽ bị chặn. Đừng hoảng khi test bằng Postman thấy lỗi mà trình duyệt vẫn chạy.
- **Không đọc được `error_log` qua SSH.** Bật log ra file trong `htdocs/data/`
  (nhớ `.htaccess` chặn), tải về qua FTP mà xem.
- Free hosting có giới hạn CPU/hit theo ngày. Quy mô 5-10 học sinh/lớp thì không chạm tới.

## Cần kiểm chứng ở lần deploy đầu

Các con số về vendor (§1) là **đo thật trên máy local**. Phần dưới đây dựa trên tài liệu và
diễn đàn InfinityFree, **chưa chạy thật** — kiểm khi deploy lần đầu và **cập nhật lại file này**:

- Cú pháp `Require all denied` có được chấp nhận không (§4).
- `chdir(dirname(__DIR__))` trong `index.php` có bị `open_basedir` chặn không (§2).
- Hostname MySQL dạng `.epizy.com` hay `.infinityfree.com` (§5).

Nguồn: [giới hạn inode](https://forum.infinityfree.com/t/inode-limit-on-free-hosting/49331) ·
[open_basedir / document root](https://forum.infinityfree.com/t/change-document-root-to-htdocs-public/66265) ·
[PHP 8.3](https://forum.infinityfree.com/t/free-hosting-is-now-upgraded-to-php-8-3/109714) ·
[MySQL trong VistaPanel](https://forum.infinityfree.com/t/how-to-setup-a-new-mysql-database/49342) ·
[kết nối ra ngoài](https://forum.infinityfree.com/t/unable-to-connect-to-external-services/114257)
