# Render view server-side — quy trình đầy đủ

Tài liệu chuẩn cho việc tạo **trang HTML render trực tiếp từ server** (Laminas `ViewModel` + PHTML + Bootstrap 5), không đi qua API JSON. Đây là cách hoạt động mặc định của toàn bộ EnglishTrain — mọi trang mới PHẢI theo đúng quy trình này.

Đọc kèm: `docs/code-standards/crud-convention.md` (cấu trúc file), `.claude/rules/03-view-ui.md` (luật PHTML/Bootstrap/JS), `docs/03-modules.md` (route hiện có).

---

## 1. Pipeline render — hệ thống quyết định thế nào

Quyết định nằm ở **kiểu giá trị action trả về**:

| Action trả về | Kết quả |
|---|---|
| `ViewModel` | Render PHTML theo quy ước template, bọc trong `layout/layout` |
| `Response` (redirect) | Trả thẳng, không render — dùng sau khi lưu thành công (PRG) |

Luồng một request trang:

```
Request → Route (module.config.php)
        → BaseController::onDispatch()
            A. Guard quyền theo ALLOWED_ROLES (guest → qua; chưa login → redirect /login;
               user bị khóa → logout + redirect; sai role → 403)
            B. Bọc try/catch quanh action:
               - AccessDeniedException → error/403 (status 403)
               - NotFoundException    → error/404 (status 404)
        → Action: gọi Service, đổ dữ liệu vào ViewModel
        → Renderer: resolve template qua template_path_stack
        → layout/layout bọc ngoài → HTML trả về trình duyệt
```

File nền (chỉ đọc, KHÔNG sửa khi thêm trang mới):

- `module/Application/src/Controller/BaseController.php` — guard role + bắt exception.
- `module/Application/config/module.config.php` — layout, error template, initializer tiêm `AuthService` vào mọi controller.
- `module/Application/view/layout/layout.phtml` — layout chung.

---

## 2. Quy ước resolve template

Template mặc định theo đường dẫn:

```
module/<Module>/view/<module-thường>/<controller-thường>/<action-thường>.phtml
```

Ví dụ: `Classroom\Controller\ClassroomController::studentsAction` → `module/Classroom/view/classroom/classroom/students.phtml`.

- CamelCase đổi thành dash-thường (`AssignmentSubmitController::viewDetailAction` → `assignment/assignment-submit/view-detail.phtml`).
- Chỉ `setTemplate()` thủ công khi nhiều action dùng chung một view (vd `create` + `edit` dùng chung `form.phtml` — xem `ClassroomController::formView()`).

---

## 3. Các file cần tạo cho một trang mới

Giữ nguyên 4 tầng Controller → Service → Mapper → Model. Trang view chỉ thêm PHTML:

```
module/<Module>/
├── config/
│   └── module.config.php            # thêm route + factory (nếu controller mới)
├── src/
│   ├── Controller/<Entity>Controller.php    # trả ViewModel
│   ├── Service/<Entity>Service.php          # logic nghiệp vụ + chạy Filter + kiểm ownership
│   ├── Filter/<Entity>/<Entity>SaveFilter.php
│   └── Model/<Entity>/...                   # Model + Mapper (SQL)
└── view/
    └── <module-thường>/
        └── <entity-thường>/
            ├── index.phtml
            └── form.phtml
```

---

## 4. Route + đăng ký trong `module.config.php`

Dự án dùng **closure factory với constructor injection** — controller/service khai báo dependency qua constructor, KHÔNG lấy tràn lan từ container trong action.

