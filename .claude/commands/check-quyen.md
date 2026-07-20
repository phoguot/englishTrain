---
description: Soi phân quyền của một module hoặc route — role và quyền sở hữu dữ liệu
argument-hint: [tên module hoặc route, ví dụ Assignment hoặc /assignments/:id]
---

Soi phân quyền cho: $1

Đây là lỗi hay gặp nhất của dự án. Với **mỗi action** thuộc phạm vi trên, lập bảng:

| Route | Controller::action | ALLOWED_ROLES | Check sở hữu ở đâu | Kết luận |

Cách làm:
1. Đọc `config/module.config.php` của module để lấy danh sách route → action.
2. Đọc controller, lấy hằng `ALLOWED_ROLES`. Thiếu hằng này = mở cho mọi người đăng nhập → **Chặn**.
3. Với action nhận `:id` hoặc `?classroom=`: truy từ controller xuống Service, xác nhận có check
   quyền sở hữu **thật** (teacher chỉ lớp mình, student chỉ dữ liệu của chính mình).
   Check ở view không tính. Check bằng cách "không select ra" cũng phải nói rõ.
4. Đối chiếu bảng route trong `docs/03-modules.md` — lệch docs thì báo.

Quy tắc đầy đủ ở `.claude/rules/01-bao-mat.md`.

Với mỗi lỗ hổng, mô tả bằng câu cụ thể: "teacher A sửa `/assignments/edit/5` của lớp teacher B được"
— kèm `file:dòng` và cách sửa. Chỉ báo cáo, chưa sửa gì; hỏi tôi trước khi sửa.
