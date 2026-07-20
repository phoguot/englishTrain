# Module User

Danh tính + đăng nhập + phân quyền. Là nền của mọi module khác → đổi ở đây ảnh hưởng toàn hệ thống.

Sở hữu bảng: `user`. Hợp đồng public: `docs/04-contracts.md`.

## Ranh giới
- **Chỉ** module này được đọc/ghi `user.password_hash`. Ra ngoài chỉ đi qua `UserDto`
  (không bao giờ chứa password_hash).
- `AuthService` là nơi duy nhất biết về session. Module khác cần biết "ai đang đăng nhập"
  → gọi `AuthService::currentUserId()`, không tự đọc `$_SESSION`.

## Luật riêng
- Hash bằng `password_hash()` mặc định (bcrypt). Không MD5/SHA1. Không tự viết hàm hash.
- Đăng nhập thành công: `session_regenerate_id(true)` rồi mới ghi session.
- Session chỉ chứa `user_id` + `role`. Cần thêm thông tin thì query lại, đừng nhồi vào session
  (đổi tên/khóa tài khoản mà session còn giữ bản cũ là sinh bug).
- Sai username hay sai mật khẩu đều trả **một** message chung: "Tên đăng nhập hoặc mật khẩu không đúng."
  — không tiết lộ username có tồn tại hay không.
- `status = 0` (khóa) không đăng nhập được, và **đăng xuất session đang mở** ở lần request kế tiếp
  → `BaseController` kiểm `status` mỗi request, không chỉ lúc login.
- Xóa user: dùng `DELETE` cứng theo quyết định nghiệp vụ. Không cho admin tự xóa chính mình.
- Không xóa khi teacher còn được gán lớp hoặc student còn là thành viên lớp; admin phải chuyển/gỡ quan hệ hiện hành trước.
- Không cho admin tự đổi role hoặc tự khóa tài khoản đang đăng nhập; đổi role người khác cũng bị chặn khi còn quan hệ lớp hiện hành.
  Dữ liệu lịch sử ở module khác không cascade vì project không dùng foreign key; các màn hình lịch sử
  phải tiếp tục render tên dự phòng khi user không còn tồn tại.
- Học sinh nhỏ có thể không có email → `email` nullable, đừng đặt required trong form.

## Hay sai
- Thêm cột vào `user` rồi quên `UserDto` → module khác không thấy.
- Loop gọi `find()` cho danh sách học sinh → N+1. Dùng `findMany()`.
