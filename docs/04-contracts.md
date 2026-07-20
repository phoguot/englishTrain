# 04 — Hợp đồng liên module (nguồn sự thật)

Dự án 6 module. File này quy định **cách duy nhất** một module được lấy dữ liệu của module khác.

## Luật nền
1. Module **không** gọi `Model/*/*Mapper.php` của module khác. Không query thẳng bảng người khác.
2. Chỉ gọi **method public đã ghi trong file này**. Không có trong đây = không được gọi.
3. Đổi chữ ký method trong bảng dưới = **breaking change**: sửa file này trước,
   grep hết chỗ gọi, sửa hết, rồi mới đổi code.
4. Thêm method mới cho module khác dùng → thêm vào Service **chủ sở hữu dữ liệu**,
   ghi vào đây, rồi mới gọi.
5. Không có DI vòng tròn. Chiều phụ thuộc cho phép:

```
Application  ← ai cũng dùng được (BaseController, layout)
User         ← ai cũng dùng được (danh tính, role)
   ↑
Classroom    ← Assignment, Attendance, Report dùng
   ↑
Assignment ─┐
Attendance ─┴→ Report   (Report đọc 2 module này, KHÔNG có chiều ngược lại)
```
`Assignment` và `Attendance` **không biết gì về nhau**. Cần ghép số liệu 2 bên → việc của `Report`.

## Ai sở hữu dữ liệu gì
| Module | Sở hữu bảng | Không ai khác được ghi |
|--------|-------------|------------------------|
| User | `user` | ✔ |
| Classroom | `classroom`, `classroom_student` | ✔ |
| Assignment | `assignment`, `submission` | ✔ |
| Attendance | `attendance_session`, `attendance_session_student`, `attendance_record` | ✔ |
| Report | `student_report` | ✔ |

## Hợp đồng đang có

### User\Service\AuthService
| Method | Trả về | Ghi chú |
|--------|--------|---------|
| `currentUserId(): ?int` | id hoặc null nếu chưa đăng nhập | |
| `currentRole(): ?string` | `admin` \| `teacher` \| `student` | |
| `isActiveUser(int $id): bool` | user còn `status=1` không — `Application\BaseController` gọi mỗi request để đá session của user bị khóa giữa chừng | |
| `csrfToken(): string` | token CSRF gắn với session hiện tại | module có form thay đổi dữ liệu |
| `isValidCsrfToken(mixed $token): bool` | kiểm token bằng so sánh timing-safe | module có form thay đổi dữ liệu |
Không module nào ngoài `User` được đọc cột `password_hash`.
`AuthService` cũng có `attempt(username, password): bool` và `logout(): void`, nhưng đó là nội bộ
luồng đăng nhập của module User — module khác không gọi.

### User\Service\UserService
| Method | Trả về |
|--------|--------|
| `find(int $id): ?UserDto` | thông tin hiển thị 1 người |
| `findMany(array $ids): array` | map `id => UserDto`, **dùng cái này để tránh N+1**, đừng loop `find()` |
| `findByRole(string $role): UserDto[]` | user đang hoạt động (`status=1`) theo role, sắp theo tên — cho dropdown giáo viên / checkbox học sinh |

`UserDto`: `{id, role, full_name, status}`. **Không** chứa `password_hash`.

### Classroom\Service\ClassroomService
| Method | Trả về | Ai gọi |
|--------|--------|--------|
| `find(int $id): ?ClassroomDto` | | Assignment, Attendance, Report |
| `findMany(array $ids): array` | map `id => ClassroomDto`, tránh N+1 khi hiển thị nhiều lớp | Report |
| `studentIds(int $classroomId): int[]` | id học sinh trong lớp | Attendance, Report |
| `isTeacherOf(int $teacherId, int $classroomId): bool` | | Assignment, Attendance, Report |
| `isStudentIn(int $studentId, int $classroomId): bool` | | Assignment, Attendance, Report |
| `listForStudent(int $studentId): array` | lớp **đang hoạt động** học sinh đang theo học: `[{id, name, teacherName}]` — cho dashboard/lối vào bài tập | Application (dashboard) |
| `listRowsForActor(int $userId, string $role): array` | lớp theo actor: admin thấy tất cả, teacher chỉ lớp phụ trách; kèm `teacherName`, `studentCount`, `status` | Classroom, Application (dashboard) |
| `hasCurrentUserReference(int $userId): bool` | user còn là giáo viên phụ trách hoặc thành viên lớp hiện tại, không phụ thuộc role hiện tại | Application (quản trị tài khoản) |

`ClassroomDto`: `{id, name, teacher_id, status}`.

> 2 method `isTeacherOf` / `isStudentIn` là **xương sống của phân quyền**. Mọi module khi nhận
> `classroom_id` từ request đều phải gọi một trong hai trước khi làm gì tiếp
> (xem `.claude/rules/01-bao-mat.md`).

