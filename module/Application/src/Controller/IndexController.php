<?php

declare(strict_types=1);

namespace Application\Controller;

use Application\Service\DashboardService;
use Laminas\View\Model\ViewModel;

/**
 * Dashboard trang chủ — lối vào chính theo từng vai trò.
 * Chỉ gọi Service của module khác (không query thẳng). Xem module/Application/CLAUDE.md.
 */
class IndexController extends BaseController
{
    protected const ALLOWED_ROLES = [BaseController::ROLE_ANY];

    public function __construct(private readonly DashboardService $dashboardService)
    {
    }

    public function indexAction(): ViewModel
    {
        $model = $this->getViewModel();
        $model->setVariables($this->dashboardService->forActor(
            (int) $this->currentUserId(),
            (string) $this->currentRole(),
        ));

        return $model;
    }
}
