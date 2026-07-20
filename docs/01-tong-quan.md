# 01 — Tổng quan kiến trúc EnglishTrain

## Bối cảnh
Trung tâm tiếng Anh nhỏ, mỗi lớp 5-10 học sinh, tổng vài trăm user.
Chọn **website monolith Laminas** thay vì mobile app: một codebase, deploy một chỗ,
học sinh làm quiz/essay trên website. Upload video qua R2 là backlog sau khi production sẵn sàng.
Múi giờ nghiệp vụ thống nhất là `Asia/Bangkok` (UTC+7); các cột `DATETIME` được hiểu theo múi giờ này.

## Kiến trúc

```
Trình duyệt (PC giáo viên / điện thoại học sinh)
        │  HTML form + fetch JSON (một số thao tác)
        ▼
Laminas monolith (module hóa theo nghiệp vụ)
        │                      │
        ▼                      ▼
     MySQL 8            Cloudflare R2 (backlog production)
                        sẽ upload trực tiếp qua presigned URL khi triển khai
```

## Ba luồng nghiệp vụ chính

### 1. Giao bài → nộp bài → chấm
1. Giáo viên tạo assignment gắn với classroom, chọn loại: `video` | `quiz` | `essay`, đặt deadline.
2. Học sinh trong lớp thấy bài trên dashboard.
   - Loại `quiz`: câu hỏi + đáp án lưu JSON trong assignment; nộp xong hệ thống chấm tự động, ghi điểm ngay.
   - Loại `video`: hiện hiển thị trạng thái chưa hỗ trợ. Luồng presigned URL chỉ triển khai sau khi
     production có credential và CORS R2.
   - Loại `essay`: textarea, lưu text vào submission.
3. Giáo viên mở danh sách submission theo assignment, xem video/bài làm, nhập điểm + nhận xét.
4. Trạng thái submission: `submitted → graded`. "Chưa nộp" = **không có row** submission
   (không phải một giá trị enum — xem `docs/02-database-schema.md`).

### 2. Điểm danh
1. Giáo viên tạo attendance_session cho buổi học (ngày, lớp).
2. Màn hình điểm danh liệt kê học sinh của lớp, mỗi em chọn: `present` | `absent` | `late` | `excused`.
3. Lưu mỗi học sinh một attendance_record. Có thể sửa lại trong ngày.

### 3. Report học sinh
1. Giáo viên viết report cho từng học sinh theo tháng (`YYYY-MM`), nội dung rich text đơn giản.
2. Report có trạng thái `draft` (chỉ giáo viên thấy) và `published` (học sinh/phụ huynh thấy).
3. Trang report tổng hợp kèm: tỉ lệ chuyên cần (từ attendance), điểm trung bình (từ submission).

## Quy tắc phân quyền
| Hành động                       | admin | teacher | student |
|---------------------------------|:-----:|:-------:|:-------:|
| Quản lý user, lớp               |   x   |         |         |
| Tạo/sửa bài tập, chấm điểm      |       | lớp mình|         |
| Xem điểm danh                   |   x   | lớp mình| chính mình |
| Ghi điểm danh, viết report      |       | lớp mình|         |
| Xem bài, nộp bài                |       |         | lớp mình|
| Xem report                      |   x   | lớp mình| published của mình |

## Những thứ CHƯA làm ở giai đoạn này (tránh over-engineering)
- Không queue/worker, không transcode video (giới hạn upload 200MB là đủ)
- Không push notification; thông báo qua Zalo nhóm lớp thủ công
- Không API mobile riêng — nếu sau này cần app, tách dần controller trả JSON
- Chưa triển khai upload/xem video R2; thực hiện sau khi cấu hình production sẵn sàng
