---
description: Tạo file migration SQL mới đúng quy ước, kèm cập nhật docs schema
argument-hint: [mô tả thay đổi, ví dụ "them cot muc tieu vao student_report"]
---

Tạo migration cho thay đổi: $1

Các bước, làm đúng thứ tự:

1. `ls data/migrations/` để lấy số lớn nhất → file mới là số kế tiếp, 4 chữ số,
   tên `NNNN_mo_ta_ngan.sql` (không dấu, snake_case).
2. Đọc `docs/02-database-schema.md` để biết trạng thái schema hiện tại và quy ước cột.
3. Viết DDL:
   - utf8mb4_unicode_ci, InnoDB, có `created_at`/`updated_at` nếu là bảng mới;
   - điểm/tiền dùng DECIMAL, thời điểm dùng DATETIME;
   - **Không** khai `FOREIGN KEY` constraint — cột liên kết chỉ cần index,
     toàn vẹn dữ liệu kiểm ở Service/Mapper (`.claude/rules/02-database.md`);
   - nếu thay đổi **phá dữ liệu** (DROP, thu hẹp kiểu, thêm cột NOT NULL không default)
     → comment cảnh báo ở đầu file và nói cho tôi biết.
4. Cập nhật `docs/02-database-schema.md` ngay trong lần này — docs là nguồn sự thật,
   migration mà docs không có coi như chưa xong.
5. Grep xem Model/Service nào chịu ảnh hưởng, liệt kê ra (chưa sửa vội).

**Không chạy SQL lên DB** — migration chạy tay, đưa file cho tôi.

Xong thì tóm tắt: tên file, tóm tắt DDL, có phá dữ liệu không, chỗ code cần sửa theo.
Quy ước đầy đủ: `.claude/rules/02-database.md`.
