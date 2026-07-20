# EnglishTrain — Design prototype

Prototype HTML độc lập để duyệt ngôn ngữ giao diện trước khi chuyển sang PHTML.

## Hướng thiết kế

- Audience: cân bằng admin, teacher và student; học sinh chính là lớp 1–5.
- Use case: trình bày concept đẹp, đồng thời giữ luồng nghiệp vụ có thể triển khai.
- Tone: playful premium / Hum — tươi, mềm và thân thiện nhưng không biến thành giao diện đồ chơi.
- Macrostructure Hallmark: Bento Grid với 6 ô dẫn vào các vai trò và luồng chính.
- Navigation: N12 reminder banner ở không gian học sinh, kết hợp side rail cho nghiệp vụ ứng dụng.
- Footer: Ft5 Statement ở các trang giới thiệu và học sinh.
- Student UI dùng màu nhấn phong phú, lời nhắc tích cực, nút lớn; teacher/admin giữ mật độ và tính chuyên nghiệp.
- Không dùng SPA, npm, bundler hoặc dữ liệu production.

## Xem prototype

Mở `index.html` trực tiếp hoặc chạy một static server trong thư mục này. Tất cả dữ liệu hiển thị đều là dữ liệu minh họa.

## Khi chuyển sang Laminas

1. Chuyển shell chung sang `module/Application/view/layout/layout.phtml`.
2. Chuyển `tokens.css`, `styles.css` và `app.js` sang `public/`.
3. Tách bảng, badge và page header thành partial PHTML.
4. Thay dữ liệu minh họa bằng ViewModel; escape toàn bộ dữ liệu động.
5. Giữ Bootstrap 5 cho grid/form utility, dùng token cho lớp nhận diện thương hiệu.
