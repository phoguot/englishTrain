# Module Classroom

Lớp học + gán học sinh vào lớp. Là **trục phân quyền** của cả hệ thống: câu hỏi
"người này có được đụng dữ liệu kia không" hầu như luôn quy về "họ có thuộc lớp đó không".

Sở hữu bảng: `classroom`, `classroom_student`. Hợp đồng public: `docs/04-contracts.md`.

## Ranh giới
- `isTeacherOf()` và `isStudentIn()` là 2 method quan trọng nhất module này export.
  Assignment / Attendance / Report gọi chúng trước mọi thao tác có `classroom_id`.
  Sửa 2 method này = sửa phân quyền toàn hệ thống → cực kỳ cẩn thận.
- Module khác **không** query `classroom_student`. Cần danh sách học sinh hiện tại → `studentIds()`.

## Luật riêng
- teacher chỉ thấy/sửa lớp có `classroom_id.teacher_id = <mình>`. admin thấy tất cả.
  Danh sách lớp của teacher phải lọc **trong SQL**, không lọc ở PHP sau khi select hết.
- Gỡ học sinh khỏi lớp = xóa row `classroom_student`. **Không** xóa theo submission,
  attendance_record, report của học sinh đó — dữ liệu lịch sử phải còn.
  Hệ quả: khi hiển thị dữ liệu cũ, học sinh có thể không còn trong lớp → vẫn phải render được,
  đừng giả định join `classroom_student` luôn ra row.
- Đổi `teacher_id` của lớp: giáo viên cũ mất quyền ngay. Report/assignment họ đã tạo vẫn giữ
  `teacher_id` cũ (ghi nhận người tạo) — **đừng** cập nhật hàng loạt theo.
- `status = 'archived'`: lớp chỉ đọc. Không tạo bài mới, không điểm danh, không report mới.
  Kiểm ở Service, không chỉ ẩn nút ở view.
- Quy mô 5-10 học sinh/lớp → giao diện gán học sinh dùng checkbox liệt kê hết,
  không cần search/paginate/autocomplete.

## Hay sai
- Check `role === 'teacher'` mà quên check `isTeacherOf()` → teacher A sửa lớp teacher B.
- Xóa cứng `classroom` → mồ côi FK ở 4 module khác. Dùng `status = 'archived'`.
