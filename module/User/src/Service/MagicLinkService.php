<?php

declare(strict_types=1);

namespace User\Service;

use Application\Exception\AccessDeniedException;
use Application\Exception\NotFoundException;
use Application\Exception\ValidationException;
use Laminas\Db\Adapter\Adapter;
use Throwable;
use User\Model\User\UserMapper;
use User\Model\User\UserModel;
use User\Model\UserLoginToken\UserLoginTokenMapper;
use User\Model\UserLoginToken\UserLoginTokenModel;
use User\Model\OAuthLinkThrottle\OAuthLinkThrottleMapper;

/**
 * Đăng nhập bằng magic-link: admin hoặc teacher tạo, học sinh/phụ huynh lớn tuổi bấm là vào thẳng.
 * TTL 15 phút, dùng một lần. Khác `UserLinkTokenService` (dùng để liên kết OAuth): đây là đăng nhập
 * thay mật khẩu, không phải để liên kết identity provider. Xem module/User/CLAUDE.md.
 *
 * Consume ở luồng `AuthController::magicLoginAction()`: nơi đó kiểm CSRF-free (GET), gọi
 * `AuthService::loginAsUser()` + phát cookie remember-me + redirect sang URL sạch.
 */
class MagicLinkService
{
    private const TOKEN_LIFETIME_MINUTES = 15;
    private const THROTTLE_WINDOW_SECONDS = 900;
    private const THROTTLE_MAX_ATTEMPTS = 10;
    private const THROTTLE_NAMESPACE = 'magic-login:';

    public function __construct(
        private readonly UserLoginTokenMapper $tokenMapper,
        private readonly OAuthLinkThrottleMapper $throttleMapper,
        private readonly UserMapper $userMapper,
        private readonly Adapter $adapter,
    ) {
    }

    /**
     * Tạo magic-link cho $targetUserId. Trả token phần công khai (32 hex) — nơi gọi ghép vào URL
     * kiểu `/login/<token>`. Không lưu token gốc, chỉ lưu SHA-256.
     *
     * Quyền (kiểm ở đây):
     *  - admin  → tạo cho user không phải admin, không tự tạo cho chính mình
     *  - teacher → chỉ cho học sinh (target role = UserModel::ROLE_STUDENT)
     *
     * Ràng buộc "teacher này có dạy student này không" thuộc kiến thức của module Classroom
     * (module User đứng dưới Classroom trong sơ đồ phụ thuộc — xem docs/04-contracts.md), nên
     * việc kiểm relationship đó do CONTROLLER phối hợp `ClassroomService::teachesStudent()`
     * TRƯỚC KHI gọi method này.
     */
    public function create(int $targetUserId, int $creatorUserId, int $creatorRole): string
    {
        if ($targetUserId <= 0) {
            throw new NotFoundException('Tài khoản không tồn tại.');
        }
        if ($targetUserId === $creatorUserId) {
            throw new AccessDeniedException('Không thể tạo link đăng nhập cho chính mình.');
        }

        $connection = $this->adapter->getDriver()->getConnection();
        $connection->beginTransaction();
        try {
            $this->userMapper->lockUser($targetUserId);
            $user = $this->userMapper->getUser($targetUserId);
            if ($user === null) {
                throw new NotFoundException('Tài khoản không tồn tại.');
            }
            if (!$user->isActive()) {
                throw new AccessDeniedException('Tài khoản đang bị khóa, không thể tạo link đăng nhập.');
            }

            $this->assertCreatorMayIssueFor($creatorRole, (int) $user->getRole());

            $this->tokenMapper->revokeUnusedByUser($targetUserId);

            $plain = bin2hex(random_bytes(16));
            $token = new UserLoginTokenModel();
            $token->setUserId($targetUserId);
            $token->setTokenHash(hash('sha256', $plain));
            $token->setExpiresAt(date('Y-m-d H:i:s', time() + self::TOKEN_LIFETIME_MINUTES * 60));
            $token->setCreatedBy($creatorUserId);
            $this->tokenMapper->saveUserLoginToken($token);

            $connection->commit();

            return $plain;
        } catch (Throwable $e) {
            $connection->rollback();
            throw $e;
        }
    }

    /**
     * Đổi token lấy user_id. Nơi gọi (controller) tự dựng session + phát cookie remember-me.
     * Throttle theo IP để chống dò token; namespace riêng để không đụng bộ đếm của luồng OAuth link.
     * Ném `ValidationException` để giữ URL sạch nhưng vẫn báo cho người dùng lý do.
     */
    public function consume(string $token, string $ipAddress): int
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw new ValidationException(['token' => 'Link đăng nhập không hợp lệ hoặc đã hết hạn.']);
        }
        if (!$this->throttleMapper->recordAttempt(
            hash('sha256', self::THROTTLE_NAMESPACE . $ipAddress),
            self::THROTTLE_WINDOW_SECONDS,
            self::THROTTLE_MAX_ATTEMPTS,
        )) {
            throw new ValidationException(['token' => 'Bạn đã thử quá nhiều lần. Vui lòng thử lại sau.']);
        }

        $stored = $this->tokenMapper->getByHash(hash('sha256', $token));
        if ($stored === null || !$stored->isUsable()) {
            throw new ValidationException(['token' => 'Link đăng nhập không hợp lệ hoặc đã hết hạn.']);
        }

        $userId = (int) $stored->getUserId();
        $user = $this->userMapper->getUser($userId);
        if ($user === null || !$user->isActive()) {
            throw new ValidationException(['token' => 'Link đăng nhập không hợp lệ hoặc đã hết hạn.']);
        }

        if (!$this->tokenMapper->consumeAtomically((int) $stored->getId())) {
            // Đã bị dùng bởi request khác trong tích tắc — trả cùng câu chung, không nhận diện được.
            throw new ValidationException(['token' => 'Link đăng nhập không hợp lệ hoặc đã hết hạn.']);
        }

        return $userId;
    }

    private function assertCreatorMayIssueFor(int $creatorRole, int $targetRole): void
    {
        if ($creatorRole === UserModel::ROLE_ADMIN) {
            if ($targetRole === UserModel::ROLE_ADMIN) {
                // Admin khác tự đổi mật khẩu được — không dùng magic-link để leo/chiếm quyền admin.
                throw new AccessDeniedException('Không tạo link đăng nhập cho tài khoản quản trị.');
            }

            return;
        }

        if ($creatorRole === UserModel::ROLE_TEACHER) {
            if ($targetRole !== UserModel::ROLE_STUDENT) {
                throw new AccessDeniedException('Giáo viên chỉ tạo link đăng nhập cho học sinh.');
            }

            return;
        }

        throw new AccessDeniedException('Bạn không có quyền tạo link đăng nhập.');
    }
}
