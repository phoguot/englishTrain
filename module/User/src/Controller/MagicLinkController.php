<?php

declare(strict_types=1);

namespace User\Controller;

use Application\Controller\BaseController;
use Application\Exception\AccessDeniedException;
use Application\Exception\ValidationException;
use User\Service\AuthService;
use User\Model\User\UserModel;
use User\Service\MagicLinkService;
use User\Service\RememberMeService;
use User\Service\UserService;

/**
 * Magic-link đăng nhập cho học sinh/phụ huynh lớn tuổi.
 *
 * - `createAction` (POST /users/:id/magic-link): admin hoặc teacher tạo link. Render 1 trang
 *   duy nhất hiển thị URL đầy đủ + nút copy — Cache-Control no-store để URL không lưu lại.
 * - `consumeAction` (GET /login/magic/:token): public. Đổi token lấy session, phát cookie
 *   remember-me luôn (mục đích cả luồng là "một lần thôi"), rồi redirect sang `/dashboard`
 *   sạch URL để token không lọt vào history/referrer/log.
 */
class MagicLinkController extends BaseController
{
    // GUEST: consume phải chạy được khi chưa đăng nhập. create tự siết role admin/teacher
    // trong action, và POST đã bị chặn bởi CSRF của BaseController — user chưa có session
    // không đi qua được. Xem AuthController::loginAction() làm cùng kiểu.
    protected const ALLOWED_ROLES = [BaseController::ROLE_GUEST];

    public function __construct(
        private readonly MagicLinkService $magicLinkService,
        private readonly UserService $userService,
        private readonly AuthService $auth,
        private readonly RememberMeService $rememberMe,
    ) {
    }

    public function createAction(): mixed
    {
        if (!$this->getRequest()->isPost()) {
            throw new AccessDeniedException('Chỉ chấp nhận tạo link từ biểu mẫu.');
        }
        $role = (int) $this->currentRole();
        // Endpoint này chỉ nhận admin — module User đứng dưới Classroom theo sơ đồ phụ thuộc,
        // nên không được kiểm `teachesStudent()` ở đây. Luồng teacher tạo link cho học sinh
        // sẽ đặt ở module Classroom (nơi biết teacher-teaches-student) và gọi MagicLinkService
        // bằng creatorRole='teacher'. Xem module/User/CLAUDE.md §"Magic-link".
        if ($role !== UserModel::ROLE_ADMIN) {
            throw new AccessDeniedException('Bạn không có quyền tạo link đăng nhập.');
        }

        $targetId = (int) $this->params()->fromRoute('id', 0);
        $target = $this->userService->getEditable($targetId);
        $token = $this->magicLinkService->create($targetId, (int) $this->currentUserId(), $role);

        $magicUrl = $this->url()->fromRoute(
            'magic_login_consume',
            ['token' => $token],
            ['force_canonical' => true],
        );

        // Không cache trang có URL đăng nhập — proxy/lịch sử không được giữ lại.
        $this->getResponse()->getHeaders()->addHeaderLine('Cache-Control', 'no-store');

        $model = $this->getViewModel();
        $model->setVariables([
            'user' => $target,
            'magicUrl' => $magicUrl,
        ]);

        return $model;
    }

    public function consumeAction(): mixed
    {
        $token = (string) $this->params()->fromRoute('token', '');

        // Người đang đăng nhập bấm nhầm link cũ của mình/của người khác → bỏ qua, về dashboard.
        // Cứ consume vẫn hợp lệ, nhưng thà không đổi session để tránh nhảy user giữa chừng.
        if ($this->currentUserId() !== null) {
            return $this->redirect()->toRoute('dashboard');
        }

        $server = $this->getRequest()->getServer();
        $ip = is_object($server) && is_scalar($server->get('REMOTE_ADDR'))
            ? (string) $server->get('REMOTE_ADDR')
            : 'unknown';

        try {
            $userId = $this->magicLinkService->consume($token, $ip);
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->flashMessenger()->addErrorMessage(
                reset($errors) ?: 'Link đăng nhập không hợp lệ hoặc đã hết hạn.',
            );

            return $this->redirect()->toRoute('login');
        }

        if (!$this->auth->loginAsUser($userId)) {
            $this->flashMessenger()->addErrorMessage('Tài khoản đang bị khóa, vui lòng liên hệ trung tâm.');

            return $this->redirect()->toRoute('login');
        }

        // Mục đích cả luồng magic-link: học sinh lớn tuổi không phải đăng nhập lại lần nào nữa.
        // Đặt cookie remember-me luôn, không hỏi checkbox — nếu người dùng dùng chung máy,
        // admin/teacher tạo lại link + đổi mật khẩu sẽ tự thu hồi cookie này.
        $this->getResponse()->getHeaders()->addHeader($this->rememberMe->issueCookie($userId));

        // Redirect ngay sang URL sạch — token không được lọt vào history/referrer/log.
        return $this->redirect()->toRoute('dashboard');
    }
}
