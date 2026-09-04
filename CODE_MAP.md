# Bản đồ Mã nguồn dự án QLNS

Tài liệu tra cứu "tính năng nằm ở file nào" — bắt buộc theo [`docs/YEU_CAU_DU_AN_QLNS.html`](docs/YEU_CAU_DU_AN_QLNS.html) mục 6.1 và [`docs/CODING_STANDARDS.md`](docs/CODING_STANDARDS.md). Cập nhật ngay khi thêm/di chuyển một chức năng, không dồn tới cuối dự án.

## 1. Xác thực (JWT Authentication)

| Hạng mục | Chi tiết |
|---|---|
| Giao diện Frontend | [`resources/js/views/Login.vue`](resources/js/views/Login.vue), [`resources/js/stores/authStore.js`](resources/js/stores/authStore.js), [`resources/js/services/authService.js`](resources/js/services/authService.js) |
| Xử lý Backend | [`app/Http/Controllers/Api/V1/AuthController.php`](app/Http/Controllers/Api/V1/AuthController.php), [`app/Services/AuthService.php`](app/Services/AuthService.php), [`app/Services/Jwt/JwtService.php`](app/Services/Jwt/JwtService.php), [`app/Auth/JwtGuard.php`](app/Auth/JwtGuard.php), [`app/Repositories/UserRepository.php`](app/Repositories/UserRepository.php), [`app/Repositories/RefreshTokenRepository.php`](app/Repositories/RefreshTokenRepository.php) |
| Validation | [`app/Http/Requests/Auth/LoginRequest.php`](app/Http/Requests/Auth/LoginRequest.php), [`app/Http/Requests/Auth/RefreshTokenRequest.php`](app/Http/Requests/Auth/RefreshTokenRequest.php) |
| Database & API | Bảng `users`, `refresh_tokens`. Endpoint `POST /api/v1/auth/login`, `POST /api/v1/auth/refresh`, `GET /api/v1/auth/me`, `POST /api/v1/auth/logout` — khai báo tại [`routes/api/v1/auth.php`](routes/api/v1/auth.php) |
| Test | [`tests/Feature/Auth/AuthTest.php`](tests/Feature/Auth/AuthTest.php), [`tests/Feature/Auth/LoginValidationTest.php`](tests/Feature/Auth/LoginValidationTest.php) |
| Danh sách tệp cần sửa khi bảo trì | Toàn bộ các file trên; đổi thời hạn token sửa [`config/config_jwt.php`](config/config_jwt.php) + `.env` (`JWT_ACCESS_TTL`, `JWT_REFRESH_TTL_DAYS`) |

## 2. Phân quyền (RBAC)

| Hạng mục | Chi tiết |
|---|---|
| Giao diện Frontend | *(chưa có UI quản lý Role/Permission riêng — dự kiến làm sau)* |
| Xử lý Backend | [`app/Http/Middleware/EnsurePermission.php`](app/Http/Middleware/EnsurePermission.php) (alias `permission`, đăng ký tại [`bootstrap/app.php`](bootstrap/app.php)) |
| Model liên quan | [`app/Models/Role.php`](app/Models/Role.php), [`app/Models/Permission.php`](app/Models/Permission.php) (dùng [`app/Models/Concerns/Auditable.php`](app/Models/Concerns/Auditable.php)) |
| Database & API | Bảng `roles`, `permissions`, `role_permissions`, `user_roles`. Danh sách mã quyền: [`database/seeders/PermissionSeeder.php`](database/seeders/PermissionSeeder.php); phân quyền theo role: [`database/seeders/RolePermissionSeeder.php`](database/seeders/RolePermissionSeeder.php) |
| Test | [`tests/Feature/PermissionMiddlewareTest.php`](tests/Feature/PermissionMiddlewareTest.php) |
| Danh sách tệp cần sửa khi bảo trì | Thêm route cần giới hạn quyền: gắn `->middleware(['auth:api', 'permission:<mã-quyền>'])` trong file route module tương ứng ([`routes/api/v1/`](routes/api/v1)) |