```php
<?php

declare(strict_types=1);

namespace <Module>;

use <Module>\Controller\<Entity>Controller;
use <Module>\Model\<Entity>\<Entity>Mapper;
use <Module>\Service\<Entity>Service;
use Interop\Container\ContainerInterface;
use Laminas\Db\Adapter\Adapter;
use Laminas\Router\Http\Segment;

return [
    'router' => [
        'routes' => [
            '<entities>' => [
                'type'    => Segment::class,
                'options' => [
                    // action bắt buộc bắt đầu bằng chữ để không nuốt segment /:id
                    'route'       => '/<entities>[/:action[/:id]]',
                    'constraints' => [
                        'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                        'id'     => '[0-9]+',
                    ],
                    'defaults'    => [
                        'controller' => <Entity>Controller::class,
                        'action'     => 'index',
                    ],
                ],
            ],
        ],
    ],
    'controllers' => [
        'factories' => [
            <Entity>Controller::class => static fn (ContainerInterface $c): <Entity>Controller
                => new <Entity>Controller($c->get(<Entity>Service::class)),
        ],
    ],
    'service_manager' => [
        'factories' => [
            <Entity>Mapper::class  => static fn (ContainerInterface $c): <Entity>Mapper
                => new <Entity>Mapper($c->get(Adapter::class)),
            <Entity>Service::class => static fn (ContainerInterface $c): <Entity>Service
                => new <Entity>Service($c->get(<Entity>Mapper::class)),
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [__DIR__ . '/../view'],
    ],
];
```

Ghi chú:

- KHÔNG cần tiêm `AuthService` — initializer trong `module/Application/config/module.config.php` tự tiêm vào mọi controller kế thừa `BaseController`.
- Route đặt tên ngắn, số nhiều (`classrooms`, `assignments`). URL con đặc thù (vd `/classrooms/:id/students`) tách route riêng.
- Đăng ký module trong `config/application.config.php` + namespace trong `composer.json` (chỉ khi module hoàn toàn mới), sau đó `composer dump-autoload`.

---

## 5. Controller

Controller kế thừa `BaseController`, BẮT BUỘC khai `ALLOWED_ROLES` (thiếu = deny mặc định → 403):

```php
<?php

declare(strict_types=1);

namespace <Module>\Controller;

use Application\Controller\BaseController;
use Application\Exception\ValidationException;
use <Module>\Service\<Entity>Service;
use Laminas\View\Model\ViewModel;

class <Entity>Controller extends BaseController
{
    protected const ALLOWED_ROLES = ['admin', 'teacher']; // hoặc [self::ROLE_GUEST], [self::ROLE_ANY]

    public function __construct(
        private readonly <Entity>Service $service,
    ) {
    }

    public function indexAction(): ViewModel
    {
        $model = $this->getViewModel();
        $model->setVariables([
            'role'  => $this->currentRole(),
            'items' => $this->service->listRowsForActor(
                (int) $this->currentUserId(),
                (string) $this->currentRole(),
            ),
        ]);

        return $model;
    }
}
```

Quy tắc controller (không đổi so với crud-convention):

- **Controller mỏng**: nhận request, gọi Service, đổ dữ liệu vào ViewModel. Không validate, không SQL, không logic nghiệp vụ.
- Quyền role kiểm ở `ALLOWED_ROLES`; quyền **sở hữu dữ liệu** kiểm ở Service — Service ném `AccessDeniedException`/`NotFoundException`, `BaseController` tự đổi thành trang 403/404. Controller KHÔNG try/catch hai exception này.
- Cần siết thêm trong controller cho phép nhiều role (vd chỉ admin được create): viết `assertAdmin()` ném `AccessDeniedException` — xem `ClassroomController`.
- Dùng `$this->getViewModel()`, không `new ViewModel()` trong action.
- Đọc route param: `$this->params()->fromRoute('id', 0)`. Đọc POST thô: `$this->getAllPostParams()` — đưa thẳng cho Service, không đụng vào giá trị.

---

## 6. Luồng form: POST-Redirect-Get (PRG)

Chuẩn bắt buộc cho mọi form, chi tiết tại `.claude/rules/03-view-ui.md`:

```php
private function handleSave(?<Entity>Model $existing): mixed
{
    $post = $this->getAllPostParams();

    try {
        $saved = $existing === null
            ? $this->service->create($post)
            : $this->service->update((int) $existing->getId(), $post, /* actor */);
        $this->flashMessenger()->addSuccessMessage('Đã lưu "' . $saved->getName() . '".');
    } catch (ValidationException $e) {
        // Lỗi validate: render LẠI form (HTTP 200) kèm dữ liệu vừa gõ + errors. KHÔNG redirect.
        return $this->formView($existing, $post, $e->getErrors());
    }

    return $this->redirect()->toRoute('<entities>'); // hợp lệ → redirect, chống F5 gửi lại
}
```

4 điểm chốt:

