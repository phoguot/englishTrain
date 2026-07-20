-- Chụp danh sách học sinh tại lúc tạo buổi để lịch sử không phụ thuộc membership hiện tại.
CREATE TABLE `attendance_session_student` (
    `session_id` INT UNSIGNED NOT NULL,
    `student_id` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`session_id`, `student_id`),
    KEY `idx_attendance_session_student_student_id` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill tốt nhất có thể cho buổi đã tồn tại trước migration. Record cũ luôn được ưu tiên ở code.
INSERT IGNORE INTO `attendance_session_student` (`session_id`, `student_id`)
SELECT s.id, cs.student_id
FROM `attendance_session` AS s
INNER JOIN `classroom_student` AS cs
    ON cs.classroom_id = s.classroom_id
   AND cs.joined_at < DATE_ADD(s.session_date, INTERVAL 1 DAY);

INSERT IGNORE INTO `attendance_session_student` (`session_id`, `student_id`)
SELECT session_id, student_id FROM `attendance_record`;
