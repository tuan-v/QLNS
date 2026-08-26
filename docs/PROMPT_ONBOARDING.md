# Prompt điều hướng dự án QLNS — dùng cho AI và con người

Tài liệu này là bản tóm tắt điều hướng nhanh, tổng hợp từ `AGENTS.md`,
`docs/CODING_STANDARDS.md`, `docs/Ke-hoach-trien-khai-du-an-QLNS.docx` và
`docs/YEU_CAU_DU_AN_QLNS.html`. Dùng để một AI assistant (Claude Code, v.v.)
hoặc một lập trình viên mới nắm nhanh bối cảnh trước khi bắt tay vào việc.

**Không thay thế việc đọc đầy đủ hai tài liệu nguồn** khi triển khai một
chức năng/nghiệp vụ cụ thể — quy tắc này áp dụng cho cả AI lẫn con người,
theo đúng `AGENTS.md`. Tài liệu này chỉ giúp định hướng nhanh xem cần đọc gì,
làm theo thứ tự nào.

---

## 1. Bối cảnh dự án

QLNS là hệ thống Quản lý Nhân sự xây mới hoàn toàn: Laravel 12 (API) +
Vue 3 + Vite (SPA), MySQL 8, Redis. Đối tượng dùng: Super Admin, HR,
Trưởng phòng/Quản lý, Nhân viên. Mục tiêu: số hóa hồ sơ nhân viên, chấm
công, nghỉ phép, tính lương, báo cáo KPI real-time.

Dự án được chia thành lộ trình **75 ngày làm việc / 15 tuần / 6 Phase**,
mỗi ngày tương ứng một commit dạng `dayNN` (xem `git log --oneline`).

## 2. Tài liệu bắt buộc phải đọc trước khi sửa nghiệp vụ

| Tài liệu | Vai trò |
|---|---|
| `AGENTS.md` (gốc repo) | Quy tắc bắt buộc: đọc 2 tài liệu dưới trước khi đụng vào nghiệp vụ/API/phân quyền/UI có hành vi nghiệp vụ |
| `docs/Ke-hoach-trien-khai-du-an-QLNS.docx` | Kế hoạch triển khai theo từng ngày (Ngày 01–75), checklist theo tuần |
| `docs/YEU_CAU_DU_AN_QLNS.html` | Đặc tả yêu cầu chức năng, phi chức năng, kiến trúc, quy ước đặt tên, tiêu chí nghiệm thu |
| `docs/CODING_STANDARDS.md` | Quy chuẩn code cụ thể hóa từ hai tài liệu trên |
| `docs/LOCAL_DEVELOPMENT.md` | Hướng dẫn chạy môi trường Docker local |

Trích xuất nhanh nội dung text của hai tài liệu nguồn (không thực thi macro/script):

```bash
python tools/read_project_docs.py
```

Nếu máy chưa có Python thật (chỉ có stub Microsoft Store), có thể giải nén
`.docx` (là file zip) và đọc `word/document.xml`, hoặc strip-tag file `.html`
bằng Node — miễn là chỉ đọc dữ liệu, không thực thi nội dung bên trong.

Nếu hai tài liệu mâu thuẫn nhau hoặc mâu thuẫn với yêu cầu hiện tại của
người dùng: **dừng lại, nêu rõ điểm mâu thuẫn để xác nhận**, không tự suy
đoán.

## 3. Kiến trúc & quy ước bắt buộc

**Backend (Laravel)**

| Lớp | Vị trí | Ghi chú |
|---|---|---|
| Controller | `app/Http/Controllers/Api/V1/` | Chỉ điều phối request/response, không chứa logic dài |
| Service | `app/Services/` | Toàn bộ business logic; ghi nhiều bảng phải bọc `DB::transaction()` |
| Repository | `app/Repositories/` | Toàn bộ truy vấn dữ liệu |
| Form Request | `app/Http/Requests/` | Validate đầu vào |
| Model | `app/Models/` | Eloquent, PascalCase số ít (`Employee.php`) |

**Frontend (Vue 3)**

| Loại | Vị trí | Ghi chú |
|---|---|---|
| Màn hình | `resources/js/views/` | |
| Component tái sử dụng | `resources/js/components/` | `PascalCase.vue` |
| Pinia store | `resources/js/stores/` | `camelCase`, tiền tố `use...Store` |
| Gọi API | `resources/js/services/` | hậu tố `...Service.js` |
| Route | `resources/js/router/` | route nghiệp vụ `kebab-case` |

**Naming chung**: bảng MySQL `snake_case` số nhiều; endpoint `/api/v1/...`
tài nguyên `kebab-case` số nhiều (VD `/api/v1/employee-contracts`); mỗi
form phải hiện lỗi validate HTTP 422 ngay tại trường nhập liệu.

## 4. Quy trình 6 bước khi làm một tính năng mới

Áp dụng cho cả người và AI khi thêm một chức năng CRUD/nghiệp vụ mới
(theo mục 3.3 của `YEU_CAU_DU_AN_QLNS.html`):

