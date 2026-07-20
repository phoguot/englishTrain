-- Seed 3 tài khoản để đăng nhập thử 3 role. CHỈ dùng ở môi trường dev.
-- Mật khẩu (bcrypt): admin=admin123, teacher=teacher123, student=student123 — ĐỔI trước khi lên prod.
-- Chạy sau 0001_init.sql.

INSERT INTO `user` (`role`, `full_name`, `email`, `phone`, `username`, `password_hash`, `status`) VALUES
('admin',   'Quản trị viên',  'admin@example.com',   NULL, 'admin',   '$2y$10$u1XQJZZxZFCS7LUrqH.iQe/y/IhLh86nv8JiBqlsSi1WyFqEznqEK', 1),
('teacher', 'Cô Lan',         'lan@example.com',     NULL, 'teacher', '$2y$10$EX25J1rOkGwl71rXFQIHzeiw47N1/Oq2QYJ0eDhGFeC7fYV5b3cK.', 1),
('student', 'Nguyễn Văn An',  NULL,                  NULL, 'student', '$2y$10$H0tc8.83e5BMddI.tYUK2uWQcgM3LT8RyTce5HukhqVRPJ8g/lo.G', 1);
