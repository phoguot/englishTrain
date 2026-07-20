# Rule — Database & truy vấn

Nguồn sự thật về schema: `docs/02-database-schema.md`. Sửa schema là sửa file đó **trước**,
rồi mới viết migration và code.

## Migration
- File đặt tại `data/migrations/NNNN_mo_ta_ngan.sql`, số tăng dần 4 chữ số, không sửa file đã chạy.
- Mỗi migration tự đủ, chạy tay theo thứ tự tên file. Không có công cụ rollback tự động
  → migration phá dữ liệu (DROP/MODIFY thu hẹp kiểu) phải ghi rõ cảnh báo ở đầu file.
- Migration mới phải cập nhật `docs/02-database-schema.md` trong cùng lần thay đổi.

## Quy ước
- Tên bảng snake_case số ít: `user`, `assignment`, `attendance_record`.
- Mọi bảng có `created_at`, `updated_at`. Charset utf8mb4_unicode_ci, engine InnoDB.
- Không khai báo `FOREIGN KEY`/constraint giữa bảng. Cột liên kết chỉ giao tiếp qua index;
  quyền và toàn vẹn dữ liệu kiểm tra ở Service/Mapper.
- Tiền/điểm dùng DECIMAL, không FLOAT. Ngày giờ dùng DATETIME (không TIMESTAMP).

## Truy vấn — chỉ trong Mapper
Cấu trúc file: `docs/code-standards/crud-convention.md`.

- SQL **chỉ** nằm trong `src/Model/<Entity>/<Entity>Mapper.php`. Service gọi Mapper, không tự viết SQL.
- Controller **không** được đụng Mapper. Controller → Service → Mapper → Model.
- Không nối chuỗi SQL. Dùng `Laminas\Db\Sql` với placeholder (`['t.id = ?' => (int) $id]`).
- Mapper CRUD nhận và trả **Model**, không trả `Select` ra ngoài. Query tổng hợp/batch được trả scalar
  hoặc read-model array có shape khai báo rõ trong PHPDoc (ví dụ count theo trạng thái, map id => số lượng).
- Tên method Mapper theo nghiệp vụ + tên entity: `getSubmission()`, `searchSubmission()`,
  `saveSubmission()`, `deleteSubmission()`. Sửa 1-2 cột → viết `updateAttrsSubmission()` riêng,
  không gọi `saveSubmission()` chung.
- Hằng tên bảng (tương thích PHP 8.2): `const TABLE_NAME = 'submission';`, tham chiếu bằng
  `SubmissionMapper::TABLE_NAME` (không `self::TABLE_NAME`).

### Khác với dự án webapp-be — đọc kỹ nếu bạn quen project đó
Convention CRUD lấy từ `webapp-be`, nhưng **bỏ** các thứ chỉ đúng ở đó:

| webapp-be | EnglishTrain |
|---|---|
| `getBusinessReplicaAdapter()` / `getBusinessMasterAdapter()` | **1 adapter duy nhất** — không master/replica |
| `businessId` scope mọi query | **`classroom_id`** — check bằng `isTeacherOf()`/`isStudentIn()` (`docs/04-contracts.md`) |
| `LastIdPaginator` | **Không phân trang** — 5-10 học sinh/lớp, trả mảng Model |
| `DateModel::getTimeStampsCurrent()` | Chưa có lớp này — dùng DATETIME MySQL |
| `AppMapper` / `AppModel` base class | **Chưa có** — viết class thuần, xem §"Base class" bên dưới |

### Base class
`webapp-be` có `AppMapper`, `AppModel`, `AppFilter`, `AppInvokableFactory`. EnglishTrain **không có**.
Đừng `extends` chúng — sẽ fatal error. Khi thấy lặp lại đủ nhiều thì mới rút base class
vào `module/Application/src/Model/`, và ghi vào file này.

## Hiệu năng (quy mô 5-10 học sinh/lớp — đừng tối ưu sớm)
- Tránh N+1 ở các trang danh sách: dashboard, danh sách nộp bài, bảng điểm danh.
  Lấy 1 query có join hoặc `WHERE id IN (...)`, không loop query.
- Chỉ thêm index khi có truy vấn thật cần. Index hiện có liệt kê trong `docs/02-database-schema.md`.
- Chưa dùng cache/Redis. Đừng thêm.

## Transaction
- Thao tác ghi nhiều bảng phải bọc transaction, ví dụ:
  - lưu cả bảng điểm danh của một buổi (n `attendance_record` cùng thành công hoặc cùng hỏng).

`attendance_session` và snapshot roster `attendance_session_student` được tạo cùng transaction, ở
request riêng trước khi giáo viên mở bảng điểm danh. Một buổi chưa có `attendance_record` là trạng
thái hợp lệ "chưa điểm danh", không phải transaction dở dang.
  - nộp bài quiz (tạo `submission` + tính `auto_score`).
- Không gọi API ngoài (R2) bên trong transaction.
