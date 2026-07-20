# Rule — View & UI

Website render server-side bằng PHTML + Bootstrap 5 + JS thuần. **Không SPA, không build step,
không npm.** Đừng đề xuất React/Vue/Tailwind.

## PHTML
- View nằm tại `module/<Ten>/view/<ten-thuong>/<controller>/<action>.phtml`.
- View chỉ hiển thị: không query DB, không gọi Service, không viết logic nghiệp vụ.
  Controller đưa xuống `ViewModel` cái gì thì dùng đúng cái đó.
- Escape mọi biến: `<?= $this->escapeHtml($x) ?>`.
- Phần lặp lại (bảng học sinh, badge trạng thái, ô điểm) tách thành partial,
  gọi bằng `$this->partial()`.

## Bootstrap
- Dùng class Bootstrap 5 sẵn có, không viết CSS custom trừ khi thật sự không có class tương ứng.
  CSS custom (nếu có) để trong `public/css/app.css`, không viết `style=""` inline.
- Trạng thái dùng badge thống nhất toàn hệ thống:
  - classroom: active = `bg-success`, archived = `bg-secondary`
  - draft = `bg-secondary`, published = `bg-success`, closed = `bg-dark`
  - submitted = `bg-warning text-dark`, graded = `bg-success`
  - present = `bg-success`, absent = `bg-danger`, late = `bg-warning text-dark`, excused = `bg-info`
- Form viết tay bằng HTML + class Bootstrap 5 (`form-control`, `form-label`, `is-invalid`...),
  dự án không dùng `laminas-form`. Server luôn validate lại bằng Filter class
  (`src/Filter/<Entity>/`, xem `docs/code-standards/crud-convention.md`) — HTML `required`/`pattern`
  chỉ là gợi ý cho trình duyệt, không phải chỗ kiểm tra thật.

### Luồng form server-render (POST-Redirect-Get)
Trang HTML cần render lại form kèm lỗi, khác API JSON của `webapp-be` (Service trả response).
Ở đây chia như sau — làm giống nhau ở mọi module:

1. **Controller** lấy POST **thô** (`getRequest()->getPost()->toArray()`) và đưa thẳng cho Service.
   Controller **không** `new Filter`, **không** `isValid()`, **không** kiểm giá trị.
2. **Service** chạy Filter class, sai thì ném `Application\Exception\ValidationException`
   (map field ⇒ câu tiếng Việt). Đây là **nơi duy nhất** kiểm giá trị đầu vào.
3. **Controller** bắt `ValidationException` → **render lại view** (HTTP 200) kèm dữ liệu người
   dùng vừa gõ + `errors`, tô `is-invalid` + `.invalid-feedback`.
   **Không redirect** khi lỗi (redirect là mất dữ liệu đã gõ).
4. Hợp lệ → Service lưu xong → controller **redirect** kèm flashMessenger (tránh F5 gửi lại form).

`ValidationException` **không** được BaseController tự bắt (khác `AccessDeniedException` /
`NotFoundException`) — vì lỗi validate phải quay về đúng form, không phải trang lỗi chung.

Giá trị chọn được từ dropdown/checkbox phải kiểm lại là **thuộc tập hợp lệ** — và kiểm **trong
Filter**, không kiểm bằng `if` trong Service/Controller. Ví dụ `ClassroomSaveFilter` nhận
`UserService` rồi dựng `InArray` haystack = id giáo viên đang hoạt động.
Mẫu: `Classroom\Service\ClassroomService::validate()`, `Assignment\Service\AssignmentService::validate()`.

Cấu trúc động (số câu hỏi quiz thay đổi được, mảng checkbox) không hợp với InputFilter →
tách class dựng-và-kiểm riêng ở tầng Service (`Assignment\Service\QuizJsonBuilder`),
Service gộp lỗi của nó vào cùng `ValidationException`.

## JS
- JS thuần trong `public/js/`, không framework, không bundler. Nạp bằng `<script>` cuối layout.
- JS chỉ lo tương tác: upload video R2 (progress bar), submit bảng điểm danh, xác nhận xóa.
- Gọi API nội bộ bằng `fetch`, luôn xử lý nhánh lỗi và hiện message cho user.
- Không đặt logic nghiệp vụ (tính điểm, check quyền) ở JS — server luôn kiểm lại.

## Thông báo & ngôn ngữ
- Message cho user viết **tiếng Việt**, có dấu, câu hoàn chỉnh.
  "Đã lưu điểm danh buổi 17/07/2026." — không phải "Success!".
- Thông báo sau redirect dùng flashMessenger, không nhồi vào query string.
- Lỗi hệ thống hiện câu chung chung cho user, chi tiết ghi log — không phơi stack trace.

## Accessibility tối thiểu
- Mọi input có `<label>` gắn `for`. Nút icon-only phải có `aria-label`.
- Bảng dữ liệu có `<th scope="col">`.