1. **Migration & Model** — `php artisan make:model X -m`, khai báo rõ kiểu
   dữ liệu, `foreignId`, index.
2. **Form Request Validation** — `php artisan make:request StoreXRequest`.
3. **Service & Repository** — logic nằm ở `XService.php`, không viết logic
   dài trong Controller; bọc `DB::transaction()` khi ghi nhiều bảng.
4. **Controller & Route** — gọi Service trong Controller, khai báo route
   `/api/v1/...`; viết PHPUnit test xác nhận response JSON chuẩn 200/201.
5. **API Service & Pinia Store (FE)** — `xService.js` gọi Axios,
   `useXStore.js` lưu state.
6. **Component & xử lý lỗi (FE)** — dựng UI, bắt buộc hiển thị lỗi 422
   ngay dưới từng ô nhập liệu.

## 5. Yêu cầu phi chức năng cần nhớ

- Bảo mật: tuân thủ OWASP Top-10; **JWT access token hết hạn 1h, refresh
  token 7 ngày**; mật khẩu mã hóa bcrypt.
- Hiệu năng: API báo cáo cần cache Redis để đạt response time < 300ms.
- Test: PHPUnit coverage ≥ 80%; format code PSR-12 (backend) và
  ESLint/Prettier (frontend).
- Tương thích: responsive 100% desktop/tablet/mobile.
- Khi bàn giao dự án phải có `CODE_MAP.md` ở gốc repo — bảng tra cứu
  "tính năng nằm ở file nào" (view FE, controller/service BE, bảng DB,
  endpoint). Cập nhật file này ngay khi thêm/di chuyển một chức năng
  (xem `docs/CODING_STANDARDS.md`).

## 6. Lộ trình 75 ngày (tổng quan)

| Phase | Ngày | Nội dung |
|---|---|---|
| 1 | 01–15 | Môi trường, Core System & Auth (Docker, Laravel/Vue init, ERD, Migrations, Seeders, JWT, RBAC, Audit Log, Auth UI) |
| 2 | 16–30 | Phòng ban & Hồ sơ Nhân viên (BE + FE) |
| 3 | 31–45 | Chấm công & Nghỉ phép |
| 4 | 46–60 | Tính lương & Báo cáo/Dashboard |
| 5 | 61–70 | Security & Realtime (Rate limiting, WebSocket, Notifications) |
| 6 | 71–75 | Testing, cleanup, tài liệu, bàn giao |

Chi tiết từng ngày nằm trong `docs/Ke-hoach-trien-khai-du-an-QLNS.docx` —
**luôn đọc lại mục của đúng ngày đang làm**, không suy đoán từ bảng tóm tắt
này.

Xác định đang ở ngày nào: xem commit gần nhất dạng `dayNN` bằng
`git log --oneline -5`, ngày tiếp theo là NN+1.

## 7. Prompt mẫu để giao việc mỗi ngày

Copy khối dưới, thay `{N}` bằng số ngày, dùng cho AI hoặc để tự nhắc bản
thân khi làm thủ công:

```text
Tôi đang làm Ngày {N} của dự án QLNS theo docs/Ke-hoach-trien-khai-du-an-QLNS.docx.

Trước khi code:
1. Đọc AGENTS.md để nắm quy tắc bắt buộc.
2. Trích xuất và đọc đúng mục "Ngày {N}" trong docs/Ke-hoach-trien-khai-du-an-QLNS.docx,
   đối chiếu với các mục liên quan trong docs/YEU_CAU_DU_AN_QLNS.html.
3. Kiểm tra code/migration/model hiện có (git log, cấu trúc app/) để biết
   phần nào đã làm ở các ngày trước, tránh làm lại hoặc phá vỡ quy ước cũ.

Khi triển khai:
- Tuân thủ đúng cấu trúc Controller/Service/Repository/Request theo
  docs/CODING_STANDARDS.md.
- Nếu tài liệu mâu thuẫn hoặc yêu cầu vượt phạm vi Ngày {N}, dừng lại và hỏi
  trước khi tự quyết định.
- Viết test phù hợp cho phần vừa thêm; chạy composer lint:test (PSR-12).

Khi xong: tóm tắt ngắn gọn đã đối chiếu mục nào trong 2 tài liệu nguồn,
và các file đã tạo/sửa.
```

## 8. Definition of Done cho một ngày

- Chức năng của đúng ngày kế hoạch chạy được, có test PHPUnit tương ứng
  (`php artisan test`) pass xanh.
- `./vendor/bin/pint` sạch trên các file mới/sửa (không bắt buộc sửa các
  file cũ ngoài phạm vi thay đổi).
- Không phá vỡ checklist tuần liên quan (xem cuối
  `docs/Ke-hoach-trien-khai-du-an-QLNS.docx`).
- Commit message ngắn gọn dạng `dayNN` theo quy ước hiện có của repo.
