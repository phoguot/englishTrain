# EnglishTrain

He thong web quan ly lop tieng Anh: giao bai, nop bai (quiz/essay),
diem danh, report hoc sinh. Stack: PHP 8.2 + Laminas MVC + MySQL 8.

## Bat dau
1. composer install
2. Tao database english_train, chay SQL trong data/migrations/ theo thu tu
3. copy config/autoload/local.php.dist -> local.php, dien DB; R2 de trong cho den khi trien khai production
4. composer serve  ->  http://localhost:8080

## Tai lieu
Bat dau tu docs/00-ban-do-tai-lieu.md (ban do: ai doc gi, sua gi cap nhat gi),
roi doc theo thu tu: 01-tong-quan -> 02-database-schema -> 03-modules -> 04-contracts.
Cau truc file CRUD bat buoc: docs/code-standards/crud-convention.md.
File CLAUDE.md la ngu canh cho Claude Code khi lam viec trong repo nay.
