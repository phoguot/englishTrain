# Rule — Kiểm thử & quality gate

Dự án **chưa có PHPUnit**. Đừng giả vờ có test suite. Có 2 lựa chọn, chọn đúng theo việc:

## A. Logic thuần → viết unit test (khi được yêu cầu thêm test)
Chỉ những chỗ này đáng test tự động, vì là logic thuần, không cần DB:
- `Assignment\Service\QuizGrader` — chấm quiz, đặc biệt: câu bỏ trống, số đáp án lệch,
  `quiz_json` hỏng, điểm làm tròn.
- `Attendance\Service\AttendanceService::summary()` — tỉ lệ chuyên cần, chia cho 0 khi chưa có buổi nào.
- `Assignment\Service\SubmissionService::avgScore()` — bỏ qua bài chưa chấm.
- Lọc HTML của `Report` — allowlist tag.

Nếu thêm PHPUnit: `composer require --dev phpunit/phpunit`, test tại `module/<Ten>/test/`,
thêm script `"test": "phpunit"` vào composer.json, và cập nhật CLAUDE.md + file này.

## B. Còn lại → kiểm tay end-to-end
CRUD, route, form, view, upload: không mock DB cho bõ công. Chạy `composer serve` và bấm thật.

## Checklist bắt buộc trước khi báo "xong" một nghiệp vụ
1. `php -l` sạch trên file đã sửa (hook tự chạy rồi).
2. Chạy `composer serve`, đi hết luồng vừa làm bằng trình duyệt.
3. Thử với **cả 3 role**: admin, teacher, student.
4. Thử truy cập dữ liệu **không thuộc về mình** (đổi `:id` trên URL sang lớp/bài của người khác)
   → phải ra 403, không được ra dữ liệu. Đây là lỗi hay gặp nhất của dự án này.
5. Submit form rỗng và form sai kiểu → phải hiện lỗi validate tiếng Việt, không fatal error.
6. Nếu đụng DB: chạy migration trên DB trống theo đúng thứ tự file, xác nhận không lỗi.

## Không được làm
- Không báo "đã test" khi mới chỉ đọc code.
- Không sửa/xóa checklist để cho qua.
- Không tạo dữ liệu giả rồi xóa DB thật. Seed để trong `data/migrations/` với tiền tố `9xxx_seed_`.
