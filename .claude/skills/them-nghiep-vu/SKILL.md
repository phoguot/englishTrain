---
name: them-nghiep-vu
description: Quy trình thêm một nghiệp vụ mới (route + controller + service + model + view) vào một module Laminas của EnglishTrain, hoặc dựng một module hoàn toàn mới. Dùng khi được yêu cầu thêm màn hình, thêm chức năng cho teacher/student, hoặc tạo module mới.
---

# Thêm nghiệp vụ vào EnglishTrain

Quy trình cố định để nghiệp vụ mới không lệch kiến trúc. Làm tuần tự, **không nhảy bước**.

## Bước 0 — Đọc ngữ cảnh
- `docs/03-modules.md` — route/controller/service hiện có (tránh trùng route).
- `docs/02-database-schema.md` — dữ liệu sẵn có.
- `docs/04-contracts.md` — nếu nghiệp vụ cần dữ liệu từ module khác.
- `module/<Ten>/CLAUDE.md` — luật riêng của module.

## Bước 1 — Chốt phạm vi trước khi gõ code
Trả lời 4 câu, hỏi lại tôi nếu chưa rõ:
1. Ai dùng? (admin / teacher / student) — và **quyền sở hữu dữ liệu** là gì?
2. Có cần cột/bảng mới không? Có → chạy `/migration-moi` trước, xong mới quay lại.
3. Có đụng module khác không? Có → phần "Hợp đồng liên module" bên dưới.
4. Màn hình gồm những gì? (danh sách / form / bảng thao tác hàng loạt)

## Bước 2 — Viết theo đúng thứ tự tầng
Cấu trúc file bắt buộc theo `docs/code-standards/crud-convention.md`. Từ trong ra ngoài,
mỗi tầng xong mới sang tầng kế:

1. **Model** `src/Model/<Entity>/<Entity>Model.php` — POPO: getter/setter thuần, không SQL.
2. **Mapper** `src/Model/<Entity>/<Entity>Mapper.php` — toàn bộ SQL nằm ở đây (`search`, `get`,
   `save`, `delete`). Nhận và trả **Model**, không trả `Select` ra ngoài. Hằng bảng:
   `const TABLE_NAME = '...';` (tương thích PHP 8.2), tham chiếu bằng `<Entity>Mapper::TABLE_NAME`.
   Không FK constraint — cột liên kết chỉ giao tiếp qua index (`.claude/rules/02-database.md`).
3. **Filter** `src/Filter/<Entity>/<Entity>SaveFilter.php` (+ `ListFilter`, `DeleteFilter` nếu cần)
   — laminas-inputfilter. Không tin POST thô, `SaveFilter` dùng chung cho create + update (`id` optional).
4. **Service** `src/Service/<Entity>Service.php` — validate bằng Filter, gọi Mapper,
   **check quyền sở hữu** (`isTeacherOf()` / `isStudentIn()`, xem `docs/04-contracts.md`).
   Sai quyền → ném exception, không trả rỗng.
5. **Controller** `src/Controller/<Entity>Controller.php` — kế thừa `BaseController`,
   khai `ALLOWED_ROLES`, mỏng: nhận request → gọi Service → trả `ViewModel`.
6. **Route** trong `config/module.config.php` của module + đăng ký factory
   cho controller/service (`service_manager` / `controller_manager`).
7. **View** `view/<ten-thuong>/<controller>/<action>.phtml` — HTML + Bootstrap 5 viết tay
   (không `laminas-form`), `escapeHtml()` mọi biến, badge trạng thái theo bảng màu chuẩn.

## Bước 3 — Cập nhật tài liệu (bắt buộc, cùng lần thay đổi)
- `docs/03-modules.md` — thêm dòng route mới.
- `module/<Ten>/CLAUDE.md` — nếu có luật/quy ước riêng mới.
- `docs/04-contracts.md` — **chỉ khi** thêm/sửa method mà module khác gọi.

## Bước 4 — Nghiệm thu
Chạy `/xong-chua`.

## Hợp đồng liên module
Module **không** gọi Model/Table của module khác. Chỉ gọi method public đã ghi trong
`docs/04-contracts.md` (ví dụ `Report` gọi `AttendanceService::summary()`).
Cần dữ liệu mà hợp đồng chưa có → thêm method vào Service **chủ sở hữu dữ liệu**,
ghi vào `docs/04-contracts.md`, rồi mới gọi. Không lách bằng cách query thẳng bảng người khác.

## Dựng module mới
Chỉ khi là nghiệp vụ thật sự mới, không nhét vừa 6 module hiện có. Ngoài các bước trên:
1. Cấu trúc: `module/<Ten>/{config/module.config.php, src/{Controller,Service,Model,Filter}, view/<ten-thuong>}`
   (chi tiết trong `docs/code-standards/crud-convention.md`) + `Module.php` + `CLAUDE.md`
   (theo mẫu module có sẵn, ví dụ `module/Attendance/CLAUDE.md`).
2. Thêm PSR-4 vào `composer.json` → `composer dump-autoload`.
3. Thêm tên module vào mảng `modules` trong `config/application.config.php`
   (**sau** `Application` và `User` — không có file `modules.config.php` riêng).
4. Thêm mục vào `docs/03-modules.md` và bảng module trong `CLAUDE.md` gốc.
