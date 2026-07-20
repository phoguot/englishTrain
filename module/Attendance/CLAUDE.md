# Module Attendance

Buổi học + điểm danh + thống kê chuyên cần.

Sở hữu bảng: `attendance_session`, `attendance_session_student`, `attendance_record`. Hợp đồng public: `docs/04-contracts.md`.

## Ranh giới
- Export cho `Report`: `AttendanceService::summary()`. Report không đụng bảng.
- Không biết gì về `Assignment`.

## Luật riêng
- UNIQUE `(classroom_id, session_date)` → **một lớp một ngày một buổi**. Tạo trùng ngày
  phải báo lỗi tiếng Việt rõ ràng, không nuốt lỗi DB thành trang trắng.
- Màn hình điểm danh lưu **cả bảng một lần** (n học sinh × 4 trạng thái), không lưu từng dòng:
  - tạo `attendance_session` và snapshot `attendance_session_student` trong cùng transaction;
  - buổi chưa có record là trạng thái "chưa điểm danh" hợp lệ;
  - khi lưu bảng điểm danh, bọc transaction để n `attendance_record` cùng thành công hoặc cùng hỏng;
  - upsert theo UNIQUE `(session_id, student_id)` — điểm danh lại là sửa, không thêm dòng;
  - danh sách lấy từ roster snapshot, hợp với record cũ để tương thích dữ liệu trước migration.
- Học sinh vào lớp giữa kỳ → không có record ở buổi trước khi vào. Đó là **thiếu record**,
  không phải `absent`. `summary()` không được tính những buổi đó vào mẫu số.
- Học sinh bị gỡ khỏi lớp: record cũ vẫn còn, vẫn hiện trong lịch sử buổi đó.
  Đừng join `classroom_student` kiểu INNER khi hiển thị buổi cũ — sẽ mất dòng.
- `rate = (present + late) / total_sessions`, làm tròn 2 số. **`total_sessions = 0` → `rate = null`**,
  không phải 0 (0% đọc thành "không bao giờ đi học", trong khi thực tế là "lớp chưa có buổi nào").
  Đây là ca biên cần được kiểm tra theo `.claude/rules/04-testing.md`.
- `late` tính là **có đi học**. `excused` (có phép) không tính vào tử số nhưng vẫn ở mẫu số.
  Quy tắc này phải giống hệt nhau ở mọi nơi hiển thị — chỉ tính ở `summary()`, đừng tính lại ở view.
- Chỉ teacher phụ trách lớp điểm danh được (`isTeacherOf()`).
  Student chỉ xem lịch sử **của chính mình** (`/attendance/student/:id` — kiểm `id === currentUserId`).

## Hay sai
- Không bọc transaction → mất mạng giữa chừng, buổi học có mà nửa bảng không có record.
- Chia cho 0 khi lớp chưa có buổi nào.