### Assignment\Service\SubmissionService
| Method | Trả về | Ai gọi |
|--------|--------|--------|
| `avgScore(int $studentId, int $classroomId, string $periodLabel): ?float` | điểm TB, `null` nếu chưa có bài chấm | Report |
| `countByStatus(int $studentId, int $classroomId, string $periodLabel): array` | `{submitted:int, graded:int, missed:int}` | Report |

- Chỉ tính bài `status='graded'`; bài `submitted` chưa chấm **không** kéo điểm xuống.
- Điểm trung bình lọc theo `graded_at`; số lượng trạng thái/missed lọc theo deadline của bài
  (bài không deadline dùng `created_at`) trong tháng `periodLabel`.
- `missed` = bài `published` đã quá `deadline_at` mà không có row submission.
- `periodLabel` dùng chung định dạng với `student_report.period_label` (ví dụ `2026-07`).

### Assignment\Service\AssignmentService
| Method | Trả về | Ai gọi |
|--------|--------|--------|
| `dashboardForTeacher(int $teacherId): array` | tối đa 5 bài của lớp active đang có submission chờ chấm: `{id,title,classroomId,classroomName,submittedCount,deadlineAt,lastSubmittedAt}` | Application |
| `dashboardForStudent(int $studentId): array` | `{upcoming: [...], graded: [...]}`; mỗi nhóm tối đa 5 bài published thuộc lớp active của học sinh | Application |

- `upcoming`: bài có deadline trong tương lai, sắp gần nhất trước; bài không deadline không tính là “sắp đến hạn”.
- Dashboard teacher xếp theo `lastSubmittedAt` mới nhất trước để bài vừa có học sinh nộp không bị khuất.
- `graded`: bài của học sinh có submission `graded`, mới chấm gần nhất trước; chỉ trả `score`, không trả `auto_score`.
- Hai method xử lý theo batch nhiều lớp, Application không được loop lớp rồi gọi truy vấn từng lớp.

### Attendance\Service\AttendanceService
| Method | Trả về | Ai gọi |
|--------|--------|--------|
| `summary(int $studentId, int $classroomId, string $periodLabel): AttendanceSummary` | | Report |

`AttendanceSummary` (`Attendance\Model\AttendanceSummary`, final readonly) — tên thuộc tính trong
code viết **camelCase** như mọi DTO khác của dự án:
`{totalSessions:int, present:int, absent:int, late:int, excused:int, rate:?float}`

- `rate` = `(present + late) / totalSessions`, làm tròn 2 số. `totalSessions = 0` → `rate = null`
  (**không** trả 0, tránh hiểu nhầm "chuyên cần 0%"). Dùng `getRateForHuman()` để in ra view
  ("80%" hoặc "—") — **đừng tự nhân 100 ở view**.
- `totalSessions` chỉ đếm buổi mà học sinh **có record**. Em vào lớp giữa kỳ thì các buổi trước
  đó là *thiếu record*, không phải vắng, và không vào mẫu số.
- `late` tính là **có đi học** (ở tử số); `excused` không ở tử số nhưng vẫn ở mẫu số.
- `periodLabel` **chỉ theo tháng**, định dạng `2026-07` — dự án không làm report theo quý.
  Nhãn khác dạng đó → `summary()` tính **tất cả** buổi của học sinh trong lớp, không lọc kỳ.
  `student_report.period_label` phải dùng đúng định dạng này.

### Report
Report **chỉ đọc**, không module nào gọi ngược vào Report.

## Backlog HTTP (JSON) — module Assignment / R2
Các endpoint dưới đây **chưa được triển khai và chưa phải hợp đồng đang hoạt động**. Chỉ chuyển thành
hợp đồng chính thức khi production đã có credential/CORS R2 và code được triển khai đầy đủ.

`POST /assignments/:id/upload-url`
```
→ {filename: string, size: int, mime: string}
← 200 {url: string, key: string, expires_in: int}
← 403 {error: "Bạn không thuộc lớp này."}
← 422 {error: "File quá lớn (tối đa 200MB)."}   // hạn đọc từ config r2.max_upload_mb, không hardcode
```

`POST /assignments/:id/upload-done`
```
→ {key: string, size: int}
← 200 {submission_id: int, status: "submitted"}
← 409 {error: "Bài tập đã đóng."}
```

Quy ước chung cho mọi endpoint JSON: lỗi luôn là `{error: string}`, thông điệp **tiếng Việt**,
hiển thị thẳng được cho user. Không trả stack trace, không trả mã lỗi trần.

## Giá trị enum (dùng thống nhất mọi module)
Enum là hợp đồng — đổi giá trị phải sửa DB + code + badge màu cùng lúc.

| Nhóm | Giá trị |
|------|---------|
| role | `admin` \| `teacher` \| `student` |
| assignment.type | `video` \| `quiz` \| `essay` |
| assignment.status | `draft` \| `published` \| `closed` |
| submission.status | `submitted` \| `graded` |
| attendance_record.status | `present` \| `absent` \| `late` \| `excused` |
| student_report.status | `draft` \| `published` |
| classroom.status | `active` \| `archived` |

Màu badge tương ứng: `.claude/rules/03-view-ui.md`.
