# Module Assignment

Module phức tạp nhất: bài tập (video/quiz/essay) + bài nộp + chấm điểm + upload R2.

Sở hữu bảng: `assignment`, `submission`. Hợp đồng public + hợp đồng JSON: `docs/04-contracts.md`.

## Ranh giới
- Export cho `Report`: `SubmissionService::avgScore()`, `countByStatus()`. Report không đụng bảng.
- Không biết gì về `Attendance`. Cần ghép số liệu 2 bên là việc của `Report`.

## Luật riêng — bài tập
- `status = 'draft'`: student **không thấy**, kể cả gõ thẳng URL. Lọc trong SQL.
- `status = 'closed'` hoặc quá `deadline_at`: không nhận nộp nữa. Kiểm ở Service.
  `deadline_at = NULL` = không hạn.
- Không xóa assignment đã có submission — sẽ mất bài học sinh. Chuyển `closed`.
- `quiz_json`: `[{question, options[], correct_index}]`. Validate cấu trúc khi lưu, đừng tin JSON đúng dạng lúc đọc.

## Luật riêng — nộp bài & chấm
- Không nộp = **không có row** submission (đừng tạo row rỗng). Hệ quả: "chưa nộp" là
  `LEFT JOIN ... IS NULL`, không phải `status = 'not_submitted'`.
- UNIQUE `(assignment_id, student_id)` → nộp lại là **UPDATE**, không INSERT thêm. Dùng upsert.
- Nộp lại bài **đã chấm** → xoá `score`, `feedback`, `graded_at` (về `submitted`, chờ chấm lại).
  Điểm cũ chấm cho nội dung cũ; giữ lại là dữ liệu sai. Đã xử lý trong `upsertSubmission()`.
- `auto_score` (quiz chấm máy) và `score` (điểm cuối) là **hai cột khác nhau**.
  Quiz nộp xong: điền `auto_score`, `status` vẫn `submitted`.
  `status = 'graded'` chỉ khi giáo viên xác nhận và `score` có giá trị — giáo viên được sửa khác `auto_score`.
- Điểm hiển thị cho học sinh = `score`. Chưa `graded` thì hiện "Chờ chấm", **không** hiện `auto_score`.
- `QuizGrader` là logic thuần → cần kiểm tra các ca biên theo `.claude/rules/04-testing.md`.
  Ca biên: câu bỏ trống, số đáp án lệch số câu, `correct_index` ngoài range.
- Chấm điểm: kiểm `isTeacherOf()` của lớp chứa assignment, không chỉ kiểm `role === 'teacher'`.

## Backlog production — upload video R2
Luồng video **chưa được triển khai ở giai đoạn hiện tại**. Chỉ bắt đầu làm khi production đã có
credential và CORS R2. Không công bố route JSON là đang hoạt động trước thời điểm đó.

Khi triển khai, tuân thủ các luật sau:
- File **không** đi qua PHP. Luồng 3 bước: xin presigned URL → browser PUT thẳng R2 → báo done.
- `upload-url`: kiểm `isStudentIn()` + assignment còn nhận bài **trước khi** ký URL.
  Ký xong mới kiểm là đã lộ quyền ghi.
- Presigned PUT hạn ≤ 15 phút, ràng content-length + content-type. Presigned GET hạn 1h,
  chỉ cấp sau khi kiểm người xem có quyền với submission đó.
- `upload-done` là **bước tạo submission**. Client có thể không gọi (mất mạng, đóng tab)
  → sẽ có object mồ côi trên R2. Chấp nhận, chưa dọn tự động.
- Chỉ lưu `video_key`, không lưu URL (URL có hạn). Không gọi R2 trong transaction.

## Hay sai
- Trả `auto_score` cho học sinh trước khi giáo viên chấm → lộ đáp án.
- Danh sách nộp bài loop query tên học sinh → N+1. Dùng `UserService::findMany()`.
