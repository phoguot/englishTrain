<?php

declare(strict_types=1);

namespace User\Service;

use Laminas\Http\Header\SetCookie;
use User\Model\UserRememberToken\UserRememberTokenMapper;
use User\Model\UserRememberToken\UserRememberTokenModel;

/**
 * "Ghi nhớ đăng nhập" — cookie bền, tách riêng khỏi session, để học sinh/phụ huynh không phải
 * đăng nhập lại mỗi lần hết phiên hoặc đóng trình duyệt. KHÔNG thay AuthService: sau khi cookie
 * hợp lệ, service này chỉ trả lại user_id, nơi gọi (BaseController) tự gọi
 * AuthService::loginAsUser() dựng session như luồng đăng nhập bình thường.
 *
 * Cookie mang `selector.validator`. Selector tra dòng qua UNIQUE index; validator so bằng
 * token_hash (SHA-256) bằng hash_equals — tránh timing attack và full-table scan.
 * Rotate validator sau MỖI lần dùng (không giữ ân hạn cho tab song song — quy mô nhỏ,
 * chấp nhận đánh đổi để không cần thêm hạ tầng). Selector đúng nhưng validator sai là dấu hiệu
 * cookie đã bị lộ và dùng trước đó → thu hồi toàn bộ token của user.
 */
class RememberMeService
{
    public const COOKIE_NAME = 'remember_me';

    private const SELECTOR_BYTES = 16;
    private const VALIDATOR_BYTES = 32;

    public function __construct(
        private readonly UserRememberTokenMapper $tokenMapper,
        private readonly int $lifetimeDays,
        private readonly bool $cookieSecure,
    ) {
    }

    public function issueCookie(int $userId): SetCookie
    {
        $selector = bin2hex(random_bytes(self::SELECTOR_BYTES));
        $validator = bin2hex(random_bytes(self::VALIDATOR_BYTES));

        $token = new UserRememberTokenModel();
        $token->setUserId($userId);
        $token->setSelector($selector);
        $token->setTokenHash(hash('sha256', $validator));
        $token->setExpiresAt($this->expiresAtString());
        $this->tokenMapper->saveUserRememberToken($token);

        return $this->buildCookie($selector . '.' . $validator, $this->expiresAtTimestamp());
    }

    /**
     * Xác thực cookie và rotate validator. Chỉ gọi trên request GET (xem BaseController) —
     * auto-login trên POST sinh CSRF token mới trong khi form đang gửi token cũ.
     *
     * @return array{userId:int,cookie:SetCookie}|null null = cookie không hợp lệ/hết hạn
     */
    public function consumeCookie(string $rawValue): ?array
    {
        [$selector, $validator] = $this->split($rawValue);
        if ($selector === null || $validator === null) {
            return null;
        }

        $token = $this->tokenMapper->getBySelector($selector);
        if ($token === null || !$token->isUsable()) {
            return null;
        }

        if (!hash_equals((string) $token->getTokenHash(), hash('sha256', $validator))) {
            $this->tokenMapper->revokeAllByUser((int) $token->getUserId());

            return null;
        }

        $newValidator = bin2hex(random_bytes(self::VALIDATOR_BYTES));
        $rotated = $this->tokenMapper->rotateValidator(
            (int) $token->getId(),
            hash('sha256', $newValidator),
            $this->expiresAtString(),
        );
        if (!$rotated) {
            return null;
        }

        return [
            'userId' => (int) $token->getUserId(),
            'cookie' => $this->buildCookie($selector . '.' . $newValidator, $this->expiresAtTimestamp()),
        ];
    }

    /** Thu hồi token của MỘT thiết bị (đăng xuất thường). */
    public function revokeCookie(string $rawValue): void
    {
        [$selector] = $this->split($rawValue);
        if ($selector !== null) {
            $this->tokenMapper->revokeBySelector($selector);
        }
    }

    /** Thu hồi toàn bộ thiết bị của user — đổi mật khẩu, phát hiện cookie bị lộ. */
    public function revokeAllForUser(int $userId): void
    {
        $this->tokenMapper->revokeAllByUser($userId);
    }

    /** Xóa cứng khi hard-delete user (gọi trong transaction của UserService::delete()). */
    public function purgeAllForUser(int $userId): void
    {
        $this->tokenMapper->deleteByUser($userId);
    }

    /** Cookie rỗng, hết hạn trong quá khứ — dùng khi logout hoặc dọn cookie rác/không hợp lệ. */
    public function clearCookie(): SetCookie
    {
        return $this->buildCookie('', time() - 3600);
    }

    /** @return array{0:?string,1:?string} */
    private function split(string $rawValue): array
    {
        $parts = explode('.', $rawValue, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return [null, null];
        }

        return $parts;
    }

    private function expiresAtTimestamp(): int
    {
        return time() + $this->lifetimeDays * 86400;
    }

    private function expiresAtString(): string
    {
        return date('Y-m-d H:i:s', $this->expiresAtTimestamp());
    }

    private function buildCookie(string $value, int $expires): SetCookie
    {
        return new SetCookie(
            self::COOKIE_NAME,
            $value,
            $expires,
            '/',
            null,
            $this->cookieSecure,
            true,
            null,
            null,
            'Lax',
        );
    }
}
