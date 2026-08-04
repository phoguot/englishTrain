<?php

declare(strict_types=1);

namespace Attendance\Controller;

use Application\Controller\BaseController;
use Attendance\Service\TuitionExcelWriter;
use Attendance\Service\TuitionService;
use Classroom\Service\ClassroomService;
use Laminas\Http\Response;
use Laminas\View\Model\ViewModel;
use User\Model\User\UserModel;

/**
 * Học phí theo buổi dạy: xem bảng tiền của một lớp trong một tháng và tải file Excel.
 *
 * **Chỉ teacher.** Student không vào được (tiền không phải thông tin học sinh tự tra) và
 * **admin cũng không vào được** — thống kê tiền là việc riêng của giáo viên dạy lớp đó.
 * Đây là ngoại lệ có chủ đích với luật "admin không bị giới hạn" ở .claude/rules/01-bao-mat.md.
 *
 * Quyền theo lớp do TuitionService kiểm (teacher chỉ lớp mình): controller không tự kiểm sở hữu,
 * và **không** được tải file trước rồi mới kiểm quyền.
 */
class TuitionController extends BaseController
{
    protected const ALLOWED_ROLES = [UserModel::ROLE_TEACHER];

    public function __construct(
        private readonly TuitionService $tuitionService,
        private readonly TuitionExcelWriter $excelWriter,
        private readonly ClassroomService $classroomService,
    ) {
    }

    /**
     * Bảng học phí. Chưa chọn lớp (`?classroom=` trống) thì chỉ hiện form chọn lớp + tháng,
     * chưa truy vấn gì — vào từ menu là vào được, không cần đi qua trang lớp.
     */
    public function indexAction(): ViewModel
    {
        $classroomId = (int) $this->params()->fromQuery('classroom', 0);
        $month       = (string) $this->params()->fromQuery('month', '');
        $userId      = (int) $this->currentUserId();
        $role        = (int) $this->currentRole();

        $model = $this->getViewModel();
        $model->setVariables([
            'classroomId' => $classroomId,
            'classrooms'  => $this->classroomService->listRowsForActor($userId, $role),
            'tuition'     => null,
            // Tháng mặc định là tháng hiện tại — cùng một chỗ chuẩn hóa với Service.
            'month'       => $this->tuitionService->monthRange($month)[0],
        ]);

        if ($classroomId <= 0) {
            return $model;
        }

        $tuition = $this->tuitionService->monthlyTuition($classroomId, $month, $userId, $role);
        $model->setVariables([
            'tuition' => $tuition,
            'month'   => $tuition['month'],
        ]);

        return $model;
    }

    /**
     * Tải file Excel. GET là đúng (chỉ đọc, để dán được thành link tải) nên không cần CSRF.
     *
     * `type` = `daily` (chi tiết theo ngày) hoặc `summary` (tổng hợp tháng) — route đã ràng buộc.
     */
    public function exportAction(): Response
    {
        $type        = (string) $this->params()->fromRoute('type', 'summary');
        $classroomId = (int) $this->params()->fromQuery('classroom', 0);
        $month       = (string) $this->params()->fromQuery('month', '');

        // Kiểm quyền TRƯỚC khi dựng file: Service ném 403/404 nếu không phải lớp của mình.
        $tuition = $this->tuitionService->monthlyTuition(
            $classroomId,
            $month,
            (int) $this->currentUserId(),
            (int) $this->currentRole(),
        );

        $path = $type === 'daily'
            ? $this->excelWriter->daily($tuition)
            : $this->excelWriter->summary($tuition);

        try {
            $content = (string) file_get_contents($path);
        } finally {
            // File tạm chỉ để chuyển tay: xóa ngay cả khi đọc lỗi, đừng để rác trong temp.
            unlink($path);
        }

        $fileName = $this->excelWriter->fileName($type, $classroomId, $tuition['month']);

        $response = new Response();
        $response->setContent($content);
        $response->getHeaders()->addHeaders([
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            'Content-Length'      => (string) strlen($content),
            'Cache-Control'       => 'no-store',
        ]);
        $response->setStatusCode(200);

        return $response;
    }
}
