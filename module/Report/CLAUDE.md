# Module Report

Report định kỳ cho từng học sinh: draft → published.

Sở hữu bảng: `student_report`. Hợp đồng: `docs/04-contracts.md`.

## Ranh giới
Report là module **đọc nhiều nhất, không ai đọc ngược**. Nó là nơi duy nhất ghép số liệu
Assignment + Attendance. Chỉ qua hợp đồng:
- `AttendanceService::summary($studentId, $classroomId, $periodLabel)`
- `SubmissionService::avgScore(...)` / `countByStatus(...)`

**Không** query `submission` hay `attendance_record`, dù chỉ để "lấy nhanh một con số".
Đây là chỗ dễ phá kiến trúc nhất của dự án.

## Luật riêng
- Số liệu chuyên cần/điểm là **tham khảo cho giáo viên lúc soạn**, không tự nhét vào `content`.
  Giáo viên tự viết nhận xét. Số liệu hiện cạnh editor, dạng chỉ đọc.
- `content` là HTML từ editor → **lọc allowlist tag khi lưu**, không lọc lúc hiển thị.
  Allowlist: `p, br, strong, em, u, ul, ol, li, h3, h4`. Bỏ hết attribute (không `style`,
  không `class`, không `href` — chưa cần link). Đây là chỗ duy nhất toàn hệ thống được in HTML
  không escape → sai ở đây là XSS thật. Có unit test.
- `draft` → chỉ giáo viên tạo ra nó thấy. `published` → học sinh **và phụ huynh** đọc,
  coi như đã gửi đi.
- Đã `published` thì **không sửa lại**. Cần đổi nội dung → tạo report kỳ mới.
  Kiểm ở Service, không chỉ ẩn nút.
- `published_at` set **một lần** lúc publish, không cập nhật về sau.
- `period_label` bắt buộc theo tháng, định dạng `YYYY-MM` (ví dụ `2026-07`) để khớp
  `summary()` / `avgScore()`; Filter phải chặn nhãn sai định dạng.
- Không có UNIQUE `(student_id, classroom_id, period_label)` → tạo trùng kỳ được về mặt DB.
  Service phải cảnh báo trước khi tạo trùng.
- Học sinh xem `/my-reports`: chỉ report `published` của **chính mình**. teacher chỉ report lớp mình.
- Chỉ lúc **tạo mới** mới bắt buộc học sinh còn là thành viên hiện tại của lớp. Draft lịch sử vẫn
  được giáo viên tạo ra nó sửa/publish sau khi học sinh rời lớp, miễn lớp chưa archived.
- Form create/edit/publish bắt buộc có CSRF token từ `AuthService`; sai token → 403 trước khi ghi.

## Hay sai
- Query thẳng `submission`/`attendance_record` cho tiện → vỡ ranh giới module.
- Quên lọc HTML khi lưu, hoặc lọc lúc hiển thị (sai chỗ) → XSS.
- Cho sửa report đã published.
