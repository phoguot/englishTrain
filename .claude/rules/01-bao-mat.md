# Rule — Bảo mật & phân quyền

Áp dụng cho: mọi controller, service, form trong `module/`.

## Phân quyền
- Không tự viết check quyền trong action. Khai `ALLOWED_ROLES` ở controller con,
  `Application\Controller\BaseController::onDispatch()` lo phần còn lại.
- Check role **chưa đủ** — phải check quyền sở hữu dữ liệu:
  - teacher chỉ đụng được lớp có `classroom.teacher_id = <mình>`;
  - student chỉ đụng được submission/report/attendance của chính mình;
  - admin không bị giới hạn.
- Quyền sở hữu check trong Service (nơi có dữ liệu), không check ở view.
  Sai quyền → ném `Application\Exception\AccessDeniedException` (BaseController tự đổi thành 403);
  dữ liệu không tồn tại → `Application\Exception\NotFoundException` (→ 404).
  Không im lặng trả rỗng, không tự `setStatusCode()` trong action.
  Chi tiết: `module/Application/CLAUDE.md` §"Exception → mã HTTP".

## Dữ liệu vào
- Mọi form `POST` phải gửi hidden field `_csrf`. `BaseController::onDispatch()` kiểm tra token tập trung
  trước khi gọi action; controller con không tự bỏ qua hoặc lặp lại bước kiểm tra này.
- Cấm `$_POST` / `$_GET` / `$_REQUEST` thô (hook `.claude/hooks/kiem-tra-php.php` chặn).
- Mọi form validate bằng Filter class (laminas-inputfilter, `src/Filter/<Entity>/`).
  Validate xong mới lấy `getValues()`.
- **Filter chạy trong Service, không chạy trong Controller.** Controller đưa POST thô xuống,
  Service `new <Entity>SaveFilter(...)` → `isValid()` → sai thì ném `ValidationException`.
  Không rải `if` kiểm giá trị trong Service — cần kiểm gì thì thêm validator vào Filter
  (kể cả loại phải tra DB: truyền Service vào constructor của Filter để dựng haystack).
- Route param (`:id`) vẫn là dữ liệu người dùng — ép kiểu int rồi kiểm tra tồn tại + sở hữu.

Phân biệt 2 loại kiểm tra, đừng lẫn:

| Loại | Ở đâu | Sai thì |
|---|---|---|
| **Giá trị** (rỗng, quá dài, ngoài tập cho phép) | Filter class, gọi từ Service | `ValidationException` → render lại form |
| **Quyền sở hữu** (lớp của ai, bài của ai) | Service (`isTeacherOf`/`isStudentIn`) | `AccessDeniedException` → 403 |

## Mật khẩu & session
- Hash bằng `password_hash()` (bcrypt), verify bằng `password_verify()`. Không MD5/SHA1.
- Không bao giờ select `password_hash` ra ngoài `AuthService`.
- Đăng nhập thành công phải `session_regenerate_id(true)` chống session fixation.
- Session chỉ chứa `user_id` và `role`. Cần gì thêm thì query lại.
- `BaseController` kiểm tra user mỗi request; `AuthService::isActiveUser()` đồng bộ lại `role` từ DB để
  thay đổi quyền có hiệu lực ngay cả với session đang mở.

## Output
- Mọi biến in ra PHTML phải qua `$this->escapeHtml()`.
- Ngoại lệ duy nhất: `student_report.content` (HTML từ editor) — phải lọc bằng
  allowlist tag trước khi lưu, không lọc lúc hiển thị.

## R2 / video
- **Backlog production:** ứng dụng hiện chưa phát hành luồng upload/xem video qua R2; các quy tắc dưới
  đây bắt buộc áp dụng khi triển khai sau khi đưa hệ thống lên production.
- Credential R2 chỉ đọc từ `config/autoload/local.php`, không hardcode, không log.
- Presigned PUT: hạn ≤ 15 phút, ràng buộc content-length và content-type.
- Presigned GET: hạn 1h, chỉ cấp sau khi đã check người xem có quyền với submission đó.
- Bucket R2 không bao giờ để public.