## 3. Audit Log (Ghi vết thao tác)

| Hạng mục | Chi tiết |
|---|---|
| Xử lý Backend | [`app/Models/AuditLog.php`](app/Models/AuditLog.php), [`app/Observers/AuditObserver.php`](app/Observers/AuditObserver.php), [`app/Models/Concerns/Auditable.php`](app/Models/Concerns/Auditable.php) |
| Database | Bảng `audit_logs` |
| Danh sách tệp cần sửa khi bảo trì | Muốn Model nào được tự động ghi log created/updated/deleted: thêm `use Auditable;` vào Model đó (xem ví dụ [`app/Models/Role.php`](app/Models/Role.php)) |

## 4. Layout chính & Điều hướng

| Hạng mục | Chi tiết |
|---|---|
| Giao diện Frontend | [`resources/js/App.vue`](resources/js/App.vue) (chọn layout theo `route.meta.layout`), [`resources/js/components/layout/AppLayout.vue`](resources/js/components/layout/AppLayout.vue), [`AppHeader.vue`](resources/js/components/layout/AppHeader.vue), [`AppSidebar.vue`](resources/js/components/layout/AppSidebar.vue), [`AppBreadcrumbs.vue`](resources/js/components/layout/AppBreadcrumbs.vue) |
| Theme (Dark/Light) | [`resources/js/plugins/vuetify.js`](resources/js/plugins/vuetify.js) (theme `qlnsDark` / `qlnsLight`), đổi qua `useTheme()` trong [`AppHeader.vue`](resources/js/components/layout/AppHeader.vue) |
| Router | [`resources/js/router/index.js`](resources/js/router/index.js) — route nào không dùng layout chính khai `meta: { layout: 'blank' }` (ví dụ `/login`) |
| Danh sách tệp cần sửa khi bảo trì | Thêm mục menu mới: [`AppSidebar.vue`](resources/js/components/layout/AppSidebar.vue). Thêm route trang mới: [`router/index.js`](resources/js/router/index.js) + tạo view trong [`resources/js/views/`](resources/js/views) |

## 5. Dashboard (tạm thời)

| Hạng mục | Chi tiết |
|---|---|
| Giao diện Frontend | [`resources/js/views/Dashboard.vue`](resources/js/views/Dashboard.vue), [`resources/js/components/dashboard/StatCards.vue`](resources/js/components/dashboard/StatCards.vue) |
| Ghi chú | Đang dùng **dữ liệu minh họa** (hardcode), chưa nối API thật — Dashboard thật (biểu đồ, số liệu động) thuộc Phase Báo cáo & Thống kê (Ngày 51-60 theo kế hoạch) |

## 6. Phòng ban (Departments)

