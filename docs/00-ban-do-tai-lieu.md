# 00 — Bản đồ tài liệu

Ai đọc gì, sửa gì đi kèm cập nhật gì. Đọc file này khi không biết nên đặt thông tin mới vào đâu.

## Cây thư mục

```
AGENTS.md                  # Chỉ dẫn cho Codex: thứ tự đọc skill → docs → code
CLAUDE.md                  # Sổ tay dự án — mục tiêu, stack, quy ước, lệnh. Luôn nạp vào ngữ cảnh.
CLAUDE.local.md            # Ghi chú cá nhân (không commit)
.mcp.json.dist             # Mẫu kết nối MCP; copy -> .mcp.json khi thật sự cần

.claude/
  settings.json            # Quyền allow/deny + đăng ký hook. Commit, cả team dùng chung.
  settings.local.json      # Tùy chỉnh máy cá nhân (không commit)
  rules/                   # Luật chi tiết theo chủ đề — tách ra để CLAUDE.md không phình
    01-bao-mat.md          #   phân quyền, dữ liệu vào, mật khẩu, R2
    02-database.md         #   migration, Mapper, transaction
    03-view-ui.md          #   PHTML, Bootstrap, JS thuần, message tiếng Việt
    04-testing.md          #   test cái gì, quality gate trước khi báo xong
    05-deployment.md       #   cấu hình, deploy, backup
  agents/                  # Subagent chuyên trách, chạy ngữ cảnh riêng
    laminas-reviewer.md    #   review code PHP/Laminas
    schema-guard.md        #   canh lệch schema giữa docs / migration / code
  commands/                # Lối tắt cho việc lặp lại
    check-quyen.md         #   /check-quyen <module>
    migration-moi.md       #   /migration-moi <mô tả>
    xong-chua.md           #   /xong-chua  — quality gate
  skills/
    them-nghiep-vu/        # Quy trình nhiều bước: thêm nghiệp vụ / dựng module mới
  hooks/
    kiem-tra-php.php       # Chạy sau mỗi Edit/Write: php -l, strict_types, cấm $_POST thô

.agents/skills/            # Skill dùng trong Codex (them-nghiep-vu, source-command-xong-chua)
.codex/agents/             # Cấu hình reviewer/schema guard cho Codex
.codex/hooks/              # Hook kiểm tra PHP của Codex

config/autoload/
  global.php               # Cấu hình cố định dùng chung mọi môi trường
  local.php.dist           # Mẫu DB/R2; copy thành local.php và không commit
  development.local.php.dist # Mẫu bật chi tiết lỗi chỉ trên máy dev

docs/
  00-ban-do-tai-lieu.md    # File này
  01-tong-quan.md          # Kiến trúc, luồng nghiệp vụ
  02-database-schema.md    # NGUỒN SỰ THẬT về DB
  03-modules.md            # Route, controller, service từng module
  04-contracts.md          # NGUỒN SỰ THẬT về hợp đồng liên module
  05-deploy-infinityfree.md # Deploy lên InfinityFree: FTP, .htaccess, VistaPanel, R2
  code-standards/
    crud-convention.md     # NGUỒN SỰ THẬT về cấu trúc file CRUD (Controller→Service→Mapper→Model)
    render-view.md         # Quy trình đầy đủ tạo trang render server-side (route→controller→PRG→PHTML)

module/<Ten>/CLAUDE.md     # Luật riêng của module — ranh giới, ca biên, lỗi hay gặp
```

## Nguyên tắc phân tầng

| Loại thông tin | Đặt ở |
|---|---|
| Đúng với **cả dự án**, cần biết ngay | `AGENTS.md` / `CLAUDE.md` (giữ đồng bộ, ngắn) |
| Luật chi tiết theo **chủ đề**, chỉ cần khi đụng chủ đề đó | `.claude/rules/` |
| Chỉ đúng với **một module** | `module/<Ten>/CLAUDE.md` |
| Cách 2 module **nói chuyện với nhau** | `docs/04-contracts.md` |
| Cấu trúc DB | `docs/02-database-schema.md` |
| Cấu trúc file CRUD (tên class, tầng, thư mục) | `docs/code-standards/crud-convention.md` |
| Quy trình tạo trang render server-side | `docs/code-standards/render-view.md` |
| Việc lặp lại nhiều lần | `.agents/skills/` (Codex) hoặc `.claude/commands/`, `.claude/skills/` (Claude Code) |
| Việc phải **luôn** xảy ra, không được quên | `.codex/hooks/` và `.claude/hooks/` |

