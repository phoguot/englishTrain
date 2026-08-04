<?php

declare(strict_types=1);

namespace User\Service;

use Application\Exception\ValidationException;
use Laminas\Session\Container as SessionContainer;
use User\Filter\Auth\LoginFilter;
use User\Model\User\UserMapper;

/**
 * Nơi DUY NHẤT trong hệ thống biết về session. Module khác cần "ai đang đăng nhập"
 * thì gọi currentUserId() / currentRole(), không tự đọc $_SESSION.
 * Hợp đồng public: docs/04-contracts.md.
 */
class AuthService
{
    private const SESSION_NS = 'auth';

    private SessionContainer $session;

    private bool $lastRememberMeRequested = false;

    public function __construct(private readonly UserMapper $userMapper)
    {
        $this->session = new SessionContainer(self::SESSION_NS);
    }

    /**
     * Thử đăng nhập. Nhận POST THÔ — validate bằng LoginFilter tại đây.
     *
     * Phân biệt 2 loại lỗi:
     * - Bỏ trống / sai định dạng → ValidationException (chỉ ra field nào thiếu).
     * - Sai username HOẶC sai mật khẩu → trả false, controller hiện MỘT câu chung
     *   "Tên đăng nhập hoặc mật khẩu không đúng." — không tiết lộ username có tồn tại hay không.
     *
     * @param array<string,mixed> $data dữ liệu POST thô
     * @throws ValidationException
     */
    public function attempt(array $data): bool
    {
        $filter = new LoginFilter();
        $filter->setData($data);
        if (!$filter->isValid()) {
            throw ValidationException::fromFilterMessages($filter->getMessages());
        }

        $values   = $filter->getValues();
        $username = (string) $values['username'];
        $password = (string) $values['password'];
        $this->lastRememberMeRequested = (bool) ($values['remember_me'] ?? false);

        $user = $this->userMapper->getUserByUsername($username);
        if ($user === null || !$user->isActive()) {
            return false;
        }

        $hash = (string) $user->getPasswordHash();
        if ($hash === '' || !password_verify($password, $hash)) {
            return false;
        }

        $this->establishSession((int) $user->getId(), (int) $user->getRole());

        return true;
    }

    /** Checkbox "ghi nhớ đăng nhập" của lần attempt() gần nhất — đọc SAU KHI attempt() trả true. */
    public function wantedRememberMe(): bool
    {
        return $this->lastRememberMeRequested;
    }

    /** Đăng nhập một user nội bộ đã được xác thực bởi external identity. */
    public function loginAsUser(int $userId): bool
    {
        $user = $this->userMapper->getUser($userId);
        if ($user === null || !$user->isActive()) {
            return false;
        }
        $this->establishSession((int) $user->getId(), (int) $user->getRole());

        return true;
    }

    public function logout(): void
    {
        unset($this->session->user_id, $this->session->role, $this->session->csrf_token);
        SessionContainer::getDefaultManager()->regenerateId(true);
    }

    public function currentUserId(): ?int
    {
        return isset($this->session->user_id) ? (int) $this->session->user_id : null;
    }

    /** Vai trò đang đăng nhập — `UserModel::ROLE_*` (TINYINT), null nếu chưa đăng nhập. */
    public function currentRole(): ?int
    {
        return isset($this->session->role) ? (int) $this->session->role : null;
    }

    public function csrfToken(): string
    {
        if (!isset($this->session->csrf_token) || !is_string($this->session->csrf_token)) {
            $this->session->csrf_token = bin2hex(random_bytes(32));
        }

        return $this->session->csrf_token;
    }

    public function isValidCsrfToken(mixed $token): bool
    {
        return is_string($token)
            && isset($this->session->csrf_token)
            && is_string($this->session->csrf_token)
            && hash_equals($this->session->csrf_token, $token);
    }

    /**
     * Kiểm user còn hoạt động (status=1). BaseController gọi mỗi request để đá
     * session của user bị khóa giữa chừng. Xem module/Application/CLAUDE.md.
     */
    public function isActiveUser(int $id): bool
    {
        $user = $this->userMapper->getUser($id);

        if ($user !== null && $user->isActive()) {
            // Đồng bộ role từ DB ở mỗi request để việc hạ/nâng quyền có hiệu lực ngay,
            // không giữ role cũ trong session cho đến lần đăng nhập kế tiếp.
            $this->session->role = $user->getRole();

            return true;
        }

        return false;
    }

    private function establishSession(int $userId, int $role): void
    {
        SessionContainer::getDefaultManager()->regenerateId(true);
        unset($this->session->csrf_token);
        $this->session->user_id = $userId;
        $this->session->role = $role;
    }
}
