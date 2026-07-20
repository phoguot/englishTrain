<?php

declare(strict_types=1);

namespace Application\Controller;

use Laminas\View\Model\ViewModel;

/** Trang giới thiệu công khai; không tải dữ liệu nghiệp vụ. */
class LandingController extends BaseController
{
    protected const ALLOWED_ROLES = [BaseController::ROLE_GUEST];

    public function indexAction(): ViewModel
    {
        $this->layout()->setVariable('publicLanding', true);

        return $this->getViewModel();
    }
}
