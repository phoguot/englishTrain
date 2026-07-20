# 02 — Schema MySQL (nguồn sự thật)

Charset: utf8mb4 / utf8mb4_unicode_ci. Engine: InnoDB. Mọi bảng có `created_at`, `updated_at`.
Không khai báo `FOREIGN KEY` constraint giữa các bảng; cột liên kết dùng `INT UNSIGNED`
và giao tiếp qua index, quyền/logic toàn vẹn dữ liệu kiểm tra ở Service/Mapper.

## user
| Cột            | Kiểu                                   | Ghi chú                         |
|----------------|----------------------------------------|---------------------------------|
| id             | INT UNSIGNED AUTO_INCREMENT PK         |                                 |
| role           | ENUM('admin','teacher','student')      |                                 |
| full_name      | VARCHAR(100)                           |                                 |
| email          | VARCHAR(150) NULL, UNIQUE              | học sinh nhỏ có thể không có    |
| phone          | VARCHAR(20) NULL                       | liên hệ phụ huynh               |
| username       | VARCHAR(50) UNIQUE                     | đăng nhập                       |
| password_hash  | VARCHAR(255)                           | password_hash() bcrypt          |
| status         | TINYINT DEFAULT 1                      | 1 active, 0 khóa                |

## classroom
| id | INT UNSIGNED AUTO_INCREMENT PK | |
| name | VARCHAR(100) | ví dụ "IELTS 6.5 tối T3-T5" |
| teacher_id | INT UNSIGNED | giáo viên phụ trách, index tới `user.id` |
| schedule_note | VARCHAR(255) NULL | mô tả lịch học dạng text |
| status | ENUM('active','archived') DEFAULT 'active' | |

Index: (teacher_id)

## classroom_student  (n-n)
| classroom_id | INT UNSIGNED | PK gộp (classroom_id, student_id), index tới `classroom.id` |
| student_id   | INT UNSIGNED | index tới `user.id` |
| joined_at    | DATE                | |

Index: PRIMARY (classroom_id, student_id), (student_id)

## assignment
| id | INT UNSIGNED AUTO_INCREMENT PK | |
| classroom_id | INT UNSIGNED | index tới `classroom.id` |
| teacher_id | INT UNSIGNED | người tạo, index tới `user.id` |
| title | VARCHAR(200) | |
| description | TEXT NULL | đề bài |
| type | ENUM('video','quiz','essay') | |
| quiz_json | JSON NULL | chỉ dùng khi type=quiz: [{question, options[], correct_index}] |
| deadline_at | DATETIME NULL | |
| status | ENUM('draft','published','closed') DEFAULT 'draft' | |

Index: (classroom_id, status, deadline_at), (teacher_id)

## submission
| id | INT UNSIGNED AUTO_INCREMENT PK | |
| assignment_id | INT UNSIGNED | index tới `assignment.id` |
| student_id | INT UNSIGNED | index tới `user.id`, UNIQUE (assignment_id, student_id) |
| status | ENUM('submitted','graded') | không nộp = không có row |
| video_key | VARCHAR(255) NULL | object key trên R2 |
| video_size | INT UNSIGNED NULL | bytes |
| essay_text | MEDIUMTEXT NULL | |
| quiz_answers | JSON NULL | mảng index đáp án học sinh chọn |
| auto_score | DECIMAL(5,2) NULL | quiz chấm tự động |
| score | DECIMAL(5,2) NULL | điểm cuối giáo viên xác nhận |
| feedback | TEXT NULL | nhận xét |
| submitted_at | DATETIME | |
| graded_at | DATETIME NULL | |

Index: (assignment_id, status), (student_id, submitted_at)

## attendance_session
| id | INT UNSIGNED AUTO_INCREMENT PK | |
| classroom_id | INT UNSIGNED | index tới `classroom.id` |
| session_date | DATE | UNIQUE (classroom_id, session_date) |
| note | VARCHAR(255) NULL | |
| created_by | INT UNSIGNED | index tới `user.id` |

Index: UNIQUE (classroom_id, session_date), (created_by)

## attendance_session_student
| session_id | INT UNSIGNED | PK ghép, index logic tới `attendance_session.id` |
| student_id | INT UNSIGNED | PK ghép, index tới `user.id` |
| created_at | DATETIME | thời điểm chụp roster |
| updated_at | DATETIME | theo quy ước chung; roster không sửa sau khi tạo |

Roster bất biến được chụp cùng transaction tạo buổi học. Nhờ vậy việc gỡ học sinh khỏi lớp hoặc xóa
cứng tài khoản không làm thay đổi danh sách lịch sử của buổi.

Index: PRIMARY (session_id, student_id), (student_id)

## attendance_record
| id | INT UNSIGNED AUTO_INCREMENT PK | |
| session_id | INT UNSIGNED | index tới `attendance_session.id`, UNIQUE (session_id, student_id) |
| student_id | INT UNSIGNED | index tới `user.id` |
| status | ENUM('present','absent','late','excused') | |
| note | VARCHAR(255) NULL | |

Index: UNIQUE (session_id, student_id), (student_id)

## student_report
| id | INT UNSIGNED AUTO_INCREMENT PK | |
| student_id | INT UNSIGNED | index tới `user.id` |
| classroom_id | INT UNSIGNED | index tới `classroom.id` |
| teacher_id | INT UNSIGNED | index tới `user.id` |
| period_label | VARCHAR(50) | kỳ theo tháng, định dạng `YYYY-MM`, ví dụ `2026-07` |
| content | MEDIUMTEXT | HTML đơn giản từ editor |
| status | ENUM('draft','published') DEFAULT 'draft' | |
| published_at | DATETIME NULL | |

Index: (student_id, status, published_at), (classroom_id), (teacher_id)

## Index phụ (đã có trong 0001_init.sql, ngoài các index ghi ở từng bảng)
Mỗi cột liên kết (thay cho FK constraint) đều có index để join không quét bảng:
- `classroom`: (`teacher_id`)
- `classroom_student`: (`student_id`) — chiều tra "học sinh này thuộc lớp nào"
- `assignment`: (`teacher_id`)
- `attendance_session`: (`created_by`)
- `attendance_record`: (`student_id`)
- `attendance_session_student`: (`student_id`)
- `student_report`: (`classroom_id`), (`teacher_id`)

## Ghi chú thiết kế
- `user.status` vẫn dùng để khóa/mở tài khoản. Khi admin chọn xóa user thì xóa cứng row `user`;
  phải chuyển/gỡ quan hệ lớp hiện hành trước. Dữ liệu lịch sử ở bảng khác không cascade vì schema
  không dùng foreign key; roster điểm danh vẫn giữ id để tái dựng buổi cũ.
- `classroom.status` dùng để lưu trữ lớp thay cho xóa cứng classroom.
- `quiz_json` để trong assignment thay vì tách bảng question: quy mô nhỏ,
  quiz ngắn, đọc/ghi nguyên khối — tách bảng khi cần ngân hàng câu hỏi dùng lại.
- Các cột video được giữ cho backlog production; khi triển khai R2 chỉ lưu key, URL xem tạo runtime
  bằng presigned GET có hạn 1h.
- Migration SQL đặt tại `data/migrations/0001_init.sql` trở đi, đánh số tăng dần.
