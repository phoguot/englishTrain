<?php

declare(strict_types=1);

namespace Application\Controller;

use Application\Exception\AccessDeniedException;
use Application\Exception\NotFoundException;
use Interop\Container\ContainerInterface;
use Laminas\Http\Request;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Mvc\MvcEvent;
use Laminas\View\Model\ViewModel;
use RuntimeException;
use User\Service\AuthService;
use User\Service\RememberMeService;
use User\Service\UserService;

/**
 * Base của MỌI controller. onDispatch() lo 2 việc trước/quanh mỗi action:
 *
 * A. Kiểm quyền TRƯỚC action:
 *   1. Controller khai ROLE_GUEST  → công khai, bỏ qua.
 *   2. Chưa đăng nhập               → redirect /login.
 *   3. user.status = 0 giữa chừng  → hủy session, đá về /login (kiểm mỗi request).
 *   4. Role không thuộc ALLOWED_ROLES → 403.
 *
 * B. Bắt exception QUANH action, đổi thành mã HTTP đúng:
 *   - AccessDeniedException (sai quyền sở hữu) → 403
 *   - NotFoundException (dữ liệu không tồn tại) → 404
 *   Nhờ vậy controller con chỉ việc để Service ném, không cần try/catch lặp lại.
 *
 * BaseController CHỈ kiểm role. Quyền sở hữu dữ liệu kiểm ở Service từng module.
 * Xem module/Application/CLAUDE.md và .claude/rules/01-bao-mat.md.
 *
 * Controller con BẮT BUỘC khai `protected const ALLOWED_ROLES = [...]`.
 * Thiếu → mặc định [] → 403 (deny by default).
 */
abstract class BaseController extends AbstractActionController
{
    public const ROLE_GUEST = 'guest';
    public const ROLE_ANY = '*';

    /** @var string[] Controller con override. Rỗng = deny (403). */
    protected const ALLOWED_ROLES = [];

    protected AuthService $authService;

    protected RememberMeService $rememberMeService;

    protected ?ViewModel $viewModel = null;

    protected ?ContainerInterface $container = null;

    public function setAuthService(AuthService $authService): void
    {
        $this->authService = $authService;
    }

    public function setRememberMeService(RememberMeService $rememberMeService): void
    {
        $this->rememberMeService = $rememberMeService;
    }

    public function getViewModel(): ViewModel
    {
        if ($this->viewModel === null) {
            $this->viewModel = new ViewModel(['csrfToken' => $this->authService->csrfToken()]);
        }

        return $this->viewModel;
    }

    public function getRequest(): Request
    {
        /** @var Request $request */
        $request = parent::getRequest();

        return $request;
    }

    protected function getUserService(): UserService
    {
        return $this->getContainerEntry(UserService::class);
    }

    public function getContainer(): ContainerInterface
    {
        if ($this->container === null) {
            throw new RuntimeException('Controller container chưa được khởi tạo.');
        }

        return $this->container;
    }

    /**
     * Lấy đối tượng từ container theo tên hoặc class.
     *
     * @template T
     * @param class-string<T> $entryName
     * @return T
     */
    public function getContainerEntry(string $entryName): mixed
    {
        return $this->getContainer()->get($entryName);
    }

    public function setContainer(ContainerInterface $container): static
    {
        $this->container = $container;

        return $this;
    }

    public function __invoke(ContainerInterface $container, string $requestedName, ?array $options = null): static
    {
        return $this->setContainer($container);
    }

    /**
     * Lấy toàn bộ POST params và file upload.
     *
     * @return array<string, mixed>
     */
    protected function getAllPostParams(): array
    {
        return array_merge_recursive(
            $this->getRequest()->getPost()->toArray(),
            $this->getRequest()->getFiles()->toArray(),
        );
    }

    /**
     * Lấy POST params từ form hoặc JSON body khi FE gọi API.
     *
     * @return array<string, mixed>
     */
    protected function getPostParamsApi(): array
    {
        $request = $this->getRequest();
        $params  = $this->getAllPostParams();

        $contentType = $request->getHeaders()->get('Content-Type');
        $ct = $contentType ? strtolower($contentType->getFieldValue()) : '';

        if (str_contains($ct, 'application/json')) {
            $json = json_decode((string) $request->getContent(), true);

            if (is_array($json)) {
                $params = array_replace_recursive($params, $json);
            }
        }

        return $params;
    }

    protected function getParam(string $param, mixed $defaultValue = null): mixed
    {
        $value = $this->getRequest()->getPost(
            $param,
            $this->getRequest()->getQuery($param),
        );

        if ($value === null && $defaultValue !== null) {
            $value = $defaultValue;
        }

        return $value;
    }

