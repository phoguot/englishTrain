# 03 — Module, route, controller

Mỗi module Laminas tự khai báo route + controller + service trong `config/module.config.php`.
Controller kế thừa `Application\Controller\BaseController` — trong `onDispatch()` check
session đăng nhập và role được phép (khai báo qua hằng `ALLOWED_ROLES` của controller con).

## module/User
| Route | Controller::action | Role | Mô tả |
|-------|--------------------|------|-------|
| /login | AuthController::login | guest | form đăng nhập, session |
| /logout | AuthController::logout | all | |
| /users | UserController::index | admin | danh sách + tìm kiếm |
| /users/create, /users/edit/:id | UserController::create/edit | admin | tạo/cập nhật tài khoản |
| /users/delete/:id | UserController::delete (POST) | admin | xóa cứng; chặn tự xóa hoặc user còn quan hệ lớp hiện hành |

Service: `AuthService` (verify password, đồng bộ role session), `UserService` (CRUD),
`Application\Service\UserAccountAdministrationService` (giữ integrity khi đổi role/hard-delete).

## module/Classroom
| /classrooms | index | admin, teacher(chỉ lớp mình) |
| /classrooms/create, edit/:id | admin |
| /classrooms/:id/students | quản lý học sinh trong lớp (thêm/xóa) | admin, teacher |

Service: `ClassroomService`. Mapper: `Model/Classroom/ClassroomMapper`,
`Model/ClassroomStudent/ClassroomStudentMapper` (cấu trúc theo `docs/code-standards/crud-convention.md`).

## module/Assignment
| /assignments?classroom=:id | index | teacher, student(lớp mình, chỉ published) |
| /assignments/create, edit/:id | teacher |
| /assignments/:id | detail — teacher thấy danh sách nộp, student thấy form nộp |
| /assignments/:id/submit | POST nộp bài (essay/quiz) | student |
| /submissions/:id/grade | POST chấm điểm + feedback | teacher |

Service hiện tại: `AssignmentService`, `SubmissionService`, `QuizGrader` (chấm quiz tự động).

Upload/xem video R2 là backlog production. Chưa đăng ký `upload-url`, `upload-done` hoặc `R2Storage`;
chỉ bổ sung các route/hợp đồng này sau khi credential và CORS production sẵn sàng.

## module/Attendance
| Route | Tên route | Màn hình | Ai vào được |
|---|---|---|---|
| /attendance?classroom=:id | `attendance` | danh sách buổi học | admin(xem), teacher |
| /attendance/create[?classroom=:id] | `attendance_create` | tạo buổi (mặc định hôm nay) | teacher |
| /attendance/:sessionId | `attendance_mark` | màn hình điểm danh: bảng học sinh × radio 4 trạng thái, POST lưu cả bảng | admin(xem), teacher |
| /attendance/student/:id[?classroom=:id] | `attendance_student` | lịch sử chuyên cần 1 học sinh | admin, teacher, student(chính mình) |

Service: `AttendanceService` (tạo session, upsert records, thống kê tỉ lệ chuyên cần).

Lưu ý phân quyền (khác các module khác — đọc kỹ):
- Module này **tách ĐỌC và GHI**, không dùng chung một mức quyền:
  - **Đọc**: admin xem được **mọi lớp**; teacher chỉ lớp mình phụ trách.
  - **Ghi** (tạo buổi, lưu bảng điểm danh): **chỉ teacher phụ trách lớp**. Admin xem được
    nhưng không điểm danh hộ — điểm danh là việc của giáo viên đứng lớp, admin ghi hộ thì
    không ai chịu trách nhiệm số liệu.
  - Hai mức này là `AttendanceService::assertCanViewClassroom()` và `assertCanEditClassroom()`.
    View chỉ ẩn nút bằng cờ `canEdit` cho đỡ rối — chặn thật nằm ở Service.
- **Lớp `archived` không điểm danh được** (kể cả giáo viên phụ trách), nhưng vẫn xem được
  lịch sử — theo `module/Classroom/CLAUDE.md`.
- `/attendance/student/:id`: **teacher bắt buộc truyền `?classroom=`** (thiếu → 404) và phải
  phụ trách lớp đó, đồng thời học sinh phải thuộc lớp đó. Admin xem được mọi học sinh
  (có `?classroom=` thì lọc theo lớp). Student xem chính mình thì không cần tham số, và thấy
  chuyên cần **mọi lớp** em đang học.
- Lối vào: nút "Điểm danh" ở dashboard và ở danh sách lớp (admin + teacher); học sinh vào bằng
  nút "Chuyên cần của tôi" trên dashboard.

## module/Report
| /reports?classroom=:id | danh sách report | teacher |
| /reports/create?classroom=:id&student=:id | soạn report (editor rich text nhẹ) | teacher |
| /reports/edit/:id | sửa draft | teacher |
| /reports/:id/publish | POST publish | teacher |
| /my-reports | học sinh xem report published của mình | student |

Service: `ReportService` — khi render trang soạn report, gọi kèm
`AttendanceService::summary(student, classroom, period)` và
`SubmissionService::avgScore(student, classroom, period)` để giáo viên tham khảo.

## module/Application
- `BaseController` (auth + role check), layout Bootstrap 5, navbar theo role
- `/` landing page công khai cho mọi người; chỉ dẫn tới `/login`, không tải dữ liệu nghiệp vụ
- `/dashboard` sau đăng nhập: admin thấy số lớp/giáo viên/học sinh active; teacher thấy lớp + tối đa 5 bài
  chờ chấm; student thấy tối đa 5 bài sắp đến hạn và 5 bài đã chấm
- `DashboardService` tổng hợp dữ liệu qua `UserService`, `ClassroomService`, `AssignmentService`;
  `IndexController` không tự query hoặc loop gọi Service theo từng lớp
- `/system-logs` (GET, chỉ admin): tra cứu bảng `system_log` — exception không được xử lý,
  ghi tự động từ listener `dispatch.error`/`render.error` (xem `module/Application/CLAUDE.md`).
  Lọc theo `?level=error|warning|info`, hiển thị 100 dòng mới nhất, có stack trace trong
  phần "Chi tiết kỹ thuật". `SystemLogController` → `SystemLogService` → `SystemLogMapper`.

## Thứ tự triển khai đề xuất (mỗi bước chạy được end-to-end)
1. User + Auth + layout + dashboard rỗng
2. Classroom + gán học sinh
3. Assignment loại essay + quiz (chưa video) + chấm điểm
4. Attendance
5. Report
6. Upload video R2 sau khi production có credential/CORS (ngoài phạm vi hiện tại)