Nguyên tắc chung của khóa học: **đừng tạo đủ mọi thư mục từ ngày đầu.**
Thêm một lớp khi có nhu cầu thật, không phải cho đẹp cây thư mục.

## Công cụ AI trong repo

Repo hỗ trợ đồng thời Codex và Claude Code. `.agents/` + `.codex/` phục vụ Codex; `.claude/` phục vụ
Claude Code và vẫn là nơi đặt các rule nghiệp vụ dùng chung. Khi sửa một quy trình dùng cho cả hai,
cập nhật hai bản tương ứng hoặc ghi rõ công cụ sở hữu để tránh lệch.

## Vì sao có tiền tố `.claude/` (khác cách khóa học viết)

Khóa học ghi tắt là `rules/`, `agents/`, `commands/`, `skills/`, `hooks/`. Ở đây dùng
`.claude/agents/`, `.claude/commands/`, `.claude/skills/` vì **Claude Code chỉ nhận đúng
những đường dẫn đó** — để ở gốc repo thì subagent, slash command và skill sẽ không được nạp.
Cách viết tắt trong khóa học là gọi tên cho ngắn, không phải đường dẫn thật.

Hai chỗ **không** bị ràng buộc, đặt ở đâu cũng chạy:
- `.claude/rules/` — chỉ là file markdown, được nạp vì `CLAUDE.md` trỏ tới. Để ở `docs/rules/`
  cũng được; đặt trong `.claude/` cho gọn vì đây là ngữ cảnh cho Claude, không phải tài liệu cho người.
- `.claude/hooks/kiem-tra-php.php` — đường dẫn script khai trong `.claude/settings.json`, tùy ý.

**Bắt buộc đúng đường dẫn:** `.claude/agents/`, `.claude/commands/`, `.claude/skills/<ten>/SKILL.md`,
`.claude/settings.json`. Đừng "sửa cho giống khóa học" — sẽ hỏng.

## Sửa gì thì cập nhật gì

| Thay đổi | Bắt buộc cập nhật kèm |
|---|---|
| Đổi schema DB | `docs/02-database-schema.md` + migration mới trong `data/migrations/` |
| Thêm/đổi route | `docs/03-modules.md` |
| Đổi method mà module khác gọi | `docs/04-contracts.md` (**trước**, rồi mới sửa code) |
| Thêm enum / trạng thái mới | `docs/04-contracts.md` + bảng màu badge ở `.claude/rules/03-view-ui.md` |
| Thêm key cấu hình theo môi trường/credential | `config/autoload/local.php.dist` + tài liệu deploy liên quan |
| Thêm key cấu hình cố định | `config/autoload/global.php` + tài liệu kiến trúc liên quan |
| Thêm module mới | `composer.json` (PSR-4) + mảng `modules` trong `config/application.config.php` + `docs/03-modules.md` + `module/<Ten>/CLAUDE.md` |
| Thêm dependency | `composer.json` + mục Stack trong `CLAUDE.md` |

Docs lệch code là docs **sai** — sửa ngay trong cùng lần thay đổi, đừng để dồn.

## Ba nguồn sự thật
- **DB**: `docs/02-database-schema.md`. Code lệch docs → code sai.
- **Liên module**: `docs/04-contracts.md`. Gọi method không có trong đó → sai kiến trúc.
- **Cấu trúc file CRUD**: `docs/code-standards/crud-convention.md`. Tạo class không đúng
  tầng/tên (Controller → Service → Mapper → Model, Filter riêng) → sai quy ước, dù chạy được.

Nghi ngờ lệch → gọi subagent `schema-guard` (DB) hoặc `laminas-reviewer` (kiến trúc/quyền/cấu trúc file).