    /** @return array<string, mixed> */
    protected function getAllQueryParams(): array
    {
        return $this->getRequest()->getQuery()->toArray();
    }

    public function onDispatch(MvcEvent $e)
    {
        $denied = $this->guardAccess($e);
        if ($denied !== null) {
            return $denied;
        }

        try {
            if ($this->getRequest()->isPost()) {
                $this->assertValidCsrfToken();
            }

            return parent::onDispatch($e);
        } catch (AccessDeniedException) {
            return $this->renderError($e, 403, 'error/403');
        } catch (NotFoundException) {
            return $this->renderError($e, 404, 'error/404');
        }
    }

    /**
     * Form HTML gửi `_csrf` qua POST thường (form-urlencoded). API JSON (fetch với
     * Content-Type: application/json, ví dụ luồng upload-url/upload-done của Assignment) không
     * có dữ liệu POST kiểu form — token nằm trong JSON body, đọc qua getPostParamsApi().
     * Kiểm cả 2 nguồn để không phải bỏ qua bước này ở controller con.
     */
    protected function assertValidCsrfToken(): void
    {
        $token = $this->getRequest()->getPost('_csrf');
        if ($token === null) {
            $token = $this->getPostParamsApi()['_csrf'] ?? null;
        }

        if (!$this->authService->isValidCsrfToken($token)) {
            throw new AccessDeniedException('Phiên biểu mẫu không hợp lệ. Vui lòng tải lại trang và thử lại.');
        }
    }

    /** @return \Laminas\Stdlib\ResponseInterface|ViewModel|null null = cho qua */
    private function guardAccess(MvcEvent $e)
    {
        $allowed = static::ALLOWED_ROLES;

        if (in_array(self::ROLE_GUEST, $allowed, true)) {
            return null;
        }

        $userId = $this->authService->currentUserId();
        if ($userId === null) {
            $userId = $this->tryRememberMeLogin($e);
        }
        if ($userId === null) {
            return $this->redirectToLogin($e);
        }

        if (!$this->authService->isActiveUser($userId)) {
            $this->authService->logout();
            return $this->redirectToLogin($e);
        }

        $role = $this->authService->currentRole();
        if (!in_array(self::ROLE_ANY, $allowed, true) && !in_array($role, $allowed, true)) {
            return $this->renderError($e, 403, 'error/403');
        }

        return null;
    }

    /**
     * Tự đăng nhập bằng cookie "ghi nhớ đăng nhập" khi session đã hết (đóng trình duyệt,
     * hết idle timeout). Chỉ áp dụng cho GET — auto-login trên POST sẽ sinh CSRF token mới
     * trong khi form đang gửi token cũ, luôn fail và mở edge case login-CSRF không cần thiết.
     */
    private function tryRememberMeLogin(MvcEvent $e): ?int
    {
        if (!$this->getRequest()->isGet()) {
            return null;
        }

        $cookieHeader = $this->getRequest()->getCookie();
        $raw = $cookieHeader !== false && $cookieHeader->offsetExists(RememberMeService::COOKIE_NAME)
            ? $cookieHeader->offsetGet(RememberMeService::COOKIE_NAME)
            : null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $result = $this->rememberMeService->consumeCookie($raw);
        if ($result === null) {
            $e->getResponse()->getHeaders()->addHeader($this->rememberMeService->clearCookie());
            return null;
        }

        // Cookie đã rotate trong DB ở consumeCookie() — phải gửi cookie mới cho trình duyệt
        // NGAY CẢ KHI loginAsUser() thất bại (vd tài khoản vừa bị khóa), nếu không lần sau
        // trình duyệt gửi lại cookie cũ (hash không còn khớp) và bị hiểu nhầm là cookie bị lộ,
        // kéo theo thu hồi oan toàn bộ thiết bị của user.
        $e->getResponse()->getHeaders()->addHeader($result['cookie']);

        if (!$this->authService->loginAsUser($result['userId'])) {
            return null;
        }

        return $result['userId'];
    }

    protected function currentUserId(): ?int
    {
        return $this->authService->currentUserId();
    }

    protected function currentRole(): ?string
    {
        return $this->authService->currentRole();
    }

    private function redirectToLogin(MvcEvent $e)
    {
        $response = $e->getResponse();
        $response->getHeaders()->addHeaderLine('Location', $this->url()->fromRoute('login'));
        $response->setStatusCode(302);

        return $response;
    }

    private function renderError(MvcEvent $e, int $status, string $template): ViewModel
    {
        $e->getResponse()->setStatusCode($status);

        $model = new ViewModel();
        $model->setTemplate($template);
        $e->setResult($model);

        return $model;
    }
}
