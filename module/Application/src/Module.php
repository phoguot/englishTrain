<?php
declare(strict_types=1);

namespace Application;

use Application\Service\SystemLogService;
use Laminas\Mvc\MvcEvent;
use Throwable;

class Module
{
    public function getConfig(): array
    {
        return include __DIR__ . "/../config/module.config.php";
    }

    public function onBootstrap(MvcEvent $event): void
    {
        $config = $event->getApplication()->getServiceManager()->get('config');
        $timezone = (string) ($config['app']['timezone'] ?? 'Asia/Bangkok');
        date_default_timezone_set($timezone);

        $this->attachErrorLogging($event);
    }

    /**
     * Ghi mọi exception không được xử lý vào bảng `system_log` trước khi user thấy trang lỗi.
     * AccessDenied/NotFound đã được BaseController bắt nên không tới đây — chỉ lỗi thật mới vào log.
     * dispatch.error không có exception (ví dụ sai route → 404) thì bỏ qua, không ghi cho đỡ nhiễu.
     */
    private function attachErrorLogging(MvcEvent $event): void
    {
        $application = $event->getApplication();
        $container   = $application->getServiceManager();

        $logger = static function (MvcEvent $e) use ($container): void {
            $exception = $e->getParam('exception');
            if (!$exception instanceof Throwable) {
                return;
            }

            try {
                $container->get(SystemLogService::class)->logThrowable($exception, $e->getRequest());
            } catch (Throwable $loggingFailure) {
                error_log(sprintf(
                    'System log fallback: %s: %s; logger failure: %s: %s',
                    $exception::class,
                    $exception->getMessage(),
                    $loggingFailure::class,
                    $loggingFailure->getMessage(),
                ));
                // Ghi log không bao giờ được làm hỏng trang lỗi đang render.
            }
        };

        $events = $application->getEventManager();
        $events->attach(MvcEvent::EVENT_DISPATCH_ERROR, $logger);
        $events->attach(MvcEvent::EVENT_RENDER_ERROR, $logger);
    }
}
