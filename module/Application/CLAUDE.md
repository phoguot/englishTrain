# Module Application

Hạ tầng chung: `BaseController`, layout, navbar, dashboard. Sở hữu duy nhất bảng hạ tầng
`system_log` (ghi lỗi hệ thống) — không sở hữu bảng nghiệp vụ nào.

## Ghi lỗi hệ thống (`system_log`)
- `Module::onBootstrap()` gắn listener vào `dispatch.error` + `render.error`: mọi exception
  không được xử lý sẽ được `SystemLogService::logThrowable()` ghi vào bảng `system_log`
  (kèm URL, method, user_id, IP, stack trace) trước khi user thấy trang lỗi chung.
- `AccessDeniedException` / `NotFoundException` do BaseController bắt nên **không** vào log —
  đó là luồng nghiệp vụ bình thường, không phải lỗi hệ thống.
- Ghi log **không bao giờ được ném lỗi tiếp**: DB hỏng thì fallback `error_log()` của PHP.
- Module nghiệp vụ **không** gọi `SystemLogService`/`SystemLogMapper` trực tiếp — cần báo lỗi
  thì cứ ném exception, listener lo phần ghi.
- Trang tra cứu: `/system-logs` (chỉ admin, chỉ đọc) — `SystemLogController`, lọc theo
  `?level=`, 100 dòng mới nhất.

Mọi module đều phụ thuộc vào đây → đổi ở đây ảnh hưởng toàn hệ thống. Sửa cẩn thận.

## BaseController — trái tim phân quyền
`onDispatch()` chạy trước **mọi** action của **mọi** module:
1. Chưa đăng nhập → redirect `/login` (trừ controller khai role `guest`).
2. `user.status = 0` (bị khóa giữa chừng) → hủy session, đá về `/login`.
   Kiểm **mỗi request**, không chỉ lúc login.
3. Role không nằm trong hằng `ALLOWED_ROLES` của controller con → 403.

Luật:
- Controller con **bắt buộc** khai `ALLOWED_ROLES`. Thiếu = mặc định `[]` → 403 (deny by default).
- `BaseController` chỉ kiểm **role**, không kiểm **quyền sở hữu dữ liệu** — cái đó là việc của
  Service từng module (`isTeacherOf()` / `isStudentIn()`). Đừng nhét logic sở hữu vào đây,
  nó không biết `classroom_id` nằm ở đâu trong request.
- Đừng thêm việc nặng (query dashboard, đếm số liệu) vào `onDispatch()` — nó chạy mọi request.

## Exception → mã HTTP (dùng cho MỌI module)
`onDispatch()` bọc action trong try/catch và tự đổi exception thành trang lỗi đúng mã:

| Service ném | Kết quả |
|---|---|
| `Application\Exception\AccessDeniedException` | 403 + `error/403` |
| `Application\Exception\NotFoundException` | 404 + `error/404` |

Nhờ vậy **controller con không cần try/catch**: cứ để Service ném, BaseController lo phần còn lại.
Đây là cách chuẩn để báo sai quyền sở hữu / dữ liệu không tồn tại — đừng tự `setStatusCode()`
rải rác trong action, cũng đừng im lặng trả rỗng.

Role khác nhau theo **từng action** trong cùng controller (ví dụ index cho admin+teacher nhưng
create chỉ admin): khai `ALLOWED_ROLES` là tập rộng nhất, rồi siết thêm ở đầu action bằng cách
ném `AccessDeniedException`. Xem `Classroom\Controller\ClassroomController::assertAdmin()`.

## Layout & navbar
- Navbar đổi theo role. Ẩn menu **không phải** là phân quyền — server vẫn phải chặn.
  Đừng bao giờ dựa vào việc ẩn nút để bảo mật.
- Layout là nơi duy nhất nạp CSS/JS chung. Không nhét `<script>` rời trong view con.

## Dashboard
- `/` là landing page công khai; `/dashboard` mới là trang tổng quan có xác thực.
- teacher: lớp mình phụ trách + bài **chờ chấm** (`submission.status = 'submitted'`).
- student: bài sắp đến hạn (`published`, `deadline_at` gần) + bài **đã chấm** (`graded`).
- admin: số liệu tổng.

Luật: dashboard gọi **Service** của các module, không tự query. Đây là trang N+1 dễ xảy ra nhất
(loop lớp → loop học sinh → loop bài). Gom bằng `findMany()` / query có join.
Chậm thì sửa query, **không** thêm cache.

## Nhắc
Application đứng dưới cùng chuỗi phụ thuộc: nó **không được** import ngược từ Assignment,
Attendance, Report... trừ qua hợp đồng trong `docs/04-contracts.md`. Xem sơ đồ chiều phụ thuộc ở đó.