| Hạng mục | Chi tiết |
|---|---|
| Giao diện Frontend | [`resources/js/views/Department/Departments.vue`](resources/js/views/Department/Departments.vue) (danh sách dạng cây thụt lề + tìm kiếm + lọc trạng thái), [`resources/js/views/Department/DepartmentForm.vue`](resources/js/views/Department/DepartmentForm.vue) (modal Thêm/Sửa, dựng trên `FormDialog`/`FormSection` dùng chung — xem mục 7), [`resources/js/stores/useDepartmentStore.js`](resources/js/stores/useDepartmentStore.js), [`resources/js/services/departmentService.js`](resources/js/services/departmentService.js) |
| Xử lý Backend | [`app/Http/Controllers/Api/V1/DepartmentController.php`](app/Http/Controllers/Api/V1/DepartmentController.php), [`app/Services/DepartmentService.php`](app/Services/DepartmentService.php) (gồm `generateCode()` tự sinh mã, xem Ghi chú), [`app/Repositories/DepartmentRepository.php`](app/Repositories/DepartmentRepository.php) (có `tree()`/`buildTree()` dựng cây và `wouldCreateCycle()` chống vòng lặp cha-con) |
| Model | [`app/Models/Department.php`](app/Models/Department.php) — tự tham chiếu `parent()`/`children()` (cây phân cấp), `manager()` trỏ tới `Employee` (khóa `manager_id`), dùng `Auditable` |
| Validation | [`app/Http/Requests/Department/StoreDepartmentRequest.php`](app/Http/Requests/Department/StoreDepartmentRequest.php), [`app/Http/Requests/Department/UpdateDepartmentRequest.php`](app/Http/Requests/Department/UpdateDepartmentRequest.php) — **không** có rule cho `code` (xem Ghi chú) |
| Database & API | Bảng `departments` (migration từ Ngày 04, khóa ngoại `manager_id` → `employees` thêm ở migration riêng). Endpoint `GET/POST /api/v1/departments`, `GET /api/v1/departments/tree`, `PUT/DELETE /api/v1/departments/{department}` — khai báo tại [`routes/api/v1/departments.php`](routes/api/v1/departments.php), yêu cầu quyền `department.view`/`department.manage` |
| Test | [`tests/Feature/Department/DepartmentTest.php`](tests/Feature/Department/DepartmentTest.php) — quyền hạn (401/403), tạo (mã tự sinh đúng định dạng, bỏ qua `code` client gửi, không đọc nhầm mã cũ dạng `PB-01`, không tái dùng mã đã xóa mềm), sửa (bỏ qua `code`, chống vòng lặp cha-con — cả tự làm cha chính mình lẫn chuyển cha xuống dưới con), xóa (chặn khi còn con, xóa mềm khi không còn con), cây phân cấp lồng nhau |
| Ghi chú | CRUD + cây phân cấp (dựng cây, chống vòng lặp) xong Ngày 16-17. UI đầy đủ (danh sách, modal Thêm/Sửa, xác nhận Xóa, tìm kiếm/lọc) xong Ngày 18-19. **Mã `code` do `DepartmentService::generateCode()` tự sinh** (`PB001`, `PB002`...), request Store/Update không nhận `code` từ client — sửa định dạng mã thì chỉ cần sửa hàm này. **Xóa phòng ban còn con nay bị chặn ở backend** (`DepartmentService::delete()` — 422 nếu còn con), không còn phụ thuộc riêng vào chặn phía UI. PHPUnit test xong Ngày 20 |
| Danh sách tệp cần sửa khi bảo trì | Toàn bộ các file trên; còn nợ: thêm ô chọn Trưởng phòng vào `DepartmentForm.vue` (Model `Employee` đã có từ Ngày 21, chỉ còn phần UI) |

## 7. Nhân viên (Employee) — mới có Model, chưa có API

| Hạng mục | Chi tiết |
|---|---|
| Model | [`app/Models/Employee.php`](app/Models/Employee.php) — quan hệ `user()` (belongsTo User, đối ứng `User::employee()`), `department()`, `position()`, tự tham chiếu `manager()`/`subordinates()` (giống cặp `parent()`/`children()` của Department), `contracts()`/`bankAccounts()`. [`app/Models/Position.php`](app/Models/Position.php) — `belongsTo` Department. [`app/Models/EmployeeContract.php`](app/Models/EmployeeContract.php), [`app/Models/EmployeeBankAccount.php`](app/Models/EmployeeBankAccount.php) — đều `belongsTo` Employee. Cả 4 Model dùng `Auditable` + `SoftDeletes` |
| Database | Bảng `employees` (migration Ngày 04: `user_id`, `department_id`, `position_id`, `manager_id` tự tham chiếu, `code`, `full_name`, `date_of_birth`, `gender`, `phone`, `company_email`, `personal_email`, `cccd`, `addresses`, `personal_tax_code`, `avatar`, `hire_date`, `probation_end_date`, `termination_date`, `employment_status`), `positions`, `employee_contracts`, `employee_bank_accounts` — schema có sẵn, đã khớp với 3 Model trên |
| Ghi chú | Ngày 21 mới xong **Model**, chưa có Controller/Service/Repository/Request/Route — chưa gọi được qua API. Đã bàn nhưng **chưa quyết dứt điểm**: mã `code` của Employee có tự sinh như `PB001` của Department không — sẽ chốt khi viết `EmployeeService` (Ngày 23) |
| Danh sách tệp cần sửa khi bảo trì | Ngày 22: `StoreEmployeeRequest`/`UpdateEmployeeRequest`. Ngày 23: `EmployeeController`, `EmployeeService`, `EmployeeRepository`, route `routes/api/v1/employees.php`, permission `employee.*` đã có sẵn trong `PermissionSeeder.php` (`employee.view`, `employee.create`, `employee.update`, `employee.delete`) — chỉ cần gắn middleware, không cần seed thêm |