1. Controller đưa POST **thô** cho Service. Service là **nơi duy nhất** chạy Filter và ném `ValidationException` (map field ⇒ câu tiếng Việt).
2. Lỗi validate → render lại view kèm `values` (dữ liệu người dùng vừa gõ) + `errors`, tô `is-invalid` + `.invalid-feedback`. Không redirect khi lỗi.
3. Thành công → `flashMessenger` + `redirect()->toRoute(...)`.
4. `ValidationException` controller tự bắt (khác `AccessDeniedException`/`NotFoundException` do BaseController bắt) — vì phải quay về đúng form.

---

## 7. View PHTML

```php
<?php
/**
 * @var Laminas\View\Renderer\PhpRenderer $this
 * @var array<int, array{id:int,name:string,status:string}> $items
 * @var string $role
 */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Tiêu đề trang</h1>
</div>

<?php if ($items === []): ?>
    <p class="text-muted">Chưa có dữ liệu.</p>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr><th scope="col">Tên</th><th scope="col" class="text-end">Thao tác</th></tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= $this->escapeHtml($item['name']) ?></td>
                        <td class="text-end">
                            <a href="<?= $this->url('<entities>', ['action' => 'edit', 'id' => $item['id']]) ?>"
                               class="btn btn-outline-primary btn-sm">Sửa</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
```

Quy tắc bắt buộc:

- Mở đầu file bằng docblock `@var` khai báo mọi biến ViewModel truyền xuống.
- **Escape mọi biến động**: `$this->escapeHtml()` cho text, `$this->escapeHtmlAttr()` cho attribute, `(int)` cast cho số. URL dựng bằng `$this->url('<route>', [...])`, không hard-code.
- View chỉ hiển thị: không query DB, không gọi Service, không logic nghiệp vụ. Controller đưa xuống gì dùng đúng cái đó.
- Bootstrap 5 class sẵn có, không `style=""` inline, không CSS custom trừ khi thật sự thiếu class (khi đó để `public/css/app.css`). Badge trạng thái theo bảng màu thống nhất trong `.claude/rules/03-view-ui.md`.
- Phần lặp lại giữa nhiều trang tách partial, gọi `$this->partial('<module>/<controller>/partial-name', [...])`.
- Message hiển thị viết tiếng Việt có dấu, câu hoàn chỉnh.
- Accessibility tối thiểu: `<label for>` cho mọi input, `<th scope="col">`, `aria-label` cho nút icon-only.
- View helper có sẵn: `$this->currentUser()` (thông tin user đăng nhập) — xem `Application\View\Helper\CurrentUser`.
- JS (nếu cần tương tác) là JS thuần trong `public/js/`, nạp cuối layout. Không framework, không bundler.

---

## 8. Layout và trang lỗi

- Layout chung `layout/layout` tự bọc mọi ViewModel — không cần set gì.
- Trang lỗi dùng template có sẵn: `error/403`, `error/404`, `error/index`. KHÔNG tự viết HTML lỗi trong module — muốn ra 403/404 thì để Service ném `AccessDeniedException`/`NotFoundException`.
- Trang không cần layout (hiếm — iframe/print): `$model->setTerminal(true);` và ghi rõ lý do trong comment.

---

## 9. Checklist hoàn thành

- [ ] Route trong `module.config.php`, constraint `action`/`id` đúng mẫu mục 4
- [ ] Controller + Service + Mapper đăng ký bằng closure factory, dependency qua constructor
- [ ] Controller kế thừa `BaseController`, khai `ALLOWED_ROLES` đúng role được phép
- [ ] Quyền sở hữu dữ liệu kiểm ở Service (ném `AccessDenied`/`NotFound`), không kiểm ở view/JS
- [ ] Form theo PRG: lỗi → render lại kèm `values` + `errors`; thành công → flashMessenger + redirect
- [ ] Template đúng quy ước `<module>/<controller>/<action>.phtml`; view có docblock `@var`
- [ ] Mọi output động đã escape; URL qua `$this->url()`
- [ ] Message tiếng Việt; badge/UI theo `.claude/rules/03-view-ui.md`
- [ ] `php -l` pass mọi file mới; nghiệm thu bằng trình duyệt từng role (`/xong-chua`)
- [ ] Cập nhật `docs/03-modules.md` (route mới) và `docs/04-contracts.md` nếu chạm ranh giới module
