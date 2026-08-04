<?php

declare(strict_types=1);

namespace User\Controller;

use Application\Controller\BaseController;
use Application\Exception\AccessDeniedException;
use Application\Exception\ValidationException;
use Application\Service\UserAccountAdministrationService;
use Laminas\View\Model\ViewModel;
use User\Model\User\UserModel;
use User\OAuth\OAuthProviderType;
use User\Service\UserService;
use User\Service\UserIdentityService;

class UserController extends BaseController
{
    protected const ALLOWED_ROLES = [UserModel::ROLE_ADMIN];

    public function __construct(
        private readonly UserService $userService,
        private readonly UserAccountAdministrationService $accountAdministrationService,
        private readonly UserIdentityService $identityService,
    ) {
    }

    public function indexAction(): ViewModel
    {
        $query = $this->getAllQueryParams();
        $errors = [];
        try {
            $users = $this->userService->search($query);
        } catch (ValidationException $e) {
            $users = [];
            $errors = $e->getErrors();
        }

        $model = $this->getViewModel();
        $model->setVariables([
            'users' => $users,
            'query' => is_scalar($query['q'] ?? null) ? (string) $query['q'] : '',
            'errors' => $errors,
            'currentUserId' => (int) $this->currentUserId(),
        ]);

        return $model;
    }

    public function createAction(): mixed
    {
        if ($this->getRequest()->isPost()) {
            return $this->handleSave(null);
        }

        return $this->formView(null, [], []);
    }

    public function editAction(): mixed
    {
        $id = (int) $this->params()->fromRoute('id', 0);
        $user = $this->userService->getEditable($id);
        if ($this->getRequest()->isPost()) {
            return $this->handleSave($user);
        }

        return $this->formView($user, [], []);
    }

    public function deleteAction(): mixed
    {
        if (!$this->getRequest()->isPost()) {
            throw new AccessDeniedException('Chỉ chấp nhận xóa tài khoản từ biểu mẫu xác nhận.');
        }
        $id = (int) $this->params()->fromRoute('id', 0);
        try {
            $this->accountAdministrationService->delete($id, (int) $this->currentUserId());
        } catch (ValidationException $e) {
            $this->flashMessenger()->addErrorMessage($e->getErrors()['delete'] ?? 'Không thể xóa tài khoản này.');

            return $this->redirect()->toRoute('users');
        }
        $this->flashMessenger()->addSuccessMessage('Đã xóa tài khoản khỏi hệ thống.');

        return $this->redirect()->toRoute('users');
    }

    private function handleSave(?UserModel $existing): mixed
    {
        $post = $this->getAllPostParams();
        try {
            $saved = $existing === null
                ? $this->userService->create($post)
                : $this->accountAdministrationService->update(
                    (int) $existing->getId(),
                    $post,
                    (int) $this->currentUserId(),
                );
        } catch (ValidationException $e) {
            return $this->formView($existing, $post, $e->getErrors());
        }
        $this->flashMessenger()->addSuccessMessage(
            $existing === null ? 'Đã tạo tài khoản mới.' : 'Đã cập nhật tài khoản.',
        );

        return $this->redirect()->toRoute('users');
    }

    /** @param array<string,mixed> $post @param array<string,string> $errors */
    private function formView(?UserModel $existing, array $post, array $errors): ViewModel
    {
        $value = static fn (string $key, string $default = ''): string
            => is_scalar($post[$key] ?? null) ? (string) $post[$key] : $default;
        if ($post !== []) {
            $values = [
                'role' => (int) $value('role', (string) UserModel::ROLE_STUDENT),
                'teacher_type' => $value('teacher_type'),
                'full_name' => $value('full_name'),
                'email' => $value('email'),
                'phone' => $value('phone'),
                'username' => $value('username'),
                'status' => is_scalar($post['status'] ?? null) ? (int) $post['status'] : 1,
            ];
        } elseif ($existing !== null) {
            $values = [
                'role' => (int) $existing->getRole(),
                'teacher_type' => $existing->getTeacherType() !== null ? (string) $existing->getTeacherType() : '',
                'full_name' => (string) $existing->getFullName(),
                'email' => (string) ($existing->getEmail() ?? ''),
                'phone' => (string) ($existing->getPhone() ?? ''),
                'username' => (string) $existing->getUsername(),
                'status' => $existing->getStatus(),
            ];
        } else {
            $values = ['role' => UserModel::ROLE_STUDENT, 'teacher_type' => '', 'full_name' => '', 'email' => '', 'phone' => '', 'username' => '', 'status' => 1];
        }

        $model = $this->getViewModel();
        $model->setVariables([
            'isEdit' => $existing !== null,
            'editId' => $existing?->getId(),
            'values' => $values,
            'errors' => $errors,
            'teacherTypeLabels' => UserModel::TEACHER_TYPE_LABELS,
            'identities' => $existing !== null
                ? $this->identityService->listForUser((int) $existing->getId())
                : [],
            'providerLabels' => OAuthProviderType::LABELS,
        ]);
        $model->setTemplate('user/user/form');

        return $model;
    }
}