## 8. Hạ tầng chung (không thuộc 1 module cụ thể)

| Hạng mục | Chi tiết |
|---|---|
| Axios & Interceptor | [`resources/js/bootstrap.js`](resources/js/bootstrap.js) — tự gắn `Authorization` header, tự xử lý 401 (đá về `/login`, trừ chính request login) và 403 |
| Icon set | `@mdi/font`, import tại [`resources/js/plugins/vuetify.js`](resources/js/plugins/vuetify.js) |
| Component UI dùng chung | [`resources/js/components/common/`](resources/js/components/common): `FormDialog.vue` (khung dialog nhập liệu — banner, alert lỗi chung, nút Hủy/Lưu, slot `footer-note`), `FormSection.vue` (khối nhóm field có tiêu đề), `DataTable.vue` (bọc `v-data-table`, tự forward slot), `SearchField.vue` (ô tìm kiếm debounce), `PageHeader.vue` (tiêu đề trang + slot `actions`). Module mới nên tái dùng các component này thay vì tự viết lại |
| Hiệu ứng Glassmorphism | Class `.glass-panel` khai tại [`resources/css/app.css`](resources/css/app.css) — nền `rgba(var(--v-theme-surface), 0.72)` + `backdrop-filter: blur(12px)`, tự đổi theo theme. Theo yêu cầu thẩm mỹ mục 6, [YEU_CAU_DU_AN_QLNS.html](docs/YEU_CAU_DU_AN_QLNS.html#L987). Đang dùng ở `Login.vue`, `FormDialog.vue`, dialog Xóa và thanh lọc của `Departments.vue` |
| Docker / môi trường local | [`docker-compose.yml`](docker-compose.yml), [`docker/`](docker), chi tiết tại [`docs/LOCAL_DEVELOPMENT.md`](docs/LOCAL_DEVELOPMENT.md) |
| Tài liệu API (Swagger/OpenAPI) | Cấu hình: [`config/l5-swagger.php`](config/l5-swagger.php). Xem tại `/api/documentation`. Annotation dùng chung (`OA\Info`, `OA\SecurityScheme`) đặt tại [`app/Http/Controllers/Controller.php`](app/Http/Controllers/Controller.php); mỗi Controller tự khai `#[OA\Get\|Post(...)]` phía trên từng hàm (xem mẫu tại [`app/Http/Controllers/Api/V1/AuthController.php`](app/Http/Controllers/Api/V1/AuthController.php)). Sinh lại tài liệu bằng `php artisan l5-swagger:generate` sau khi thêm/sửa annotation |

---

**Quy ước cập nhật file này**: mỗi khi thêm 1 tính năng/module mới, thêm 1 mục mới theo đúng bảng 5 cột như trên (Frontend / Backend / Database & API / Test / Tệp cần sửa khi bảo trì) — không cần đủ cả 5 cột nếu module đó không có, nhưng phải cập nhật ngay trong ngày làm, không để dồn. Đường dẫn file viết dạng link Markdown `[tên](đường/dẫn)` để bấm mở trực tiếp.
