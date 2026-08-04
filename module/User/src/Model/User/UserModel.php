<?php

declare(strict_types=1);

namespace User\Model\User;

/**
 * POPO nội bộ module User. CHỨA password_hash → tuyệt đối không trả thẳng ra ngoài module.
 * Ra khỏi module chỉ đi qua UserDto (không có password_hash). Xem module/User/CLAUDE.md.
 */
class UserModel
{
    // `user.role` là TINYINT — Ý NGHĨA TỪNG SỐ CHỈ KHAI Ở ĐÂY, mọi module so bằng hằng này.
    // Không có "guest": chưa đăng nhập là KHÔNG có user, xem BaseController::ROLE_GUEST.

    public const ROLE_ADMIN   = 1;
    public const ROLE_TEACHER = 2;
    public const ROLE_STUDENT = 3;

    /** @var array<int,string> mã vai trò => nhãn tiếng Việt (dropdown, bảng danh sách) */
    public const ROLE_LABELS = [
        self::ROLE_ADMIN   => 'Quản trị',
        self::ROLE_TEACHER => 'Giáo viên',
        self::ROLE_STUDENT => 'Học sinh',
    ];

    // `user.teacher_type` là TINYINT — Ý NGHĨA TỪNG SỐ CHỈ KHAI Ở ĐÂY.
    // Thêm loại mới: cấp số tiếp theo + thêm nhãn vào TEACHER_TYPE_LABELS, không phải ALTER TABLE.
    // Không bao giờ viết số trần (1, 2) ở Service, Filter, view hay SQL.

    /** Giáo viên tiếng Anh — loại duy nhất được giao bài tập dạng video. */
    public const TEACHER_TYPE_ENGLISH = 1;

    /** Giáo viên môn khác — điểm danh, học phí, bài tự luận/trắc nghiệm; không giao bài video. */
    public const TEACHER_TYPE_GENERAL = 2;

    /** @var array<int,string> giá trị hợp lệ => nhãn tiếng Việt (dùng cho dropdown + Filter) */
    public const TEACHER_TYPE_LABELS = [
        self::TEACHER_TYPE_ENGLISH => 'Giáo viên tiếng Anh (giao được bài video)',
        self::TEACHER_TYPE_GENERAL => 'Giáo viên khác (điểm danh, học phí, bài không video)',
    ];

    private ?int $id = null;
    /** Xem ROLE_*. */
    private ?int $role = null;
    /** Chỉ có nghĩa khi role = 'teacher'; role khác luôn null. Xem TEACHER_TYPE_*. */
    private ?int $teacherType = null;
    private ?string $fullName = null;
    private ?string $email = null;
    private ?string $phone = null;
    private ?string $username = null;
    private ?string $passwordHash = null;
    private int $status = 1;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    public function getRole(): ?int
    {
        return $this->role;
    }

    public function setRole(?int $role): void
    {
        $this->role = $role;
    }

    public function getTeacherType(): ?int
    {
        return $this->teacherType;
    }

    public function setTeacherType(?int $teacherType): void
    {
        $this->teacherType = $teacherType;
    }

    public function getFullName(): ?string
    {
        return $this->fullName;
    }

    public function setFullName(?string $fullName): void
    {
        $this->fullName = $fullName;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): void
    {
        $this->email = $email;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): void
    {
        $this->phone = $phone;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(?string $username): void
    {
        $this->username = $username;
    }

    public function getPasswordHash(): ?string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(?string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): void
    {
        $this->status = $status;
    }

    public function isActive(): bool
    {
        return $this->status === 1;
    }

    /** Hydrate từ 1 row DB. Chỉ Mapper gọi. */
    public function exchangeArray(array $row): self
    {
        $this->id           = isset($row['id']) ? (int) $row['id'] : null;
        $this->role         = ($row['role'] ?? null) !== null ? (int) $row['role'] : null;
        $this->teacherType  = ($row['teacher_type'] ?? null) !== null ? (int) $row['teacher_type'] : null;
        $this->fullName     = $row['full_name'] ?? null;
        $this->email        = $row['email'] ?? null;
        $this->phone        = $row['phone'] ?? null;
        $this->username     = $row['username'] ?? null;
        $this->passwordHash = $row['password_hash'] ?? null;
        $this->status       = isset($row['status']) ? (int) $row['status'] : 1;

        return $this;
    }

    /** Bản chiếu an toàn để truyền ra ngoài module — KHÔNG có password_hash. */
    public function toDto(): UserDto
    {
        return new UserDto(
            (int) $this->id,
            (int) $this->role,
            (string) $this->fullName,
            (string) $this->username,
            $this->status,
            $this->teacherType,
        );
    }
}
