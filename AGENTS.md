# EnglishTrain — Hệ thống quản lý lớp học tiếng Anh

## Trình tự bắt buộc trước khi code
Luôn thực hiện đúng thứ tự sau, không được bỏ qua hoặc đảo thứ tự:
1. **Đọc skill trước** — xác định skill liên quan và đọc đầy đủ `SKILL.md` cùng các tài liệu bắt buộc mà skill tham chiếu.
2. **Đọc docs sau** — đọc `CLAUDE.md` của module, bản đồ tài liệu và các tài liệu/quy tắc liên quan đến phạm vi thay đổi.
3. **Sau đó mới code** — chỉ bắt đầu tạo hoặc sửa code khi đã hoàn tất hai bước trên.

## Mục tiêu
Website (không phải mobile app) cho trung tâm/giáo viên dạy tiếng Anh quy mô nhỏ,
mỗi lớp 5-10 học sinh. Nghiệp vụ chính:
1. Giáo viên giao bài tập cho nhiều học sinh (bài video, trắc nghiệm, tự luận)
2. Học sinh nộp bài: upload video hoặc làm bài trực tiếp (trắc nghiệm chấm tự động)
3. Điểm danh học sinh theo buổi học
4. Giáo viên viết report định kỳ cho từng học sinh (draft → published)

## Stack
- Backend: PHP 8.2+, Laminas MVC (laminas-mvc, laminas-db, laminas-inputfilter)
- Database: MySQL 8, charset utf8mb4
- Frontend: view PHTML render từ Laminas + Bootstrap 5, JS thuần (không SPA)
- File video: dự kiến upload lên Cloudflare R2 qua presigned URL, KHÔNG đi qua PHP backend;
  endpoint upload/xem video chỉ triển khai khi cấu hình production sẵn sàng
- Chưa dùng: queue, Redis, transcode video, push notification (quy mô nhỏ chưa cần)

## Cấu trúc module (mỗi module 1 nghiệp vụ)
Mỗi module có `CLAUDE.md` riêng — **đọc file đó trước khi sửa module tương ứng**.

- `module/Application` — layout chung, trang chủ, dashboard theo role, BaseController
- `module/User`        — đăng nhập (session), phân quyền role: admin / teacher / student
- `module/Classroom`   — lớp học, gán học sinh vào lớp
- `module/Assignment`  — bài tập + bài nộp (submission) + chấm điểm
- `module/Attendance`  — buổi học + điểm danh
- `module/Report`      — report định kỳ cho từng học sinh

Module chỉ nói chuyện với nhau qua hợp đồng ở `docs/04-contracts.md` — không query bảng của nhau.

## Quy ước code
- PSR-12, strict_types=1 trong mọi file PHP
- 4 tầng: Controller → Service → Mapper → Model. Cấu trúc file bắt buộc theo
  `docs/code-standards/crud-convention.md` — **đọc trước khi tạo class mới**
- Controller mỏng: chỉ nhận request + gọi Service. Logic nghiệp vụ ở src/Service,
  SQL ở src/Model/<Entity>/<Entity>Mapper.php
- Mọi dữ liệu vào validate bằng Filter class (src/Filter/<Entity>/), không tin dữ liệu POST thô
- Route đặt trong config/module.config.php của từng module
- Tên bảng snake_case số ít: `user`, `classroom`, `assignment`, `submission`,
  `attendance_session`, `attendance_record`, `student_report`
- Phân quyền check ở onDispatch của AbstractController base (xem docs/03-modules.md)
- Comment và message hiển thị cho user viết bằng tiếng Việt

## Tài liệu chi tiết (đọc trước khi code)
- docs/00-ban-do-tai-lieu.md — **bản đồ: ai đọc gì, sửa gì cập nhật gì**
- docs/code-standards/crud-convention.md — **cấu trúc file CRUD bắt buộc**
- docs/01-tong-quan.md       — kiến trúc, luồng nghiệp vụ
- docs/02-database-schema.md — schema MySQL đầy đủ (nguồn sự thật về DB)
- docs/03-modules.md         — route, controller, service của từng module
- docs/04-contracts.md       — hợp đồng liên module (nguồn sự thật về ranh giới)

## Luật chi tiết (đọc khi đụng tới chủ đề tương ứng)
- .claude/rules/01-bao-mat.md   — phân quyền, dữ liệu vào, mật khẩu, R2
- .claude/rules/02-database.md  — migration, Mapper, transaction
- .claude/rules/03-view-ui.md   — PHTML, Bootstrap, JS thuần, message tiếng Việt
- .claude/rules/04-testing.md   — test cái gì, quality gate trước khi báo "xong"
- .claude/rules/05-deployment.md — cấu hình, deploy, backup

## Công cụ có sẵn
- `/check-quyen <module>`  — soi phân quyền role + quyền sở hữu dữ liệu
- `/migration-moi <mô tả>` — tạo migration SQL đúng quy ước + cập nhật docs
- `/xong-chua`             — chạy quality gate trước khi coi là hoàn thành
- Skill `them-nghiep-vu`   — quy trình thêm nghiệp vụ / dựng module mới
- Subagent `laminas-reviewer` (review code), `schema-guard` (canh lệch schema)
- Hook tự chạy sau mỗi Edit/Write: `php -l`, strict_types, cấm `$_POST` thô

## Lệnh thường dùng
- `composer install` — cài dependency
- `composer serve`   — chạy dev server tại http://localhost:8080
- Migration SQL nằm trong data/migrations/, chạy tay theo thứ tự tên file

## Ranh giới
- Không thêm SPA framework, npm, bundler, Docker, queue, Redis, cache — quy mô nhỏ chưa cần.
- Không tự chạy SQL lên DB, không `git push`. Đưa file cho người thật chạy.
- Chưa có PHPUnit — đừng báo "đã chạy test". Nghiệm thu bằng trình duyệt (`.claude/rules/04-testing.md`).
