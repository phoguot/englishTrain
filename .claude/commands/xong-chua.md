---
description: Chạy quality gate trước khi coi một nghiệp vụ là hoàn thành
---

Chạy quality gate cho phần code vừa làm. Theo checklist `.claude/rules/04-testing.md`.

1. `php -l` mọi file PHP vừa sửa.
2. Đọc lại diff: có `declare(strict_types=1)`, không `$_POST`/`$_GET` thô,
   không SQL ngoài Model, controller không chứa logic nghiệp vụ.
3. Gọi subagent `laminas-reviewer` review phần vừa làm.
4. Nếu có đụng DB: gọi subagent `schema-guard`.
5. Liệt kê **những gì tôi cần bấm tay** để nghiệm thu — cụ thể theo nghiệp vụ vừa làm,
   không copy checklist chung:
   - đường dẫn cần mở, đăng nhập role nào;
   - thao tác sai quyền cần thử (đổi `:id` sang lớp/bài người khác → phải 403);
   - form rỗng / sai kiểu → phải ra lỗi validate tiếng Việt.

Báo cáo cuối: **Xong / Chưa xong** kèm lý do. Nếu có mục nào bạn chưa thực sự kiểm chứng
thì nói thẳng là chưa kiểm, đừng đoán. Chưa chạy trình duyệt thì không được kết luận "Xong".
