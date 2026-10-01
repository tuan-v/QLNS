# Bản đồ Mã nguồn dự án QLNS

Tra nhanh màn hình, API, backend, dữ liệu và test của từng module — theo [`docs/YEU_CAU_DU_AN_QLNS.html`](docs/YEU_CAU_DU_AN_QLNS.html) mục 6.1 (bảng chỉ mục tính năng) và [`docs/CODING_STANDARDS.md`](docs/CODING_STANDARDS.md).

> Nếu tài liệu khác code, tin code: [`routes/api/v1/`](routes/api/v1) là danh sách API đúng nhất. Chỉ ghi "cái gì ở đâu" và quy tắc còn đúng hiện nay; lịch sử/lý do làm xem `git log` và comment trong code.

**Đi nhanh:** [Bắt đầu nhanh](#bắt-đầu-nhanh) · [Người mới](#tổng-quan-cho-người-mới) · [Muốn sửa gì → mở đâu](#muốn-sửa-gì--mở-đâu-trước) · [Chọn module](#chọn-module) · [Liên thông](#liên-thông-giữa-module) · [Tra cứu theo chức năng](#tra-cứu-theo-chức-năng-api--controller--màn-hình) · [Checklist sửa code](#checklist-sửa-code-an-toàn)

## Bắt đầu nhanh

| Bạn đang cần | Bắt đầu từ |
| --- | --- |
| Mới tham gia dự án | [Tổng quan cho người mới](#tổng-quan-cho-người-mới) |
| Có task nhưng chưa biết mở file nào | [Muốn sửa gì → mở đâu trước](#muốn-sửa-gì--mở-đâu-trước) |
| Đã biết module hoặc màn hình cần tìm | [Chọn module](#chọn-module) |
| Chỉ nhớ chức năng ("Duyệt nghỉ phép"), chưa biết tên hàm | [Tra cứu theo chức năng](#tra-cứu-theo-chức-năng-api--controller--màn-hình) |
| Thêm chức năng mới end-to-end | [Quy trình thêm chức năng mới](#quy-trình-thêm-chức-năng-mới) |
| Lỗi nghi do module khác | [Liên thông giữa module](#liên-thông-giữa-module) |
| Chạy thử, cần tài khoản demo | [Tài khoản demo](#tài-khoản-demo) |

## Tổng quan cho người mới

**Hệ thống giải quyết việc gì?** QLNS quản lý nhân sự trong một công ty: hồ sơ & hợp đồng → ca làm việc → chấm công (có duyệt) → nghỉ phép → bảng lương. Một thao tác của người này tạo ra việc cần xử lý của người khác (nhân viên chấm công → HR duyệt → mới được tính vào lương), nên hệ thống có thông báo + cập nhật realtime thay vì bắt tải lại trang.

**Một yêu cầu đi qua mã nguồn như thế nào?**

`Màn hình Vue → service FE (axios) → API → middleware (auth, permission) → Request (validate) → Controller → Service (nghiệp vụ) → Repository → Model/DB → Resource (JSON) → Test`

| Thuật ngữ | Nghĩa trong dự án |
| --- | --- |
| View / Component | File `.vue` — nơi người dùng xem và bấm ([`views/`](resources/js/views), [`components/`](resources/js/components)) |
| Service FE | `services/xxxService.js` — chỉ gọi API bằng axios |
| Store | Pinia `stores/useXxxStore.js` — dùng khi nhiều trang chung dữ liệu/realtime |
| Controller | Nhận request, gọi Service, trả JSON — **mỏng**, không chứa nghiệp vụ |
| Service BE | Quy tắc nghiệp vụ, `DB::transaction`, gọi Repository, bắn thông báo/realtime |
| Repository | Chỉ truy vấn DB |
| Permission | Chuỗi quyền như `attendance.approve`, chặn ở route bằng `permission:xxx` |

Khi mới tìm hiểu, dừng ở **Vai trò**, **Liên thông** và **Luồng demo nhanh** của mỗi module là đủ; chỉ xuống bảng file/luồng chi tiết khi đã có một màn hình hoặc lỗi cụ thể.

### Luồng 1 request (mẫu: thêm nhân viên)

```
Employees.vue → EmployeeForm.vue → useEmployeeStore.js → employeeService.create()
→ POST /api/v1/employees → auth:api → permission:employee.create → StoreEmployeeRequest
→ EmployeeController::store → EmployeeService::create → EmployeeRepository → Employee → EmployeeResource → JSON
→ store nhận response → toast + đóng dialog → Employees.vue gọi lại GET làm mới bảng
```

Dừng sớm khi sai: validate client (0 request) → `auth:api` 401 → `permission:*` 403 → FormRequest 422.

### Tài khoản demo

| Vai trò | Email | Mật khẩu | Ghi chú |
| --- | --- | --- | --- |
| Admin | `admin@qlns.local` | `Admin@123` | Toàn quyền; không gắn hồ sơ nhân viên |
| HR | `hr@qlns.local` | `Hr@123456` | Quản lý nhân sự, duyệt chấm công/phép, bảng lương |
| Manager | `manager@qlns.local` | `Manager@123` | Duyệt phép cấp 1, xem tổng hợp chấm công |
| Employee | `employee@qlns.local` | `Employee@123` | Tự chấm công, xin phép, xem lương mình |

Nguồn: [`UserSeeder.php`](database/seeders/UserSeeder.php); chạy tại `http://localhost:8080` (Docker, xem [`docs/LOCAL_DEVELOPMENT.md`](docs/LOCAL_DEVELOPMENT.md)). Đổi mật khẩu mặc định trước khi triển khai thật.

## Muốn sửa gì → mở đâu trước

| Muốn sửa | Mở đầu tiên |
| --- | --- |
| Chữ, màu, icon, vị trí nút | View `.vue` trong [`resources/js/views`](resources/js/views), sau đó component được import |
| Nút ẩn/hiện hoặc bị disable | `v-if`/`:disabled` trong view; quyền: `auth.permissions.includes(...)`, `can()` ở [`AppSidebar.vue`](resources/js/components/layout/AppSidebar.vue) |
| Hành động khi bấm nút | Hàm `@click` trong view → tìm `xxxService.method()` → file ở [`services/`](resources/js/services) |
| URL màn hình / quyền vào trang | [`router/index.js`](resources/js/router/index.js) (`meta.permission`) |
| API và quyền của API | [`routes/api/v1/<module>.php`](routes/api/v1) |
| Validate dữ liệu | [`app/Http/Requests`](app/Http/Requests); form FE: `:rules` + [`validationRules.js`](resources/js/composables/validationRules.js) |
| Quy tắc nghiệp vụ / trạng thái | [`app/Services`](app/Services) (Controller không chứa nghiệp vụ) |
| Truy vấn dữ liệu | [`app/Repositories`](app/Repositories), Model ở [`app/Models`](app/Models) |
| Cấu trúc bảng | [`database/migrations`](database/migrations) (thêm migration mới, không sửa migration đã chạy) và Model (`$fillable`, `$casts`) |
| Response JSON / ẩn field nhạy cảm | [`app/Http/Resources`](app/Http/Resources) (`EmployeeResource` ẩn CCCD/lương cho cấp dưới) |
| Cách tính công / lương | [`WorkTimeCalculationService`](app/Services/WorkTimeCalculationService.php), [`PayrollService`](app/Services/PayrollService.php) |
| Thông báo / chuông | [`NotificationService`](app/Services/NotificationService.php), [`NotificationCenter.vue`](resources/js/components/layout/NotificationCenter.vue) |
| Trang không tự làm mới (realtime) | Trait `BroadcastsChanges` ở model + `channels.php` + `useRealtimeRefresh` ở trang (module 11) |
| Lỗi 401 | `JwtGuard`, `bootstrap.js` (tự refresh), token hết hạn |
| Lỗi 403 | `permission:xxx` ở route, `PermissionSeeder`/`RolePermissionSeeder`, quyền của role (`/roles`) |
| Lỗi 422 | Request class của API đó |
| Lỗi 429 | [`config/rate_limits.php`](config/rate_limits.php) |
| Lỗi 500 | `storage/logs/laravel.log` (trong container `app`), Controller/Service |

## Quy trình thêm chức năng mới

Theo khuôn `Position` (gọn nhất) và `Employee` (đầy đủ nhất).

| Lớp | Các bước |
| --- | --- |
| Backend | Migration → Model → Request → Repository → Service (`DB::transaction`) → Controller (mỏng) → Route (+`permission:xxx`) → Resource → Test. Mẫu: [`PositionController`](app/Http/Controllers/Api/V1/PositionController.php), [`PositionService`](app/Services/PositionService.php), [`PositionRepository`](app/Repositories/PositionRepository.php), [`PositionTest`](tests/Feature/Position/PositionTest.php) |
| Frontend | `xxxService.js` → Store (chỉ khi nhiều trang cần) → View danh sách (`DataTable`) → Form (`FormDialog`) → router (`meta.title`, `meta.permission`) + sidebar + `QuickSearch.vue` |
| Realtime | Trait `BroadcastsChanges` ở model + resource trong `channels.php` + `useRealtimeRefresh` ở trang |
| Tài liệu | Cập nhật đúng mục module trong file này |

## Chọn module

| # | Module | Màn hình FE | API chính | Chi tiết |
| --- | --- | --- | --- | --- |
| 1 | Xác thực, Phân quyền, Bảo mật | `/login`, `/roles` | `/auth/*`, `/roles`, `/permissions` | [→](#1-xác-thực-phân-quyền-bảo-mật) |
| 2 | Khung ứng dụng, Dashboard, Tìm nhanh | `/`, header, Ctrl+K | `/dashboard`, `/search` | [→](#2-khung-ứng-dụng-dashboard-tìm-nhanh) |
| 3 | Tổ chức (Phòng ban, Chức vụ, Địa chỉ) | `/departments`, `/positions` | `/departments`, `/positions`, `/addresses/*` | [→](#3-tổ-chức-phòng-ban-chức-vụ-địa-chỉ) |
| 4 | Nhân viên & Hồ sơ | `/employees`, `/employees/:id`, `/my-profile` | `/employees/*` | [→](#4-nhân-viên--hồ-sơ) |
| 5 | Ca làm việc & Cài đặt | `/work-shifts`, `/settings` | `/work-shifts` | [→](#5-ca-làm-việc--cài-đặt) |
| 6 | Chấm công | `/check-in`, `/attendance-history`, `/attendance-overview` | `/attendances/*` | [→](#6-chấm-công) |
| 7 | Điều chỉnh công / OT / làm ngoài lịch | `/check-in` (dialog), `/attendance-adjustments` | `/attendances/adjustments*` | [→](#7-điều-chỉnh-công--ot--làm-ngoài-lịch) |
| 8 | Nghỉ phép | `/leave-requests`, `/leave-management`, `/leave-overview` | `/leave-requests*`, `/leave-types` | [→](#8-nghỉ-phép) |
| 9 | Nghỉ việc | `/resignations`, `/my-profile` | `/resignations*` | [→](#9-nghỉ-việc) |
| 10 | Bảng lương | `/payrolls`, `/payrolls/:id` | `/payrolls*` | [→](#10-bảng-lương) |
| 11 | Thông báo & Realtime | chuông header, `/notifications` | `/notifications*`, kênh Reverb | [→](#11-thông-báo--realtime) |
| 12 | Thành phần dùng chung (FE) | — | — | [→](#12-thành-phần-dùng-chung-fe) |

**Quy ước đường dẫn.** FE = `resources/js/`; BE = `app/` (`Http/Controllers/Api/V1`, `Http/Requests`, `Http/Resources`, `Services`, `Repositories`, `Models`); route = `routes/api/v1/<tên>.php`, prefix `/api/v1`; test = `tests/Feature/<Module>/`, `tests/Unit/`. Cột "Luồng" đọc từ trái sang phải: `View → service FE → METHOD /api → middleware → Controller → Service → Repository/Model → hiệu ứng phụ`.

---

## 1. Xác thực, Phân quyền, Bảo mật

**Vai trò:** đăng nhập JWT, quên/đặt lại mật khẩu, vai trò & quyền (RBAC), audit log, giới hạn tần suất gọi API, khóa tài khoản.

- **Điểm vào:** `/login`, `/forgot-password`, `/reset-password`, `/roles`; API `/auth/*`, `/roles`, `/permissions` ([`auth.php`](routes/api/v1/auth.php), [`roles.php`](routes/api/v1/roles.php), [`permissions.php`](routes/api/v1/permissions.php)).
- **Danh mục file & hàm:** xem [cuối mục này](#danh-mục-file--hàm-module-1) — mỗi file và mỗi hàm public có link riêng.


**Luồng chính**

| Thao tác | Luồng |
| --- | --- |
| Đăng nhập | `Login.vue → authStore.login → authService.login → POST /auth/login → throttle:login (5/phút theo email+IP) → AuthController::login → AuthService → UserRepository + JwtService (access + refresh token) → lưu localStorage → router vào /` |
| Mỗi request sau đó | `axios (bootstrap.js tự gắn Authorization) → auth:api (JwtGuard: giải mã, kiểm users.status = active) → permission:xxx (EnsurePermission, cache quyền 60s)`; 401 → `bootstrap.js` tự gọi `POST /auth/refresh` rồi gửi lại request gốc |
| Quên/đặt lại mật khẩu | `ForgotPassword.vue → POST /auth/forgot-password → PasswordResetController::forgot → PasswordResetService → PasswordResetMail (luôn trả thành công)`; `ResetPassword.vue (token, email từ query) → POST /auth/reset-password → thu hồi mọi refresh token` |
| Gán quyền cho vai trò | `RolePermissionsDialog.vue → roleService.updatePermissions → PUT /roles/{id}/permissions → rbac.manage → RoleController::updatePermissions → RoleService::syncPermissions (sync thật, ghi granted_by/at) → Cache::forget("permission:{userId}") → ResourceChanged('roles') + tín hiệu 'account' cho phiên đang mở` |
| Tạo quyền mới | `RolePermissionsDialog.vue (form nhỏ) → POST /permissions → PermissionController::store → PermissionService::create` (chưa khóa API nào cho tới khi gắn `permission:<mã>` vào route) |

**Luồng demo nhanh:** Đăng nhập `admin@qlns.local` / `Admin@123` → mở `/roles` → bỏ 1 quyền của role Manager → đăng nhập `manager@qlns.local` / `Manager@123` ở tab khác: menu tương ứng biến mất ngay (không cần F5). Đăng nhập sai 6 lần liên tiếp → lần 6 báo 429.

**Quy tắc & bẫy**
- `JwtGuard` cache theo token đã giải mã; `JwtService::decodeAccessToken()` bắt cả `DomainException`/`InvalidArgumentException` để token hỏng trả 401 thay vì 500. Dòng 401 đỏ ở console sau khi token hết hạn là bình thường (đã tự refresh).
- Role Employee không có `employee.view`. `RolePermissionSeeder` xóa quyền không có trong danh sách — **không chạy lại trên DB thật** (mất quyền HR tự chỉnh); thêm quyền cho DB đang chạy bằng migration chỉ-thêm.
- Chặn gỡ `rbac.manage` khỏi role duy nhất còn giữ nó; xóa Role là xóa mềm. HR không gán được role có `rbac.manage` (`EmployeeAccountService::assertCanAssignRoles()`), `GET /roles` ẩn role Admin với người không có `rbac.manage`.
- Khóa tài khoản: nghỉ việc có hiệu lực, chấm dứt HĐ cuối, hoặc xóa hồ sơ → `users.status = inactive` + thu hồi refresh token + xóa cache quyền (`EmployeeAccountService::deactivateAccountOf()`); ký HĐ mới → `reactivateAccountOf()`.
- Rate limit: [`config/rate_limits.php`](config/rate_limits.php) (`RATE_LIMIT_*`, tắt bằng `RATE_LIMITS_ENABLED=false`), đăng ký ở `AppServiceProvider` (`RateLimiter::for`): đăng nhập 5/phút theo email+IP (và 20/phút theo IP), quên/đặt lại MK 5/phút/IP, refresh 30/phút/IP, API khác 600/phút theo `sha1(bearerToken)` (throttle chạy trước auth). Vượt → 429 + `Retry-After`; `phpunit.xml` tắt cho test thường.
- API luôn trả JSON ([`bootstrap/app.php`](bootstrap/app.php): `shouldRenderJsonWhen`, `redirectGuestsTo` trả null cho `/api/*`); `trustProxies` chỉ localhost + subnet Docker.
- Thêm model tự ghi audit: `use Auditable;`.
- Còn tồn đọng: cổng Redis 6379/MySQL 3307 mở ra ngoài, Redis không mật khẩu; Manager xem được CCCD/lương mọi nhân viên (`employee.view`); `APP_DEBUG=true`, Swagger `/api/documentation` công khai; `xlsx` chưa có bản vá, `laravel/framework` 12.67 (lên ≥ 12.69); mật khẩu chỉ yêu cầu ≥ 8 ký tự; đổi mật khẩu mặc định của tài khoản seed trước khi triển khai.

**Liên thông:** Nhân viên/Nghỉ việc/Hợp đồng đổi trạng thái → khóa/mở tài khoản (module 4, 9). Mọi route dùng `permission:*` ở module này.

<a id="danh-mục-file--hàm-module-1"></a>

### Danh mục file & hàm — module 1

#### Giao diện (9 file)

- [ForgotPassword.vue](resources/js/views/ForgotPassword.vue#L1) — route `/forgot-password`
- [Login.vue](resources/js/views/Login.vue#L1) — route `/login`
- [ResetPassword.vue](resources/js/views/ResetPassword.vue#L1) — route `/reset-password`
- [RoleForm.vue](resources/js/views/Role/RoleForm.vue#L1)
- [RolePermissionsDialog.vue](resources/js/views/Role/RolePermissionsDialog.vue#L1)
- [Roles.vue](resources/js/views/Role/Roles.vue#L1) — route `/roles`
- [authStore.js](resources/js/stores/authStore.js#L1)
- [usePermissionStore.js](resources/js/stores/usePermissionStore.js#L1)
- [useRoleStore.js](resources/js/stores/useRoleStore.js#L1)

#### Controller (4 file, 16 hàm public)

- [AuthController.php](app/Http/Controllers/Api/V1/AuthController.php#L1) — 4 hàm: [login()](app/Http/Controllers/Api/V1/AuthController.php#L60) · [refresh()](app/Http/Controllers/Api/V1/AuthController.php#L86) · [logout()](app/Http/Controllers/Api/V1/AuthController.php#L116) · [me()](app/Http/Controllers/Api/V1/AuthController.php#L135)
- [PasswordResetController.php](app/Http/Controllers/Api/V1/PasswordResetController.php#L1) — 2 hàm: [forgot()](app/Http/Controllers/Api/V1/PasswordResetController.php#L34) · [reset()](app/Http/Controllers/Api/V1/PasswordResetController.php#L63)
- [PermissionController.php](app/Http/Controllers/Api/V1/PermissionController.php#L1) — 4 hàm: [index()](app/Http/Controllers/Api/V1/PermissionController.php#L19) · [store()](app/Http/Controllers/Api/V1/PermissionController.php#L27) · [update()](app/Http/Controllers/Api/V1/PermissionController.php#L34) · [destroy()](app/Http/Controllers/Api/V1/PermissionController.php#L41)
- [RoleController.php](app/Http/Controllers/Api/V1/RoleController.php#L1) — 6 hàm: [index()](app/Http/Controllers/Api/V1/RoleController.php#L24) · [show()](app/Http/Controllers/Api/V1/RoleController.php#L37) · [store()](app/Http/Controllers/Api/V1/RoleController.php#L44) · [update()](app/Http/Controllers/Api/V1/RoleController.php#L51) · [destroy()](app/Http/Controllers/Api/V1/RoleController.php#L58) · [updatePermissions()](app/Http/Controllers/Api/V1/RoleController.php#L65)

#### Service (5 file, 22 hàm public)

- [AuthService.php](app/Services/AuthService.php#L1) — 3 hàm: [login()](app/Services/AuthService.php#L23) · [refresh()](app/Services/AuthService.php#L38) · [logout()](app/Services/AuthService.php#L54)
- [JwtService.php](app/Services/Jwt/JwtService.php#L1) — 6 hàm: [issueAccessToken()](app/Services/Jwt/JwtService.php#L31) · [accessTtlSeconds()](app/Services/Jwt/JwtService.php#L46) · [decodeAccessToken()](app/Services/Jwt/JwtService.php#L57) · [refreshTtlDays()](app/Services/Jwt/JwtService.php#L66) · [generateRefreshTokenPlain()](app/Services/Jwt/JwtService.php#L71) · [hashRefreshToken()](app/Services/Jwt/JwtService.php#L76)
- [PasswordResetService.php](app/Services/PasswordResetService.php#L1) — 2 hàm: [requestReset()](app/Services/PasswordResetService.php#L30) · [reset()](app/Services/PasswordResetService.php#L60)
- [PermissionService.php](app/Services/PermissionService.php#L1) — 4 hàm: [list()](app/Services/PermissionService.php#L18) · [create()](app/Services/PermissionService.php#L23) · [update()](app/Services/PermissionService.php#L34) · [delete()](app/Services/PermissionService.php#L43)
- [RoleService.php](app/Services/RoleService.php#L1) — 7 hàm: [list()](app/Services/RoleService.php#L22) · [find()](app/Services/RoleService.php#L27) · [create()](app/Services/RoleService.php#L32) · [update()](app/Services/RoleService.php#L43) · [delete()](app/Services/RoleService.php#L54) · [usersForRole()](app/Services/RoleService.php#L61) · [syncPermissions()](app/Services/RoleService.php#L72)

#### Repository (5 file)

- [PasswordResetRepository.php](app/Repositories/PasswordResetRepository.php#L1) — [create()](app/Repositories/PasswordResetRepository.php#L11) · [findValidByHash()](app/Repositories/PasswordResetRepository.php#L21) · [markUsed()](app/Repositories/PasswordResetRepository.php#L31) · [invalidateAllForUser()](app/Repositories/PasswordResetRepository.php#L39)
- [PermissionRepository.php](app/Repositories/PermissionRepository.php#L1) — [all()](app/Repositories/PermissionRepository.php#L10) · [find()](app/Repositories/PermissionRepository.php#L15) · [create()](app/Repositories/PermissionRepository.php#L20) · [update()](app/Repositories/PermissionRepository.php#L25) · [delete()](app/Repositories/PermissionRepository.php#L32) · [isAttachedToAnyRole()](app/Repositories/PermissionRepository.php#L37)
- [RefreshTokenRepository.php](app/Repositories/RefreshTokenRepository.php#L1) — [create()](app/Repositories/RefreshTokenRepository.php#L13) · [findValidByHash()](app/Repositories/RefreshTokenRepository.php#L26) · [revoke()](app/Repositories/RefreshTokenRepository.php#L35) · [revokeAllForUser()](app/Repositories/RefreshTokenRepository.php#L44)
- [RoleRepository.php](app/Repositories/RoleRepository.php#L1) — [all()](app/Repositories/RoleRepository.php#L10) · [find()](app/Repositories/RoleRepository.php#L15) · [create()](app/Repositories/RoleRepository.php#L20) · [update()](app/Repositories/RoleRepository.php#L25) · [delete()](app/Repositories/RoleRepository.php#L32) · [usersForRole()](app/Repositories/RoleRepository.php#L37) · [countRolesWithPermission()](app/Repositories/RoleRepository.php#L43)
- [UserRepository.php](app/Repositories/UserRepository.php#L1) — [findActiveByEmail()](app/Repositories/UserRepository.php#L9) · [findByEmail()](app/Repositories/UserRepository.php#L17) · [create()](app/Repositories/UserRepository.php#L22) · [touchLastLogin()](app/Repositories/UserRepository.php#L27)

#### Model (8 file)

- [AuditLog.php](app/Models/AuditLog.php#L1)
- [PasswordHistory.php](app/Models/PasswordHistory.php#L1)
- [PasswordResetToken.php](app/Models/PasswordResetToken.php#L1)
- [Permission.php](app/Models/Permission.php#L1)
- [RefreshToken.php](app/Models/RefreshToken.php#L1)
- [Role.php](app/Models/Role.php#L1)
- [User.php](app/Models/User.php#L1)
- [Auditable.php](app/Models/Concerns/Auditable.php#L1)

#### Request & Resource (11 file)

- [ForgotPasswordRequest.php](app/Http/Requests/Auth/ForgotPasswordRequest.php#L1)
- [LoginRequest.php](app/Http/Requests/Auth/LoginRequest.php#L1)
- [RefreshTokenRequest.php](app/Http/Requests/Auth/RefreshTokenRequest.php#L1)
- [ResetPasswordRequest.php](app/Http/Requests/Auth/ResetPasswordRequest.php#L1)
- [StorePermissionRequest.php](app/Http/Requests/Permission/StorePermissionRequest.php#L1)
- [UpdatePermissionRequest.php](app/Http/Requests/Permission/UpdatePermissionRequest.php#L1)
- [StoreRoleRequest.php](app/Http/Requests/Role/StoreRoleRequest.php#L1)
- [UpdateRolePermissionsRequest.php](app/Http/Requests/Role/UpdateRolePermissionsRequest.php#L1)
- [UpdateRoleRequest.php](app/Http/Requests/Role/UpdateRoleRequest.php#L1)
- [PermissionResource.php](app/Http/Resources/PermissionResource.php#L1)
- [RoleResource.php](app/Http/Resources/RoleResource.php#L1)

#### Lệnh, Event, Middleware, hạ tầng BE (4 file)

- [EnsurePermission.php](app/Http/Middleware/EnsurePermission.php#L1)
- [JwtGuard.php](app/Auth/JwtGuard.php#L1)
- [AuditObserver.php](app/Observers/AuditObserver.php#L1)
- [PasswordResetMail.php](app/Mail/PasswordResetMail.php#L1)

#### Migration (10 file)

- [2026_01_01_000000_create_sessions_table.php](database/migrations/2026_01_01_000000_create_sessions_table.php)
- [2026_01_01_000001_create_users_table.php](database/migrations/2026_01_01_000001_create_users_table.php)
- [2026_01_01_000002_create_roles_table.php](database/migrations/2026_01_01_000002_create_roles_table.php)
- [2026_01_01_000003_create_permissions_table.php](database/migrations/2026_01_01_000003_create_permissions_table.php)
- [2026_01_01_000004_create_user_roles_table.php](database/migrations/2026_01_01_000004_create_user_roles_table.php)
- [2026_01_01_000005_create_role_permissions_table.php](database/migrations/2026_01_01_000005_create_role_permissions_table.php)
- [2026_01_01_000006_create_refresh_tokens_table.php](database/migrations/2026_01_01_000006_create_refresh_tokens_table.php)
- [2026_01_01_000007_create_password_histories_table.php](database/migrations/2026_01_01_000007_create_password_histories_table.php)
- [2026_01_01_000008_create_password_reset_tokens_table.php](database/migrations/2026_01_01_000008_create_password_reset_tokens_table.php)
- [2026_01_05_000003_create_audit_logs_table.php](database/migrations/2026_01_05_000003_create_audit_logs_table.php)

#### Kiểm thử (8 file, 61 test)

- [AuthTest.php](tests/Feature/Auth/AuthTest.php#L1) — 11 test
- [LoginValidationTest.php](tests/Feature/Auth/LoginValidationTest.php#L1) — 6 test
- [PasswordResetTest.php](tests/Feature/Auth/PasswordResetTest.php#L1) — 9 test
- [PermissionCrudTest.php](tests/Feature/Permission/PermissionCrudTest.php#L1) — 9 test
- [PermissionMiddlewareTest.php](tests/Feature/PermissionMiddlewareTest.php#L1) — 3 test
- [RoleCrudTest.php](tests/Feature/Role/RoleCrudTest.php#L1) — 7 test
- [RolePermissionSyncTest.php](tests/Feature/Role/RolePermissionSyncTest.php#L1) — 5 test
- [HardeningTest.php](tests/Feature/Security/HardeningTest.php#L1) — 11 test

<details>
<summary><strong>Chi tiết từng màn hình Vue</strong> — 9 file</summary>

<details>
<summary><code>ForgotPassword.vue</code></summary>

- **Mã nguồn:** [resources/js/views/ForgotPassword.vue](resources/js/views/ForgotPassword.vue#L1)
- **Route FE:** `/forgot-password`
- **Gọi API:**
  - `authService.forgotPassword()` → `POST /auth/forgot-password` → [PasswordResetController::forgot()](app/Http/Controllers/Api/V1/PasswordResetController.php#L34)

</details>

<details>
<summary><code>Login.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Login.vue](resources/js/views/Login.vue#L1)
- **Route FE:** `/login`
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).
- **Store dùng:** `useAuthStore`

</details>

<details>
<summary><code>ResetPassword.vue</code></summary>

- **Mã nguồn:** [resources/js/views/ResetPassword.vue](resources/js/views/ResetPassword.vue#L1)
- **Route FE:** `/reset-password`
- **Gọi API:**
  - `authService.resetPassword()` → `POST /auth/reset-password` → [PasswordResetController::reset()](app/Http/Controllers/Api/V1/PasswordResetController.php#L63)

</details>

<details>
<summary><code>RoleForm.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Role/RoleForm.vue](resources/js/views/Role/RoleForm.vue#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).
- **Store dùng:** `useRoleStore`
- **Component con:** [FormDialog.vue](resources/js/components/common/FormDialog.vue#L1), [FormSection.vue](resources/js/components/common/FormSection.vue#L1)

</details>

<details>
<summary><code>RolePermissionsDialog.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Role/RolePermissionsDialog.vue](resources/js/views/Role/RolePermissionsDialog.vue#L1)
- **Gọi API:**
  - `roleService.show()` → `GET /roles/{x}` → [RoleController::show()](app/Http/Controllers/Api/V1/RoleController.php#L37)
  - `roleService.updatePermissions()` → `PUT /roles/{x}/permissions` → [RoleController::updatePermissions()](app/Http/Controllers/Api/V1/RoleController.php#L65)
- **Store dùng:** `usePermissionStore`
- **Component con:** [FormDialog.vue](resources/js/components/common/FormDialog.vue#L1), [FormSection.vue](resources/js/components/common/FormSection.vue#L1)

</details>

<details>
<summary><code>Roles.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Role/Roles.vue](resources/js/views/Role/Roles.vue#L1)
- **Route FE:** `/roles`
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).
- **Store dùng:** `useRoleStore`, `useResourceSyncStore`
- **Component con:** [DataTable.vue](resources/js/components/common/DataTable.vue#L1), [PageHeader.vue](resources/js/components/common/PageHeader.vue#L1), [RoleForm.vue](resources/js/views/Role/RoleForm.vue#L1), [RolePermissionsDialog.vue](resources/js/views/Role/RolePermissionsDialog.vue#L1)

</details>

<details>
<summary><code>authStore.js</code></summary>

- **Mã nguồn:** [resources/js/stores/authStore.js](resources/js/stores/authStore.js#L1)
- **Gọi API:**
  - `authService.login()` → `POST /auth/login` → [AuthController::login()](app/Http/Controllers/Api/V1/AuthController.php#L60)
  - `authService.refresh()` → `POST /auth/refresh` → [AuthController::refresh()](app/Http/Controllers/Api/V1/AuthController.php#L86)
  - `authService.me()` → `GET /auth/me` → [AuthController::me()](app/Http/Controllers/Api/V1/AuthController.php#L135)
  - `authService.logout()` → `POST /auth/logout` → [AuthController::logout()](app/Http/Controllers/Api/V1/AuthController.php#L116)
- **Store dùng:** `useAuthStore`

</details>

<details>
<summary><code>usePermissionStore.js</code></summary>

- **Mã nguồn:** [resources/js/stores/usePermissionStore.js](resources/js/stores/usePermissionStore.js#L1)
- **Gọi API:**
  - `permissionService.list()` → `GET /permissions` → [PermissionController::index()](app/Http/Controllers/Api/V1/PermissionController.php#L19)
  - `permissionService.create()` → `POST /permissions` → [PermissionController::store()](app/Http/Controllers/Api/V1/PermissionController.php#L27)
  - `permissionService.remove()` → `DELETE /permissions/{x}` → [PermissionController::destroy()](app/Http/Controllers/Api/V1/PermissionController.php#L41)
- **Store dùng:** `usePermissionStore`

</details>

<details>
<summary><code>useRoleStore.js</code></summary>

- **Mã nguồn:** [resources/js/stores/useRoleStore.js](resources/js/stores/useRoleStore.js#L1)
- **Gọi API:**
  - `roleService.list()` → `GET /roles` → [RoleController::index()](app/Http/Controllers/Api/V1/RoleController.php#L24)
  - `roleService.create()` → `POST /roles` → [RoleController::store()](app/Http/Controllers/Api/V1/RoleController.php#L44)
  - `roleService.update()` → `PUT /roles/{x}` → [RoleController::update()](app/Http/Controllers/Api/V1/RoleController.php#L51)
  - `roleService.remove()` → `DELETE /roles/{x}` → [RoleController::destroy()](app/Http/Controllers/Api/V1/RoleController.php#L58)
- **Store dùng:** `useRoleStore`

</details>

</details>

<details>
<summary><strong>Chi tiết từng hàm Controller</strong> — 16 hàm</summary>

<details>
<summary><code>AuthController</code> — 4 hàm</summary>

- **Mã nguồn:** [app/Http/Controllers/Api/V1/AuthController.php](app/Http/Controllers/Api/V1/AuthController.php#L1)

<details>
<summary><code>public login()</code> — Đăng nhập</summary>

- **Mã nguồn:** [AuthController::login()](app/Http/Controllers/Api/V1/AuthController.php#L60)
- **API:** `POST /api/v1/auth/login` — quyền `auth` ([auth.php](routes/api/v1/auth.php))
- **FE service:** [authService.login()](resources/js/services/authService.js#L6) ← gọi từ [authStore.js](resources/js/stores/authStore.js#L1)
- **Validate:** [LoginRequest](app/Http/Requests/Auth/LoginRequest.php#L1)
- **Gọi xuống:** [AuthService::login()](app/Services/AuthService.php#L23)

</details>

<details>
<summary><code>public refresh()</code> — Làm mới token</summary>

- **Mã nguồn:** [AuthController::refresh()](app/Http/Controllers/Api/V1/AuthController.php#L86)
- **API:** `POST /api/v1/auth/refresh` — quyền `auth` ([auth.php](routes/api/v1/auth.php))
- **FE service:** [authService.refresh()](resources/js/services/authService.js#L9) ← gọi từ [authStore.js](resources/js/stores/authStore.js#L1)
- **Validate:** [RefreshTokenRequest](app/Http/Requests/Auth/RefreshTokenRequest.php#L1)
- **Gọi xuống:** [AuthService::refresh()](app/Services/AuthService.php#L38)

</details>

<details>
<summary><code>public logout()</code> — Đăng xuất</summary>

- **Mã nguồn:** [AuthController::logout()](app/Http/Controllers/Api/V1/AuthController.php#L116)
- **API:** `POST /api/v1/auth/logout` — quyền `auth` ([auth.php](routes/api/v1/auth.php))
- **FE service:** [authService.logout()](resources/js/services/authService.js#L17) ← gọi từ [authStore.js](resources/js/stores/authStore.js#L1)
- **Validate:** [RefreshTokenRequest](app/Http/Requests/Auth/RefreshTokenRequest.php#L1)
- **Gọi xuống:** [AuthService::logout()](app/Services/AuthService.php#L54)

</details>

<details>
<summary><code>public me()</code> — Thông tin của chính mình</summary>

- **Mã nguồn:** [AuthController::me()](app/Http/Controllers/Api/V1/AuthController.php#L135)
- **API:** `GET /api/v1/auth/me` — quyền `auth` ([auth.php](routes/api/v1/auth.php))
- **FE service:** [authService.me()](resources/js/services/authService.js#L12) ← gọi từ [authStore.js](resources/js/stores/authStore.js#L1)

</details>

</details>

<details>
<summary><code>PasswordResetController</code> — 2 hàm</summary>

- **Mã nguồn:** [app/Http/Controllers/Api/V1/PasswordResetController.php](app/Http/Controllers/Api/V1/PasswordResetController.php#L1)

<details>
<summary><code>public forgot()</code> — Quên mật khẩu</summary>

- **Mã nguồn:** [PasswordResetController::forgot()](app/Http/Controllers/Api/V1/PasswordResetController.php#L34)
- **API:** `POST /api/v1/auth/forgot-password` — quyền `auth` ([auth.php](routes/api/v1/auth.php))
- **FE service:** [authService.forgotPassword()](resources/js/services/authService.js#L24) ← gọi từ [ForgotPassword.vue](resources/js/views/ForgotPassword.vue#L1)
- **Validate:** [ForgotPasswordRequest](app/Http/Requests/Auth/ForgotPasswordRequest.php#L1)
- **Gọi xuống:** [PasswordResetService::requestReset()](app/Services/PasswordResetService.php#L30)

</details>

<details>
<summary><code>public reset()</code> — Đặt lại mật khẩu</summary>

- **Mã nguồn:** [PasswordResetController::reset()](app/Http/Controllers/Api/V1/PasswordResetController.php#L63)
- **API:** `POST /api/v1/auth/reset-password` — quyền `auth` ([auth.php](routes/api/v1/auth.php))
- **FE service:** [authService.resetPassword()](resources/js/services/authService.js#L27) ← gọi từ [ResetPassword.vue](resources/js/views/ResetPassword.vue#L1)
- **Validate:** [ResetPasswordRequest](app/Http/Requests/Auth/ResetPasswordRequest.php#L1)
- **Gọi xuống:** [PasswordResetService::reset()](app/Services/PasswordResetService.php#L60)

</details>

</details>

<details>
<summary><code>PermissionController</code> — 4 hàm</summary>

- **Mã nguồn:** [app/Http/Controllers/Api/V1/PermissionController.php](app/Http/Controllers/Api/V1/PermissionController.php#L1)

<details>
<summary><code>public index()</code> — Danh sách</summary>

- **Mã nguồn:** [PermissionController::index()](app/Http/Controllers/Api/V1/PermissionController.php#L19)
- **API:** `GET /api/v1/permissions` — quyền `rbac.manage` ([permissions.php](routes/api/v1/permissions.php))
- **FE service:** [permissionService.list()](resources/js/services/permissionService.js#L4) ← gọi từ [usePermissionStore.js](resources/js/stores/usePermissionStore.js#L1)
- **Gọi xuống:** [PermissionService::list()](app/Services/PermissionService.php#L18)

</details>

<details>
<summary><code>public store()</code> — Tạo mới</summary>

- **Mã nguồn:** [PermissionController::store()](app/Http/Controllers/Api/V1/PermissionController.php#L27)
- **API:** `POST /api/v1/permissions` — quyền `rbac.manage` ([permissions.php](routes/api/v1/permissions.php))
- **FE service:** [permissionService.create()](resources/js/services/permissionService.js#L7) ← gọi từ [usePermissionStore.js](resources/js/stores/usePermissionStore.js#L1)
- **Validate:** [StorePermissionRequest](app/Http/Requests/Permission/StorePermissionRequest.php#L1)
- **Gọi xuống:** [PermissionService::create()](app/Services/PermissionService.php#L23)

</details>

<details>
<summary><code>public update()</code> — Cập nhật</summary>

- **Mã nguồn:** [PermissionController::update()](app/Http/Controllers/Api/V1/PermissionController.php#L34)
- **API:** `PUT /api/v1/permissions/{permission}` — quyền `rbac.manage` ([permissions.php](routes/api/v1/permissions.php))
- **FE service:** [permissionService.update()](resources/js/services/permissionService.js#L10)
- **Validate:** [UpdatePermissionRequest](app/Http/Requests/Permission/UpdatePermissionRequest.php#L1)
- **Gọi xuống:** [PermissionService::update()](app/Services/PermissionService.php#L34)

</details>

<details>
<summary><code>public destroy()</code> — Xóa</summary>

- **Mã nguồn:** [PermissionController::destroy()](app/Http/Controllers/Api/V1/PermissionController.php#L41)
- **API:** `DELETE /api/v1/permissions/{permission}` — quyền `rbac.manage` ([permissions.php](routes/api/v1/permissions.php))
- **FE service:** [permissionService.remove()](resources/js/services/permissionService.js#L13) ← gọi từ [usePermissionStore.js](resources/js/stores/usePermissionStore.js#L1)
- **Gọi xuống:** [PermissionService::delete()](app/Services/PermissionService.php#L43)

</details>

</details>

<details>
<summary><code>RoleController</code> — 6 hàm</summary>

- **Mã nguồn:** [app/Http/Controllers/Api/V1/RoleController.php](app/Http/Controllers/Api/V1/RoleController.php#L1)

<details>
<summary><code>public index()</code> — Chỉ đọc, gate riêng "employee.update" — dùng để đổ danh sách Role vào ô chọn khi tạo tài khoản đăng nhập cho nhân viên (xem EmployeeAccountController).</summary>

- **Mã nguồn:** [RoleController::index()](app/Http/Controllers/Api/V1/RoleController.php#L24)
- **API:** `GET /api/v1/roles` — quyền `employee.update` ([roles.php](routes/api/v1/roles.php))
- **FE service:** [roleService.list()](resources/js/services/roleService.js#L4) ← gọi từ [EmployeeForm.vue](resources/js/views/Employee/EmployeeForm.vue#L1), [EmployeeProfileTab.vue](resources/js/views/Employee/EmployeeProfileTab.vue#L1), [useRoleStore.js](resources/js/stores/useRoleStore.js#L1)

</details>

<details>
<summary><code>public show()</code> — Xem chi tiết</summary>

- **Mã nguồn:** [RoleController::show()](app/Http/Controllers/Api/V1/RoleController.php#L37)
- **API:** `GET /api/v1/roles/{role}` — quyền `rbac.manage` ([roles.php](routes/api/v1/roles.php))
- **FE service:** [roleService.show()](resources/js/services/roleService.js#L7) ← gọi từ [RolePermissionsDialog.vue](resources/js/views/Role/RolePermissionsDialog.vue#L1)
- **Gọi xuống:** [RoleService::find()](app/Services/RoleService.php#L27)

</details>

<details>
<summary><code>public store()</code> — Tạo mới</summary>

- **Mã nguồn:** [RoleController::store()](app/Http/Controllers/Api/V1/RoleController.php#L44)
- **API:** `POST /api/v1/roles` — quyền `rbac.manage` ([roles.php](routes/api/v1/roles.php))
- **FE service:** [roleService.create()](resources/js/services/roleService.js#L10) ← gọi từ [useRoleStore.js](resources/js/stores/useRoleStore.js#L1)
- **Validate:** [StoreRoleRequest](app/Http/Requests/Role/StoreRoleRequest.php#L1)
- **Gọi xuống:** [RoleService::create()](app/Services/RoleService.php#L32)

</details>

<details>
<summary><code>public update()</code> — Cập nhật</summary>

- **Mã nguồn:** [RoleController::update()](app/Http/Controllers/Api/V1/RoleController.php#L51)
- **API:** `PUT /api/v1/roles/{role}` — quyền `rbac.manage` ([roles.php](routes/api/v1/roles.php))
- **FE service:** [roleService.update()](resources/js/services/roleService.js#L13) ← gọi từ [useRoleStore.js](resources/js/stores/useRoleStore.js#L1)
- **Validate:** [UpdateRoleRequest](app/Http/Requests/Role/UpdateRoleRequest.php#L1)
- **Gọi xuống:** [RoleService::update()](app/Services/RoleService.php#L43)

</details>

<details>
<summary><code>public destroy()</code> — Xóa</summary>

- **Mã nguồn:** [RoleController::destroy()](app/Http/Controllers/Api/V1/RoleController.php#L58)
- **API:** `DELETE /api/v1/roles/{role}` — quyền `rbac.manage` ([roles.php](routes/api/v1/roles.php))
- **FE service:** [roleService.remove()](resources/js/services/roleService.js#L16) ← gọi từ [useRoleStore.js](resources/js/stores/useRoleStore.js#L1)
- **Gọi xuống:** [RoleService::delete()](app/Services/RoleService.php#L54)

</details>

<details>
<summary><code>public updatePermissions()</code> — Cập nhật permissions</summary>

- **Mã nguồn:** [RoleController::updatePermissions()](app/Http/Controllers/Api/V1/RoleController.php#L65)
- **API:** `PUT /api/v1/roles/{role}/permissions` — quyền `rbac.manage` ([roles.php](routes/api/v1/roles.php))
- **FE service:** [roleService.updatePermissions()](resources/js/services/roleService.js#L19) ← gọi từ [RolePermissionsDialog.vue](resources/js/views/Role/RolePermissionsDialog.vue#L1)
- **Validate:** [UpdateRolePermissionsRequest](app/Http/Requests/Role/UpdateRolePermissionsRequest.php#L1)
- **Gọi xuống:** [RoleService::syncPermissions()](app/Services/RoleService.php#L72)

</details>

</details>

</details>

<details>
<summary><strong>Chi tiết từng hàm Service</strong> — 22 hàm</summary>

<details>
<summary><code>AuthService</code> — 3 hàm</summary>

- **Mã nguồn:** [app/Services/AuthService.php](app/Services/AuthService.php#L1)

<details>
<summary><code>public login()</code> — Đăng nhập</summary>

- **Mã nguồn:** [AuthService::login()](app/Services/AuthService.php#L23)
- **Gọi xuống:** [UserRepository::findActiveByEmail()](app/Repositories/UserRepository.php#L9) · [UserRepository::touchLastLogin()](app/Repositories/UserRepository.php#L27)
- **Hiệu ứng phụ:** `DB::transaction`
- **Được gọi bởi:** [AuthController::login()](app/Http/Controllers/Api/V1/AuthController.php#L60)

</details>

<details>
<summary><code>public refresh()</code> — Làm mới token</summary>

- **Mã nguồn:** [AuthService::refresh()](app/Services/AuthService.php#L38)
- **Gọi xuống:** [JwtService::hashRefreshToken()](app/Services/Jwt/JwtService.php#L76) · [RefreshTokenRepository::findValidByHash()](app/Repositories/RefreshTokenRepository.php#L26) · [RefreshTokenRepository::revoke()](app/Repositories/RefreshTokenRepository.php#L35)
- **Hiệu ứng phụ:** `DB::transaction`
- **Được gọi bởi:** [AuthController::refresh()](app/Http/Controllers/Api/V1/AuthController.php#L86)

</details>

<details>
<summary><code>public logout()</code> — Đăng xuất</summary>

- **Mã nguồn:** [AuthService::logout()](app/Services/AuthService.php#L54)
- **Gọi xuống:** [JwtService::hashRefreshToken()](app/Services/Jwt/JwtService.php#L76) · [RefreshTokenRepository::findValidByHash()](app/Repositories/RefreshTokenRepository.php#L26) · [RefreshTokenRepository::revoke()](app/Repositories/RefreshTokenRepository.php#L35)
- **Được gọi bởi:** [AuthController::logout()](app/Http/Controllers/Api/V1/AuthController.php#L116)

</details>

</details>

<details>
<summary><code>JwtService</code> — 6 hàm</summary>

- **Mã nguồn:** [app/Services/Jwt/JwtService.php](app/Services/Jwt/JwtService.php#L1)

<details>
<summary><code>public issueAccessToken()</code> — issue access token</summary>

- **Mã nguồn:** [JwtService::issueAccessToken()](app/Services/Jwt/JwtService.php#L31)
- **Được gọi bởi:** [AuthService::issueTokenPair()](app/Services/AuthService.php#L66)

</details>

<details>
<summary><code>public accessTtlSeconds()</code> — access ttl seconds</summary>

- **Mã nguồn:** [JwtService::accessTtlSeconds()](app/Services/Jwt/JwtService.php#L46)
- **Được gọi bởi:** [AuthService::issueTokenPair()](app/Services/AuthService.php#L66)

</details>

<details>
<summary><code>public decodeAccessToken()</code> — Bắt thêm DomainException/InvalidArgumentException (Ngày 44 — phát hiện qua kiểm thử thật): firebase/php-jwt không chỉ ném 3 loại lỗi ban đầu — token bị hỏng dạng khác (v…</summary>

- **Mã nguồn:** [JwtService::decodeAccessToken()](app/Services/Jwt/JwtService.php#L57)
- **Được gọi bởi:** [JwtGuard::user()](app/Auth/JwtGuard.php#L45)

</details>

<details>
<summary><code>public refreshTtlDays()</code> — Làm mới token ttl days</summary>

- **Mã nguồn:** [JwtService::refreshTtlDays()](app/Services/Jwt/JwtService.php#L66)
- **Được gọi bởi:** [AuthService::issueTokenPair()](app/Services/AuthService.php#L66)

</details>

<details>
<summary><code>public generateRefreshTokenPlain()</code> — Sinh refresh token plain</summary>

- **Mã nguồn:** [JwtService::generateRefreshTokenPlain()](app/Services/Jwt/JwtService.php#L71)
- **Được gọi bởi:** [AuthService::issueTokenPair()](app/Services/AuthService.php#L66)

</details>

<details>
<summary><code>public hashRefreshToken()</code> — hash refresh token</summary>

- **Mã nguồn:** [JwtService::hashRefreshToken()](app/Services/Jwt/JwtService.php#L76)
- **Được gọi bởi:** [AuthService::refresh()](app/Services/AuthService.php#L38) · [AuthService::logout()](app/Services/AuthService.php#L54) · [AuthService::issueTokenPair()](app/Services/AuthService.php#L66)

</details>

</details>

<details>
<summary><code>PasswordResetService</code> — 2 hàm</summary>

- **Mã nguồn:** [app/Services/PasswordResetService.php](app/Services/PasswordResetService.php#L1)

<details>
<summary><code>public requestReset()</code> — Luôn trả về thành công phía Controller kể cả khi email không tồn tại — nếu báo "email không tồn tại" sẽ lộ ra ai đang có tài khoản trong hệ thống (User Enumeration, nằm …</summary>

- **Mã nguồn:** [PasswordResetService::requestReset()](app/Services/PasswordResetService.php#L30)
- **Gọi xuống:** [UserRepository::findActiveByEmail()](app/Repositories/UserRepository.php#L9) · [PasswordResetRepository::invalidateAllForUser()](app/Repositories/PasswordResetRepository.php#L39) · [PasswordResetRepository::create()](app/Repositories/PasswordResetRepository.php#L11)
- **Được gọi bởi:** [PasswordResetController::forgot()](app/Http/Controllers/Api/V1/PasswordResetController.php#L34) · [EmployeeAccountService::create()](app/Services/EmployeeAccountService.php#L34)

</details>

<details>
<summary><code>public reset()</code> — Đặt lại mật khẩu</summary>

- **Mã nguồn:** [PasswordResetService::reset()](app/Services/PasswordResetService.php#L60)
- **Gọi xuống:** [PasswordResetRepository::findValidByHash()](app/Repositories/PasswordResetRepository.php#L21) · [PasswordResetRepository::markUsed()](app/Repositories/PasswordResetRepository.php#L31) · [RefreshTokenRepository::revokeAllForUser()](app/Repositories/RefreshTokenRepository.php#L44)
- **Hiệu ứng phụ:** `DB::transaction`
- **Được gọi bởi:** [PasswordResetController::reset()](app/Http/Controllers/Api/V1/PasswordResetController.php#L63)

</details>

</details>

<details>
<summary><code>PermissionService</code> — 4 hàm</summary>

- **Mã nguồn:** [app/Services/PermissionService.php](app/Services/PermissionService.php#L1)

<details>
<summary><code>public list()</code> — Lấy danh sách</summary>

- **Mã nguồn:** [PermissionService::list()](app/Services/PermissionService.php#L18)
- **Gọi xuống:** [PermissionRepository::all()](app/Repositories/PermissionRepository.php#L10)
- **Được gọi bởi:** [PermissionController::index()](app/Http/Controllers/Api/V1/PermissionController.php#L19)

</details>

<details>
<summary><code>public create()</code> — Tạo</summary>

- **Mã nguồn:** [PermissionService::create()](app/Services/PermissionService.php#L23)
- **Gọi xuống:** [PermissionRepository::create()](app/Repositories/PermissionRepository.php#L20)
- **Hiệu ứng phụ:** `DB::transaction`
- **Được gọi bởi:** [PermissionController::store()](app/Http/Controllers/Api/V1/PermissionController.php#L27)

</details>

<details>
<summary><code>public update()</code> — Cập nhật</summary>

- **Mã nguồn:** [PermissionService::update()](app/Services/PermissionService.php#L34)
- **Gọi xuống:** [PermissionRepository::update()](app/Repositories/PermissionRepository.php#L25)
- **Hiệu ứng phụ:** `DB::transaction`
- **Được gọi bởi:** [PermissionController::update()](app/Http/Controllers/Api/V1/PermissionController.php#L34)

</details>

<details>
<summary><code>public delete()</code> — Xóa</summary>

- **Mã nguồn:** [PermissionService::delete()](app/Services/PermissionService.php#L43)
- **Gọi xuống:** [PermissionRepository::isAttachedToAnyRole()](app/Repositories/PermissionRepository.php#L37) · [PermissionRepository::delete()](app/Repositories/PermissionRepository.php#L32)
- **Được gọi bởi:** [PermissionController::destroy()](app/Http/Controllers/Api/V1/PermissionController.php#L41)

</details>

</details>

<details>
<summary><code>RoleService</code> — 7 hàm</summary>

- **Mã nguồn:** [app/Services/RoleService.php](app/Services/RoleService.php#L1)

<details>
<summary><code>public list()</code> — Lấy danh sách</summary>

- **Mã nguồn:** [RoleService::list()](app/Services/RoleService.php#L22)
- **Gọi xuống:** [RoleRepository::all()](app/Repositories/RoleRepository.php#L10)

</details>

<details>
<summary><code>public find()</code> — Tìm</summary>

- **Mã nguồn:** [RoleService::find()](app/Services/RoleService.php#L27)
- **Gọi xuống:** [RoleRepository::find()](app/Repositories/RoleRepository.php#L15)
- **Được gọi bởi:** [RoleController::show()](app/Http/Controllers/Api/V1/RoleController.php#L37)

</details>

<details>
<summary><code>public create()</code> — Tạo</summary>

- **Mã nguồn:** [RoleService::create()](app/Services/RoleService.php#L32)
- **Gọi xuống:** [RoleRepository::create()](app/Repositories/RoleRepository.php#L20)
- **Hiệu ứng phụ:** `DB::transaction`
- **Được gọi bởi:** [RoleController::store()](app/Http/Controllers/Api/V1/RoleController.php#L44)

</details>

<details>
<summary><code>public update()</code> — Cập nhật</summary>

- **Mã nguồn:** [RoleService::update()](app/Services/RoleService.php#L43)
- **Gọi xuống:** [RoleRepository::update()](app/Repositories/RoleRepository.php#L25)
- **Hiệu ứng phụ:** `DB::transaction`
- **Được gọi bởi:** [RoleController::update()](app/Http/Controllers/Api/V1/RoleController.php#L51)

</details>

<details>
<summary><code>public delete()</code> — Xóa mềm — KHÔNG chặn cứng dù role đang có user (khôi phục được, khác hẳn xóa cứng).</summary>

- **Mã nguồn:** [RoleService::delete()](app/Services/RoleService.php#L54)
- **Gọi xuống:** [RoleRepository::delete()](app/Repositories/RoleRepository.php#L32)
- **Được gọi bởi:** [RoleController::destroy()](app/Http/Controllers/Api/V1/RoleController.php#L58)

</details>

<details>
<summary><code>public usersForRole()</code> — users for role</summary>

- **Mã nguồn:** [RoleService::usersForRole()](app/Services/RoleService.php#L61)
- **Gọi xuống:** [RoleRepository::usersForRole()](app/Repositories/RoleRepository.php#L37)

</details>

<details>
<summary><code>public syncPermissions()</code> — Đồng bộ permissions</summary>

- **Mã nguồn:** [RoleService::syncPermissions()](app/Services/RoleService.php#L72)
- **Gọi xuống:** [RoleRepository::usersForRole()](app/Repositories/RoleRepository.php#L37) · [RoleRepository::find()](app/Repositories/RoleRepository.php#L15)
- **Hiệu ứng phụ:** `Realtime` (tín hiệu realtime), `DB::transaction`
- **Được gọi bởi:** [RoleController::updatePermissions()](app/Http/Controllers/Api/V1/RoleController.php#L65)

</details>

</details>

</details>

<details>
<summary><strong>Danh sách test</strong> — 61 test</summary>

<details>
<summary><code>AuthTest.php</code> — 11 test</summary>

- **Mã nguồn:** [tests/Feature/Auth/AuthTest.php](tests/Feature/Auth/AuthTest.php#L1)
- [test_login_with_valid_credentials_returns_tokens](tests/Feature/Auth/AuthTest.php#L26)
- [test_login_with_invalid_password_is_rejected](tests/Feature/Auth/AuthTest.php#L40)
- [test_me_requires_valid_access_token](tests/Feature/Auth/AuthTest.php#L52)
- [test_me_returns_authenticated_user_with_valid_token](tests/Feature/Auth/AuthTest.php#L59)
- [test_me_includes_avatar_url_from_linked_employee](tests/Feature/Auth/AuthTest.php#L78)
- [test_me_avatar_url_is_null_without_linked_employee](tests/Feature/Auth/AuthTest.php#L100)
- [test_refresh_token_rotates_and_old_token_becomes_invalid](tests/Feature/Auth/AuthTest.php#L115)
- [test_logout_revokes_refresh_token](tests/Feature/Auth/AuthTest.php#L138)
- [test_expired_access_token_is_rejected](tests/Feature/Auth/AuthTest.php#L162)
- [test_malformed_access_token_is_rejected](tests/Feature/Auth/AuthTest.php#L179)
- [test_access_token_with_invalid_payload_encoding_is_rejected](tests/Feature/Auth/AuthTest.php#L197)

</details>

<details>
<summary><code>LoginValidationTest.php</code> — 6 test</summary>

- **Mã nguồn:** [tests/Feature/Auth/LoginValidationTest.php](tests/Feature/Auth/LoginValidationTest.php#L1)
- [test_login_rejects_missing_fields](tests/Feature/Auth/LoginValidationTest.php#L12)
- [test_login_rejects_invalid_email_format](tests/Feature/Auth/LoginValidationTest.php#L19)
- [test_login_rejects_password_shorter_than_minimum](tests/Feature/Auth/LoginValidationTest.php#L29)
- [test_login_rejects_email_submitted_as_array](tests/Feature/Auth/LoginValidationTest.php#L39)
- [test_login_rejects_password_submitted_as_array](tests/Feature/Auth/LoginValidationTest.php#L49)
- [test_refresh_rejects_missing_token](tests/Feature/Auth/LoginValidationTest.php#L59)

</details>

<details>
<summary><code>PasswordResetTest.php</code> — 9 test</summary>

- **Mã nguồn:** [tests/Feature/Auth/PasswordResetTest.php](tests/Feature/Auth/PasswordResetTest.php#L1)
- [test_forgot_password_with_existing_email_sends_mail](tests/Feature/Auth/PasswordResetTest.php#L28)
- [test_forgot_password_with_unknown_email_does_not_leak_existence](tests/Feature/Auth/PasswordResetTest.php#L42)
- [test_forgot_password_requires_valid_email_format](tests/Feature/Auth/PasswordResetTest.php#L57)
- [test_requesting_new_reset_invalidates_previous_token](tests/Feature/Auth/PasswordResetTest.php#L66)
- [test_can_reset_password_with_valid_token](tests/Feature/Auth/PasswordResetTest.php#L94)
- [test_reset_password_with_invalid_token_is_rejected](tests/Feature/Auth/PasswordResetTest.php#L131)
- [test_reset_password_token_cannot_be_reused](tests/Feature/Auth/PasswordResetTest.php#L142)
- [test_reset_password_requires_confirmation_to_match](tests/Feature/Auth/PasswordResetTest.php#L172)
- [test_reset_password_revokes_existing_refresh_tokens](tests/Feature/Auth/PasswordResetTest.php#L183)

</details>

<details>
<summary><code>PermissionCrudTest.php</code> — 9 test</summary>

- **Mã nguồn:** [tests/Feature/Permission/PermissionCrudTest.php](tests/Feature/Permission/PermissionCrudTest.php#L1)
- [test_unauthenticated_is_rejected](tests/Feature/Permission/PermissionCrudTest.php#L30)
- [test_user_without_rbac_manage_is_forbidden](tests/Feature/Permission/PermissionCrudTest.php#L37)
- [test_admin_can_list_permissions](tests/Feature/Permission/PermissionCrudTest.php#L48)
- [test_admin_can_create_permission](tests/Feature/Permission/PermissionCrudTest.php#L60)
- [test_code_must_follow_dot_namespaced_format](tests/Feature/Permission/PermissionCrudTest.php#L75)
- [test_code_must_be_unique](tests/Feature/Permission/PermissionCrudTest.php#L89)
- [test_admin_can_update_permission](tests/Feature/Permission/PermissionCrudTest.php#L103)
- [test_admin_can_delete_unattached_permission](tests/Feature/Permission/PermissionCrudTest.php#L118)
- [test_cannot_delete_permission_attached_to_a_role](tests/Feature/Permission/PermissionCrudTest.php#L131)

</details>

<details>
<summary><code>PermissionMiddlewareTest.php</code> — 3 test</summary>

- **Mã nguồn:** [tests/Feature/PermissionMiddlewareTest.php](tests/Feature/PermissionMiddlewareTest.php#L1)
- [test_user_with_required_permission_is_allowed](tests/Feature/PermissionMiddlewareTest.php#L35)
- [test_user_without_required_permission_is_forbidden](tests/Feature/PermissionMiddlewareTest.php#L46)
- [test_unauthenticated_request_is_rejected](tests/Feature/PermissionMiddlewareTest.php#L57)

</details>

<details>
<summary><code>RoleCrudTest.php</code> — 7 test</summary>

- **Mã nguồn:** [tests/Feature/Role/RoleCrudTest.php](tests/Feature/Role/RoleCrudTest.php#L1)
- [test_existing_readonly_index_route_is_untouched_for_employee_update_holders](tests/Feature/Role/RoleCrudTest.php#L29)
- [test_hr_without_rbac_manage_cannot_create_role](tests/Feature/Role/RoleCrudTest.php#L43)
- [test_admin_can_create_role](tests/Feature/Role/RoleCrudTest.php#L56)
- [test_role_name_must_be_unique](tests/Feature/Role/RoleCrudTest.php#L71)
- [test_admin_can_update_role](tests/Feature/Role/RoleCrudTest.php#L84)
- [test_admin_can_delete_role](tests/Feature/Role/RoleCrudTest.php#L98)
- [test_show_includes_permissions_and_users_count](tests/Feature/Role/RoleCrudTest.php#L111)

</details>

<details>
<summary><code>RolePermissionSyncTest.php</code> — 5 test</summary>

- **Mã nguồn:** [tests/Feature/Role/RolePermissionSyncTest.php](tests/Feature/Role/RolePermissionSyncTest.php#L1)
- [test_sync_grants_permissions_recording_granted_by_and_granted_at](tests/Feature/Role/RolePermissionSyncTest.php#L31)
- [test_sync_is_a_true_sync_not_additive](tests/Feature/Role/RolePermissionSyncTest.php#L52)
- [test_permission_change_takes_effect_immediately_not_after_60_seconds](tests/Feature/Role/RolePermissionSyncTest.php#L71)
- [test_cannot_remove_rbac_manage_from_the_only_role_holding_it](tests/Feature/Role/RolePermissionSyncTest.php#L99)
- [test_can_remove_rbac_manage_when_another_role_still_has_it](tests/Feature/Role/RolePermissionSyncTest.php#L119)

</details>

<details>
<summary><code>HardeningTest.php</code> — 11 test</summary>

- **Mã nguồn:** [tests/Feature/Security/HardeningTest.php](tests/Feature/Security/HardeningTest.php#L1)
- [test_api_errors_are_json_even_without_an_accept_header](tests/Feature/Security/HardeningTest.php#L57)
- [test_login_is_throttled_per_email_after_too_many_attempts](tests/Feature/Security/HardeningTest.php#L70)
- [test_password_reset_endpoints_are_throttled](tests/Feature/Security/HardeningTest.php#L88)
- [test_general_api_calls_are_throttled_per_session](tests/Feature/Security/HardeningTest.php#L99)
- [test_hr_cannot_create_an_account_with_the_admin_role](tests/Feature/Security/HardeningTest.php#L112)
- [test_hr_can_still_create_accounts_with_ordinary_roles](tests/Feature/Security/HardeningTest.php#L124)
- [test_admin_can_assign_the_admin_role](tests/Feature/Security/HardeningTest.php#L133)
- [test_role_dropdown_hides_the_admin_role_from_hr_but_not_from_admin](tests/Feature/Security/HardeningTest.php#L142)
- [test_applied_resignation_locks_the_account_and_revokes_refresh_tokens](tests/Feature/Security/HardeningTest.php#L154)
- [test_terminating_the_last_contract_locks_the_account_and_a_new_contract_reopens_it](tests/Feature/Security/HardeningTest.php#L173)
- [test_deleting_an_employee_locks_their_account](tests/Feature/Security/HardeningTest.php#L192)

</details>

</details>

---

## 2. Khung ứng dụng, Dashboard, Tìm nhanh

**Vai trò:** layout + điều hướng, trang chủ theo vai trò, tìm nhanh Ctrl+K.

- **Điểm vào:** `/` (Dashboard), header + sidebar mọi trang, `Ctrl+K`; API `GET /dashboard`, `GET /search` ([`dashboard.php`](routes/api/v1/dashboard.php), [`search.php`](routes/api/v1/search.php)).
- **Danh mục file & hàm:** xem [cuối mục này](#danh-mục-file--hàm-module-2) — mỗi file và mỗi hàm public có link riêng.


**Luồng chính**

| Thao tác | Luồng |
| --- | --- |
| Tải Dashboard | `Dashboard.vue → dashboardService.get(date) → GET /dashboard → auth:api → DashboardController → DashboardService::forUser (có employee.view → company; không → personal; vừa có employee.view vừa gắn employee → gộp thêm personal + has_personal_view) → JSON`; làm mới realtime qua `useRealtimeRefresh` |
| Chấm công trên Dashboard (nhân viên) | `PersonalCheckInCard.vue → useCheckIn() (composable dùng chung với trang Chấm công) → module 6` |
| Mở "Cần bạn xử lý" | thẻ bấm được → `router.push(item.route, query item.route_query)` (chấm công chờ duyệt kèm `date` = ngày tồn đọng sớm nhất) |
| Quick Search | `Ctrl+K → QuickSearch.vue (mục tĩnh lọc theo quyền, bỏ dấu) + debounce 400ms → searchService → GET /search?q= → SearchController → SearchService (gate employee.view, ≥ 2 ký tự, tối đa 8 nhân viên) → route + route_params → router.push` |

**Luồng demo nhanh:** Đăng nhập `employee@qlns.local` / `Employee@123` → Dashboard "personal" (chấm công, công tháng, lương gần nhất); đăng nhập `hr@qlns.local` / `Hr@123456` → Dashboard công ty; bấm `Ctrl+K`, gõ "cham cong" (không dấu) → nhảy tới trang Chấm công.

**Quy tắc & bẫy**
- Bộ lọc ngày chỉ áp cho 3 widget (tỷ lệ đi làm, xu hướng, phân bổ phòng ban); `employee_stats`, `leave_pending`, `action_items` luôn theo hiện tại. `attendance_trend` dùng mẫu số xấp xỉ, không dùng cho báo cáo chính thức.
- `actionItems()` lọc từng mục theo quyền (`attendance.approve`, `leave.approve_*`, `attendance.adjust`), count = 0 thì ẩn. Ngày tồn đọng phải `?->toDateString()` (tránh lệch ngày do UTC).
- `ALL_ITEMS` của `QuickSearch.vue` khai tay (title/icon/permission/keywords), thêm trang mới phải thêm ở đây **và** `AppSidebar.vue` **và** `router/index.js`. "Gần đây" lưu `localStorage` (bọc try/catch).
- Sidebar: `v-navigation-drawer` `temporary` dưới breakpoint mobile; khởi tạo `drawerOpen = ref(!mobile.value)` + `watch(mobile)` (cố định `false` sẽ ẩn sidebar ở mọi kích thước).
- 3 tài khoản demo `admin@/hr@/manager@qlns.local` không gắn employee → không có tab "Của tôi".

**Liên thông:** Dashboard đọc dữ liệu từ module 6, 7, 8, 10; thông báo gần đây từ module 11.

<a id="danh-mục-file--hàm-module-2"></a>

### Danh mục file & hàm — module 2

#### Giao diện (11 file)

- [Dashboard.vue](resources/js/views/Dashboard.vue#L1) — route `/`
- [AreaTrendChart.vue](resources/js/components/dashboard/AreaTrendChart.vue#L1)
- [DonutChart.vue](resources/js/components/dashboard/DonutChart.vue#L1)
- [GaugeChart.vue](resources/js/components/dashboard/GaugeChart.vue#L1)
- [StatCards.vue](resources/js/components/dashboard/StatCards.vue#L1)
- [AppBreadcrumbs.vue](resources/js/components/layout/AppBreadcrumbs.vue#L1)
- [AppHeader.vue](resources/js/components/layout/AppHeader.vue#L1)
- [AppLayout.vue](resources/js/components/layout/AppLayout.vue#L1)
- [AppSidebar.vue](resources/js/components/layout/AppSidebar.vue#L1)
- [QuickSearch.vue](resources/js/components/layout/QuickSearch.vue#L1)
- [App.vue](resources/js/App.vue#L1)

#### Controller (2 file, 2 hàm public)

- [DashboardController.php](app/Http/Controllers/Api/V1/DashboardController.php#L1) — 1 hàm: [index()](app/Http/Controllers/Api/V1/DashboardController.php#L16)
- [SearchController.php](app/Http/Controllers/Api/V1/SearchController.php#L1) — 1 hàm: [index()](app/Http/Controllers/Api/V1/SearchController.php#L16)

#### Service (2 file, 2 hàm public)

- [DashboardService.php](app/Services/DashboardService.php#L1) — 1 hàm: [forUser()](app/Services/DashboardService.php#L47)
- [SearchService.php](app/Services/SearchService.php#L1) — 1 hàm: [search()](app/Services/SearchService.php#L19)

#### Migration (1 file)

- [2026_09_05_000001_add_search_indexes_to_employees_table.php](database/migrations/2026_09_05_000001_add_search_indexes_to_employees_table.php)

#### Kiểm thử (2 file, 22 test)

- [DashboardTest.php](tests/Feature/DashboardTest.php#L1) — 16 test
- [SearchTest.php](tests/Feature/SearchTest.php#L1) — 6 test

<details>
<summary><strong>Chi tiết từng màn hình Vue</strong> — 11 file</summary>

<details>
<summary><code>Dashboard.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Dashboard.vue](resources/js/views/Dashboard.vue#L1)
- **Route FE:** `/`
- **Gọi API:**
  - `dashboardService.get()` → `GET /dashboard` → [DashboardController::index()](app/Http/Controllers/Api/V1/DashboardController.php#L16)
- **Store dùng:** `useAuthStore`, `useNotificationStore`, `useAttendanceFeedStore`, `useLeaveFeedStore`, `useResourceSyncStore`
- **Component con:** [PageHeader.vue](resources/js/components/common/PageHeader.vue#L1), [StatCards.vue](resources/js/components/dashboard/StatCards.vue#L1), [GaugeChart.vue](resources/js/components/dashboard/GaugeChart.vue#L1), [DonutChart.vue](resources/js/components/dashboard/DonutChart.vue#L1), [AreaTrendChart.vue](resources/js/components/dashboard/AreaTrendChart.vue#L1), [PersonalCheckInCard.vue](resources/js/components/dashboard/PersonalCheckInCard.vue#L1), [StatusChip.vue](resources/js/components/common/StatusChip.vue#L1)

</details>

<details>
<summary><code>AreaTrendChart.vue</code></summary>

- **Mã nguồn:** [resources/js/components/dashboard/AreaTrendChart.vue](resources/js/components/dashboard/AreaTrendChart.vue#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).

</details>

<details>
<summary><code>DonutChart.vue</code></summary>

- **Mã nguồn:** [resources/js/components/dashboard/DonutChart.vue](resources/js/components/dashboard/DonutChart.vue#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).

</details>

<details>
<summary><code>GaugeChart.vue</code></summary>

- **Mã nguồn:** [resources/js/components/dashboard/GaugeChart.vue](resources/js/components/dashboard/GaugeChart.vue#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).

</details>

<details>
<summary><code>StatCards.vue</code></summary>

- **Mã nguồn:** [resources/js/components/dashboard/StatCards.vue](resources/js/components/dashboard/StatCards.vue#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).

</details>

<details>
<summary><code>AppBreadcrumbs.vue</code></summary>

- **Mã nguồn:** [resources/js/components/layout/AppBreadcrumbs.vue](resources/js/components/layout/AppBreadcrumbs.vue#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).

</details>

<details>
<summary><code>AppHeader.vue</code></summary>

- **Mã nguồn:** [resources/js/components/layout/AppHeader.vue](resources/js/components/layout/AppHeader.vue#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).
- **Store dùng:** `useAuthStore`
- **Component con:** [NotificationCenter.vue](resources/js/components/layout/NotificationCenter.vue#L1), [QuickSearch.vue](resources/js/components/layout/QuickSearch.vue#L1)

</details>

<details>
<summary><code>AppLayout.vue</code></summary>

- **Mã nguồn:** [resources/js/components/layout/AppLayout.vue](resources/js/components/layout/AppLayout.vue#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).
- **Component con:** [AppHeader.vue](resources/js/components/layout/AppHeader.vue#L1), [AppSidebar.vue](resources/js/components/layout/AppSidebar.vue#L1), [AppBreadcrumbs.vue](resources/js/components/layout/AppBreadcrumbs.vue#L1)

</details>

<details>
<summary><code>AppSidebar.vue</code></summary>

- **Mã nguồn:** [resources/js/components/layout/AppSidebar.vue](resources/js/components/layout/AppSidebar.vue#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).
- **Store dùng:** `useAuthStore`

</details>

<details>
<summary><code>QuickSearch.vue</code></summary>

- **Mã nguồn:** [resources/js/components/layout/QuickSearch.vue](resources/js/components/layout/QuickSearch.vue#L1)
- **Gọi API:**
  - `searchService.search()` → `GET /search` → [SearchController::index()](app/Http/Controllers/Api/V1/SearchController.php#L16)
- **Store dùng:** `useAuthStore`

</details>

<details>
<summary><code>App.vue</code></summary>

- **Mã nguồn:** [resources/js/App.vue](resources/js/App.vue#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).
- **Store dùng:** `useAuthStore`, `usePresenceStore`, `useNotificationStore`, `useAttendanceFeedStore`, `useLeaveFeedStore`, `useResourceSyncStore`, `useMySyncStore`

</details>

</details>

<details>
<summary><strong>Chi tiết từng hàm Controller</strong> — 2 hàm</summary>

<details>
<summary><code>DashboardController</code> — 1 hàm</summary>

- **Mã nguồn:** [app/Http/Controllers/Api/V1/DashboardController.php](app/Http/Controllers/Api/V1/DashboardController.php#L1)

<details>
<summary><code>public index()</code> — Danh sách</summary>

- **Mã nguồn:** [DashboardController::index()](app/Http/Controllers/Api/V1/DashboardController.php#L16)
- **API:** `GET /api/v1/dashboard` — quyền `auth` ([dashboard.php](routes/api/v1/dashboard.php))
- **FE service:** [dashboardService.get()](resources/js/services/dashboardService.js#L4) ← gọi từ [Dashboard.vue](resources/js/views/Dashboard.vue#L1)
- **Gọi xuống:** [DashboardService::forUser()](app/Services/DashboardService.php#L47)

</details>

</details>

<details>
<summary><code>SearchController</code> — 1 hàm</summary>

- **Mã nguồn:** [app/Http/Controllers/Api/V1/SearchController.php](app/Http/Controllers/Api/V1/SearchController.php#L1)

<details>
<summary><code>public index()</code> — Danh sách</summary>

- **Mã nguồn:** [SearchController::index()](app/Http/Controllers/Api/V1/SearchController.php#L16)
- **API:** `GET /api/v1/search` — quyền `auth (SearchService tự lọc theo quyền)` ([search.php](routes/api/v1/search.php))
- **FE service:** [searchService.search()](resources/js/services/searchService.js#L4) ← gọi từ [QuickSearch.vue](resources/js/components/layout/QuickSearch.vue#L1)
- **Gọi xuống:** [SearchService::search()](app/Services/SearchService.php#L19)

</details>

</details>

</details>

<details>
<summary><strong>Chi tiết từng hàm Service</strong> — 2 hàm</summary>

<details>
<summary><code>DashboardService</code> — 1 hàm</summary>

- **Mã nguồn:** [app/Services/DashboardService.php](app/Services/DashboardService.php#L1)

<details>
<summary><code>public forUser()</code> — Nội dung Dashboard theo ĐÚNG quyền của $user — không phải 1 dashboard chung cho mọi người: có "employee.view" (HR/Manager/Admin) thấy số liệu TOÀN CÔNG TY, không có (Emp…</summary>

- **Mã nguồn:** [DashboardService::forUser()](app/Services/DashboardService.php#L47)
- **Được gọi bởi:** [DashboardController::index()](app/Http/Controllers/Api/V1/DashboardController.php#L16)

</details>

</details>

<details>
<summary><code>SearchService</code> — 1 hàm — Quick Search (Ctrl+K) Phase 2 — tìm THEO DỮ LIỆU THẬT , khác Phase 1 (resources/js/compon…</summary>

- **Mã nguồn:** [app/Services/SearchService.php](app/Services/SearchService.php#L1)

<details>
<summary><code>public search()</code> — search</summary>

- **Mã nguồn:** [SearchService::search()](app/Services/SearchService.php#L19)
- **Được gọi bởi:** [SearchController::index()](app/Http/Controllers/Api/V1/SearchController.php#L16)

</details>

</details>

</details>

<details>
<summary><strong>Danh sách test</strong> — 22 test</summary>

<details>
<summary><code>DashboardTest.php</code> — 16 test</summary>

- **Mã nguồn:** [tests/Feature/DashboardTest.php](tests/Feature/DashboardTest.php#L1)
- [test_unauthenticated_is_rejected](tests/Feature/DashboardTest.php#L65)
- [test_hr_sees_company_wide_scope](tests/Feature/DashboardTest.php#L70)
- [test_hr_with_employee_record_also_gets_personal_data_and_flag](tests/Feature/DashboardTest.php#L94)
- [test_hr_without_employee_record_has_no_personal_view](tests/Feature/DashboardTest.php#L114)
- [test_employee_sees_personal_scope](tests/Feature/DashboardTest.php#L130)
- [test_leave_pending_counts_split_by_manager_and_hr_correctly](tests/Feature/DashboardTest.php#L150)
- [test_action_items_empty_when_nothing_pending](tests/Feature/DashboardTest.php#L194)
- [test_hr_sees_all_three_action_items_when_pending](tests/Feature/DashboardTest.php#L204)
- [test_attendance_approval_item_points_to_the_date_with_the_oldest_pending_record](tests/Feature/DashboardTest.php#L247)
- [test_manager_only_sees_leave_action_item_not_attendance_ones](tests/Feature/DashboardTest.php#L269)
- [test_date_param_scopes_attendance_today_but_not_action_items_or_leave_pending](tests/Feature/DashboardTest.php#L308)
- [test_department_distribution_excludes_employees_not_yet_hired_on_selected_date](tests/Feature/DashboardTest.php#L361)
- [test_personal_scope_week_schedule_marks_today_and_includes_assigned_shift](tests/Feature/DashboardTest.php#L386)
- [test_personal_scope_monthly_work_reflects_approved_attendance](tests/Feature/DashboardTest.php#L410)
- [test_personal_scope_recent_requests_merges_leave_and_adjustment](tests/Feature/DashboardTest.php#L446)
- [test_personal_scope_latest_payslip_only_counts_closed_or_paid_periods](tests/Feature/DashboardTest.php#L476)

</details>

<details>
<summary><code>SearchTest.php</code> — 6 test</summary>

- **Mã nguồn:** [tests/Feature/SearchTest.php](tests/Feature/SearchTest.php#L1)
- [test_unauthenticated_is_rejected](tests/Feature/SearchTest.php#L57)
- [test_query_shorter_than_two_characters_returns_empty_without_querying](tests/Feature/SearchTest.php#L62)
- [test_hr_can_find_employee_by_name](tests/Feature/SearchTest.php#L73)
- [test_search_matches_by_code_and_company_email_too](tests/Feature/SearchTest.php#L97)
- [test_plain_employee_gets_no_employee_results](tests/Feature/SearchTest.php#L118)
- [test_result_limited_to_eight_matches](tests/Feature/SearchTest.php#L134)

</details>

</details>

---

## 3. Tổ chức: Phòng ban, Chức vụ, Địa chỉ

- **Điểm vào:** `/departments`, `/positions`; API [`departments.php`](routes/api/v1/departments.php), [`positions.php`](routes/api/v1/positions.php), [`addresses.php`](routes/api/v1/addresses.php).
- **Danh mục file & hàm:** xem [cuối mục này](#danh-mục-file--hàm-module-3) — mỗi file và mỗi hàm public có link riêng.


**Luồng chính**

| Thao tác | Luồng |
| --- | --- |
| Thêm/sửa phòng ban | `DepartmentForm.vue → useDepartmentStore → POST/PUT /departments → department.manage → DepartmentController → DepartmentService (generateCode, chặn vòng cha–con) → DepartmentRepository → [nếu đổi manager_id] syncHeadPosition + ReportingLineService::syncDepartmentTree → ResourceChanged('departments')` |
| Xóa phòng ban | `DELETE /departments/{id} → DepartmentService::delete` (chặn nếu còn phòng ban con, ở backend) |
| Chọn Tỉnh → Xã | `EmployeeForm.vue → addressService.provinces() → GET /addresses/provinces → chọn → communes(province_code)` |

**Luồng demo nhanh:** HR vào `/departments` → tạo phòng ban con → gán Trưởng phòng → mở `/employees`: nhân viên của phòng đó có "Quản lý trực tiếp" = Trưởng phòng vừa gán.

**Quy tắc & bẫy**
- Bản ghi Chức vụ hệ thống (`head`/`default`) bị khóa Sửa/Xóa trên UI để không phá đồng bộ với Department/EmployeeTransfer.
- Quản lý trực tiếp của nhân viên = `departments.manager_id` của phòng ban; chính họ là Trưởng phòng hoặc phòng chưa có → lên phòng cha; hết cấp → null (đơn nghỉ phép đi thẳng HR). Vẫn lưu vào `employees.manager_id` vì duyệt phép, Dashboard, ẩn lương/CCCD đọc thẳng cột này. Đồng bộ ở `EmployeeService`, `DepartmentService`, `EmployeeTransferService`; dữ liệu cũ: `php artisan employees:sync-managers`.

**Liên thông:** Phòng ban/Trưởng phòng quyết định quản lý duyệt phép (module 8), phạm vi duyệt nghỉ việc (module 9), và ẩn dữ liệu nhạy cảm (module 4).

<a id="danh-mục-file--hàm-module-3"></a>

### Danh mục file & hàm — module 3

#### Giao diện (5 file)

- [DepartmentForm.vue](resources/js/views/Department/DepartmentForm.vue#L1)
- [Departments.vue](resources/js/views/Department/Departments.vue#L1) — route `/departments`
- [PositionForm.vue](resources/js/views/Position/PositionForm.vue#L1)
- [Positions.vue](resources/js/views/Position/Positions.vue#L1) — route `/positions`
- [useDepartmentStore.js](resources/js/stores/useDepartmentStore.js#L1)

#### Controller (3 file, 11 hàm public)

- [AddressController.php](app/Http/Controllers/Api/V1/AddressController.php#L1) — 2 hàm: [provinces()](app/Http/Controllers/Api/V1/AddressController.php#L16) · [communes()](app/Http/Controllers/Api/V1/AddressController.php#L21)
- [DepartmentController.php](app/Http/Controllers/Api/V1/DepartmentController.php#L1) — 5 hàm: [index()](app/Http/Controllers/Api/V1/DepartmentController.php#L20) · [store()](app/Http/Controllers/Api/V1/DepartmentController.php#L25) · [update()](app/Http/Controllers/Api/V1/DepartmentController.php#L33) · [destroy()](app/Http/Controllers/Api/V1/DepartmentController.php#L41) · [tree()](app/Http/Controllers/Api/V1/DepartmentController.php#L47)
- [PositionController.php](app/Http/Controllers/Api/V1/PositionController.php#L1) — 4 hàm: [index()](app/Http/Controllers/Api/V1/PositionController.php#L19) · [store()](app/Http/Controllers/Api/V1/PositionController.php#L27) · [update()](app/Http/Controllers/Api/V1/PositionController.php#L34) · [destroy()](app/Http/Controllers/Api/V1/PositionController.php#L41)

#### Service (3 file, 15 hàm public)

- [DepartmentService.php](app/Services/DepartmentService.php#L1) — 5 hàm: [list()](app/Services/DepartmentService.php#L23) · [create()](app/Services/DepartmentService.php#L28) · [update()](app/Services/DepartmentService.php#L66) · [delete()](app/Services/DepartmentService.php#L127) · [tree()](app/Services/DepartmentService.php#L140)
- [PositionService.php](app/Services/PositionService.php#L1) — 6 hàm: [list()](app/Services/PositionService.php#L19) · [create()](app/Services/PositionService.php#L24) · [update()](app/Services/PositionService.php#L55) · [delete()](app/Services/PositionService.php#L64) · [ensureHeadPosition()](app/Services/PositionService.php#L74) · [ensureDefaultPosition()](app/Services/PositionService.php#L91)
- [ReportingLineService.php](app/Services/ReportingLineService.php#L1) — 4 hàm: [resolveManagerId()](app/Services/ReportingLineService.php#L22) · [syncEmployee()](app/Services/ReportingLineService.php#L40) · [syncDepartmentTree()](app/Services/ReportingLineService.php#L52) · [syncAll()](app/Services/ReportingLineService.php#L75)

#### Repository (2 file)

- [DepartmentRepository.php](app/Repositories/DepartmentRepository.php#L1) — [paginate()](app/Repositories/DepartmentRepository.php#L10) · [find()](app/Repositories/DepartmentRepository.php#L15) · [create()](app/Repositories/DepartmentRepository.php#L20) · [update()](app/Repositories/DepartmentRepository.php#L25) · [delete()](app/Repositories/DepartmentRepository.php#L32) · [wouldCreateCycle()](app/Repositories/DepartmentRepository.php#L36) · [tree()](app/Repositories/DepartmentRepository.php#L58)
- [PositionRepository.php](app/Repositories/PositionRepository.php#L1) — [paginate()](app/Repositories/PositionRepository.php#L10) · [find()](app/Repositories/PositionRepository.php#L22) · [create()](app/Repositories/PositionRepository.php#L27) · [update()](app/Repositories/PositionRepository.php#L32) · [delete()](app/Repositories/PositionRepository.php#L39)

#### Model (4 file)

- [Commune.php](app/Models/Commune.php#L1)
- [Department.php](app/Models/Department.php#L1)
- [Position.php](app/Models/Position.php#L1)
- [Province.php](app/Models/Province.php#L1)

#### Request & Resource (5 file)

- [StoreDepartmentRequest.php](app/Http/Requests/Department/StoreDepartmentRequest.php#L1)
- [UpdateDepartmentRequest.php](app/Http/Requests/Department/UpdateDepartmentRequest.php#L1)
- [StorePositionRequest.php](app/Http/Requests/Position/StorePositionRequest.php#L1)
- [UpdatePositionRequest.php](app/Http/Requests/Position/UpdatePositionRequest.php#L1)
- [DepartmentResource.php](app/Http/Resources/DepartmentResource.php#L1)

#### Lệnh, Event, Middleware, hạ tầng BE (1 file)

- [SyncEmployeeManagers.php](app/Console/Commands/SyncEmployeeManagers.php#L1) — Chạy 1 lần sau khi triển khai — đưa manager_id của dữ liệu cũ (trước đây HR chọn tay) về đúng quy tắc mới "qu…

#### Migration (8 file)

- [2026_01_02_000001_create_departments_table.php](database/migrations/2026_01_02_000001_create_departments_table.php)
- [2026_01_02_000002_create_positions_table.php](database/migrations/2026_01_02_000002_create_positions_table.php)
- [2026_01_02_000005_add_manager_foreign_to_departments_table.php](database/migrations/2026_01_02_000005_add_manager_foreign_to_departments_table.php)
- [2026_09_07_000001_create_provinces_table.php](database/migrations/2026_09_07_000001_create_provinces_table.php)
- [2026_09_07_000002_create_communes_table.php](database/migrations/2026_09_07_000002_create_communes_table.php)
- [2026_09_07_000003_add_province_commune_to_employees_table.php](database/migrations/2026_09_07_000003_add_province_commune_to_employees_table.php)
- [2026_09_08_000001_add_type_to_positions_table.php](database/migrations/2026_09_08_000001_add_type_to_positions_table.php)
- [2026_09_09_000001_create_role_positions_table.php](database/migrations/2026_09_09_000001_create_role_positions_table.php)

#### Kiểm thử (2 file, 31 test)

- [DepartmentTest.php](tests/Feature/Department/DepartmentTest.php#L1) — 22 test
- [PositionTest.php](tests/Feature/Position/PositionTest.php#L1) — 9 test

<details>
<summary><strong>Chi tiết từng màn hình Vue</strong> — 5 file</summary>

<details>
<summary><code>DepartmentForm.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Department/DepartmentForm.vue](resources/js/views/Department/DepartmentForm.vue#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).
- **Store dùng:** `useDepartmentStore`, `useEmployeeStore`
- **Component con:** [FormDialog.vue](resources/js/components/common/FormDialog.vue#L1), [FormSection.vue](resources/js/components/common/FormSection.vue#L1), [SearchSelect.vue](resources/js/components/common/SearchSelect.vue#L1)

</details>

<details>
<summary><code>Departments.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Department/Departments.vue](resources/js/views/Department/Departments.vue#L1)
- **Route FE:** `/departments`
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).
- **Store dùng:** `useDepartmentStore`, `useAuthStore`, `useResourceSyncStore`
- **Component con:** [DataTable.vue](resources/js/components/common/DataTable.vue#L1), [SearchField.vue](resources/js/components/common/SearchField.vue#L1), [PageHeader.vue](resources/js/components/common/PageHeader.vue#L1), DepartmentFormDialog.vue, [StatusChip.vue](resources/js/components/common/StatusChip.vue#L1)

</details>

<details>
<summary><code>PositionForm.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Position/PositionForm.vue](resources/js/views/Position/PositionForm.vue#L1)
- **Gọi API:**
  - `positionService.create()` → `POST /positions` → [PositionController::store()](app/Http/Controllers/Api/V1/PositionController.php#L27)
  - `positionService.update()` → `PUT /positions/{x}` → [PositionController::update()](app/Http/Controllers/Api/V1/PositionController.php#L34)
- **Component con:** [FormDialog.vue](resources/js/components/common/FormDialog.vue#L1), [FormSection.vue](resources/js/components/common/FormSection.vue#L1), [FormField.vue](resources/js/components/common/FormField.vue#L1), [SearchSelect.vue](resources/js/components/common/SearchSelect.vue#L1), [InputMoney.vue](resources/js/components/common/InputMoney.vue#L1)

</details>

<details>
<summary><code>Positions.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Position/Positions.vue](resources/js/views/Position/Positions.vue#L1)
- **Route FE:** `/positions`
- **Gọi API:**
  - `positionService.list()` → `GET /positions` → [PositionController::index()](app/Http/Controllers/Api/V1/PositionController.php#L19)
  - `positionService.remove()` → `DELETE /positions/{x}` → [PositionController::destroy()](app/Http/Controllers/Api/V1/PositionController.php#L41)
- **Store dùng:** `useDepartmentStore`, `useAuthStore`, `useResourceSyncStore`
- **Component con:** [DataTable.vue](resources/js/components/common/DataTable.vue#L1), [SearchField.vue](resources/js/components/common/SearchField.vue#L1), [PageHeader.vue](resources/js/components/common/PageHeader.vue#L1), PositionFormDialog.vue, [StatusChip.vue](resources/js/components/common/StatusChip.vue#L1)

</details>

<details>
<summary><code>useDepartmentStore.js</code></summary>

- **Mã nguồn:** [resources/js/stores/useDepartmentStore.js](resources/js/stores/useDepartmentStore.js#L1)
- **Gọi API:**
  - `departmentService.list()` → `GET /departments` → [DepartmentController::index()](app/Http/Controllers/Api/V1/DepartmentController.php#L20)
  - `departmentService.tree()` → `GET /departments/tree` → [DepartmentController::tree()](app/Http/Controllers/Api/V1/DepartmentController.php#L47)
  - `departmentService.create()` → `POST /departments` → [DepartmentController::store()](app/Http/Controllers/Api/V1/DepartmentController.php#L25)
  - `departmentService.update()` → `PUT /departments/{x}` → [DepartmentController::update()](app/Http/Controllers/Api/V1/DepartmentController.php#L33)
  - `departmentService.remove()` → `DELETE /departments/{x}` → [DepartmentController::destroy()](app/Http/Controllers/Api/V1/DepartmentController.php#L41)
- **Store dùng:** `useDepartmentStore`

</details>

</details>

<details>
<summary><strong>Chi tiết từng hàm Controller</strong> — 11 hàm</summary>

<details>
<summary><code>AddressController</code> — 2 hàm</summary>

- **Mã nguồn:** [app/Http/Controllers/Api/V1/AddressController.php](app/Http/Controllers/Api/V1/AddressController.php#L1)

<details>
<summary><code>public provinces()</code> — provinces</summary>

- **Mã nguồn:** [AddressController::provinces()](app/Http/Controllers/Api/V1/AddressController.php#L16)
- **API:** `GET /api/v1/addresses/provinces` — quyền `auth` ([addresses.php](routes/api/v1/addresses.php))
- **FE service:** [addressService.provinces()](resources/js/services/addressService.js#L5) ← gọi từ [EmployeeForm.vue](resources/js/views/Employee/EmployeeForm.vue#L1), [MyProfileInfoTab.vue](resources/js/views/Me/MyProfileInfoTab.vue#L1)

</details>

<details>
<summary><code>public communes()</code> — communes</summary>

- **Mã nguồn:** [AddressController::communes()](app/Http/Controllers/Api/V1/AddressController.php#L21)
- **API:** `GET /api/v1/addresses/communes` — quyền `auth` ([addresses.php](routes/api/v1/addresses.php))
- **FE service:** [addressService.communes()](resources/js/services/addressService.js#L8) ← gọi từ [EmployeeForm.vue](resources/js/views/Employee/EmployeeForm.vue#L1), [MyProfileInfoTab.vue](resources/js/views/Me/MyProfileInfoTab.vue#L1)

</details>

</details>

<details>
<summary><code>DepartmentController</code> — 5 hàm</summary>

- **Mã nguồn:** [app/Http/Controllers/Api/V1/DepartmentController.php](app/Http/Controllers/Api/V1/DepartmentController.php#L1)

<details>
<summary><code>public index()</code> — Danh sách</summary>

- **Mã nguồn:** [DepartmentController::index()](app/Http/Controllers/Api/V1/DepartmentController.php#L20)
- **API:** `GET /api/v1/departments` — quyền `department.view` ([departments.php](routes/api/v1/departments.php))
- **FE service:** [departmentService.list()](resources/js/services/departmentService.js#L4) ← gọi từ [useDepartmentStore.js](resources/js/stores/useDepartmentStore.js#L1)
- **Gọi xuống:** [DepartmentService::list()](app/Services/DepartmentService.php#L23)

</details>

<details>
<summary><code>public store()</code> — Tạo mới</summary>

- **Mã nguồn:** [DepartmentController::store()](app/Http/Controllers/Api/V1/DepartmentController.php#L25)
- **API:** `POST /api/v1/departments` — quyền `department.manage` ([departments.php](routes/api/v1/departments.php))
- **FE service:** [departmentService.create()](resources/js/services/departmentService.js#L10) ← gọi từ [useDepartmentStore.js](resources/js/stores/useDepartmentStore.js#L1)
- **Validate:** [StoreDepartmentRequest](app/Http/Requests/Department/StoreDepartmentRequest.php#L1)
- **Gọi xuống:** [DepartmentService::create()](app/Services/DepartmentService.php#L28)

</details>

<details>
<summary><code>public update()</code> — Cập nhật</summary>

- **Mã nguồn:** [DepartmentController::update()](app/Http/Controllers/Api/V1/DepartmentController.php#L33)
- **API:** `PUT /api/v1/departments/{department}` — quyền `department.manage` ([departments.php](routes/api/v1/departments.php))
- **FE service:** [departmentService.update()](resources/js/services/departmentService.js#L13) ← gọi từ [useDepartmentStore.js](resources/js/stores/useDepartmentStore.js#L1)
- **Validate:** [UpdateDepartmentRequest](app/Http/Requests/Department/UpdateDepartmentRequest.php#L1)
- **Gọi xuống:** [DepartmentService::update()](app/Services/DepartmentService.php#L66)

</details>

<details>
<summary><code>public destroy()</code> — Xóa</summary>

- **Mã nguồn:** [DepartmentController::destroy()](app/Http/Controllers/Api/V1/DepartmentController.php#L41)
- **API:** `DELETE /api/v1/departments/{department}` — quyền `department.manage` ([departments.php](routes/api/v1/departments.php))
- **FE service:** [departmentService.remove()](resources/js/services/departmentService.js#L16) ← gọi từ [useDepartmentStore.js](resources/js/stores/useDepartmentStore.js#L1)
- **Gọi xuống:** [DepartmentService::delete()](app/Services/DepartmentService.php#L127)

</details>

<details>
<summary><code>public tree()</code> — Cây</summary>

- **Mã nguồn:** [DepartmentController::tree()](app/Http/Controllers/Api/V1/DepartmentController.php#L47)
- **API:** `GET /api/v1/departments/tree` — quyền `department.view` ([departments.php](routes/api/v1/departments.php))
- **FE service:** [departmentService.tree()](resources/js/services/departmentService.js#L7) ← gọi từ [EmployeeTransfersTab.vue](resources/js/views/Employee/EmployeeTransfersTab.vue#L1), [useDepartmentStore.js](resources/js/stores/useDepartmentStore.js#L1)
- **Gọi xuống:** [DepartmentService::tree()](app/Services/DepartmentService.php#L140)

</details>

</details>

<details>
<summary><code>PositionController</code> — 4 hàm</summary>

- **Mã nguồn:** [app/Http/Controllers/Api/V1/PositionController.php](app/Http/Controllers/Api/V1/PositionController.php#L1)

<details>
<summary><code>public index()</code> — Danh sách</summary>

- **Mã nguồn:** [PositionController::index()](app/Http/Controllers/Api/V1/PositionController.php#L19)
- **API:** `GET /api/v1/positions` — quyền `department.view` ([positions.php](routes/api/v1/positions.php))
- **FE service:** [positionService.list()](resources/js/services/positionService.js#L5) ← gọi từ [EmployeeForm.vue](resources/js/views/Employee/EmployeeForm.vue#L1), [EmployeeTransfersTab.vue](resources/js/views/Employee/EmployeeTransfersTab.vue#L1), [Positions.vue](resources/js/views/Position/Positions.vue#L1)
- **Gọi xuống:** [PositionService::list()](app/Services/PositionService.php#L19)

</details>

<details>
<summary><code>public store()</code> — Tạo mới</summary>

- **Mã nguồn:** [PositionController::store()](app/Http/Controllers/Api/V1/PositionController.php#L27)
- **API:** `POST /api/v1/positions` — quyền `department.manage` ([positions.php](routes/api/v1/positions.php))
- **FE service:** [positionService.create()](resources/js/services/positionService.js#L8) ← gọi từ [PositionForm.vue](resources/js/views/Position/PositionForm.vue#L1)
- **Validate:** [StorePositionRequest](app/Http/Requests/Position/StorePositionRequest.php#L1)
- **Gọi xuống:** [PositionService::create()](app/Services/PositionService.php#L24)

</details>

<details>
<summary><code>public update()</code> — Cập nhật</summary>

- **Mã nguồn:** [PositionController::update()](app/Http/Controllers/Api/V1/PositionController.php#L34)
- **API:** `PUT /api/v1/positions/{position}` — quyền `department.manage` ([positions.php](routes/api/v1/positions.php))
- **FE service:** [positionService.update()](resources/js/services/positionService.js#L11) ← gọi từ [PositionForm.vue](resources/js/views/Position/PositionForm.vue#L1)
- **Validate:** [UpdatePositionRequest](app/Http/Requests/Position/UpdatePositionRequest.php#L1)
- **Gọi xuống:** [PositionService::update()](app/Services/PositionService.php#L55)

</details>

<details>
<summary><code>public destroy()</code> — Xóa</summary>

- **Mã nguồn:** [PositionController::destroy()](app/Http/Controllers/Api/V1/PositionController.php#L41)
- **API:** `DELETE /api/v1/positions/{position}` — quyền `department.manage` ([positions.php](routes/api/v1/positions.php))
- **FE service:** [positionService.remove()](resources/js/services/positionService.js#L14) ← gọi từ [Positions.vue](resources/js/views/Position/Positions.vue#L1)
- **Gọi xuống:** [PositionService::delete()](app/Services/PositionService.php#L64)

</details>

</details>

</details>

<details>
<summary><strong>Chi tiết từng hàm Service</strong> — 15 hàm</summary>

<details>
<summary><code>DepartmentService</code> — 5 hàm</summary>

- **Mã nguồn:** [app/Services/DepartmentService.php](app/Services/DepartmentService.php#L1)

<details>
<summary><code>public list()</code> — Lấy danh sách</summary>

- **Mã nguồn:** [DepartmentService::list()](app/Services/DepartmentService.php#L23)
- **Gọi xuống:** [DepartmentRepository::paginate()](app/Repositories/DepartmentRepository.php#L10)
- **Được gọi bởi:** [DepartmentController::index()](app/Http/Controllers/Api/V1/DepartmentController.php#L20)

</details>

<details>
<summary><code>public create()</code> — Tạo</summary>

- **Mã nguồn:** [DepartmentService::create()](app/Services/DepartmentService.php#L28)
- **Gọi xuống:** [DepartmentRepository::create()](app/Repositories/DepartmentRepository.php#L20) · [ReportingLineService::syncDepartmentTree()](app/Services/ReportingLineService.php#L52)
- **Hiệu ứng phụ:** `DB::transaction`
- **Được gọi bởi:** [DepartmentController::store()](app/Http/Controllers/Api/V1/DepartmentController.php#L25)

</details>

<details>
<summary><code>public update()</code> — Cập nhật</summary>

- **Mã nguồn:** [DepartmentService::update()](app/Services/DepartmentService.php#L66)
- **Gọi xuống:** [DepartmentRepository::wouldCreateCycle()](app/Repositories/DepartmentRepository.php#L36) · [DepartmentRepository::update()](app/Repositories/DepartmentRepository.php#L25) · [ReportingLineService::syncDepartmentTree()](app/Services/ReportingLineService.php#L52)
- **Hiệu ứng phụ:** `DB::transaction`
- **Được gọi bởi:** [DepartmentController::update()](app/Http/Controllers/Api/V1/DepartmentController.php#L33)

</details>

<details>
<summary><code>public delete()</code> — Xóa</summary>

- **Mã nguồn:** [DepartmentService::delete()](app/Services/DepartmentService.php#L127)
- **Gọi xuống:** [DepartmentRepository::delete()](app/Repositories/DepartmentRepository.php#L32)
- **Được gọi bởi:** [DepartmentController::destroy()](app/Http/Controllers/Api/V1/DepartmentController.php#L41)

</details>

<details>
<summary><code>public tree()</code> — Cây</summary>

- **Mã nguồn:** [DepartmentService::tree()](app/Services/DepartmentService.php#L140)
- **Gọi xuống:** [DepartmentRepository::tree()](app/Repositories/DepartmentRepository.php#L58)
- **Được gọi bởi:** [DepartmentController::tree()](app/Http/Controllers/Api/V1/DepartmentController.php#L47)

</details>

</details>

<details>
<summary><code>PositionService</code> — 6 hàm</summary>

- **Mã nguồn:** [app/Services/PositionService.php](app/Services/PositionService.php#L1)

<details>
<summary><code>public list()</code> — Lấy danh sách</summary>

- **Mã nguồn:** [PositionService::list()](app/Services/PositionService.php#L19)
- **Gọi xuống:** [PositionRepository::paginate()](app/Repositories/PositionRepository.php#L10)
- **Được gọi bởi:** [PositionController::index()](app/Http/Controllers/Api/V1/PositionController.php#L19)

</details>

<details>
<summary><code>public create()</code> — Tạo</summary>

- **Mã nguồn:** [PositionService::create()](app/Services/PositionService.php#L24)
- **Gọi xuống:** [PositionRepository::create()](app/Repositories/PositionRepository.php#L27)
- **Hiệu ứng phụ:** `DB::transaction`
- **Được gọi bởi:** [PositionController::store()](app/Http/Controllers/Api/V1/PositionController.php#L27)

</details>

<details>
<summary><code>public update()</code> — Cập nhật</summary>

- **Mã nguồn:** [PositionService::update()](app/Services/PositionService.php#L55)
- **Gọi xuống:** [PositionRepository::update()](app/Repositories/PositionRepository.php#L32)
- **Được gọi bởi:** [PositionController::update()](app/Http/Controllers/Api/V1/PositionController.php#L34)

</details>

<details>
<summary><code>public delete()</code> — Xóa</summary>

- **Mã nguồn:** [PositionService::delete()](app/Services/PositionService.php#L64)
- **Gọi xuống:** [PositionRepository::delete()](app/Repositories/PositionRepository.php#L39)
- **Được gọi bởi:** [PositionController::destroy()](app/Http/Controllers/Api/V1/PositionController.php#L41)

</details>

<details>
<summary><code>public ensureHeadPosition()</code> — Đảm bảo head position</summary>

- **Mã nguồn:** [PositionService::ensureHeadPosition()](app/Services/PositionService.php#L74)
- **Được gọi bởi:** [DepartmentService::syncHeadPosition()](app/Services/DepartmentService.php#L102)

</details>

<details>
<summary><code>public ensureDefaultPosition()</code> — Đảm bảo default position</summary>

- **Mã nguồn:** [PositionService::ensureDefaultPosition()](app/Services/PositionService.php#L91)
- **Được gọi bởi:** [DepartmentService::syncHeadPosition()](app/Services/DepartmentService.php#L102) · [EmployeeTransferService::create()](app/Services/EmployeeTransferService.php#L34)

</details>

</details>

<details>
<summary><code>ReportingLineService</code> — 4 hàm — "Quản lý trực tiếp" (employees.manager_id) KHÔNG còn chọn tay — luôn TỰ SUY RA từ cơ cấu …</summary>

- **Mã nguồn:** [app/Services/ReportingLineService.php](app/Services/ReportingLineService.php#L1)

<details>
<summary><code>public resolveManagerId()</code> — Xác định manager id</summary>

- **Mã nguồn:** [ReportingLineService::resolveManagerId()](app/Services/ReportingLineService.php#L22)

</details>

<details>
<summary><code>public syncEmployee()</code> — Đồng bộ employee</summary>

- **Mã nguồn:** [ReportingLineService::syncEmployee()](app/Services/ReportingLineService.php#L40)
- **Được gọi bởi:** [EmployeeService::create()](app/Services/EmployeeService.php#L41) · [EmployeeService::update()](app/Services/EmployeeService.php#L104) · [EmployeeTransferService::create()](app/Services/EmployeeTransferService.php#L34)

</details>

<details>
<summary><code>public syncDepartmentTree()</code> — Đồng bộ department tree</summary>

- **Mã nguồn:** [ReportingLineService::syncDepartmentTree()](app/Services/ReportingLineService.php#L52)
- **Được gọi bởi:** [DepartmentService::create()](app/Services/DepartmentService.php#L28) · [DepartmentService::update()](app/Services/DepartmentService.php#L66) · [EmployeeTransferService::create()](app/Services/EmployeeTransferService.php#L34)

</details>

<details>
<summary><code>public syncAll()</code> — Đồng bộ all</summary>

- **Mã nguồn:** [ReportingLineService::syncAll()](app/Services/ReportingLineService.php#L75)

</details>

</details>

</details>

<details>
<summary><strong>Danh sách test</strong> — 31 test</summary>

<details>
<summary><code>DepartmentTest.php</code> — 22 test</summary>

- **Mã nguồn:** [tests/Feature/Department/DepartmentTest.php](tests/Feature/Department/DepartmentTest.php#L1)
- [test_user_without_view_permission_is_forbidden](tests/Feature/Department/DepartmentTest.php#L42)
- [test_unauthenticated_is_rejected](tests/Feature/Department/DepartmentTest.php#L52)
- [test_user_with_view_permission_can_list_departments](tests/Feature/Department/DepartmentTest.php#L58)
- [test_user_without_manage_permission_cannot_create](tests/Feature/Department/DepartmentTest.php#L67)
- [test_admin_can_create_department_with_auto_generated_code](tests/Feature/Department/DepartmentTest.php#L80)
- [test_client_supplied_code_is_ignored_on_create](tests/Feature/Department/DepartmentTest.php#L95)
- [test_generated_code_ignores_legacy_non_numeric_codes](tests/Feature/Department/DepartmentTest.php#L110)
- [test_generated_code_continues_after_soft_deleted_department](tests/Feature/Department/DepartmentTest.php#L128)
- [test_create_requires_name](tests/Feature/Department/DepartmentTest.php#L146)
- [test_admin_can_update_department_name](tests/Feature/Department/DepartmentTest.php#L157)
- [test_update_ignores_client_supplied_code](tests/Feature/Department/DepartmentTest.php#L172)
- [test_cannot_set_department_as_its_own_parent](tests/Feature/Department/DepartmentTest.php#L188)
- [test_cannot_move_department_under_its_own_child](tests/Feature/Department/DepartmentTest.php#L203)
- [test_admin_can_delete_department_without_children](tests/Feature/Department/DepartmentTest.php#L221)
- [test_cannot_delete_department_with_children](tests/Feature/Department/DepartmentTest.php#L234)
- [test_tree_endpoint_returns_nested_structure](tests/Feature/Department/DepartmentTest.php#L248)
- [test_admin_can_set_department_manager](tests/Feature/Department/DepartmentTest.php#L266)
- [test_department_manager_id_must_exist](tests/Feature/Department/DepartmentTest.php#L284)
- [test_setting_department_manager_assigns_head_position](tests/Feature/Department/DepartmentTest.php#L298)
- [test_newly_created_head_position_suggests_manager_role](tests/Feature/Department/DepartmentTest.php#L314)
- [test_replacing_department_manager_demotes_old_one_to_default_position](tests/Feature/Department/DepartmentTest.php#L330)
- [test_tree_endpoint_hides_manager_sensitive_fields_from_subordinate_viewer](tests/Feature/Department/DepartmentTest.php#L354)

</details>

<details>
<summary><code>PositionTest.php</code> — 9 test</summary>

- **Mã nguồn:** [tests/Feature/Position/PositionTest.php](tests/Feature/Position/PositionTest.php#L1)
- [test_unauthenticated_is_rejected](tests/Feature/Position/PositionTest.php#L30)
- [test_user_without_manage_permission_cannot_create](tests/Feature/Position/PositionTest.php#L37)
- [test_admin_can_create_position_with_auto_generated_code](tests/Feature/Position/PositionTest.php#L52)
- [test_client_supplied_code_is_ignored_on_create](tests/Feature/Position/PositionTest.php#L71)
- [test_create_requires_department_id_and_name](tests/Feature/Position/PositionTest.php#L88)
- [test_admin_can_update_position](tests/Feature/Position/PositionTest.php#L99)
- [test_update_ignores_client_supplied_code](tests/Feature/Position/PositionTest.php#L115)
- [test_admin_can_delete_position](tests/Feature/Position/PositionTest.php#L133)
- [test_can_filter_positions_by_department](tests/Feature/Position/PositionTest.php#L147)

</details>

</details>

---

## 4. Nhân viên & Hồ sơ

**Vai trò:** hồ sơ nhân viên, hợp đồng, tài liệu, điều chuyển, tài khoản đăng nhập; "Hồ sơ của tôi".

- **Điểm vào:** `/employees`, `/employees/:id`, `/my-profile`; API [`employees.php`](routes/api/v1/employees.php).
- **Danh mục file & hàm:** xem [cuối mục này](#danh-mục-file--hàm-module-4) — mỗi file và mỗi hàm public có link riêng.


**Luồng chính**

| Thao tác | Luồng |
| --- | --- |
| Thêm nhân viên | `EmployeeForm.vue (rời ô → check-unique) → employeeService.create → POST /employees → employee.create → StoreEmployeeRequest (14 field + contract_type + agreed_salary) → EmployeeService::create → [transaction] Employee + EmployeeContractService::create (HĐ đầu tiên, insurance_salary = agreed_salary) + ReportingLineService + EmployeeShiftAssignmentService::assignDefaultShift (+ EmployeeAccountService nếu tick tạo tài khoản) → ResourceChanged('employees')` |
| Ký hợp đồng mới | `EmployeeContractsTab.vue → employeeService.createContract → POST /employees/{id}/contracts → employee.update → EmployeeContractService::create → [start_date ≤ hôm nay: active + supersede HĐ cũ + applyEmploymentStatus; tương lai: pending] → tab tải lại danh sách + emit 'changed'` |
| Chấm dứt hợp đồng | `POST /employees/{id}/contracts/{c}/terminate → EmployeeContractService::terminate (chỉ từ active; không còn HĐ khác → "Đã chấm dứt HĐ" + khóa tài khoản)` |
| Điều chuyển | `EmployeeTransfersTab.vue → POST /employees/{id}/transfers → EmployeeTransferService::create (áp dụng ngay; cập nhật phòng/chức vụ; sync quản lý; Trưởng phòng rời đi → dọn phòng cũ)` |
| Tạo tài khoản đăng nhập | `EmployeeProfileTab.vue (dialog) → roleService.list → GET /roles → POST /employees/{id}/account → EmployeeAccountService::store (chặn trùng, assertCanAssignRoles) → email đặt mật khẩu qua luồng quên mật khẩu` |
| Tự sửa liên hệ / đổi ảnh | `MyProfileInfoTab.vue → PUT /employees/me (chỉ 5 field, phone digits:10)`; `MyProfile.vue → POST /employees/me/avatar (jpg/png/webp ≤ 2MB) → EmployeeService::updateAvatar → cập nhật auth.user.avatar_url` |
| Tải/xem file | `GET /employees/{id}/contracts/{c}/download` (IDOR: so employee_id trước khi tải; route "của tôi" tự kiểm chính chủ HOẶC có quyền) |

**Luồng demo nhanh:** HR vào `/employees` → "Thêm nhân viên" (chọn loại hợp đồng, điền lương) → nhân viên tự có Ca mặc định và hợp đồng đầu tiên; mở tab "Hợp đồng" thấy HĐ vừa tạo; "Tạo tài khoản đăng nhập" → email đặt mật khẩu.

**Quy tắc & bẫy**
- Trạng thái nhân viên suy từ hợp đồng (`probation`/`active`/`resigned`/`terminated`; nhãn "Thử việc/Chính thức"); không sửa tay. Hợp đồng tự hết hạn (`contracts:expire`) không đổi trạng thái nhân viên (chủ ý).
- Vòng đời hợp đồng: `pending` → `active` → `expired`/`terminated`; `contracts:activate-pending` (00:05) chạy trước `contracts:expire` (00:06). Không Sửa/Xóa hợp đồng. Số HĐ tự sinh (`HDTV-`/`HDCT-`).
- Lương đổi = ký hợp đồng mới. `UpdateEmployeeRequest` không có field lương. Nhân viên chỉ **xem** lương ở "Hồ sơ của tôi" (không nút sửa).
- `EmployeeResource` ẩn CCCD, SĐT, ngày sinh, `agreed_salary`, `leave_*` khi người xem là cấp dưới (cache `ancestorIds` theo viewer, chặn vòng lặp).
- Giới hạn tải lên (Backend chặn thật; PHP 20M, nginx 20m): hợp đồng 5MB (PDF), tài liệu 10MB, quyết định điều động 10MB (PDF), giấy tờ đơn nghỉ phép 5MB. Số MB khai một chỗ trong `UPLOAD_LIMITS` ở `InputFile.vue`, phải khớp rule Backend.
- Form dùng `validate-on="blur invalid-input lazy"` (mục 12); check-unique chỉ là tiện lợi (lúc lưu Backend vẫn kiểm; tính cả nhân viên xóa mềm; khi sửa bỏ qua chính người đó).
- Xóa nhân viên → gỡ bản gán ca (`removeAllForEmployee()`) + khóa tài khoản; không chặn nếu còn cấp dưới. Docker: tạo `storage/app/private/...` bằng tinker/root trước sẽ làm request thật (`www-data`) bị Permission denied.

**Liên thông:** HĐ/nghỉ việc → trạng thái nhân viên → khóa/mở tài khoản (module 1); hợp đồng là nguồn lương/bảo hiểm cho module 10; nhân viên mới tự có Ca mặc định (module 5).

<a id="danh-mục-file--hàm-module-4"></a>

### Danh mục file & hàm — module 4

#### Giao diện (17 file)

- [EmployeeContractsTab.vue](resources/js/views/Employee/EmployeeContractsTab.vue#L1)
- [EmployeeDetail.vue](resources/js/views/Employee/EmployeeDetail.vue#L1) — route `/employees/:id`
- [EmployeeDocumentsTab.vue](resources/js/views/Employee/EmployeeDocumentsTab.vue#L1)
- [EmployeeForm.vue](resources/js/views/Employee/EmployeeForm.vue#L1)
- [EmployeeProfileTab.vue](resources/js/views/Employee/EmployeeProfileTab.vue#L1)
- [Employees.vue](resources/js/views/Employee/Employees.vue#L1) — route `/employees`
- [EmployeeTransfersTab.vue](resources/js/views/Employee/EmployeeTransfersTab.vue#L1)
- [MyProfile.vue](resources/js/views/Me/MyProfile.vue#L1) — route `/my-profile`
- [MyProfileContractsTab.vue](resources/js/views/Me/MyProfileContractsTab.vue#L1)
- [MyProfileDocumentsTab.vue](resources/js/views/Me/MyProfileDocumentsTab.vue#L1)
- [MyProfileInfoTab.vue](resources/js/views/Me/MyProfileInfoTab.vue#L1)
- [MyProfilePayslipsTab.vue](resources/js/views/Me/MyProfilePayslipsTab.vue#L1)
- [MyProfileShiftsTab.vue](resources/js/views/Me/MyProfileShiftsTab.vue#L1)
- [MyProfileTransfersTab.vue](resources/js/views/Me/MyProfileTransfersTab.vue#L1)
- [useEmployeeStore.js](resources/js/stores/useEmployeeStore.js#L1)
- [avatar.js](resources/js/composables/avatar.js#L1)
- [employmentStatus.js](resources/js/composables/employmentStatus.js#L1)

#### Controller (5 file, 27 hàm public)

- [EmployeeAccountController.php](app/Http/Controllers/Api/V1/EmployeeAccountController.php#L1) — 1 hàm: [store()](app/Http/Controllers/Api/V1/EmployeeAccountController.php#L17)
- [EmployeeContractController.php](app/Http/Controllers/Api/V1/EmployeeContractController.php#L1) — 5 hàm: [index()](app/Http/Controllers/Api/V1/EmployeeContractController.php#L23) · [mine()](app/Http/Controllers/Api/V1/EmployeeContractController.php#L31) · [store()](app/Http/Controllers/Api/V1/EmployeeContractController.php#L40) · [terminate()](app/Http/Controllers/Api/V1/EmployeeContractController.php#L46) · [download()](app/Http/Controllers/Api/V1/EmployeeContractController.php#L57)
- [EmployeeController.php](app/Http/Controllers/Api/V1/EmployeeController.php#L1) — 11 hàm: [index()](app/Http/Controllers/Api/V1/EmployeeController.php#L28) · [checkUnique()](app/Http/Controllers/Api/V1/EmployeeController.php#L39) · [stats()](app/Http/Controllers/Api/V1/EmployeeController.php#L55) · [me()](app/Http/Controllers/Api/V1/EmployeeController.php#L64) · [updateMine()](app/Http/Controllers/Api/V1/EmployeeController.php#L76) · [show()](app/Http/Controllers/Api/V1/EmployeeController.php#L86) · [store()](app/Http/Controllers/Api/V1/EmployeeController.php#L90) · [update()](app/Http/Controllers/Api/V1/EmployeeController.php#L96) · [destroy()](app/Http/Controllers/Api/V1/EmployeeController.php#L102) · [uploadAvatar()](app/Http/Controllers/Api/V1/EmployeeController.php#L108) · [uploadMyAvatar()](app/Http/Controllers/Api/V1/EmployeeController.php#L120)
- [EmployeeDocumentController.php](app/Http/Controllers/Api/V1/EmployeeDocumentController.php#L1) — 6 hàm: [index()](app/Http/Controllers/Api/V1/EmployeeDocumentController.php#L23) · [mine()](app/Http/Controllers/Api/V1/EmployeeDocumentController.php#L32) · [storeMine()](app/Http/Controllers/Api/V1/EmployeeDocumentController.php#L44) · [store()](app/Http/Controllers/Api/V1/EmployeeDocumentController.php#L60) · [destroy()](app/Http/Controllers/Api/V1/EmployeeDocumentController.php#L72) · [download()](app/Http/Controllers/Api/V1/EmployeeDocumentController.php#L83)
- [EmployeeTransferController.php](app/Http/Controllers/Api/V1/EmployeeTransferController.php#L1) — 4 hàm: [index()](app/Http/Controllers/Api/V1/EmployeeTransferController.php#L23) · [mine()](app/Http/Controllers/Api/V1/EmployeeTransferController.php#L36) · [store()](app/Http/Controllers/Api/V1/EmployeeTransferController.php#L45) · [download()](app/Http/Controllers/Api/V1/EmployeeTransferController.php#L57)

#### Service (5 file, 18 hàm public)

- [EmployeeAccountService.php](app/Services/EmployeeAccountService.php#L1) — 3 hàm: [create()](app/Services/EmployeeAccountService.php#L34) · [deactivateAccountOf()](app/Services/EmployeeAccountService.php#L94) · [reactivateAccountOf()](app/Services/EmployeeAccountService.php#L108)
- [EmployeeContractService.php](app/Services/EmployeeContractService.php#L1) — 4 hàm: [applyEmploymentStatus()](app/Services/EmployeeContractService.php#L33) · [listForEmployee()](app/Services/EmployeeContractService.php#L51) · [create()](app/Services/EmployeeContractService.php#L62) · [terminate()](app/Services/EmployeeContractService.php#L115)
- [EmployeeDocumentService.php](app/Services/EmployeeDocumentService.php#L1) — 3 hàm: [listForEmployee()](app/Services/EmployeeDocumentService.php#L18) · [create()](app/Services/EmployeeDocumentService.php#L23) · [delete()](app/Services/EmployeeDocumentService.php#L34)
- [EmployeeService.php](app/Services/EmployeeService.php#L1) — 6 hàm: [list()](app/Services/EmployeeService.php#L22) · [stats()](app/Services/EmployeeService.php#L30) · [create()](app/Services/EmployeeService.php#L41) · [update()](app/Services/EmployeeService.php#L104) · [delete()](app/Services/EmployeeService.php#L121) · [updateAvatar()](app/Services/EmployeeService.php#L134)
- [EmployeeTransferService.php](app/Services/EmployeeTransferService.php#L1) — 2 hàm: [listForEmployee()](app/Services/EmployeeTransferService.php#L23) · [create()](app/Services/EmployeeTransferService.php#L34)

#### Repository (4 file)

- [EmployeeContractRepository.php](app/Repositories/EmployeeContractRepository.php#L1) — [listByEmployee()](app/Repositories/EmployeeContractRepository.php#L11) · [find()](app/Repositories/EmployeeContractRepository.php#L16) · [create()](app/Repositories/EmployeeContractRepository.php#L21) · [update()](app/Repositories/EmployeeContractRepository.php#L32)
- [EmployeeDocumentRepository.php](app/Repositories/EmployeeDocumentRepository.php#L1) — [listByEmployee()](app/Repositories/EmployeeDocumentRepository.php#L11) · [create()](app/Repositories/EmployeeDocumentRepository.php#L16) · [delete()](app/Repositories/EmployeeDocumentRepository.php#L21)
- [EmployeeRepository.php](app/Repositories/EmployeeRepository.php#L1) — [paginate()](app/Repositories/EmployeeRepository.php#L10) · [find()](app/Repositories/EmployeeRepository.php#L29) · [countByStatus()](app/Repositories/EmployeeRepository.php#L36) · [create()](app/Repositories/EmployeeRepository.php#L43) · [update()](app/Repositories/EmployeeRepository.php#L47) · [delete()](app/Repositories/EmployeeRepository.php#L52) · [ancestorIds()](app/Repositories/EmployeeRepository.php#L61)
- [EmployeeTransferRepository.php](app/Repositories/EmployeeTransferRepository.php#L1) — [listByEmployee()](app/Repositories/EmployeeTransferRepository.php#L11) · [create()](app/Repositories/EmployeeTransferRepository.php#L19)

#### Model (5 file)

- [Employee.php](app/Models/Employee.php#L1)
- [EmployeeBankAccount.php](app/Models/EmployeeBankAccount.php#L1)
- [EmployeeContract.php](app/Models/EmployeeContract.php#L1)
- [EmployeeDocument.php](app/Models/EmployeeDocument.php#L1)
- [EmployeeTransfer.php](app/Models/EmployeeTransfer.php#L1)

#### Request & Resource (11 file)

- [StoreEmployeeRequest.php](app/Http/Requests/Employee/StoreEmployeeRequest.php#L1)
- [UpdateEmployeeRequest.php](app/Http/Requests/Employee/UpdateEmployeeRequest.php#L1)
- [UpdateMyProfileRequest.php](app/Http/Requests/Employee/UpdateMyProfileRequest.php#L1) — Chỉ cho tự sửa THÔNG TIN LIÊN HỆ (điện thoại, email cá nhân, địa chỉ) — cố tình KHÔNG cho sửa qua đây: field …
- [StoreEmployeeAccountRequest.php](app/Http/Requests/EmployeeAccount/StoreEmployeeAccountRequest.php#L1)
- [StoreEmployeeContractRequest.php](app/Http/Requests/EmployeeContract/StoreEmployeeContractRequest.php#L1)
- [StoreEmployeeDocumentRequest.php](app/Http/Requests/EmployeeDocument/StoreEmployeeDocumentRequest.php#L1)
- [StoreEmployeeTransferRequest.php](app/Http/Requests/EmployeeTransfer/StoreEmployeeTransferRequest.php#L1)
- [EmployeeContractResource.php](app/Http/Resources/EmployeeContractResource.php#L1)
- [EmployeeDocumentResource.php](app/Http/Resources/EmployeeDocumentResource.php#L1)
- [EmployeeResource.php](app/Http/Resources/EmployeeResource.php#L1)
- [EmployeeTransferResource.php](app/Http/Resources/EmployeeTransferResource.php#L1)

#### Lệnh, Event, Middleware, hạ tầng BE (2 file)

- [ActivatePendingContracts.php](app/Console/Commands/ActivatePendingContracts.php#L1) — Chạy hằng ngày TRƯỚC contracts:expire (xem routes/console.php) — hợp đồng ký trước ngày bắt đầu được tạo với …
- [ExpireEmployeeContracts.php](app/Console/Commands/ExpireEmployeeContracts.php#L1) — Chạy hằng ngày (đăng ký lịch ở routes/console.php) — chuyển hợp đồng đã qua end_date nhưng vẫn còn "active" (…

#### Migration (8 file)

- [2026_01_02_000003_create_employees_table.php](database/migrations/2026_01_02_000003_create_employees_table.php)
- [2026_01_02_000004_add_manager_foreign_to_employees_table.php](database/migrations/2026_01_02_000004_add_manager_foreign_to_employees_table.php)
- [2026_01_02_000006_create_employee_contracts_table.php](database/migrations/2026_01_02_000006_create_employee_contracts_table.php)
- [2026_01_02_000007_create_employee_documents_table.php](database/migrations/2026_01_02_000007_create_employee_documents_table.php)
- [2026_01_02_000008_create_employee_bank_accounts_table.php](database/migrations/2026_01_02_000008_create_employee_bank_accounts_table.php)
- [2026_01_02_000009_create_employee_transfers_table.php](database/migrations/2026_01_02_000009_create_employee_transfers_table.php)
- [2026_09_09_000002_add_unique_to_phone_personal_email_on_employees_table.php](database/migrations/2026_09_09_000002_add_unique_to_phone_personal_email_on_employees_table.php)
- [2026_09_16_000001_make_insurance_salary_nullable_on_employee_contracts_table.php](database/migrations/2026_09_16_000001_make_insurance_salary_nullable_on_employee_contracts_table.php)

#### Kiểm thử (9 file, 123 test)

- [ActivatePendingContractsTest.php](tests/Feature/Console/ActivatePendingContractsTest.php#L1) — 4 test
- [ExpireEmployeeContractsTest.php](tests/Feature/Console/ExpireEmployeeContractsTest.php#L1) — 4 test
- [EmployeeAccountTest.php](tests/Feature/Employee/EmployeeAccountTest.php#L1) — 8 test
- [EmployeeCheckUniqueTest.php](tests/Feature/Employee/EmployeeCheckUniqueTest.php#L1) — 5 test
- [EmployeeContractTest.php](tests/Feature/Employee/EmployeeContractTest.php#L1) — 23 test
- [EmployeeDocumentTest.php](tests/Feature/Employee/EmployeeDocumentTest.php#L1) — 17 test
- [EmployeePhoneRuleTest.php](tests/Feature/Employee/EmployeePhoneRuleTest.php#L1) — 1 test
- [EmployeeTest.php](tests/Feature/Employee/EmployeeTest.php#L1) — 48 test
- [EmployeeTransferTest.php](tests/Feature/Employee/EmployeeTransferTest.php#L1) — 13 test

<details>
<summary><strong>Chi tiết từng màn hình Vue</strong> — 17 file</summary>

<details>
<summary><code>EmployeeContractsTab.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Employee/EmployeeContractsTab.vue](resources/js/views/Employee/EmployeeContractsTab.vue#L1)
- **Gọi API:**
  - `employeeService.contracts()` → `GET /employees/{x}/contracts` → [EmployeeContractController::index()](app/Http/Controllers/Api/V1/EmployeeContractController.php#L23)
  - `employeeService.createContract()` → `POST /employees/{x}/contracts` → [EmployeeContractController::store()](app/Http/Controllers/Api/V1/EmployeeContractController.php#L40)
  - `employeeService.terminateContract()` → `POST /employees/{x}/contracts/{x}/terminate` → [EmployeeContractController::terminate()](app/Http/Controllers/Api/V1/EmployeeContractController.php#L46)
- **Component con:** [StatusChip.vue](resources/js/components/common/StatusChip.vue#L1), [InputMoney.vue](resources/js/components/common/InputMoney.vue#L1)

</details>

<details>
<summary><code>EmployeeDetail.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Employee/EmployeeDetail.vue](resources/js/views/Employee/EmployeeDetail.vue#L1)
- **Route FE:** `/employees/:id`
- **Gọi API:**
  - `employeeService.get()` → `GET /employees/{x}` → [EmployeeController::show()](app/Http/Controllers/Api/V1/EmployeeController.php#L86)
- **Store dùng:** `useAuthStore`
- **Component con:** [PageHeader.vue](resources/js/components/common/PageHeader.vue#L1), [FilePreviewDialog.vue](resources/js/components/common/FilePreviewDialog.vue#L1), [AttendanceHistoryPanel.vue](resources/js/views/Attendance/AttendanceHistoryPanel.vue#L1), [EmployeeProfileTab.vue](resources/js/views/Employee/EmployeeProfileTab.vue#L1), [EmployeeContractsTab.vue](resources/js/views/Employee/EmployeeContractsTab.vue#L1), [EmployeeDocumentsTab.vue](resources/js/views/Employee/EmployeeDocumentsTab.vue#L1), [EmployeeTransfersTab.vue](resources/js/views/Employee/EmployeeTransfersTab.vue#L1), [EmployeeShiftAssignmentsTab.vue](resources/js/views/Employee/EmployeeShiftAssignmentsTab.vue#L1), [EmployeeSalaryLeaveTab.vue](resources/js/views/Employee/EmployeeSalaryLeaveTab.vue#L1)

</details>

<details>
<summary><code>EmployeeDocumentsTab.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Employee/EmployeeDocumentsTab.vue](resources/js/views/Employee/EmployeeDocumentsTab.vue#L1)
- **Gọi API:**
  - `employeeService.documents()` → `GET /employees/{x}/documents` → [EmployeeDocumentController::index()](app/Http/Controllers/Api/V1/EmployeeDocumentController.php#L23)
  - `employeeService.deleteDocument()` → `DELETE /employees/{x}/documents/{x}` → [EmployeeDocumentController::destroy()](app/Http/Controllers/Api/V1/EmployeeDocumentController.php#L72)

</details>

<details>
<summary><code>EmployeeForm.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Employee/EmployeeForm.vue](resources/js/views/Employee/EmployeeForm.vue#L1)
- **Gọi API:**
  - `addressService.provinces()` → `GET /addresses/provinces` → [AddressController::provinces()](app/Http/Controllers/Api/V1/AddressController.php#L16)
  - `addressService.communes()` → `GET /addresses/communes` → [AddressController::communes()](app/Http/Controllers/Api/V1/AddressController.php#L21)
  - `employeeService.checkUnique()` → `GET /employees/check-unique` → [EmployeeController::checkUnique()](app/Http/Controllers/Api/V1/EmployeeController.php#L39)
  - `employeeService.createAccount()` → `POST /employees/{x}/account` → [EmployeeAccountController::store()](app/Http/Controllers/Api/V1/EmployeeAccountController.php#L17)
  - `positionService.list()` → `GET /positions` → [PositionController::index()](app/Http/Controllers/Api/V1/PositionController.php#L19)
  - `roleService.list()` → `GET /roles` → [RoleController::index()](app/Http/Controllers/Api/V1/RoleController.php#L24)
- **Store dùng:** `useEmployeeStore`, `useDepartmentStore`, `useAuthStore`
- **Component con:** [StatusChip.vue](resources/js/components/common/StatusChip.vue#L1), [FormDialog.vue](resources/js/components/common/FormDialog.vue#L1), [FormSection.vue](resources/js/components/common/FormSection.vue#L1), [SearchSelect.vue](resources/js/components/common/SearchSelect.vue#L1), DepartmentFormDialog.vue, PositionFormDialog.vue

</details>

<details>
<summary><code>EmployeeProfileTab.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Employee/EmployeeProfileTab.vue](resources/js/views/Employee/EmployeeProfileTab.vue#L1)
- **Gọi API:**
  - `employeeService.createAccount()` → `POST /employees/{x}/account` → [EmployeeAccountController::store()](app/Http/Controllers/Api/V1/EmployeeAccountController.php#L17)
  - `roleService.list()` → `GET /roles` → [RoleController::index()](app/Http/Controllers/Api/V1/RoleController.php#L24)
- **Component con:** [StatusChip.vue](resources/js/components/common/StatusChip.vue#L1), [SearchSelect.vue](resources/js/components/common/SearchSelect.vue#L1)

</details>

<details>
<summary><code>Employees.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Employee/Employees.vue](resources/js/views/Employee/Employees.vue#L1)
- **Route FE:** `/employees`
- **Gọi API:**
  - `employeeService.stats()` → `GET /employees/stats` → [EmployeeController::stats()](app/Http/Controllers/Api/V1/EmployeeController.php#L55)
- **Store dùng:** `useEmployeeStore`, `useDepartmentStore`, `useAuthStore`, `usePresenceStore`, `useResourceSyncStore`
- **Component con:** [DataTable.vue](resources/js/components/common/DataTable.vue#L1), [SearchField.vue](resources/js/components/common/SearchField.vue#L1), [PageHeader.vue](resources/js/components/common/PageHeader.vue#L1), [StatusChip.vue](resources/js/components/common/StatusChip.vue#L1), [StatCards.vue](resources/js/components/dashboard/StatCards.vue#L1), EmployeeFormDialog.vue

</details>

<details>
<summary><code>EmployeeTransfersTab.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Employee/EmployeeTransfersTab.vue](resources/js/views/Employee/EmployeeTransfersTab.vue#L1)
- **Gọi API:**
  - `departmentService.tree()` → `GET /departments/tree` → [DepartmentController::tree()](app/Http/Controllers/Api/V1/DepartmentController.php#L47)
  - `employeeService.transfers()` → `GET /employees/{x}/transfers` → [EmployeeTransferController::index()](app/Http/Controllers/Api/V1/EmployeeTransferController.php#L23)
  - `employeeService.createTransfer()` → `POST /employees/{x}/transfers` → [EmployeeTransferController::store()](app/Http/Controllers/Api/V1/EmployeeTransferController.php#L45)
  - `positionService.list()` → `GET /positions` → [PositionController::index()](app/Http/Controllers/Api/V1/PositionController.php#L19)
- **Component con:** [SearchSelect.vue](resources/js/components/common/SearchSelect.vue#L1), [InputDate.vue](resources/js/components/common/InputDate.vue#L1)

</details>

<details>
<summary><code>MyProfile.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Me/MyProfile.vue](resources/js/views/Me/MyProfile.vue#L1)
- **Route FE:** `/my-profile`
- **Gọi API:**
  - `employeeService.me()` → `GET /employees/me` → [EmployeeController::me()](app/Http/Controllers/Api/V1/EmployeeController.php#L64)
  - `employeeService.uploadMyAvatar()` → `POST /employees/me/avatar` → [EmployeeController::uploadMyAvatar()](app/Http/Controllers/Api/V1/EmployeeController.php#L120)
  - `resignationService.create()` → `POST /resignations` → [ResignationRequestController::store()](app/Http/Controllers/Api/V1/ResignationRequestController.php#L21)
  - `resignationService.policy()` → `GET /resignations/policy` → [ResignationRequestController::policy()](app/Http/Controllers/Api/V1/ResignationRequestController.php#L44)
  - `resignationService.mine()` → `GET /resignations/me` → [ResignationRequestController::mine()](app/Http/Controllers/Api/V1/ResignationRequestController.php#L53)
  - `resignationService.cancel()` → `POST /resignations/{x}/cancel` → [ResignationRequestController::cancel()](app/Http/Controllers/Api/V1/ResignationRequestController.php#L62)
- **Store dùng:** `useAuthStore`
- **Component con:** [PageHeader.vue](resources/js/components/common/PageHeader.vue#L1), [StatusChip.vue](resources/js/components/common/StatusChip.vue#L1), [StatCards.vue](resources/js/components/dashboard/StatCards.vue#L1), [FilePreviewDialog.vue](resources/js/components/common/FilePreviewDialog.vue#L1), [MyProfileInfoTab.vue](resources/js/views/Me/MyProfileInfoTab.vue#L1), [MyProfileContractsTab.vue](resources/js/views/Me/MyProfileContractsTab.vue#L1), [MyProfileDocumentsTab.vue](resources/js/views/Me/MyProfileDocumentsTab.vue#L1), [MyProfileShiftsTab.vue](resources/js/views/Me/MyProfileShiftsTab.vue#L1), [MyProfileTransfersTab.vue](resources/js/views/Me/MyProfileTransfersTab.vue#L1), [MyProfilePayslipsTab.vue](resources/js/views/Me/MyProfilePayslipsTab.vue#L1)

</details>

<details>
<summary><code>MyProfileContractsTab.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Me/MyProfileContractsTab.vue](resources/js/views/Me/MyProfileContractsTab.vue#L1)
- **Gọi API:**
  - `employeeService.myContracts()` → `GET /employees/me/contracts` → [EmployeeContractController::mine()](app/Http/Controllers/Api/V1/EmployeeContractController.php#L31)
- **Component con:** [StatusChip.vue](resources/js/components/common/StatusChip.vue#L1)

</details>

<details>
<summary><code>MyProfileDocumentsTab.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Me/MyProfileDocumentsTab.vue](resources/js/views/Me/MyProfileDocumentsTab.vue#L1)
- **Gọi API:**
  - `employeeService.myDocuments()` → `GET /employees/me/documents` → [EmployeeDocumentController::mine()](app/Http/Controllers/Api/V1/EmployeeDocumentController.php#L32)
  - `employeeService.uploadMyDocument()` → `POST /employees/me/documents` → [EmployeeDocumentController::storeMine()](app/Http/Controllers/Api/V1/EmployeeDocumentController.php#L44)

</details>

<details>
<summary><code>MyProfileInfoTab.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Me/MyProfileInfoTab.vue](resources/js/views/Me/MyProfileInfoTab.vue#L1)
- **Gọi API:**
  - `addressService.provinces()` → `GET /addresses/provinces` → [AddressController::provinces()](app/Http/Controllers/Api/V1/AddressController.php#L16)
  - `addressService.communes()` → `GET /addresses/communes` → [AddressController::communes()](app/Http/Controllers/Api/V1/AddressController.php#L21)
  - `employeeService.updateMe()` → `PUT /employees/me` → [EmployeeController::updateMine()](app/Http/Controllers/Api/V1/EmployeeController.php#L76)
- **Component con:** [SearchSelect.vue](resources/js/components/common/SearchSelect.vue#L1)

</details>

<details>
<summary><code>MyProfilePayslipsTab.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Me/MyProfilePayslipsTab.vue](resources/js/views/Me/MyProfilePayslipsTab.vue#L1)
- **Gọi API:**
  - `payrollService.mine()` → `GET /payrolls/me` → [PayrollController::mine()](app/Http/Controllers/Api/V1/PayrollController.php#L31)
- **Component con:** [StatusChip.vue](resources/js/components/common/StatusChip.vue#L1), [PayrollPayslipDialog.vue](resources/js/views/Payroll/PayrollPayslipDialog.vue#L1)

</details>

<details>
<summary><code>MyProfileShiftsTab.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Me/MyProfileShiftsTab.vue](resources/js/views/Me/MyProfileShiftsTab.vue#L1)
- **Gọi API:**
  - `employeeService.myShiftAssignments()` → `GET /employees/me/shift-assignments` → [EmployeeShiftAssignmentController::mine()](app/Http/Controllers/Api/V1/EmployeeShiftAssignmentController.php#L27)

</details>

<details>
<summary><code>MyProfileTransfersTab.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Me/MyProfileTransfersTab.vue](resources/js/views/Me/MyProfileTransfersTab.vue#L1)
- **Gọi API:**
  - `employeeService.myTransfers()` → `GET /employees/me/transfers` → [EmployeeTransferController::mine()](app/Http/Controllers/Api/V1/EmployeeTransferController.php#L36)

</details>

<details>
<summary><code>useEmployeeStore.js</code></summary>

- **Mã nguồn:** [resources/js/stores/useEmployeeStore.js](resources/js/stores/useEmployeeStore.js#L1)
- **Gọi API:**
  - `employeeService.list()` → `GET /employees` → [EmployeeController::index()](app/Http/Controllers/Api/V1/EmployeeController.php#L28)
  - `employeeService.create()` → `POST /employees` → [EmployeeController::store()](app/Http/Controllers/Api/V1/EmployeeController.php#L90)
  - `employeeService.update()` → `PUT /employees/{x}` → [EmployeeController::update()](app/Http/Controllers/Api/V1/EmployeeController.php#L96)
- **Store dùng:** `useEmployeeStore`

</details>

<details>
<summary><code>avatar.js</code></summary>

- **Mã nguồn:** [resources/js/composables/avatar.js](resources/js/composables/avatar.js#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).

</details>

<details>
<summary><code>employmentStatus.js</code></summary>

- **Mã nguồn:** [resources/js/composables/employmentStatus.js](resources/js/composables/employmentStatus.js#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).

</details>

</details>

<details>
<summary><strong>Chi tiết từng hàm Controller</strong> — 27 hàm</summary>

<details>
<summary><code>EmployeeAccountController</code> — 1 hàm</summary>

- **Mã nguồn:** [app/Http/Controllers/Api/V1/EmployeeAccountController.php](app/Http/Controllers/Api/V1/EmployeeAccountController.php#L1)

<details>
<summary><code>public store()</code> — Tạo mới</summary>

- **Mã nguồn:** [EmployeeAccountController::store()](app/Http/Controllers/Api/V1/EmployeeAccountController.php#L17)
- **API:** `POST /api/v1/employees/{employee}/account` — quyền `employee.update` ([employees.php](routes/api/v1/employees.php))
- **FE service:** [employeeService.createAccount()](resources/js/services/employeeService.js#L78) ← gọi từ [EmployeeForm.vue](resources/js/views/Employee/EmployeeForm.vue#L1), [EmployeeProfileTab.vue](resources/js/views/Employee/EmployeeProfileTab.vue#L1)
- **Validate:** [StoreEmployeeAccountRequest](app/Http/Requests/EmployeeAccount/StoreEmployeeAccountRequest.php#L1)
- **Gọi xuống:** [EmployeeAccountService::create()](app/Services/EmployeeAccountService.php#L34)

</details>

</details>

<details>
<summary><code>EmployeeContractController</code> — 5 hàm</summary>

- **Mã nguồn:** [app/Http/Controllers/Api/V1/EmployeeContractController.php](app/Http/Controllers/Api/V1/EmployeeContractController.php#L1)

<details>
<summary><code>public index()</code> — Danh sách</summary>

- **Mã nguồn:** [EmployeeContractController::index()](app/Http/Controllers/Api/V1/EmployeeContractController.php#L23)
- **API:** `GET /api/v1/employees/{employee}/contracts` — quyền `employee.view` ([employees.php](routes/api/v1/employees.php))
- **FE service:** [employeeService.contracts()](resources/js/services/employeeService.js#L42) ← gọi từ [EmployeeContractsTab.vue](resources/js/views/Employee/EmployeeContractsTab.vue#L1)
- **Gọi xuống:** [EmployeeContractService::listForEmployee()](app/Services/EmployeeContractService.php#L51)

</details>

<details>
<summary><code>public mine()</code> — Hợp đồng của CHÍNH người đang đăng nhập — cùng lý do không gắn permission:employee.view như EmployeeController::me(), xem route riêng.</summary>

- **Mã nguồn:** [EmployeeContractController::mine()](app/Http/Controllers/Api/V1/EmployeeContractController.php#L31)
- **API:** `GET /api/v1/employees/me/contracts` — quyền `auth` ([employees.php](routes/api/v1/employees.php))
- **FE service:** [employeeService.myContracts()](resources/js/services/employeeService.js#L18) ← gọi từ [MyProfileContractsTab.vue](resources/js/views/Me/MyProfileContractsTab.vue#L1)
- **Gọi xuống:** [EmployeeContractService::listForEmployee()](app/Services/EmployeeContractService.php#L51)

</details>

<details>
<summary><code>public store()</code> — Tạo mới</summary>

- **Mã nguồn:** [EmployeeContractController::store()](app/Http/Controllers/Api/V1/EmployeeContractController.php#L40)
- **API:** `POST /api/v1/employees/{employee}/contracts` — quyền `employee.update` ([employees.php](routes/api/v1/employees.php))
- **FE service:** [employeeService.createContract()](resources/js/services/employeeService.js#L45) ← gọi từ [EmployeeContractsTab.vue](resources/js/views/Employee/EmployeeContractsTab.vue#L1)
- **Validate:** [StoreEmployeeContractRequest](app/Http/Requests/EmployeeContract/StoreEmployeeContractRequest.php#L1)
- **Gọi xuống:** [EmployeeContractService::create()](app/Services/EmployeeContractService.php#L62)

</details>

<details>
<summary><code>public terminate()</code> — Chấm dứt</summary>

- **Mã nguồn:** [EmployeeContractController::terminate()](app/Http/Controllers/Api/V1/EmployeeContractController.php#L46)
- **API:** `POST /api/v1/employees/{employee}/contracts/{contract}/terminate` — quyền `employee.update` ([employees.php](routes/api/v1/employees.php))
- **FE service:** [employeeService.terminateContract()](resources/js/services/employeeService.js#L48) ← gọi từ [EmployeeContractsTab.vue](resources/js/views/Employee/EmployeeContractsTab.vue#L1)
- **Gọi xuống:** [EmployeeContractService::terminate()](app/Services/EmployeeContractService.php#L115)

</details>

<details>
<summary><code>public download()</code> — Tải xuống</summary>

- **Mã nguồn:** [EmployeeContractController::download()](app/Http/Controllers/Api/V1/EmployeeContractController.php#L57)
- **API:** `GET /api/v1/employees/{employee}/contracts/{contract}/download` — quyền `auth` ([employees.php](routes/api/v1/employees.php))

</details>

</details>

<details>
<summary><code>EmployeeController</code> — 11 hàm</summary>

- **Mã nguồn:** [app/Http/Controllers/Api/V1/EmployeeController.php](app/Http/Controllers/Api/V1/EmployeeController.php#L1)

<details>
<summary><code>public index()</code> — Danh sách</summary>

- **Mã nguồn:** [EmployeeController::index()](app/Http/Controllers/Api/V1/EmployeeController.php#L28)
- **API:** `GET /api/v1/employees` — quyền `employee.view` ([employees.php](routes/api/v1/employees.php))
- **FE service:** [employeeService.list()](resources/js/services/employeeService.js#L5) ← gọi từ [useEmployeeStore.js](resources/js/stores/useEmployeeStore.js#L1)
- **Gọi xuống:** [EmployeeService::list()](app/Services/EmployeeService.php#L22)

</details>

<details>
<summary><code>public checkUnique()</code> — Trùng dữ liệu định danh? Đối chiếu ĐÚNG cách rule 'unique:employees,...' của Store/UpdateEmployeeRequest — tính cả nhân viên đã xóa mềm; khi sửa thì bỏ qua chính nhân vi…</summary>

- **Mã nguồn:** [EmployeeController::checkUnique()](app/Http/Controllers/Api/V1/EmployeeController.php#L39)
- **API:** `GET /api/v1/employees/check-unique` — quyền `employee.create,employee.update` ([employees.php](routes/api/v1/employees.php))
- **FE service:** [employeeService.checkUnique()](resources/js/services/employeeService.js#L9) ← gọi từ [EmployeeForm.vue](resources/js/views/Employee/EmployeeForm.vue#L1)

</details>

<details>
<summary><code>public stats()</code> — Thống kê</summary>

- **Mã nguồn:** [EmployeeController::stats()](app/Http/Controllers/Api/V1/EmployeeController.php#L55)
- **API:** `GET /api/v1/employees/stats` — quyền `employee.view` ([employees.php](routes/api/v1/employees.php))
- **FE service:** [employeeService.stats()](resources/js/services/employeeService.js#L12) ← gọi từ [Employees.vue](resources/js/views/Employee/Employees.vue#L1)
- **Gọi xuống:** [EmployeeService::stats()](app/Services/EmployeeService.php#L30)

</details>

<details>
<summary><code>public me()</code> — Hồ sơ của CHÍNH người đang đăng nhập — cố tình không gắn middleware permission:employee.view (route riêng, xem routes/api/v1/employees.php): xem hồ sơ của bản thân là qu…</summary>

- **Mã nguồn:** [EmployeeController::me()](app/Http/Controllers/Api/V1/EmployeeController.php#L64)
- **API:** `GET /api/v1/employees/me` — quyền `auth` ([employees.php](routes/api/v1/employees.php))
- **FE service:** [employeeService.me()](resources/js/services/employeeService.js#L15) ← gọi từ [MyProfile.vue](resources/js/views/Me/MyProfile.vue#L1)

</details>

<details>
<summary><code>public updateMine()</code> — Tự sửa MỘT PHẦN hồ sơ chính mình (chỉ thông tin liên hệ — xem UpdateMyProfileRequest) — cùng không gắn permission:employee.update như me(), tái dùng nguyên EmployeeServi…</summary>

- **Mã nguồn:** [EmployeeController::updateMine()](app/Http/Controllers/Api/V1/EmployeeController.php#L76)
- **API:** `PUT /api/v1/employees/me` — quyền `auth` ([employees.php](routes/api/v1/employees.php))
- **FE service:** [employeeService.updateMe()](resources/js/services/employeeService.js#L30) ← gọi từ [MyProfileInfoTab.vue](resources/js/views/Me/MyProfileInfoTab.vue#L1)
- **Validate:** [UpdateMyProfileRequest](app/Http/Requests/Employee/UpdateMyProfileRequest.php#L1)
- **Gọi xuống:** [EmployeeService::update()](app/Services/EmployeeService.php#L104)

</details>

<details>
<summary><code>public show()</code> — Xem chi tiết</summary>

- **Mã nguồn:** [EmployeeController::show()](app/Http/Controllers/Api/V1/EmployeeController.php#L86)
- **API:** `GET /api/v1/employees/{employee}` — quyền `employee.view` ([employees.php](routes/api/v1/employees.php))
- **FE service:** [employeeService.get()](resources/js/services/employeeService.js#L39) ← gọi từ [EmployeeDetail.vue](resources/js/views/Employee/EmployeeDetail.vue#L1)

</details>

<details>
<summary><code>public store()</code> — Tạo mới</summary>

- **Mã nguồn:** [EmployeeController::store()](app/Http/Controllers/Api/V1/EmployeeController.php#L90)
- **API:** `POST /api/v1/employees` — quyền `employee.create` ([employees.php](routes/api/v1/employees.php))
- **FE service:** [employeeService.create()](resources/js/services/employeeService.js#L72) ← gọi từ [useEmployeeStore.js](resources/js/stores/useEmployeeStore.js#L1)
- **Validate:** [StoreEmployeeRequest](app/Http/Requests/Employee/StoreEmployeeRequest.php#L1)
- **Gọi xuống:** [EmployeeService::create()](app/Services/EmployeeService.php#L41)

</details>

<details>
<summary><code>public update()</code> — Cập nhật</summary>

- **Mã nguồn:** [EmployeeController::update()](app/Http/Controllers/Api/V1/EmployeeController.php#L96)
- **API:** `PUT /api/v1/employees/{employee}` — quyền `employee.update` ([employees.php](routes/api/v1/employees.php))
- **FE service:** [employeeService.update()](resources/js/services/employeeService.js#L75) ← gọi từ [useEmployeeStore.js](resources/js/stores/useEmployeeStore.js#L1)
- **Validate:** [UpdateEmployeeRequest](app/Http/Requests/Employee/UpdateEmployeeRequest.php#L1)
- **Gọi xuống:** [EmployeeService::update()](app/Services/EmployeeService.php#L104)

</details>

<details>
<summary><code>public destroy()</code> — Xóa</summary>

- **Mã nguồn:** [EmployeeController::destroy()](app/Http/Controllers/Api/V1/EmployeeController.php#L102)
- **API:** `DELETE /api/v1/employees/{employee}` — quyền `employee.delete` ([employees.php](routes/api/v1/employees.php))
- **Gọi xuống:** [EmployeeService::delete()](app/Services/EmployeeService.php#L121)

</details>

<details>
<summary><code>public uploadAvatar()</code> — Tải lên avatar</summary>

- **Mã nguồn:** [EmployeeController::uploadAvatar()](app/Http/Controllers/Api/V1/EmployeeController.php#L108)
- **API:** `POST /api/v1/employees/{employee}/avatar` — quyền `employee.update` ([employees.php](routes/api/v1/employees.php))
- **Gọi xuống:** [EmployeeService::updateAvatar()](app/Services/EmployeeService.php#L134)

</details>

<details>
<summary><code>public uploadMyAvatar()</code> — Tự đổi ảnh đại diện của CHÍNH MÌNH (trang "Hồ sơ của tôi") — cùng luật kiểm tra file và cùng EmployeeService::updateAvatar() với bản HR ở trên, chỉ khác nguồn lấy employ…</summary>

- **Mã nguồn:** [EmployeeController::uploadMyAvatar()](app/Http/Controllers/Api/V1/EmployeeController.php#L120)
- **API:** `POST /api/v1/employees/me/avatar` — quyền `auth` ([employees.php](routes/api/v1/employees.php))
- **FE service:** [employeeService.uploadMyAvatar()](resources/js/services/employeeService.js#L33) ← gọi từ [MyProfile.vue](resources/js/views/Me/MyProfile.vue#L1)
- **Gọi xuống:** [EmployeeService::updateAvatar()](app/Services/EmployeeService.php#L134)

</details>

</details>

<details>
<summary><code>EmployeeDocumentController</code> — 6 hàm</summary>

- **Mã nguồn:** [app/Http/Controllers/Api/V1/EmployeeDocumentController.php](app/Http/Controllers/Api/V1/EmployeeDocumentController.php#L1)

<details>
<summary><code>public index()</code> — Danh sách</summary>

- **Mã nguồn:** [EmployeeDocumentController::index()](app/Http/Controllers/Api/V1/EmployeeDocumentController.php#L23)
- **API:** `GET /api/v1/employees/{employee}/documents` — quyền `employee.view` ([employees.php](routes/api/v1/employees.php))
- **FE service:** [employeeService.documents()](resources/js/services/employeeService.js#L54) ← gọi từ [EmployeeDocumentsTab.vue](resources/js/views/Employee/EmployeeDocumentsTab.vue#L1)
- **Gọi xuống:** [EmployeeDocumentService::listForEmployee()](app/Services/EmployeeDocumentService.php#L18)

</details>

<details>
<summary><code>public mine()</code> — Tài liệu của CHÍNH người đang đăng nhập — cùng lý do không gắn permission:employee.view như EmployeeController::me(), xem route riêng.</summary>

- **Mã nguồn:** [EmployeeDocumentController::mine()](app/Http/Controllers/Api/V1/EmployeeDocumentController.php#L32)
- **API:** `GET /api/v1/employees/me/documents` — quyền `auth` ([employees.php](routes/api/v1/employees.php))
- **FE service:** [employeeService.myDocuments()](resources/js/services/employeeService.js#L21) ← gọi từ [MyProfileDocumentsTab.vue](resources/js/views/Me/MyProfileDocumentsTab.vue#L1)
- **Gọi xuống:** [EmployeeDocumentService::listForEmployee()](app/Services/EmployeeDocumentService.php#L18)

</details>

<details>
<summary><code>public storeMine()</code> — Tự tải tài liệu lên hồ sơ CHÍNH MÌNH — cùng lý do không gắn permission:employee.update như mine()/me(): tài liệu cá nhân (CCCD chụp ảnh, sơ yếu lý lịch...) nhân viên tự …</summary>

- **Mã nguồn:** [EmployeeDocumentController::storeMine()](app/Http/Controllers/Api/V1/EmployeeDocumentController.php#L44)
- **API:** `POST /api/v1/employees/me/documents` — quyền `auth` ([employees.php](routes/api/v1/employees.php))
- **FE service:** [employeeService.uploadMyDocument()](resources/js/services/employeeService.js#L36) ← gọi từ [MyProfileDocumentsTab.vue](resources/js/views/Me/MyProfileDocumentsTab.vue#L1)
- **Validate:** [StoreEmployeeDocumentRequest](app/Http/Requests/EmployeeDocument/StoreEmployeeDocumentRequest.php#L1)
- **Gọi xuống:** [EmployeeDocumentService::create()](app/Services/EmployeeDocumentService.php#L23)

</details>

<details>
<summary><code>public store()</code> — Tạo mới</summary>

- **Mã nguồn:** [EmployeeDocumentController::store()](app/Http/Controllers/Api/V1/EmployeeDocumentController.php#L60)
- **API:** `POST /api/v1/employees/{employee}/documents` — quyền `employee.update` ([employees.php](routes/api/v1/employees.php))
- **Validate:** [StoreEmployeeDocumentRequest](app/Http/Requests/EmployeeDocument/StoreEmployeeDocumentRequest.php#L1)
- **Gọi xuống:** [EmployeeDocumentService::create()](app/Services/EmployeeDocumentService.php#L23)

</details>

<details>
<summary><code>public destroy()</code> — Xóa</summary>

- **Mã nguồn:** [EmployeeDocumentController::destroy()](app/Http/Controllers/Api/V1/EmployeeDocumentController.php#L72)
- **API:** `DELETE /api/v1/employees/{employee}/documents/{document}` — quyền `employee.update` ([employees.php](routes/api/v1/employees.php))
- **FE service:** [employeeService.deleteDocument()](resources/js/services/employeeService.js#L63) ← gọi từ [EmployeeDocumentsTab.vue](resources/js/views/Employee/EmployeeDocumentsTab.vue#L1)
- **Gọi xuống:** [EmployeeDocumentService::delete()](app/Services/EmployeeDocumentService.php#L34)

</details>

<details>
<summary><code>public download()</code> — Tải xuống</summary>

- **Mã nguồn:** [EmployeeDocumentController::download()](app/Http/Controllers/Api/V1/EmployeeDocumentController.php#L83)
- **API:** `GET /api/v1/employees/{employee}/documents/{document}/download` — quyền `auth` ([employees.php](routes/api/v1/employees.php))

</details>

</details>

<details>
<summary><code>EmployeeTransferController</code> — 4 hàm</summary>

- **Mã nguồn:** [app/Http/Controllers/Api/V1/EmployeeTransferController.php](app/Http/Controllers/Api/V1/EmployeeTransferController.php#L1)

<details>
<summary><code>public index()</code> — Danh sách</summary>

- **Mã nguồn:** [EmployeeTransferController::index()](app/Http/Controllers/Api/V1/EmployeeTransferController.php#L23)
- **API:** `GET /api/v1/employees/{employee}/transfers` — quyền `employee.view` ([employees.php](routes/api/v1/employees.php))
- **FE service:** [employeeService.transfers()](resources/js/services/employeeService.js#L66) ← gọi từ [EmployeeTransfersTab.vue](resources/js/views/Employee/EmployeeTransfersTab.vue#L1)
- **Gọi xuống:** [EmployeeTransferService::listForEmployee()](app/Services/EmployeeTransferService.php#L23)

</details>

<details>
<summary><code>public mine()</code> — Lịch sử luân chuyển của CHÍNH người đang đăng nhập — cùng lý do không gắn permission:employee.view như EmployeeController::me().</summary>

- **Mã nguồn:** [EmployeeTransferController::mine()](app/Http/Controllers/Api/V1/EmployeeTransferController.php#L36)
- **API:** `GET /api/v1/employees/me/transfers` — quyền `auth` ([employees.php](routes/api/v1/employees.php))
- **FE service:** [employeeService.myTransfers()](resources/js/services/employeeService.js#L27) ← gọi từ [MyProfileTransfersTab.vue](resources/js/views/Me/MyProfileTransfersTab.vue#L1)
- **Gọi xuống:** [EmployeeTransferService::listForEmployee()](app/Services/EmployeeTransferService.php#L23)

</details>

<details>
<summary><code>public store()</code> — Tạo mới</summary>

- **Mã nguồn:** [EmployeeTransferController::store()](app/Http/Controllers/Api/V1/EmployeeTransferController.php#L45)
- **API:** `POST /api/v1/employees/{employee}/transfers` — quyền `employee.update` ([employees.php](routes/api/v1/employees.php))
- **FE service:** [employeeService.createTransfer()](resources/js/services/employeeService.js#L69) ← gọi từ [EmployeeTransfersTab.vue](resources/js/views/Employee/EmployeeTransfersTab.vue#L1)
- **Validate:** [StoreEmployeeTransferRequest](app/Http/Requests/EmployeeTransfer/StoreEmployeeTransferRequest.php#L1)
- **Gọi xuống:** [EmployeeTransferService::create()](app/Services/EmployeeTransferService.php#L34)

</details>

<details>
<summary><code>public download()</code> — Tải xuống</summary>

- **Mã nguồn:** [EmployeeTransferController::download()](app/Http/Controllers/Api/V1/EmployeeTransferController.php#L57)
- **API:** `GET /api/v1/employees/{employee}/transfers/{transfer}/download` — quyền `auth` ([employees.php](routes/api/v1/employees.php))

</details>

</details>

</details>

<details>
<summary><strong>Chi tiết từng hàm Service</strong> — 18 hàm</summary>

<details>
<summary><code>EmployeeAccountService</code> — 3 hàm</summary>

- **Mã nguồn:** [app/Services/EmployeeAccountService.php](app/Services/EmployeeAccountService.php#L1)

<details>
<summary><code>public create()</code> — Tạo tài khoản đăng nhập cho 1 nhân viên chưa có tài khoản (user_id null), gán sẵn các Role được chọn (thường là gợi ý theo Chức vụ — xem PositionService::suggestRole() —…</summary>

- **Mã nguồn:** [EmployeeAccountService::create()](app/Services/EmployeeAccountService.php#L34)
- **Gọi xuống:** [UserRepository::findByEmail()](app/Repositories/UserRepository.php#L17) · [UserRepository::create()](app/Repositories/UserRepository.php#L22) · [PasswordResetService::requestReset()](app/Services/PasswordResetService.php#L30)
- **Hiệu ứng phụ:** `DB::transaction`
- **Được gọi bởi:** [EmployeeAccountController::store()](app/Http/Controllers/Api/V1/EmployeeAccountController.php#L17)

</details>

<details>
<summary><code>public deactivateAccountOf()</code> — Nhân viên nghỉ việc / chấm dứt hợp đồng / bị xóa hồ sơ thì KHÓA tài khoản đăng nhập : access token hiện có mất hiệu lực ngay (JwtGuard kiểm tra status mỗi request), refr…</summary>

- **Mã nguồn:** [EmployeeAccountService::deactivateAccountOf()](app/Services/EmployeeAccountService.php#L94)
- **Gọi xuống:** [RefreshTokenRepository::revokeAllForUser()](app/Repositories/RefreshTokenRepository.php#L44)
- **Được gọi bởi:** [EmployeeContractService::terminate()](app/Services/EmployeeContractService.php#L115) · [EmployeeService::delete()](app/Services/EmployeeService.php#L121) · [ResignationService::applyIfDue()](app/Services/ResignationService.php#L217)

</details>

<details>
<summary><code>public reactivateAccountOf()</code> — Tuyển lại (ký hợp đồng mới cho người từng nghỉ) -> mở lại tài khoản đã khóa.</summary>

- **Mã nguồn:** [EmployeeAccountService::reactivateAccountOf()](app/Services/EmployeeAccountService.php#L108)
- **Được gọi bởi:** [EmployeeContractService::applyEmploymentStatus()](app/Services/EmployeeContractService.php#L33)

</details>

</details>

<details>
<summary><code>EmployeeContractService</code> — 4 hàm</summary>

- **Mã nguồn:** [app/Services/EmployeeContractService.php](app/Services/EmployeeContractService.php#L1)

<details>
<summary><code>public applyEmploymentStatus()</code> — Gọi mỗi khi 1 hợp đồng BẮT ĐẦU có hiệu lực (tạo mới với start_date <= hôm nay, hoặc job contracts:activate-pending kích hoạt) — trạng thái nhân viên theo đúng loại hợp đ…</summary>

- **Mã nguồn:** [EmployeeContractService::applyEmploymentStatus()](app/Services/EmployeeContractService.php#L33)
- **Gọi xuống:** [EmployeeAccountService::reactivateAccountOf()](app/Services/EmployeeAccountService.php#L108)

</details>

<details>
<summary><code>public listForEmployee()</code> — Lấy danh sách for employee</summary>

- **Mã nguồn:** [EmployeeContractService::listForEmployee()](app/Services/EmployeeContractService.php#L51)
- **Gọi xuống:** [EmployeeContractRepository::listByEmployee()](app/Repositories/EmployeeContractRepository.php#L11)
- **Được gọi bởi:** [EmployeeContractController::index()](app/Http/Controllers/Api/V1/EmployeeContractController.php#L23) · [EmployeeContractController::mine()](app/Http/Controllers/Api/V1/EmployeeContractController.php#L31)

</details>

<details>
<summary><code>public create()</code> — Tạo</summary>

- **Mã nguồn:** [EmployeeContractService::create()](app/Services/EmployeeContractService.php#L62)
- **Gọi xuống:** [EmployeeContractRepository::update()](app/Repositories/EmployeeContractRepository.php#L32) · [EmployeeContractRepository::create()](app/Repositories/EmployeeContractRepository.php#L21)
- **Hiệu ứng phụ:** `DB::transaction`
- **Được gọi bởi:** [EmployeeContractController::store()](app/Http/Controllers/Api/V1/EmployeeContractController.php#L40) · [EmployeeService::create()](app/Services/EmployeeService.php#L41)

</details>

<details>
<summary><code>public terminate()</code> — Chấm dứt hợp đồng giữa chừng (HR chủ động, khác "expired" tự nhiên hết hạn/bị thay thế) — chỉ cho phép từ "active", ghi lại terminated_at để biết chính xác ngày dừng thự…</summary>

- **Mã nguồn:** [EmployeeContractService::terminate()](app/Services/EmployeeContractService.php#L115)
- **Gọi xuống:** [EmployeeContractRepository::update()](app/Repositories/EmployeeContractRepository.php#L32) · [EmployeeAccountService::deactivateAccountOf()](app/Services/EmployeeAccountService.php#L94)
- **Hiệu ứng phụ:** `DB::transaction`
- **Được gọi bởi:** [EmployeeContractController::terminate()](app/Http/Controllers/Api/V1/EmployeeContractController.php#L46)

</details>

</details>

<details>
<summary><code>EmployeeDocumentService</code> — 3 hàm</summary>

- **Mã nguồn:** [app/Services/EmployeeDocumentService.php](app/Services/EmployeeDocumentService.php#L1)

<details>
<summary><code>public listForEmployee()</code> — Lấy danh sách for employee</summary>

- **Mã nguồn:** [EmployeeDocumentService::listForEmployee()](app/Services/EmployeeDocumentService.php#L18)
- **Gọi xuống:** [EmployeeDocumentRepository::listByEmployee()](app/Repositories/EmployeeDocumentRepository.php#L11)
- **Được gọi bởi:** [EmployeeDocumentController::index()](app/Http/Controllers/Api/V1/EmployeeDocumentController.php#L23) · [EmployeeDocumentController::mine()](app/Http/Controllers/Api/V1/EmployeeDocumentController.php#L32)

</details>

<details>
<summary><code>public create()</code> — Tạo</summary>

- **Mã nguồn:** [EmployeeDocumentService::create()](app/Services/EmployeeDocumentService.php#L23)
- **Gọi xuống:** [EmployeeDocumentRepository::create()](app/Repositories/EmployeeDocumentRepository.php#L16)
- **Hiệu ứng phụ:** `DB::transaction`
- **Được gọi bởi:** [EmployeeDocumentController::storeMine()](app/Http/Controllers/Api/V1/EmployeeDocumentController.php#L44) · [EmployeeDocumentController::store()](app/Http/Controllers/Api/V1/EmployeeDocumentController.php#L60)

</details>

<details>
<summary><code>public delete()</code> — Xóa</summary>

- **Mã nguồn:** [EmployeeDocumentService::delete()](app/Services/EmployeeDocumentService.php#L34)
- **Gọi xuống:** [EmployeeDocumentRepository::delete()](app/Repositories/EmployeeDocumentRepository.php#L21)
- **Được gọi bởi:** [EmployeeDocumentController::destroy()](app/Http/Controllers/Api/V1/EmployeeDocumentController.php#L72)

</details>

</details>

<details>
<summary><code>EmployeeService</code> — 6 hàm</summary>

- **Mã nguồn:** [app/Services/EmployeeService.php](app/Services/EmployeeService.php#L1)

<details>
<summary><code>public list()</code> — Lấy danh sách</summary>

- **Mã nguồn:** [EmployeeService::list()](app/Services/EmployeeService.php#L22)
- **Gọi xuống:** [EmployeeRepository::paginate()](app/Repositories/EmployeeRepository.php#L10)
- **Được gọi bởi:** [EmployeeController::index()](app/Http/Controllers/Api/V1/EmployeeController.php#L28)

</details>

<details>
<summary><code>public stats()</code> — Thống kê</summary>

- **Mã nguồn:** [EmployeeService::stats()](app/Services/EmployeeService.php#L30)
- **Gọi xuống:** [EmployeeRepository::countByStatus()](app/Repositories/EmployeeRepository.php#L36)
- **Được gọi bởi:** [EmployeeController::stats()](app/Http/Controllers/Api/V1/EmployeeController.php#L55)

</details>

<details>
<summary><code>public create()</code> — Tạo</summary>

- **Mã nguồn:** [EmployeeService::create()](app/Services/EmployeeService.php#L41)
- **Gọi xuống:** [EmployeeRepository::create()](app/Repositories/EmployeeRepository.php#L43) · [ReportingLineService::syncEmployee()](app/Services/ReportingLineService.php#L40) · [EmployeeShiftAssignmentService::assignDefaultShift()](app/Services/EmployeeShiftAssignmentService.php#L38) · [EmployeeContractService::create()](app/Services/EmployeeContractService.php#L62)
- **Hiệu ứng phụ:** `DB::transaction`
- **Được gọi bởi:** [EmployeeController::store()](app/Http/Controllers/Api/V1/EmployeeController.php#L90)

</details>

<details>
<summary><code>public update()</code> — Cập nhật</summary>

- **Mã nguồn:** [EmployeeService::update()](app/Services/EmployeeService.php#L104)
- **Gọi xuống:** [EmployeeRepository::update()](app/Repositories/EmployeeRepository.php#L47) · [ReportingLineService::syncEmployee()](app/Services/ReportingLineService.php#L40)
- **Được gọi bởi:** [EmployeeController::updateMine()](app/Http/Controllers/Api/V1/EmployeeController.php#L76) · [EmployeeController::update()](app/Http/Controllers/Api/V1/EmployeeController.php#L96)

</details>

<details>
<summary><code>public delete()</code> — xóa nhân viên mà không gỡ bản gán ca (EmployeeShiftAssignment) trước sẽ để lại bản gán "mồ côi" theo chiều ngược lại (employee_id trỏ tới nhân viên đã xóa mềm), cùng ngu…</summary>

- **Mã nguồn:** [EmployeeService::delete()](app/Services/EmployeeService.php#L121)
- **Gọi xuống:** [EmployeeShiftAssignmentService::removeAllForEmployee()](app/Services/EmployeeShiftAssignmentService.php#L150) · [EmployeeRepository::delete()](app/Repositories/EmployeeRepository.php#L52) · [EmployeeAccountService::deactivateAccountOf()](app/Services/EmployeeAccountService.php#L94)
- **Hiệu ứng phụ:** `DB::transaction`
- **Được gọi bởi:** [EmployeeController::destroy()](app/Http/Controllers/Api/V1/EmployeeController.php#L102)

</details>

<details>
<summary><code>public updateAvatar()</code> — Cập nhật avatar</summary>

- **Mã nguồn:** [EmployeeService::updateAvatar()](app/Services/EmployeeService.php#L134)
- **Gọi xuống:** [EmployeeRepository::update()](app/Repositories/EmployeeRepository.php#L47)
- **Được gọi bởi:** [EmployeeController::uploadAvatar()](app/Http/Controllers/Api/V1/EmployeeController.php#L108) · [EmployeeController::uploadMyAvatar()](app/Http/Controllers/Api/V1/EmployeeController.php#L120)

</details>

</details>

<details>
<summary><code>EmployeeTransferService</code> — 2 hàm</summary>

- **Mã nguồn:** [app/Services/EmployeeTransferService.php](app/Services/EmployeeTransferService.php#L1)

<details>
<summary><code>public listForEmployee()</code> — Lấy danh sách for employee</summary>

- **Mã nguồn:** [EmployeeTransferService::listForEmployee()](app/Services/EmployeeTransferService.php#L23)
- **Gọi xuống:** [EmployeeTransferRepository::listByEmployee()](app/Repositories/EmployeeTransferRepository.php#L11)
- **Được gọi bởi:** [EmployeeTransferController::index()](app/Http/Controllers/Api/V1/EmployeeTransferController.php#L23) · [EmployeeTransferController::mine()](app/Http/Controllers/Api/V1/EmployeeTransferController.php#L36)

</details>

<details>
<summary><code>public create()</code> — Tạo bản ghi luân chuyển ĐỒNG THỜI áp dụng ngay vào hồ sơ nhân viên (department_id/position_id/manager_id) — dự án chưa có hàng chờ/lịch chạy nền (queue worker, xem Ghi c…</summary>

- **Mã nguồn:** [EmployeeTransferService::create()](app/Services/EmployeeTransferService.php#L34)
- **Gọi xuống:** [EmployeeTransferRepository::create()](app/Repositories/EmployeeTransferRepository.php#L19) · [PositionService::ensureDefaultPosition()](app/Services/PositionService.php#L91) · [ReportingLineService::syncEmployee()](app/Services/ReportingLineService.php#L40) · [ReportingLineService::syncDepartmentTree()](app/Services/ReportingLineService.php#L52)
- **Hiệu ứng phụ:** `DB::transaction`
- **Được gọi bởi:** [EmployeeTransferController::store()](app/Http/Controllers/Api/V1/EmployeeTransferController.php#L45)

</details>

</details>

</details>

<details>
<summary><strong>Danh sách test</strong> — 123 test</summary>

<details>
<summary><code>ActivatePendingContractsTest.php</code> — 4 test</summary>

- **Mã nguồn:** [tests/Feature/Console/ActivatePendingContractsTest.php](tests/Feature/Console/ActivatePendingContractsTest.php#L1)
- [test_activates_pending_contracts_whose_start_date_has_arrived](tests/Feature/Console/ActivatePendingContractsTest.php#L42)
- [test_activating_a_pending_contract_supersedes_the_old_active_contract](tests/Feature/Console/ActivatePendingContractsTest.php#L55)
- [test_does_not_touch_pending_contracts_whose_start_date_has_not_arrived](tests/Feature/Console/ActivatePendingContractsTest.php#L70)
- [test_does_not_touch_contracts_that_are_not_pending](tests/Feature/Console/ActivatePendingContractsTest.php#L83)

</details>

<details>
<summary><code>ExpireEmployeeContractsTest.php</code> — 4 test</summary>

- **Mã nguồn:** [tests/Feature/Console/ExpireEmployeeContractsTest.php](tests/Feature/Console/ExpireEmployeeContractsTest.php#L1)
- [test_expires_active_contracts_past_their_end_date](tests/Feature/Console/ExpireEmployeeContractsTest.php#L42)
- [test_does_not_touch_contracts_still_within_end_date](tests/Feature/Console/ExpireEmployeeContractsTest.php#L52)
- [test_does_not_touch_contracts_without_end_date](tests/Feature/Console/ExpireEmployeeContractsTest.php#L62)
- [test_does_not_touch_already_terminated_contracts](tests/Feature/Console/ExpireEmployeeContractsTest.php#L72)

</details>

<details>
<summary><code>EmployeeAccountTest.php</code> — 8 test</summary>

- **Mã nguồn:** [tests/Feature/Employee/EmployeeAccountTest.php](tests/Feature/Employee/EmployeeAccountTest.php#L1)
- [test_unauthenticated_cannot_create_account](tests/Feature/Employee/EmployeeAccountTest.php#L45)
- [test_user_without_update_permission_cannot_create_account](tests/Feature/Employee/EmployeeAccountTest.php#L56)
- [test_admin_can_create_account_and_reset_email_is_sent](tests/Feature/Employee/EmployeeAccountTest.php#L68)
- [test_can_assign_multiple_roles](tests/Feature/Employee/EmployeeAccountTest.php#L92)
- [test_cannot_create_account_twice_for_same_employee](tests/Feature/Employee/EmployeeAccountTest.php#L109)
- [test_cannot_create_account_when_email_already_used](tests/Feature/Employee/EmployeeAccountTest.php#L128)
- [test_role_ids_is_required](tests/Feature/Employee/EmployeeAccountTest.php#L147)
- [test_positions_endpoint_includes_suggested_roles_after_head_position_created](tests/Feature/Employee/EmployeeAccountTest.php#L159)

</details>

<details>
<summary><code>EmployeeCheckUniqueTest.php</code> — 5 test</summary>

- **Mã nguồn:** [tests/Feature/Employee/EmployeeCheckUniqueTest.php](tests/Feature/Employee/EmployeeCheckUniqueTest.php#L1)
- [test_requires_authentication_and_create_or_update_permission](tests/Feature/Employee/EmployeeCheckUniqueTest.php#L42)
- [test_reports_taken_and_available_values_for_every_unique_field](tests/Feature/Employee/EmployeeCheckUniqueTest.php#L52)
- [test_editing_ignores_the_employee_being_edited](tests/Feature/Employee/EmployeeCheckUniqueTest.php#L67)
- [test_soft_deleted_employees_still_count_like_the_save_rule](tests/Feature/Employee/EmployeeCheckUniqueTest.php#L77)
- [test_rejects_unknown_fields](tests/Feature/Employee/EmployeeCheckUniqueTest.php#L85)

</details>

<details>
<summary><code>EmployeeContractTest.php</code> — 23 test</summary>

- **Mã nguồn:** [tests/Feature/Employee/EmployeeContractTest.php](tests/Feature/Employee/EmployeeContractTest.php#L1)
- [test_unauthenticated_cannot_list_contracts](tests/Feature/Employee/EmployeeContractTest.php#L57)
- [test_user_without_update_permission_cannot_create_contract](tests/Feature/Employee/EmployeeContractTest.php#L66)
- [test_admin_can_create_contract_with_pdf_file](tests/Feature/Employee/EmployeeContractTest.php#L78)
- [test_insurance_salary_always_matches_agreed_salary](tests/Feature/Employee/EmployeeContractTest.php#L102)
- [test_contract_file_is_required](tests/Feature/Employee/EmployeeContractTest.php#L120)
- [test_client_supplied_contract_number_is_ignored_on_create](tests/Feature/Employee/EmployeeContractTest.php#L135)
- [test_contract_numbers_auto_increment_per_prefix](tests/Feature/Employee/EmployeeContractTest.php#L150)
- [test_can_list_contracts_for_employee](tests/Feature/Employee/EmployeeContractTest.php#L169)
- [test_can_download_own_contract_file](tests/Feature/Employee/EmployeeContractTest.php#L186)
- [test_cannot_download_contract_via_mismatched_employee](tests/Feature/Employee/EmployeeContractTest.php#L204)
- [test_employee_can_download_own_contract_even_without_employee_view](tests/Feature/Employee/EmployeeContractTest.php#L223)
- [test_user_without_permission_or_ownership_cannot_download_contract](tests/Feature/Employee/EmployeeContractTest.php#L246)
- [test_me_contracts_returns_own_contracts_without_employee_view](tests/Feature/Employee/EmployeeContractTest.php#L270)
- [test_creating_new_contract_auto_expires_previous_active_contract](tests/Feature/Employee/EmployeeContractTest.php#L293)
- [test_creating_contract_with_future_start_date_does_not_touch_current_active_contract](tests/Feature/Employee/EmployeeContractTest.php#L324)
- [test_signing_official_contract_turns_probation_employee_into_official](tests/Feature/Employee/EmployeeContractTest.php#L354)
- [test_future_official_contract_changes_status_only_when_activated](tests/Feature/Employee/EmployeeContractTest.php#L367)
- [test_terminating_the_only_active_contract_marks_employee_terminated](tests/Feature/Employee/EmployeeContractTest.php#L386)
- [test_admin_can_terminate_active_contract](tests/Feature/Employee/EmployeeContractTest.php#L403)
- [test_cannot_terminate_an_already_terminated_contract](tests/Feature/Employee/EmployeeContractTest.php#L426)
- [test_cannot_terminate_contract_via_mismatched_employee](tests/Feature/Employee/EmployeeContractTest.php#L447)
- [test_pending_contract_cannot_be_terminated](tests/Feature/Employee/EmployeeContractTest.php#L465)
- [test_user_without_update_permission_cannot_terminate_contract](tests/Feature/Employee/EmployeeContractTest.php#L485)

</details>

<details>
<summary><code>EmployeeDocumentTest.php</code> — 17 test</summary>

- **Mã nguồn:** [tests/Feature/Employee/EmployeeDocumentTest.php](tests/Feature/Employee/EmployeeDocumentTest.php#L1)
- [test_unauthenticated_cannot_list_documents](tests/Feature/Employee/EmployeeDocumentTest.php#L51)
- [test_user_without_update_permission_cannot_create_document](tests/Feature/Employee/EmployeeDocumentTest.php#L60)
- [test_admin_can_upload_document](tests/Feature/Employee/EmployeeDocumentTest.php#L72)
- [test_document_file_is_required](tests/Feature/Employee/EmployeeDocumentTest.php#L92)
- [test_document_file_rejects_disallowed_type](tests/Feature/Employee/EmployeeDocumentTest.php#L107)
- [test_admin_can_upload_word_document](tests/Feature/Employee/EmployeeDocumentTest.php#L121)
- [test_admin_can_upload_excel_document](tests/Feature/Employee/EmployeeDocumentTest.php#L145)
- [test_document_file_rejects_legacy_office_formats](tests/Feature/Employee/EmployeeDocumentTest.php#L171)
- [test_can_list_documents_for_employee](tests/Feature/Employee/EmployeeDocumentTest.php#L192)
- [test_can_download_own_document_file](tests/Feature/Employee/EmployeeDocumentTest.php#L209)
- [test_download_file_name_keeps_extension](tests/Feature/Employee/EmployeeDocumentTest.php#L226)
- [test_cannot_download_document_via_mismatched_employee](tests/Feature/Employee/EmployeeDocumentTest.php#L245)
- [test_employee_can_download_own_document_even_without_employee_view](tests/Feature/Employee/EmployeeDocumentTest.php#L264)
- [test_user_without_permission_or_ownership_cannot_download_document](tests/Feature/Employee/EmployeeDocumentTest.php#L287)
- [test_me_documents_returns_own_documents_without_employee_view](tests/Feature/Employee/EmployeeDocumentTest.php#L311)
- [test_admin_can_delete_document](tests/Feature/Employee/EmployeeDocumentTest.php#L334)
- [test_cannot_delete_document_via_mismatched_employee](tests/Feature/Employee/EmployeeDocumentTest.php#L356)

</details>

<details>
<summary><code>EmployeePhoneRuleTest.php</code> — 1 test</summary>

- **Mã nguồn:** [tests/Feature/Employee/EmployeePhoneRuleTest.php](tests/Feature/Employee/EmployeePhoneRuleTest.php#L1)
- [test_phone_must_be_exactly_ten_digits_when_creating_an_employee](tests/Feature/Employee/EmployeePhoneRuleTest.php#L26)

</details>

<details>
<summary><code>EmployeeTest.php</code> — 48 test</summary>

- **Mã nguồn:** [tests/Feature/Employee/EmployeeTest.php](tests/Feature/Employee/EmployeeTest.php#L1)
- [test_unauthenticated_is_rejected](tests/Feature/Employee/EmployeeTest.php#L132)
- [test_user_without_view_permission_is_forbidden](tests/Feature/Employee/EmployeeTest.php#L139)
- [test_user_with_view_permission_can_list_employees](tests/Feature/Employee/EmployeeTest.php#L158)
- [test_stats_endpoint_requires_view_permission](tests/Feature/Employee/EmployeeTest.php#L169)
- [test_stats_endpoint_returns_counts_by_employment_status](tests/Feature/Employee/EmployeeTest.php#L176)
- [test_can_search_employees_by_name](tests/Feature/Employee/EmployeeTest.php#L197)
- [test_can_filter_employees_by_employment_status](tests/Feature/Employee/EmployeeTest.php#L213)
- [test_user_without_create_permission_cannot_create](tests/Feature/Employee/EmployeeTest.php#L229)
- [test_admin_can_create_employee_with_auto_generated_code](tests/Feature/Employee/EmployeeTest.php#L246)
- [test_client_supplied_code_is_ignored_on_create](tests/Feature/Employee/EmployeeTest.php#L262)
- [test_create_requires_full_employee_profile](tests/Feature/Employee/EmployeeTest.php#L278)
- [test_creating_employee_auto_creates_first_contract](tests/Feature/Employee/EmployeeTest.php#L311)
- [test_official_contract_type_sets_employee_status_to_official](tests/Feature/Employee/EmployeeTest.php#L340)
- [test_update_cannot_change_employment_status_manually](tests/Feature/Employee/EmployeeTest.php#L361)
- [test_contract_start_date_matches_hire_date_and_is_pending_when_hire_date_is_in_the_future](tests/Feature/Employee/EmployeeTest.php#L376)
- [test_optional_fields_stay_optional_on_create](tests/Feature/Employee/EmployeeTest.php#L393)
- [test_position_id_is_optional_on_create](tests/Feature/Employee/EmployeeTest.php#L405)
- [test_admin_can_update_employee_name](tests/Feature/Employee/EmployeeTest.php#L423)
- [test_update_ignores_client_supplied_code](tests/Feature/Employee/EmployeeTest.php#L439)
- [test_new_employee_manager_is_department_head_and_client_value_is_ignored](tests/Feature/Employee/EmployeeTest.php#L459)
- [test_department_head_reports_to_parent_department_head](tests/Feature/Employee/EmployeeTest.php#L475)
- [test_changing_department_head_updates_manager_of_all_members](tests/Feature/Employee/EmployeeTest.php#L497)
- [test_admin_can_delete_employee](tests/Feature/Employee/EmployeeTest.php#L518)
- [test_deleting_employee_removes_their_shift_assignments](tests/Feature/Employee/EmployeeTest.php#L536)
- [test_subordinate_cannot_see_superior_sensitive_fields](tests/Feature/Employee/EmployeeTest.php#L560)
- [test_employee_can_see_own_sensitive_fields](tests/Feature/Employee/EmployeeTest.php#L590)
- [test_nested_manager_field_also_hides_sensitive_fields](tests/Feature/Employee/EmployeeTest.php#L615)
- [test_employee_data_includes_current_agreed_salary_from_active_contract](tests/Feature/Employee/EmployeeTest.php#L643)
- [test_subordinate_cannot_see_superior_agreed_salary](tests/Feature/Employee/EmployeeTest.php#L661)
- [test_employee_data_includes_current_year_leave_remaining_days](tests/Feature/Employee/EmployeeTest.php#L701)
- [test_leave_balance_ignores_non_entitled_leave_types](tests/Feature/Employee/EmployeeTest.php#L725)
- [test_employee_without_leave_balance_shows_null_leave_days](tests/Feature/Employee/EmployeeTest.php#L747)
- [test_subordinate_cannot_see_superior_leave_balance](tests/Feature/Employee/EmployeeTest.php#L763)
- [test_can_upload_avatar](tests/Feature/Employee/EmployeeTest.php#L795)
- [test_uploading_new_avatar_deletes_old_one](tests/Feature/Employee/EmployeeTest.php#L815)
- [test_avatar_upload_requires_image_file](tests/Feature/Employee/EmployeeTest.php#L836)
- [test_employee_without_update_permission_can_upload_own_avatar](tests/Feature/Employee/EmployeeTest.php#L855)
- [test_employee_cannot_upload_avatar_for_someone_else](tests/Feature/Employee/EmployeeTest.php#L873)
- [test_own_avatar_rejects_svg_and_non_images](tests/Feature/Employee/EmployeeTest.php#L886)
- [test_own_avatar_returns_404_when_account_has_no_employee](tests/Feature/Employee/EmployeeTest.php#L900)
- [test_me_endpoint_requires_authentication](tests/Feature/Employee/EmployeeTest.php#L914)
- [test_authenticated_user_can_view_own_profile_via_me](tests/Feature/Employee/EmployeeTest.php#L921)
- [test_me_endpoint_returns_404_when_user_has_no_linked_employee](tests/Feature/Employee/EmployeeTest.php#L936)
- [test_employee_can_update_own_contact_info_without_employee_update](tests/Feature/Employee/EmployeeTest.php#L950)
- [test_cannot_update_own_profile_with_duplicate_phone](tests/Feature/Employee/EmployeeTest.php#L975)
- [test_updating_own_profile_requires_authentication](tests/Feature/Employee/EmployeeTest.php#L994)
- [test_employee_can_upload_own_document_without_employee_update](tests/Feature/Employee/EmployeeTest.php#L1003)
- [test_uploading_own_document_requires_linked_employee](tests/Feature/Employee/EmployeeTest.php#L1020)

</details>

<details>
<summary><code>EmployeeTransferTest.php</code> — 13 test</summary>

- **Mã nguồn:** [tests/Feature/Employee/EmployeeTransferTest.php](tests/Feature/Employee/EmployeeTransferTest.php#L1)
- [test_unauthenticated_cannot_list_transfers](tests/Feature/Employee/EmployeeTransferTest.php#L44)
- [test_user_without_update_permission_cannot_create_transfer](tests/Feature/Employee/EmployeeTransferTest.php#L53)
- [test_admin_can_transfer_employee_and_it_applies_immediately](tests/Feature/Employee/EmployeeTransferTest.php#L69)
- [test_transfer_without_new_position_keeps_current_position](tests/Feature/Employee/EmployeeTransferTest.php#L109)
- [test_cannot_transfer_to_current_department](tests/Feature/Employee/EmployeeTransferTest.php#L132)
- [test_transferring_department_head_clears_old_department_manager_and_demotes_position](tests/Feature/Employee/EmployeeTransferTest.php#L148)
- [test_transfer_sets_manager_to_destination_department_head_automatically](tests/Feature/Employee/EmployeeTransferTest.php#L181)
- [test_transfer_to_department_without_head_leaves_manager_empty](tests/Feature/Employee/EmployeeTransferTest.php#L203)
- [test_can_list_transfer_history_for_employee](tests/Feature/Employee/EmployeeTransferTest.php#L219)
- [test_can_upload_and_download_decision_file](tests/Feature/Employee/EmployeeTransferTest.php#L244)
- [test_cannot_download_decision_file_via_mismatched_employee](tests/Feature/Employee/EmployeeTransferTest.php#L269)
- [test_employee_can_view_own_transfer_history_without_employee_view](tests/Feature/Employee/EmployeeTransferTest.php#L292)
- [test_employee_can_download_own_transfer_decision_without_employee_view](tests/Feature/Employee/EmployeeTransferTest.php#L317)

</details>

</details>

---

## 5. Ca làm việc & Cài đặt

- **Điểm vào:** `/work-shifts`, `/settings`, tab "Ca làm việc" ở `/employees/:id`; API [`work-shifts.php`](routes/api/v1/work-shifts.php).
- **Danh mục file & hàm:** xem [cuối mục này](#danh-mục-file--hàm-module-5) — mỗi file và mỗi hàm public có link riêng.


**Luồng chính**

| Thao tác | Luồng |
| --- | --- |
| Đổi Ca mặc định | `Settings.vue → workShiftService.getDefault / create|update → PUT /work-shifts/{id} (is_default, giờ vào/ra, nghỉ trưa, work_days) → shift.manage → WorkShiftService::update (clearOtherDefaults, tính lại phút công chuẩn) → resyncOpenEndedWorkDays → ResourceChanged('work_shifts')` — áp dụng ngay cho mọi người đang dùng ca mặc định |
| Nhân viên mới có ca | `EmployeeService::create → EmployeeShiftAssignmentService::assignDefaultShift (WorkShift::default()->active(); work_days của ca, dự phòng T2–T6)`; lưới an toàn: `shifts:assign-missing-default` hằng ngày 00:07 |
| Gán ca riêng | `EmployeeShiftAssignmentsTab.vue → POST /employees/{id}/shift-assignments → shift.manage → assertNoConflict (chồng giờ + ngày trong tuần + hiệu lực)` |
| Xóa/tắt ca | `WorkShiftService::delete/update(is_active=false) → removeAllForWorkShift` (tránh bản gán "mồ côi") |

**Luồng demo nhanh:** HR vào `/settings` → đổi giờ vào/ra của Ca mặc định → mở `/my-profile` bằng tài khoản nhân viên: lịch ca đổi theo ngay.

**Quy tắc & bẫy**
- Ca mặc định là 1 dòng `work_shifts` có `is_default=true` (đúng 1 dòng); không tắt hoạt động/xóa được khi đang mặc định (422). Giờ nghỉ trưa trừ thật vào công (`AttendanceService::calculateActualWorkMinutes()`); ca chưa khai báo giờ nghỉ thì không trừ.
- T7/CN bị chặn chấm công nếu không nằm trong `work_days` — làm thì phải "Xin làm ngoài lịch" (module 7). "Số phút công chuẩn" ở Settings tự tính, không nhập tay.
- Chưa xử lý ca qua đêm (`end_time < start_time`). Chưa có nút "reset về ca mặc định".
- Bản gán mồ côi: `AttendanceService::listActiveAssignmentsForDate()` lọc bỏ `workShift === null`; `dailyOverview()` lọc `employee/workShift === null`.
- Ca đã xóa mềm → chấm công của ca đó tính **0 công** ở bảng lương (chủ ý).

**Liên thông:** ca quyết định lịch, giờ vào/ra, hệ số công cho module 6, 7, 10.

<a id="danh-mục-file--hàm-module-5"></a>

### Danh mục file & hàm — module 5

#### Giao diện (4 file)

- [EmployeeShiftAssignmentsTab.vue](resources/js/views/Employee/EmployeeShiftAssignmentsTab.vue#L1)
- [Settings.vue](resources/js/views/Settings/Settings.vue#L1) — route `/settings`
- [WorkShiftForm.vue](resources/js/views/WorkShift/WorkShiftForm.vue#L1)
- [WorkShifts.vue](resources/js/views/WorkShift/WorkShifts.vue#L1) — route `/work-shifts`

#### Controller (2 file, 10 hàm public)

- [EmployeeShiftAssignmentController.php](app/Http/Controllers/Api/V1/EmployeeShiftAssignmentController.php#L1) — 5 hàm: [index()](app/Http/Controllers/Api/V1/EmployeeShiftAssignmentController.php#L20) · [mine()](app/Http/Controllers/Api/V1/EmployeeShiftAssignmentController.php#L27) · [store()](app/Http/Controllers/Api/V1/EmployeeShiftAssignmentController.php#L36) · [update()](app/Http/Controllers/Api/V1/EmployeeShiftAssignmentController.php#L44) · [destroy()](app/Http/Controllers/Api/V1/EmployeeShiftAssignmentController.php#L56)
- [WorkShiftController.php](app/Http/Controllers/Api/V1/WorkShiftController.php#L1) — 5 hàm: [index()](app/Http/Controllers/Api/V1/WorkShiftController.php#L19) · [showDefault()](app/Http/Controllers/Api/V1/WorkShiftController.php#L29) · [store()](app/Http/Controllers/Api/V1/WorkShiftController.php#L34) · [update()](app/Http/Controllers/Api/V1/WorkShiftController.php#L41) · [destroy()](app/Http/Controllers/Api/V1/WorkShiftController.php#L48)

#### Service (2 file, 15 hàm public)

- [EmployeeShiftAssignmentService.php](app/Services/EmployeeShiftAssignmentService.php#L1) — 9 hàm: [listForEmployee()](app/Services/EmployeeShiftAssignmentService.php#L27) · [assignDefaultShift()](app/Services/EmployeeShiftAssignmentService.php#L38) · [resyncOpenEndedWorkDays()](app/Services/EmployeeShiftAssignmentService.php#L66) · [createOneOffAssignment()](app/Services/EmployeeShiftAssignmentService.php#L87) · [create()](app/Services/EmployeeShiftAssignmentService.php#L103) · [update()](app/Services/EmployeeShiftAssignmentService.php#L113) · [delete()](app/Services/EmployeeShiftAssignmentService.php#L120) · [removeAllForWorkShift()](app/Services/EmployeeShiftAssignmentService.php#L131) · [removeAllForEmployee()](app/Services/EmployeeShiftAssignmentService.php#L150)
- [WorkShiftService.php](app/Services/WorkShiftService.php#L1) — 6 hàm: [list()](app/Services/WorkShiftService.php#L20) · [getDefault()](app/Services/WorkShiftService.php#L28) · [create()](app/Services/WorkShiftService.php#L33) · [createCustomOneOff()](app/Services/WorkShiftService.php#L59) · [update()](app/Services/WorkShiftService.php#L116) · [delete()](app/Services/WorkShiftService.php#L165)

#### Repository (2 file)

- [EmployeeShiftAssignmentRepository.php](app/Repositories/EmployeeShiftAssignmentRepository.php#L1) — [listByEmployee()](app/Repositories/EmployeeShiftAssignmentRepository.php#L11) · [listByWorkShift()](app/Repositories/EmployeeShiftAssignmentRepository.php#L19) · [listOpenEndedByWorkShift()](app/Repositories/EmployeeShiftAssignmentRepository.php#L29) · [create()](app/Repositories/EmployeeShiftAssignmentRepository.php#L37) · [update()](app/Repositories/EmployeeShiftAssignmentRepository.php#L42) · [delete()](app/Repositories/EmployeeShiftAssignmentRepository.php#L49)
- [WorkShiftRepository.php](app/Repositories/WorkShiftRepository.php#L1) — [paginate()](app/Repositories/WorkShiftRepository.php#L10) · [find()](app/Repositories/WorkShiftRepository.php#L15) · [create()](app/Repositories/WorkShiftRepository.php#L20) · [update()](app/Repositories/WorkShiftRepository.php#L25) · [delete()](app/Repositories/WorkShiftRepository.php#L32)

#### Model (3 file)

- [EmployeeShiftAssignment.php](app/Models/EmployeeShiftAssignment.php#L1)
- [Holiday.php](app/Models/Holiday.php#L1)
- [WorkShift.php](app/Models/WorkShift.php#L1)

#### Request & Resource (4 file)

- [StoreEmployeeShiftAssignmentRequest.php](app/Http/Requests/EmployeeShiftAssignment/StoreEmployeeShiftAssignmentRequest.php#L1)
- [UpdateEmployeeShiftAssignmentRequest.php](app/Http/Requests/EmployeeShiftAssignment/UpdateEmployeeShiftAssignmentRequest.php#L1)
- [StoreWorkShiftRequest.php](app/Http/Requests/WorkShift/StoreWorkShiftRequest.php#L1)
- [UpdateWorkShiftRequest.php](app/Http/Requests/WorkShift/UpdateWorkShiftRequest.php#L1)

#### Lệnh, Event, Middleware, hạ tầng BE (1 file)

- [AssignMissingDefaultShift.php](app/Console/Commands/AssignMissingDefaultShift.php#L1) — " — không còn coi "Gán ca làm việc" (mục 14) là bước BẮT BUỘC với từng người nữa; tab đó vẫn giữ nguyên, chỉ …

#### Migration (5 file)

- [2026_01_03_000001_create_work_shifts_table.php](database/migrations/2026_01_03_000001_create_work_shifts_table.php)
- [2026_01_03_000002_create_holidays_table.php](database/migrations/2026_01_03_000002_create_holidays_table.php)
- [2026_01_03_000005_create_employee_shift_assignments_table.php](database/migrations/2026_01_03_000005_create_employee_shift_assignments_table.php)
- [2026_09_23_000001_add_default_and_break_times_to_work_shifts_table.php](database/migrations/2026_09_23_000001_add_default_and_break_times_to_work_shifts_table.php)
- [2026_09_24_000001_add_work_days_to_work_shifts_table.php](database/migrations/2026_09_24_000001_add_work_days_to_work_shifts_table.php)

#### Kiểm thử (3 file, 48 test)

- [AssignMissingDefaultShiftTest.php](tests/Feature/Console/AssignMissingDefaultShiftTest.php#L1) — 6 test
- [EmployeeShiftAssignmentTest.php](tests/Feature/Employee/EmployeeShiftAssignmentTest.php#L1) — 13 test
- [WorkShiftTest.php](tests/Feature/WorkShift/WorkShiftTest.php#L1) — 29 test

<details>
<summary><strong>Chi tiết từng màn hình Vue</strong> — 4 file</summary>

<details>
<summary><code>EmployeeShiftAssignmentsTab.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Employee/EmployeeShiftAssignmentsTab.vue](resources/js/views/Employee/EmployeeShiftAssignmentsTab.vue#L1)
- **Gọi API:**
  - `employeeService.shiftAssignments()` → `GET /employees/{x}/shift-assignments` → [EmployeeShiftAssignmentController::index()](app/Http/Controllers/Api/V1/EmployeeShiftAssignmentController.php#L20)
  - `employeeService.createShiftAssignment()` → `POST /employees/{x}/shift-assignments` → [EmployeeShiftAssignmentController::store()](app/Http/Controllers/Api/V1/EmployeeShiftAssignmentController.php#L36)
  - `employeeService.updateShiftAssignment()` → `PUT /employees/{x}/shift-assignments/{x}` → [EmployeeShiftAssignmentController::update()](app/Http/Controllers/Api/V1/EmployeeShiftAssignmentController.php#L44)
  - `employeeService.deleteShiftAssignment()` → `DELETE /employees/{x}/shift-assignments/{x}` → [EmployeeShiftAssignmentController::destroy()](app/Http/Controllers/Api/V1/EmployeeShiftAssignmentController.php#L56)
  - `workShiftService.list()` → `GET /work-shifts` → [WorkShiftController::index()](app/Http/Controllers/Api/V1/WorkShiftController.php#L19)
- **Component con:** [SearchSelect.vue](resources/js/components/common/SearchSelect.vue#L1), [InputDate.vue](resources/js/components/common/InputDate.vue#L1)

</details>

<details>
<summary><code>Settings.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Settings/Settings.vue](resources/js/views/Settings/Settings.vue#L1)
- **Route FE:** `/settings`
- **Gọi API:**
  - `workShiftService.getDefault()` → `GET /work-shifts/default` → [WorkShiftController::showDefault()](app/Http/Controllers/Api/V1/WorkShiftController.php#L29)
  - `workShiftService.create()` → `POST /work-shifts` → [WorkShiftController::store()](app/Http/Controllers/Api/V1/WorkShiftController.php#L34)
  - `workShiftService.update()` → `PUT /work-shifts/{x}` → [WorkShiftController::update()](app/Http/Controllers/Api/V1/WorkShiftController.php#L41)
- **Store dùng:** `useAuthStore`, `useResourceSyncStore`
- **Component con:** [PageHeader.vue](resources/js/components/common/PageHeader.vue#L1), [FormSection.vue](resources/js/components/common/FormSection.vue#L1)

</details>

<details>
<summary><code>WorkShiftForm.vue</code></summary>

- **Mã nguồn:** [resources/js/views/WorkShift/WorkShiftForm.vue](resources/js/views/WorkShift/WorkShiftForm.vue#L1)
- **Gọi API:**
  - `workShiftService.create()` → `POST /work-shifts` → [WorkShiftController::store()](app/Http/Controllers/Api/V1/WorkShiftController.php#L34)
  - `workShiftService.update()` → `PUT /work-shifts/{x}` → [WorkShiftController::update()](app/Http/Controllers/Api/V1/WorkShiftController.php#L41)
- **Component con:** [FormDialog.vue](resources/js/components/common/FormDialog.vue#L1), [FormSection.vue](resources/js/components/common/FormSection.vue#L1)

</details>

<details>
<summary><code>WorkShifts.vue</code></summary>

- **Mã nguồn:** [resources/js/views/WorkShift/WorkShifts.vue](resources/js/views/WorkShift/WorkShifts.vue#L1)
- **Route FE:** `/work-shifts`
- **Gọi API:**
  - `workShiftService.list()` → `GET /work-shifts` → [WorkShiftController::index()](app/Http/Controllers/Api/V1/WorkShiftController.php#L19)
  - `workShiftService.remove()` → `DELETE /work-shifts/{x}` → [WorkShiftController::destroy()](app/Http/Controllers/Api/V1/WorkShiftController.php#L48)
- **Store dùng:** `useAuthStore`, `useResourceSyncStore`
- **Component con:** [DataTable.vue](resources/js/components/common/DataTable.vue#L1), [SearchField.vue](resources/js/components/common/SearchField.vue#L1), [PageHeader.vue](resources/js/components/common/PageHeader.vue#L1), WorkShiftFormDialog.vue, [StatusChip.vue](resources/js/components/common/StatusChip.vue#L1)

</details>

</details>

<details>
<summary><strong>Chi tiết từng hàm Controller</strong> — 10 hàm</summary>

<details>
<summary><code>EmployeeShiftAssignmentController</code> — 5 hàm</summary>

- **Mã nguồn:** [app/Http/Controllers/Api/V1/EmployeeShiftAssignmentController.php](app/Http/Controllers/Api/V1/EmployeeShiftAssignmentController.php#L1)

<details>
<summary><code>public index()</code> — Danh sách</summary>

- **Mã nguồn:** [EmployeeShiftAssignmentController::index()](app/Http/Controllers/Api/V1/EmployeeShiftAssignmentController.php#L20)
- **API:** `GET /api/v1/employees/{employee}/shift-assignments` — quyền `shift.view` ([employees.php](routes/api/v1/employees.php))
- **FE service:** [employeeService.shiftAssignments()](resources/js/services/employeeService.js#L81) ← gọi từ [AttendanceHistoryPanel.vue](resources/js/views/Attendance/AttendanceHistoryPanel.vue#L1), [EmployeeShiftAssignmentsTab.vue](resources/js/views/Employee/EmployeeShiftAssignmentsTab.vue#L1)
- **Gọi xuống:** [EmployeeShiftAssignmentService::listForEmployee()](app/Services/EmployeeShiftAssignmentService.php#L27)

</details>

<details>
<summary><code>public mine()</code> — Ca làm việc của CHÍNH người đang đăng nhập — cùng lý do không gắn permission:shift.view như EmployeeController::me(), xem route riêng.</summary>

- **Mã nguồn:** [EmployeeShiftAssignmentController::mine()](app/Http/Controllers/Api/V1/EmployeeShiftAssignmentController.php#L27)
- **API:** `GET /api/v1/employees/me/shift-assignments` — quyền `auth` ([employees.php](routes/api/v1/employees.php))
- **FE service:** [employeeService.myShiftAssignments()](resources/js/services/employeeService.js#L24) ← gọi từ [AttendanceHistoryPanel.vue](resources/js/views/Attendance/AttendanceHistoryPanel.vue#L1), [MyProfileShiftsTab.vue](resources/js/views/Me/MyProfileShiftsTab.vue#L1), [useCheckIn.js](resources/js/composables/useCheckIn.js#L1)
- **Gọi xuống:** [EmployeeShiftAssignmentService::listForEmployee()](app/Services/EmployeeShiftAssignmentService.php#L27)

</details>

<details>
<summary><code>public store()</code> — Tạo mới</summary>

- **Mã nguồn:** [EmployeeShiftAssignmentController::store()](app/Http/Controllers/Api/V1/EmployeeShiftAssignmentController.php#L36)
- **API:** `POST /api/v1/employees/{employee}/shift-assignments` — quyền `shift.manage` ([employees.php](routes/api/v1/employees.php))
- **FE service:** [employeeService.createShiftAssignment()](resources/js/services/employeeService.js#L84) ← gọi từ [EmployeeShiftAssignmentsTab.vue](resources/js/views/Employee/EmployeeShiftAssignmentsTab.vue#L1)
- **Validate:** [StoreEmployeeShiftAssignmentRequest](app/Http/Requests/EmployeeShiftAssignment/StoreEmployeeShiftAssignmentRequest.php#L1)
- **Gọi xuống:** [EmployeeShiftAssignmentService::create()](app/Services/EmployeeShiftAssignmentService.php#L103)

</details>

<details>
<summary><code>public update()</code> — Cập nhật</summary>

- **Mã nguồn:** [EmployeeShiftAssignmentController::update()](app/Http/Controllers/Api/V1/EmployeeShiftAssignmentController.php#L44)
- **API:** `PUT /api/v1/employees/{employee}/shift-assignments/{shiftAssignment}` — quyền `shift.manage` ([employees.php](routes/api/v1/employees.php))
- **FE service:** [employeeService.updateShiftAssignment()](resources/js/services/employeeService.js#L87) ← gọi từ [EmployeeShiftAssignmentsTab.vue](resources/js/views/Employee/EmployeeShiftAssignmentsTab.vue#L1)
- **Validate:** [UpdateEmployeeShiftAssignmentRequest](app/Http/Requests/EmployeeShiftAssignment/UpdateEmployeeShiftAssignmentRequest.php#L1)
- **Gọi xuống:** [EmployeeShiftAssignmentService::update()](app/Services/EmployeeShiftAssignmentService.php#L113)

</details>

<details>
<summary><code>public destroy()</code> — Xóa</summary>

- **Mã nguồn:** [EmployeeShiftAssignmentController::destroy()](app/Http/Controllers/Api/V1/EmployeeShiftAssignmentController.php#L56)
- **API:** `DELETE /api/v1/employees/{employee}/shift-assignments/{shiftAssignment}` — quyền `shift.manage` ([employees.php](routes/api/v1/employees.php))
- **FE service:** [employeeService.deleteShiftAssignment()](resources/js/services/employeeService.js#L93) ← gọi từ [EmployeeShiftAssignmentsTab.vue](resources/js/views/Employee/EmployeeShiftAssignmentsTab.vue#L1)
- **Gọi xuống:** [EmployeeShiftAssignmentService::delete()](app/Services/EmployeeShiftAssignmentService.php#L120)

</details>

</details>

<details>
<summary><code>WorkShiftController</code> — 5 hàm</summary>

- **Mã nguồn:** [app/Http/Controllers/Api/V1/WorkShiftController.php](app/Http/Controllers/Api/V1/WorkShiftController.php#L1)

<details>
<summary><code>public index()</code> — Danh sách</summary>

- **Mã nguồn:** [WorkShiftController::index()](app/Http/Controllers/Api/V1/WorkShiftController.php#L19)
- **API:** `GET /api/v1/work-shifts` — quyền `shift.view,attendance.check` ([work-shifts.php](routes/api/v1/work-shifts.php))
- **FE service:** [workShiftService.list()](resources/js/services/workShiftService.js#L5) ← gọi từ [AttendanceOverview.vue](resources/js/views/Attendance/AttendanceOverview.vue#L1), [EmployeeShiftAssignmentsTab.vue](resources/js/views/Employee/EmployeeShiftAssignmentsTab.vue#L1), [WorkShifts.vue](resources/js/views/WorkShift/WorkShifts.vue#L1), [useCheckIn.js](resources/js/composables/useCheckIn.js#L1)
- **Gọi xuống:** [WorkShiftService::list()](app/Services/WorkShiftService.php#L20)

</details>

<details>
<summary><code>public showDefault()</code> — Ca mặc định — trang "Cài đặt" đọc bản ghi này để hiển thị/ sửa.</summary>

- **Mã nguồn:** [WorkShiftController::showDefault()](app/Http/Controllers/Api/V1/WorkShiftController.php#L29)
- **API:** `GET /api/v1/work-shifts/default` — quyền `shift.view` ([work-shifts.php](routes/api/v1/work-shifts.php))
- **FE service:** [workShiftService.getDefault()](resources/js/services/workShiftService.js#L10) ← gọi từ [Settings.vue](resources/js/views/Settings/Settings.vue#L1)
- **Gọi xuống:** [WorkShiftService::getDefault()](app/Services/WorkShiftService.php#L28)

</details>

<details>
<summary><code>public store()</code> — Tạo mới</summary>

- **Mã nguồn:** [WorkShiftController::store()](app/Http/Controllers/Api/V1/WorkShiftController.php#L34)
- **API:** `POST /api/v1/work-shifts` — quyền `shift.manage` ([work-shifts.php](routes/api/v1/work-shifts.php))
- **FE service:** [workShiftService.create()](resources/js/services/workShiftService.js#L13) ← gọi từ [Settings.vue](resources/js/views/Settings/Settings.vue#L1), [WorkShiftForm.vue](resources/js/views/WorkShift/WorkShiftForm.vue#L1)
- **Validate:** [StoreWorkShiftRequest](app/Http/Requests/WorkShift/StoreWorkShiftRequest.php#L1)
- **Gọi xuống:** [WorkShiftService::create()](app/Services/WorkShiftService.php#L33)

</details>

<details>
<summary><code>public update()</code> — Cập nhật</summary>

- **Mã nguồn:** [WorkShiftController::update()](app/Http/Controllers/Api/V1/WorkShiftController.php#L41)
- **API:** `PUT /api/v1/work-shifts/{workShift}` — quyền `shift.manage` ([work-shifts.php](routes/api/v1/work-shifts.php))
- **FE service:** [workShiftService.update()](resources/js/services/workShiftService.js#L16) ← gọi từ [Settings.vue](resources/js/views/Settings/Settings.vue#L1), [WorkShiftForm.vue](resources/js/views/WorkShift/WorkShiftForm.vue#L1)
- **Validate:** [UpdateWorkShiftRequest](app/Http/Requests/WorkShift/UpdateWorkShiftRequest.php#L1)
- **Gọi xuống:** [WorkShiftService::update()](app/Services/WorkShiftService.php#L116)

</details>

<details>
<summary><code>public destroy()</code> — Xóa</summary>

- **Mã nguồn:** [WorkShiftController::destroy()](app/Http/Controllers/Api/V1/WorkShiftController.php#L48)
- **API:** `DELETE /api/v1/work-shifts/{workShift}` — quyền `shift.manage` ([work-shifts.php](routes/api/v1/work-shifts.php))
- **FE service:** [workShiftService.remove()](resources/js/services/workShiftService.js#L19) ← gọi từ [WorkShifts.vue](resources/js/views/WorkShift/WorkShifts.vue#L1)
- **Gọi xuống:** [WorkShiftService::delete()](app/Services/WorkShiftService.php#L165)

</details>

</details>

</details>

<details>
<summary><strong>Chi tiết từng hàm Service</strong> — 15 hàm</summary>

<details>
<summary><code>EmployeeShiftAssignmentService</code> — 9 hàm</summary>

- **Mã nguồn:** [app/Services/EmployeeShiftAssignmentService.php](app/Services/EmployeeShiftAssignmentService.php#L1)

<details>
<summary><code>public listForEmployee()</code> — Lấy danh sách for employee</summary>

- **Mã nguồn:** [EmployeeShiftAssignmentService::listForEmployee()](app/Services/EmployeeShiftAssignmentService.php#L27)
- **Gọi xuống:** [EmployeeShiftAssignmentRepository::listByEmployee()](app/Repositories/EmployeeShiftAssignmentRepository.php#L11)
- **Được gọi bởi:** [EmployeeShiftAssignmentController::index()](app/Http/Controllers/Api/V1/EmployeeShiftAssignmentController.php#L20) · [EmployeeShiftAssignmentController::mine()](app/Http/Controllers/Api/V1/EmployeeShiftAssignmentController.php#L27)

</details>

<details>
<summary><code>public assignDefaultShift()</code> — Gán "Ca mặc định" (is_default=true) cho nhân viên VỪA tạo ).</summary>

- **Mã nguồn:** [EmployeeShiftAssignmentService::assignDefaultShift()](app/Services/EmployeeShiftAssignmentService.php#L38)
- **Gọi xuống:** [EmployeeShiftAssignmentRepository::create()](app/Repositories/EmployeeShiftAssignmentRepository.php#L37)
- **Được gọi bởi:** [EmployeeService::create()](app/Services/EmployeeService.php#L41)

</details>

<details>
<summary><code>public resyncOpenEndedWorkDays()</code> — Cài đặt đổi "ngày làm việc" của Ca mặc định — áp dụng NGAY cho mọi nhân viên đang theo ca đó, nhất quán với cách giờ vào/ra đã propagate tự động vì cùng chung 1 dòng Wor…</summary>

- **Mã nguồn:** [EmployeeShiftAssignmentService::resyncOpenEndedWorkDays()](app/Services/EmployeeShiftAssignmentService.php#L66)
- **Gọi xuống:** [EmployeeShiftAssignmentRepository::listOpenEndedByWorkShift()](app/Repositories/EmployeeShiftAssignmentRepository.php#L29) · [EmployeeShiftAssignmentRepository::update()](app/Repositories/EmployeeShiftAssignmentRepository.php#L42)
- **Được gọi bởi:** [WorkShiftService::update()](app/Services/WorkShiftService.php#L116)

</details>

<details>
<summary><code>public createOneOffAssignment()</code> — Tạo one off assignment</summary>

- **Mã nguồn:** [EmployeeShiftAssignmentService::createOneOffAssignment()](app/Services/EmployeeShiftAssignmentService.php#L87)
- **Gọi xuống:** [EmployeeShiftAssignmentRepository::create()](app/Repositories/EmployeeShiftAssignmentRepository.php#L37)
- **Được gọi bởi:** [AttendanceAdjustmentService::decide()](app/Services/AttendanceAdjustmentService.php#L280)

</details>

<details>
<summary><code>public create()</code> — 1 nhân viên được gán NHIỀU ca cùng lúc (vd Ca sáng + Ca chiều) — chỉ cấm 2 ca chồng giờ nhau.</summary>

- **Mã nguồn:** [EmployeeShiftAssignmentService::create()](app/Services/EmployeeShiftAssignmentService.php#L103)
- **Gọi xuống:** [EmployeeShiftAssignmentRepository::create()](app/Repositories/EmployeeShiftAssignmentRepository.php#L37)
- **Hiệu ứng phụ:** `DB::transaction`
- **Được gọi bởi:** [EmployeeShiftAssignmentController::store()](app/Http/Controllers/Api/V1/EmployeeShiftAssignmentController.php#L36)

</details>

<details>
<summary><code>public update()</code> — Cập nhật</summary>

- **Mã nguồn:** [EmployeeShiftAssignmentService::update()](app/Services/EmployeeShiftAssignmentService.php#L113)
- **Gọi xuống:** [EmployeeShiftAssignmentRepository::update()](app/Repositories/EmployeeShiftAssignmentRepository.php#L42)
- **Được gọi bởi:** [EmployeeShiftAssignmentController::update()](app/Http/Controllers/Api/V1/EmployeeShiftAssignmentController.php#L44)

</details>

<details>
<summary><code>public delete()</code> — Xóa</summary>

- **Mã nguồn:** [EmployeeShiftAssignmentService::delete()](app/Services/EmployeeShiftAssignmentService.php#L120)
- **Gọi xuống:** [EmployeeShiftAssignmentRepository::delete()](app/Repositories/EmployeeShiftAssignmentRepository.php#L49)
- **Được gọi bởi:** [EmployeeShiftAssignmentController::destroy()](app/Http/Controllers/Api/V1/EmployeeShiftAssignmentController.php#L56)

</details>

<details>
<summary><code>public removeAllForWorkShift()</code> — Gỡ TOÀN BỘ bản gán ca đang trỏ tới 1 Ca làm việc, khỏi MỌI nhân viên — dùng khi Ca đó bị tắt is_active (xem WorkShiftService::update()).</summary>

- **Mã nguồn:** [EmployeeShiftAssignmentService::removeAllForWorkShift()](app/Services/EmployeeShiftAssignmentService.php#L131)
- **Gọi xuống:** [EmployeeShiftAssignmentRepository::listByWorkShift()](app/Repositories/EmployeeShiftAssignmentRepository.php#L19) · [EmployeeShiftAssignmentRepository::delete()](app/Repositories/EmployeeShiftAssignmentRepository.php#L49)
- **Được gọi bởi:** [WorkShiftService::update()](app/Services/WorkShiftService.php#L116) · [WorkShiftService::delete()](app/Services/WorkShiftService.php#L165)

</details>

<details>
<summary><code>public removeAllForEmployee()</code> — Gỡ TOÀN BỘ bản gán ca của 1 nhân viên — dùng khi XÓA nhân viên (xem EmployeeService::delete()), cùng lý do/cách làm với removeAllForWorkShift() ở trên '/'history()' gọi …</summary>

- **Mã nguồn:** [EmployeeShiftAssignmentService::removeAllForEmployee()](app/Services/EmployeeShiftAssignmentService.php#L150)
- **Gọi xuống:** [EmployeeShiftAssignmentRepository::listByEmployee()](app/Repositories/EmployeeShiftAssignmentRepository.php#L11) · [EmployeeShiftAssignmentRepository::delete()](app/Repositories/EmployeeShiftAssignmentRepository.php#L49)
- **Được gọi bởi:** [EmployeeService::delete()](app/Services/EmployeeService.php#L121)

</details>

</details>

<details>
<summary><code>WorkShiftService</code> — 6 hàm</summary>

- **Mã nguồn:** [app/Services/WorkShiftService.php](app/Services/WorkShiftService.php#L1)

<details>
<summary><code>public list()</code> — Lấy danh sách</summary>

- **Mã nguồn:** [WorkShiftService::list()](app/Services/WorkShiftService.php#L20)
- **Gọi xuống:** [WorkShiftRepository::paginate()](app/Repositories/WorkShiftRepository.php#L10)
- **Được gọi bởi:** [WorkShiftController::index()](app/Http/Controllers/Api/V1/WorkShiftController.php#L19)

</details>

<details>
<summary><code>public getDefault()</code> — Ca mặc định — nhân viên mới tạo tự động được gán ca này (xem EmployeeShiftAssignmentService::assignDefaultShift()), trang "Cài đặt" (Settings.vue) đọc/sửa NGAY bản ghi n…</summary>

- **Mã nguồn:** [WorkShiftService::getDefault()](app/Services/WorkShiftService.php#L28)
- **Được gọi bởi:** [WorkShiftController::showDefault()](app/Http/Controllers/Api/V1/WorkShiftController.php#L29)

</details>

<details>
<summary><code>public create()</code> — Tạo</summary>

- **Mã nguồn:** [WorkShiftService::create()](app/Services/WorkShiftService.php#L33)
- **Gọi xuống:** [WorkShiftRepository::create()](app/Repositories/WorkShiftRepository.php#L20)
- **Hiệu ứng phụ:** `DB::transaction`
- **Được gọi bởi:** [WorkShiftController::store()](app/Http/Controllers/Api/V1/WorkShiftController.php#L34)

</details>

<details>
<summary><code>public createCustomOneOff()</code> — Tạo custom one off</summary>

- **Mã nguồn:** [WorkShiftService::createCustomOneOff()](app/Services/WorkShiftService.php#L59)

</details>

<details>
<summary><code>public update()</code> — Tắt is_active thì tự gỡ TOÀN BỘ bản gán ca đang trỏ tới Ca này khỏi mọi nhân viên — Ca ngừng hoạt động không còn hợp lệ để ai theo nữa.</summary>

- **Mã nguồn:** [WorkShiftService::update()](app/Services/WorkShiftService.php#L116)
- **Gọi xuống:** [WorkShiftRepository::update()](app/Repositories/WorkShiftRepository.php#L25) · [EmployeeShiftAssignmentService::removeAllForWorkShift()](app/Services/EmployeeShiftAssignmentService.php#L131) · [EmployeeShiftAssignmentService::resyncOpenEndedWorkDays()](app/Services/EmployeeShiftAssignmentService.php#L66)
- **Hiệu ứng phụ:** `DB::transaction`
- **Được gọi bởi:** [WorkShiftController::update()](app/Http/Controllers/Api/V1/WorkShiftController.php#L41)

</details>

<details>
<summary><code>public delete()</code> — xóa Ca làm việc TRƯỚC ĐÂY không gỡ các bản gán ca (EmployeeShiftAssignment) còn đang trỏ tới nó — để lại bản gán "mồ côi" (work_shift_id trỏ tới Ca đã xóa mềm), khiến tr…</summary>

- **Mã nguồn:** [WorkShiftService::delete()](app/Services/WorkShiftService.php#L165)
- **Gọi xuống:** [EmployeeShiftAssignmentService::removeAllForWorkShift()](app/Services/EmployeeShiftAssignmentService.php#L131) · [WorkShiftRepository::delete()](app/Repositories/WorkShiftRepository.php#L32)
- **Hiệu ứng phụ:** `DB::transaction`
- **Được gọi bởi:** [WorkShiftController::destroy()](app/Http/Controllers/Api/V1/WorkShiftController.php#L48)

</details>

</details>

</details>

<details>
<summary><strong>Danh sách test</strong> — 48 test</summary>

<details>
<summary><code>AssignMissingDefaultShiftTest.php</code> — 6 test</summary>

- **Mã nguồn:** [tests/Feature/Console/AssignMissingDefaultShiftTest.php](tests/Feature/Console/AssignMissingDefaultShiftTest.php#L1)
- [test_assigns_default_shift_to_employee_with_no_assignment](tests/Feature/Console/AssignMissingDefaultShiftTest.php#L42)
- [test_does_not_touch_employee_who_already_has_an_assignment](tests/Feature/Console/AssignMissingDefaultShiftTest.php#L54)
- [test_does_not_touch_resigned_employees](tests/Feature/Console/AssignMissingDefaultShiftTest.php#L76)
- [test_probation_employee_without_assignment_gets_default_too](tests/Feature/Console/AssignMissingDefaultShiftTest.php#L87)
- [test_reports_when_no_default_shift_is_configured](tests/Feature/Console/AssignMissingDefaultShiftTest.php#L97)
- [test_assigns_to_multiple_employees_in_one_run](tests/Feature/Console/AssignMissingDefaultShiftTest.php#L106)

</details>

<details>
<summary><code>EmployeeShiftAssignmentTest.php</code> — 13 test</summary>

- **Mã nguồn:** [tests/Feature/Employee/EmployeeShiftAssignmentTest.php](tests/Feature/Employee/EmployeeShiftAssignmentTest.php#L1)
- [test_unauthenticated_cannot_list](tests/Feature/Employee/EmployeeShiftAssignmentTest.php#L55)
- [test_user_without_manage_permission_cannot_create](tests/Feature/Employee/EmployeeShiftAssignmentTest.php#L64)
- [test_admin_can_assign_shift_to_employee](tests/Feature/Employee/EmployeeShiftAssignmentTest.php#L79)
- [test_cannot_assign_inactive_work_shift](tests/Feature/Employee/EmployeeShiftAssignmentTest.php#L100)
- [test_can_assign_two_non_overlapping_shifts_same_days](tests/Feature/Employee/EmployeeShiftAssignmentTest.php#L116)
- [test_cannot_assign_overlapping_time_on_same_days](tests/Feature/Employee/EmployeeShiftAssignmentTest.php#L142)
- [test_can_assign_overlapping_time_on_different_days](tests/Feature/Employee/EmployeeShiftAssignmentTest.php#L164)
- [test_can_update_shift_assignment](tests/Feature/Employee/EmployeeShiftAssignmentTest.php#L187)
- [test_updating_shift_assignment_does_not_conflict_with_itself](tests/Feature/Employee/EmployeeShiftAssignmentTest.php#L219)
- [test_work_days_must_be_valid_iso_weekday_values](tests/Feature/Employee/EmployeeShiftAssignmentTest.php#L246)
- [test_admin_can_delete_shift_assignment](tests/Feature/Employee/EmployeeShiftAssignmentTest.php#L261)
- [test_cannot_update_shift_assignment_via_mismatched_employee](tests/Feature/Employee/EmployeeShiftAssignmentTest.php#L283)
- [test_me_shift_assignments_returns_own_assignments_without_shift_view](tests/Feature/Employee/EmployeeShiftAssignmentTest.php#L309)

</details>

<details>
<summary><code>WorkShiftTest.php</code> — 29 test</summary>

- **Mã nguồn:** [tests/Feature/WorkShift/WorkShiftTest.php](tests/Feature/WorkShift/WorkShiftTest.php#L1)
- [test_unauthenticated_is_rejected](tests/Feature/WorkShift/WorkShiftTest.php#L48)
- [test_user_without_manage_permission_cannot_create](tests/Feature/WorkShift/WorkShiftTest.php#L55)
- [test_employee_without_shift_view_can_still_list_shifts_for_extra_shift_dropdown](tests/Feature/WorkShift/WorkShiftTest.php#L70)
- [test_user_without_any_shift_permission_cannot_list](tests/Feature/WorkShift/WorkShiftTest.php#L79)
- [test_admin_can_create_work_shift_with_auto_generated_code](tests/Feature/WorkShift/WorkShiftTest.php#L92)
- [test_client_supplied_code_is_ignored_on_create](tests/Feature/WorkShift/WorkShiftTest.php#L105)
- [test_create_requires_name_times_and_standard_work_minutes](tests/Feature/WorkShift/WorkShiftTest.php#L117)
- [test_admin_can_update_work_shift](tests/Feature/WorkShift/WorkShiftTest.php#L130)
- [test_update_ignores_client_supplied_code](tests/Feature/WorkShift/WorkShiftTest.php#L142)
- [test_deactivating_work_shift_removes_it_from_all_employees](tests/Feature/WorkShift/WorkShiftTest.php#L155)
- [test_updating_work_shift_without_deactivating_keeps_assignments](tests/Feature/WorkShift/WorkShiftTest.php#L188)
- [test_admin_can_delete_work_shift](tests/Feature/WorkShift/WorkShiftTest.php#L212)
- [test_deleting_work_shift_removes_it_from_all_employees](tests/Feature/WorkShift/WorkShiftTest.php#L231)
- [test_break_end_time_must_be_after_break_start_time](tests/Feature/WorkShift/WorkShiftTest.php#L256)
- [test_break_times_must_be_given_as_a_pair](tests/Feature/WorkShift/WorkShiftTest.php#L267)
- [test_work_shift_without_lunch_break_is_allowed](tests/Feature/WorkShift/WorkShiftTest.php#L278)
- [test_default_endpoint_returns_null_when_none_configured](tests/Feature/WorkShift/WorkShiftTest.php#L291)
- [test_creating_a_shift_as_default_makes_it_the_only_default](tests/Feature/WorkShift/WorkShiftTest.php#L300)
- [test_setting_default_via_update_unsets_previous_default](tests/Feature/WorkShift/WorkShiftTest.php#L318)
- [test_cannot_deactivate_the_default_work_shift](tests/Feature/WorkShift/WorkShiftTest.php#L332)
- [test_cannot_delete_the_default_work_shift](tests/Feature/WorkShift/WorkShiftTest.php#L348)
- [test_new_employee_is_auto_assigned_to_the_default_shift](tests/Feature/WorkShift/WorkShiftTest.php#L365)
- [test_new_employee_has_no_shift_when_no_default_configured](tests/Feature/WorkShift/WorkShiftTest.php#L386)
- [test_work_days_rejects_invalid_weekday_values](tests/Feature/WorkShift/WorkShiftTest.php#L405)
- [test_work_days_rejects_duplicate_values](tests/Feature/WorkShift/WorkShiftTest.php#L416)
- [test_new_employee_falls_back_to_monday_friday_when_default_work_days_not_configured](tests/Feature/WorkShift/WorkShiftTest.php#L429)
- [test_new_employee_uses_configured_default_work_days](tests/Feature/WorkShift/WorkShiftTest.php#L450)
- [test_updating_default_shift_work_days_resyncs_open_ended_assignments](tests/Feature/WorkShift/WorkShiftTest.php#L473)
- [test_updating_default_shift_work_days_does_not_touch_one_off_assignments](tests/Feature/WorkShift/WorkShiftTest.php#L503)

</details>

</details>

---

## 6. Chấm công

**Vai trò:** check-in/out, lịch sử, tổng hợp cho HR, duyệt chấm công, tính công.

- **Điểm vào:** `/check-in`, `/attendance-history`, `/attendance-overview`, thẻ chấm công ở `/`; API [`attendances.php`](routes/api/v1/attendances.php).
- **Danh mục file & hàm:** xem [cuối mục này](#danh-mục-file--hàm-module-6) — mỗi file và mỗi hàm public có link riêng.


**Luồng chính**

| Thao tác | Luồng |
| --- | --- |
| Chấm công vào | `CheckInDesktop/Mobile → useCheckIn.submit (tự xin vị trí) → attendanceService.checkIn → POST /attendances/check-in → attendance.check → CheckInRequest → AttendanceController::checkIn → AttendanceService::checkIn (assertWithinCheckInWindow: sớm tối đa 30 phút, chặn sau end_time; resolveActiveAssignment; ReverseGeocoder trước transaction) → [transaction] AttendanceRepository + AttendanceLogRepository (IP/thiết bị/vị trí) → AttendanceChecked (live-feed) + notifyApprovers (attendance.approve) → Attendance model phát ResourceChanged/UserDataChanged` |
| Chấm công ra | `POST /attendances/check-out → AttendanceService::checkOut → calculateActualWorkMinutes (trừ giờ nghỉ trưa) + trễ/sớm/OT (ân hạn OT 5 phút) → status = completed` |
| Duyệt chấm công | `AttendanceOverview.vue (nút Duyệt/Từ chối, cần attendance.approve) → attendanceService.decideApproval → PUT /attendances/{id}/approval → AttendanceService::decideApproval (từ chối bắt buộc lý do; được đổi quyết định) → AttendanceApprovalDecided → trang HR tự làm mới`; hàng loạt: `bulkDecideApproval → PUT /bulk-approval → lặp decideApproval, trả {succeeded, failed}` |
| Xem tổng hợp ngày (HR) | `AttendanceOverview.vue → attendanceService.dailyOverview → GET /attendances/overview?date=&department_id=&work_shift_id=&status= → attendance.view_all → AttendanceService::dailyOverview (assignments + attendances + nghỉ phép đã duyệt; 1 dòng/ca; summary theo nhân viên)` |
| Lịch sử cá nhân / theo nhân viên | `AttendanceHistoryPanel.vue → GET /attendances/history/me hoặc /history/{employee} → AttendanceService::history (date_to ≤ hôm nay; displayStatusFor; day_equivalent mỗi dòng)` |
| Nhắc quên chấm ra | `RemindMissingCheckout (mỗi phút) → bản ghi chưa chấm ra quá end_time 5 phút → NotificationService::send('attendance.checkout_reminder') → đánh dấu checkout_reminder_sent_at` |

**Luồng demo nhanh:** Nhân viên vào `/check-in` → Chấm công vào → HR (đang mở `/attendance-overview`) thấy dòng mới và chip live-feed không cần F5, nhận thông báo "chờ duyệt" → bấm Duyệt → nhân viên thấy trạng thái chuyển từ "Chờ duyệt" sang "Đang trong ca" → Chấm công ra → "Đủ công".

**Quy tắc & bẫy**
- **Công**: [`dayEquivalentFor()`](app/Services/WorkTimeCalculationService.php) dùng chung cho lịch sử và bảng lương: chưa chấm ra → 0; `late_excused` → đủ `work_coefficient`; `max(muộn, sớm) ≤ 30` phút → đủ `work_coefficient`; vượt 30 → phạt liên tục theo `actual/standard`; cuối nhân `work_coefficient` (ca sáng + chiều mỗi ca 0.5, không nhân thì làm đủ 2 ca bị 2.0). Chỉ bản ghi `approved` có công.
- **Bộ trạng thái dùng chung** (`displayStatusFor`, `composables/attendanceStatus.js`): không có lượt vào → `on_leave`/`absent`; `rejected`; `pending_approval`; đã duyệt → `full`/`late`/`in_progress` (hôm nay chưa chấm ra)/`insufficient`. Đã chấm vào thì thắng đơn nghỉ phép. Tổng hợp gộp theo nhân viên, ưu tiên `EMPLOYEE_STATUS_PRIORITY`: từ chối > chờ duyệt > thiếu công > đi muộn > đang trong ca > vắng > đủ công > nghỉ phép. Ca chưa tới giờ vẫn hiện "Vắng" (hạn chế).
- Ghi dữ liệu thiết bị: IP, thiết bị (User-Agent), tọa độ, địa chỉ; **không còn** Điểm chấm công/QR/`needs_review`. Fail-soft: từ chối vị trí/dịch vụ địa chỉ sập vẫn chấm công được. Nominatim: timeout 3s, cache 1 ngày; đổi `NOMINATIM_USER_AGENT` thành tên + email liên hệ thật khi triển khai; tọa độ được gửi sang OpenStreetMap. Test luôn `Http::preventStrayRequests()` + `fakeNominatim()`.
- WiFi "mất điểm" trên Docker Desktop do NAT (IP nguồn bị đổi thành gateway) — không phải bug.
- Duyệt được ngay từ lúc chấm vào (cần `first_check_in_at`); duyệt `correction`/`supplement` = duyệt luôn bản ghi. Chip trạng thái ở trang chấm công gộp qua `mergedAttendanceStatus()`.
- Lưới an toàn: bản ghi chấm công mất bản gán/ca (đổi ca cùng ngày, xóa ca) vẫn hiện ở `dailyOverview()` (`listAttendancesForDate()` nạp `workShift` kèm `withTrashed()`); `history()` chưa có lưới này.
- Không dựng dòng "Vắng" cho ngày tương lai ở lịch sử.

**Liên thông:** chỉ bản ghi `approved` thành công ở module 10; nghỉ phép đã duyệt che ngày thành "Nghỉ phép"; mọi lượt chấm vào báo người có `attendance.approve` (module 11).

<a id="danh-mục-file--hàm-module-6"></a>

### Danh mục file & hàm — module 6

#### Giao diện (11 file)

- [AttendanceAdjustments.vue](resources/js/views/Attendance/AttendanceAdjustments.vue#L1) — route `/attendance-adjustments`
- [AttendanceHistory.vue](resources/js/views/Attendance/AttendanceHistory.vue#L1) — route `/attendance-history`
- [AttendanceHistoryPanel.vue](resources/js/views/Attendance/AttendanceHistoryPanel.vue#L1)
- [AttendanceOverview.vue](resources/js/views/Attendance/AttendanceOverview.vue#L1) — route `/attendance-overview`
- [CheckIn.vue](resources/js/views/Attendance/CheckIn.vue#L1) — route `/check-in`
- [CheckInDesktop.vue](resources/js/views/Attendance/CheckInDesktop.vue#L1)
- [CheckInMobile.vue](resources/js/views/Attendance/CheckInMobile.vue#L1)
- [AttendanceLogList.vue](resources/js/components/attendance/AttendanceLogList.vue#L1)
- [PersonalCheckInCard.vue](resources/js/components/dashboard/PersonalCheckInCard.vue#L1)
- [attendanceStatus.js](resources/js/composables/attendanceStatus.js#L1)
- [useCheckIn.js](resources/js/composables/useCheckIn.js#L1)

#### Controller (2 file, 14 hàm public)

- [AttendanceAdjustmentController.php](app/Http/Controllers/Api/V1/AttendanceAdjustmentController.php#L1) — 4 hàm: [store()](app/Http/Controllers/Api/V1/AttendanceAdjustmentController.php#L21) · [mine()](app/Http/Controllers/Api/V1/AttendanceAdjustmentController.php#L37) · [index()](app/Http/Controllers/Api/V1/AttendanceAdjustmentController.php#L46) · [decide()](app/Http/Controllers/Api/V1/AttendanceAdjustmentController.php#L54)
- [AttendanceController.php](app/Http/Controllers/Api/V1/AttendanceController.php#L1) — 10 hàm: [checkIn()](app/Http/Controllers/Api/V1/AttendanceController.php#L24) · [checkOut()](app/Http/Controllers/Api/V1/AttendanceController.php#L36) · [today()](app/Http/Controllers/Api/V1/AttendanceController.php#L51) · [mine()](app/Http/Controllers/Api/V1/AttendanceController.php#L60) · [index()](app/Http/Controllers/Api/V1/AttendanceController.php#L69) · [decideApproval()](app/Http/Controllers/Api/V1/AttendanceController.php#L80) · [bulkDecideApproval()](app/Http/Controllers/Api/V1/AttendanceController.php#L93) · [overview()](app/Http/Controllers/Api/V1/AttendanceController.php#L111) · [historyMine()](app/Http/Controllers/Api/V1/AttendanceController.php#L126) · [history()](app/Http/Controllers/Api/V1/AttendanceController.php#L137)

#### Service (5 file, 21 hàm public)

- [DeviceInfoParser.php](app/Services/Attendance/DeviceInfoParser.php#L1) — 1 hàm: [describe()](app/Services/Attendance/DeviceInfoParser.php#L18)
- [ReverseGeocoder.php](app/Services/Attendance/ReverseGeocoder.php#L1) — 1 hàm: [addressFor()](app/Services/Attendance/ReverseGeocoder.php#L31)
- [AttendanceAdjustmentService.php](app/Services/AttendanceAdjustmentService.php#L1) — 4 hàm: [requestForEmployee()](app/Services/AttendanceAdjustmentService.php#L58) · [listForEmployee()](app/Services/AttendanceAdjustmentService.php#L263) · [list()](app/Services/AttendanceAdjustmentService.php#L268) · [decide()](app/Services/AttendanceAdjustmentService.php#L280)
- [AttendanceService.php](app/Services/AttendanceService.php#L1) — 14 hàm: [listTodayStatusForEmployee()](app/Services/AttendanceService.php#L54) · [history()](app/Services/AttendanceService.php#L70) · [sumOvertimeMinutesForEmployee()](app/Services/AttendanceService.php#L143) · [listForEmployee()](app/Services/AttendanceService.php#L266) · [list()](app/Services/AttendanceService.php#L271) · [dailyOverview()](app/Services/AttendanceService.php#L286) · [decideApproval()](app/Services/AttendanceService.php#L440) · [bulkDecideApproval()](app/Services/AttendanceService.php#L475) · [applyAdjustment()](app/Services/AttendanceService.php#L506) · [findOrCreateAttendanceForShift()](app/Services/AttendanceService.php#L541) · [checkIn()](app/Services/AttendanceService.php#L551) · [checkOut()](app/Services/AttendanceService.php#L622) · [listActiveAssignmentsForDate()](app/Services/AttendanceService.php#L718) · [resolveActiveAssignment()](app/Services/AttendanceService.php#L741)
- [WorkTimeCalculationService.php](app/Services/WorkTimeCalculationService.php#L1) — 1 hàm: [dayEquivalentFor()](app/Services/WorkTimeCalculationService.php#L38)

#### Repository (3 file)

- [AttendanceAdjustmentRepository.php](app/Repositories/AttendanceAdjustmentRepository.php#L1) — [create()](app/Repositories/AttendanceAdjustmentRepository.php#L12) · [listForEmployee()](app/Repositories/AttendanceAdjustmentRepository.php#L17) · [paginate()](app/Repositories/AttendanceAdjustmentRepository.php#L28)
- [AttendanceLogRepository.php](app/Repositories/AttendanceLogRepository.php#L1) — [create()](app/Repositories/AttendanceLogRepository.php#L9)
- [AttendanceRepository.php](app/Repositories/AttendanceRepository.php#L1) — [findForShift()](app/Repositories/AttendanceRepository.php#L14) · [findOrCreateForShift()](app/Repositories/AttendanceRepository.php#L25) · [listForEmployee()](app/Repositories/AttendanceRepository.php#L39) · [listForEmployeeInRange()](app/Repositories/AttendanceRepository.php#L51) · [listAssignmentsForDate()](app/Repositories/AttendanceRepository.php#L65) · [listAttendancesForDate()](app/Repositories/AttendanceRepository.php#L85) · [paginate()](app/Repositories/AttendanceRepository.php#L106)

#### Model (3 file)

- [Attendance.php](app/Models/Attendance.php#L1)
- [AttendanceAdjustment.php](app/Models/AttendanceAdjustment.php#L1)
- [AttendanceLog.php](app/Models/AttendanceLog.php#L1)

#### Request & Resource (6 file)

- [BulkDecideAttendanceApprovalRequest.php](app/Http/Requests/Attendance/BulkDecideAttendanceApprovalRequest.php#L1) — Duyệt/Từ chối HÀNG LOẠT — cùng luật với DecideAttendanceApprovalRequest (bản 1 dòng), chỉ thêm attendance_ids…
- [CheckInRequest.php](app/Http/Requests/Attendance/CheckInRequest.php#L1)
- [CheckOutRequest.php](app/Http/Requests/Attendance/CheckOutRequest.php#L1)
- [DecideAttendanceAdjustmentRequest.php](app/Http/Requests/Attendance/DecideAttendanceAdjustmentRequest.php#L1)
- [DecideAttendanceApprovalRequest.php](app/Http/Requests/Attendance/DecideAttendanceApprovalRequest.php#L1)
- [StoreAttendanceAdjustmentRequest.php](app/Http/Requests/Attendance/StoreAttendanceAdjustmentRequest.php#L1)

#### Lệnh, Event, Middleware, hạ tầng BE (1 file)

- [RemindMissingCheckout.php](app/Console/Commands/RemindMissingCheckout.php#L1) — — đúng edge case "Chấm công quên Check-out" (Ke-hoach-trien-khai-du-an-QLNS.docx, Ngày 42), tài liệu chỉ nêu …

#### Migration (12 file)

- [2026_01_03_000003_create_attendance_locations_table.php](database/migrations/2026_01_03_000003_create_attendance_locations_table.php)
- [2026_01_03_000006_create_attendances_table.php](database/migrations/2026_01_03_000006_create_attendances_table.php)
- [2026_01_03_000007_create_attendance_logs_table.php](database/migrations/2026_01_03_000007_create_attendance_logs_table.php)
- [2026_09_09_000003_add_soft_deletes_to_attendance_locations_table.php](database/migrations/2026_09_09_000003_add_soft_deletes_to_attendance_locations_table.php)
- [2026_09_10_000001_add_soft_deletes_to_attendances_table.php](database/migrations/2026_09_10_000001_add_soft_deletes_to_attendances_table.php)
- [2026_09_11_000001_change_attendances_unique_key_to_include_work_shift.php](database/migrations/2026_09_11_000001_change_attendances_unique_key_to_include_work_shift.php)
- [2026_09_11_000004_add_late_excused_to_attendances_table.php](database/migrations/2026_09_11_000004_add_late_excused_to_attendances_table.php)
- [2026_09_21_000001_add_overtime_approved_to_attendances_table.php](database/migrations/2026_09_21_000001_add_overtime_approved_to_attendances_table.php)
- [2026_09_21_000002_add_device_info_to_attendance_logs_table.php](database/migrations/2026_09_21_000002_add_device_info_to_attendance_logs_table.php)
- [2026_09_21_000003_add_approval_to_attendances_table.php](database/migrations/2026_09_21_000003_add_approval_to_attendances_table.php)
- [2026_09_25_000001_add_checkout_reminder_sent_at_to_attendances_table.php](database/migrations/2026_09_25_000001_add_checkout_reminder_sent_at_to_attendances_table.php)
- [2026_09_30_000001_remove_attendance_locations.php](database/migrations/2026_09_30_000001_remove_attendance_locations.php)

#### Kiểm thử (11 file, 137 test)

- [AttendanceApprovalTest.php](tests/Feature/Attendance/AttendanceApprovalTest.php#L1) — 17 test
- [AttendanceBulkApprovalTest.php](tests/Feature/Attendance/AttendanceBulkApprovalTest.php#L1) — 6 test
- [AttendanceHistoryTest.php](tests/Feature/Attendance/AttendanceHistoryTest.php#L1) — 14 test
- [AttendanceLiveFeedTest.php](tests/Feature/Attendance/AttendanceLiveFeedTest.php#L1) — 4 test
- [AttendanceOverviewTest.php](tests/Feature/Attendance/AttendanceOverviewTest.php#L1) — 18 test
- [AttendanceTest.php](tests/Feature/Attendance/AttendanceTest.php#L1) — 26 test
- [RemindMissingCheckoutTest.php](tests/Feature/Console/RemindMissingCheckoutTest.php#L1) — 4 test
- [DeviceInfoParserTest.php](tests/Unit/Services/Attendance/DeviceInfoParserTest.php#L1) — 3 test
- [ReverseGeocoderTest.php](tests/Unit/Services/Attendance/ReverseGeocoderTest.php#L1) — 6 test
- [AttendanceServiceCalculationTest.php](tests/Unit/Services/AttendanceServiceCalculationTest.php#L1) — 21 test
- [WorkTimeCalculationServiceTest.php](tests/Unit/Services/WorkTimeCalculationServiceTest.php#L1) — 18 test

<details>
<summary><strong>Chi tiết từng màn hình Vue</strong> — 11 file</summary>

<details>
<summary><code>AttendanceAdjustments.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Attendance/AttendanceAdjustments.vue](resources/js/views/Attendance/AttendanceAdjustments.vue#L1)
- **Route FE:** `/attendance-adjustments`
- **Gọi API:**
  - `attendanceService.listAdjustments()` → `GET /attendances/adjustments` → [AttendanceAdjustmentController::index()](app/Http/Controllers/Api/V1/AttendanceAdjustmentController.php#L46)
  - `attendanceService.decideAdjustment()` → `PUT /attendances/adjustments/{x}` → [AttendanceAdjustmentController::decide()](app/Http/Controllers/Api/V1/AttendanceAdjustmentController.php#L54)
- **Store dùng:** `useResourceSyncStore`
- **Component con:** [DataTable.vue](resources/js/components/common/DataTable.vue#L1), [PageHeader.vue](resources/js/components/common/PageHeader.vue#L1), [StatusChip.vue](resources/js/components/common/StatusChip.vue#L1)

</details>

<details>
<summary><code>AttendanceHistory.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Attendance/AttendanceHistory.vue](resources/js/views/Attendance/AttendanceHistory.vue#L1)
- **Route FE:** `/attendance-history`
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).
- **Component con:** [PageHeader.vue](resources/js/components/common/PageHeader.vue#L1), [AttendanceHistoryPanel.vue](resources/js/views/Attendance/AttendanceHistoryPanel.vue#L1)

</details>

<details>
<summary><code>AttendanceHistoryPanel.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Attendance/AttendanceHistoryPanel.vue](resources/js/views/Attendance/AttendanceHistoryPanel.vue#L1)
- **Gọi API:**
  - `attendanceService.historyMine()` → `GET /attendances/history/me` → [AttendanceController::historyMine()](app/Http/Controllers/Api/V1/AttendanceController.php#L126)
  - `attendanceService.history()` → `GET /attendances/history/{x}` → [AttendanceController::history()](app/Http/Controllers/Api/V1/AttendanceController.php#L137)
  - `employeeService.myShiftAssignments()` → `GET /employees/me/shift-assignments` → [EmployeeShiftAssignmentController::mine()](app/Http/Controllers/Api/V1/EmployeeShiftAssignmentController.php#L27)
  - `employeeService.shiftAssignments()` → `GET /employees/{x}/shift-assignments` → [EmployeeShiftAssignmentController::index()](app/Http/Controllers/Api/V1/EmployeeShiftAssignmentController.php#L20)
- **Component con:** [StatCards.vue](resources/js/components/dashboard/StatCards.vue#L1), [StatusChip.vue](resources/js/components/common/StatusChip.vue#L1), [SearchSelect.vue](resources/js/components/common/SearchSelect.vue#L1), [InputDate.vue](resources/js/components/common/InputDate.vue#L1), [AttendanceLogList.vue](resources/js/components/attendance/AttendanceLogList.vue#L1)

</details>

<details>
<summary><code>AttendanceOverview.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Attendance/AttendanceOverview.vue](resources/js/views/Attendance/AttendanceOverview.vue#L1)
- **Route FE:** `/attendance-overview`
- **Gọi API:**
  - `attendanceService.dailyOverview()` → `GET /attendances/overview` → [AttendanceController::overview()](app/Http/Controllers/Api/V1/AttendanceController.php#L111)
  - `attendanceService.decideApproval()` → `PUT /attendances/{x}/approval` → [AttendanceController::decideApproval()](app/Http/Controllers/Api/V1/AttendanceController.php#L80)
  - `attendanceService.bulkDecideApproval()` → `PUT /attendances/bulk-approval` → [AttendanceController::bulkDecideApproval()](app/Http/Controllers/Api/V1/AttendanceController.php#L93)
  - `workShiftService.list()` → `GET /work-shifts` → [WorkShiftController::index()](app/Http/Controllers/Api/V1/WorkShiftController.php#L19)
- **Store dùng:** `useAttendanceFeedStore`, `useAuthStore`, `useDepartmentStore`
- **Component con:** [DataTable.vue](resources/js/components/common/DataTable.vue#L1), [PageHeader.vue](resources/js/components/common/PageHeader.vue#L1), [StatusChip.vue](resources/js/components/common/StatusChip.vue#L1), [StatCards.vue](resources/js/components/dashboard/StatCards.vue#L1), [SearchSelect.vue](resources/js/components/common/SearchSelect.vue#L1), [AttendanceLogList.vue](resources/js/components/attendance/AttendanceLogList.vue#L1)

</details>

<details>
<summary><code>CheckIn.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Attendance/CheckIn.vue](resources/js/views/Attendance/CheckIn.vue#L1)
- **Route FE:** `/check-in`
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).
- **Component con:** [CheckInDesktop.vue](resources/js/views/Attendance/CheckInDesktop.vue#L1), [CheckInMobile.vue](resources/js/views/Attendance/CheckInMobile.vue#L1)

</details>

<details>
<summary><code>CheckInDesktop.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Attendance/CheckInDesktop.vue](resources/js/views/Attendance/CheckInDesktop.vue#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).
- **Component con:** [PageHeader.vue](resources/js/components/common/PageHeader.vue#L1), [StatusChip.vue](resources/js/components/common/StatusChip.vue#L1), [SearchSelect.vue](resources/js/components/common/SearchSelect.vue#L1), [InputDate.vue](resources/js/components/common/InputDate.vue#L1)

</details>

<details>
<summary><code>CheckInMobile.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Attendance/CheckInMobile.vue](resources/js/views/Attendance/CheckInMobile.vue#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).
- **Component con:** [PageHeader.vue](resources/js/components/common/PageHeader.vue#L1), [StatusChip.vue](resources/js/components/common/StatusChip.vue#L1), [SearchSelect.vue](resources/js/components/common/SearchSelect.vue#L1), [InputDate.vue](resources/js/components/common/InputDate.vue#L1)

</details>

<details>
<summary><code>AttendanceLogList.vue</code></summary>

- **Mã nguồn:** [resources/js/components/attendance/AttendanceLogList.vue](resources/js/components/attendance/AttendanceLogList.vue#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).

</details>

<details>
<summary><code>PersonalCheckInCard.vue</code></summary>

- **Mã nguồn:** [resources/js/components/dashboard/PersonalCheckInCard.vue](resources/js/components/dashboard/PersonalCheckInCard.vue#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).

</details>

<details>
<summary><code>attendanceStatus.js</code></summary>

- **Mã nguồn:** [resources/js/composables/attendanceStatus.js](resources/js/composables/attendanceStatus.js#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).

</details>

<details>
<summary><code>useCheckIn.js</code></summary>

- **Mã nguồn:** [resources/js/composables/useCheckIn.js](resources/js/composables/useCheckIn.js#L1)
- **Gọi API:**
  - `attendanceService.checkIn()` → `POST /attendances/check-in` → [AttendanceController::checkIn()](app/Http/Controllers/Api/V1/AttendanceController.php#L24)
  - `attendanceService.checkOut()` → `POST /attendances/check-out` → [AttendanceController::checkOut()](app/Http/Controllers/Api/V1/AttendanceController.php#L36)
  - `attendanceService.today()` → `GET /attendances/today` → [AttendanceController::today()](app/Http/Controllers/Api/V1/AttendanceController.php#L51)
  - `attendanceService.myHistory()` → `GET /attendances/me` → [AttendanceController::mine()](app/Http/Controllers/Api/V1/AttendanceController.php#L60)
  - `attendanceService.requestAdjustment()` → `POST /attendances/adjustments` → [AttendanceAdjustmentController::store()](app/Http/Controllers/Api/V1/AttendanceAdjustmentController.php#L21)
  - `attendanceService.myAdjustments()` → `GET /attendances/adjustments/me` → [AttendanceAdjustmentController::mine()](app/Http/Controllers/Api/V1/AttendanceAdjustmentController.php#L37)
  - `employeeService.myShiftAssignments()` → `GET /employees/me/shift-assignments` → [EmployeeShiftAssignmentController::mine()](app/Http/Controllers/Api/V1/EmployeeShiftAssignmentController.php#L27)
  - `workShiftService.list()` → `GET /work-shifts` → [WorkShiftController::index()](app/Http/Controllers/Api/V1/WorkShiftController.php#L19)

</details>

</details>

<details>
<summary><strong>Chi tiết từng hàm Controller</strong> — 14 hàm</summary>

<details>
<summary><code>AttendanceAdjustmentController</code> — 4 hàm</summary>

- **Mã nguồn:** [app/Http/Controllers/Api/V1/AttendanceAdjustmentController.php](app/Http/Controllers/Api/V1/AttendanceAdjustmentController.php#L1)

<details>
<summary><code>public store()</code> — Xin điều chỉnh công CHO CHÍNH MÌNH — không nhận employee_id từ client, luôn resolve theo token đăng nhập (cùng khuôn với checkIn()/checkOut()).</summary>

- **Mã nguồn:** [AttendanceAdjustmentController::store()](app/Http/Controllers/Api/V1/AttendanceAdjustmentController.php#L21)
- **API:** `POST /api/v1/attendances/adjustments` — quyền `attendance.check` ([attendances.php](routes/api/v1/attendances.php))
- **FE service:** [attendanceService.requestAdjustment()](resources/js/services/attendanceService.js#L23) ← gọi từ [useCheckIn.js](resources/js/composables/useCheckIn.js#L1)
- **Validate:** [StoreAttendanceAdjustmentRequest](app/Http/Requests/Attendance/StoreAttendanceAdjustmentRequest.php#L1)
- **Gọi xuống:** [AttendanceAdjustmentService::requestForEmployee()](app/Services/AttendanceAdjustmentService.php#L58)

</details>

<details>
<summary><code>public mine()</code> — Dữ liệu của chính người đăng nhập</summary>

- **Mã nguồn:** [AttendanceAdjustmentController::mine()](app/Http/Controllers/Api/V1/AttendanceAdjustmentController.php#L37)
- **API:** `GET /api/v1/attendances/adjustments/me` — quyền `attendance.view_own` ([attendances.php](routes/api/v1/attendances.php))
- **FE service:** [attendanceService.myAdjustments()](resources/js/services/attendanceService.js#L26) ← gọi từ [useCheckIn.js](resources/js/composables/useCheckIn.js#L1)
- **Gọi xuống:** [AttendanceAdjustmentService::listForEmployee()](app/Services/AttendanceAdjustmentService.php#L263)

</details>

<details>
<summary><code>public index()</code> — Danh sách</summary>

- **Mã nguồn:** [AttendanceAdjustmentController::index()](app/Http/Controllers/Api/V1/AttendanceAdjustmentController.php#L46)
- **API:** `GET /api/v1/attendances/adjustments` — quyền `attendance.adjust` ([attendances.php](routes/api/v1/attendances.php))
- **FE service:** [attendanceService.listAdjustments()](resources/js/services/attendanceService.js#L29) ← gọi từ [AttendanceAdjustments.vue](resources/js/views/Attendance/AttendanceAdjustments.vue#L1)
- **Gọi xuống:** [AttendanceAdjustmentService::list()](app/Services/AttendanceAdjustmentService.php#L268)

</details>

<details>
<summary><code>public decide()</code> — Duyệt/từ chối</summary>

- **Mã nguồn:** [AttendanceAdjustmentController::decide()](app/Http/Controllers/Api/V1/AttendanceAdjustmentController.php#L54)
- **API:** `PUT /api/v1/attendances/adjustments/{adjustment}` — quyền `attendance.adjust` ([attendances.php](routes/api/v1/attendances.php))
- **FE service:** [attendanceService.decideAdjustment()](resources/js/services/attendanceService.js#L32) ← gọi từ [AttendanceAdjustments.vue](resources/js/views/Attendance/AttendanceAdjustments.vue#L1)
- **Validate:** [DecideAttendanceAdjustmentRequest](app/Http/Requests/Attendance/DecideAttendanceAdjustmentRequest.php#L1)
- **Gọi xuống:** [AttendanceAdjustmentService::decide()](app/Services/AttendanceAdjustmentService.php#L280)

</details>

</details>

<details>
<summary><code>AttendanceController</code> — 10 hàm</summary>

- **Mã nguồn:** [app/Http/Controllers/Api/V1/AttendanceController.php](app/Http/Controllers/Api/V1/AttendanceController.php#L1)

<details>
<summary><code>public checkIn()</code> — Chấm công luôn là chấm cho CHÍNH người gọi API — không nhận employee_id từ client, tránh 1 tài khoản chấm công hộ người khác.</summary>

- **Mã nguồn:** [AttendanceController::checkIn()](app/Http/Controllers/Api/V1/AttendanceController.php#L24)
- **API:** `POST /api/v1/attendances/check-in` — quyền `attendance.check` ([attendances.php](routes/api/v1/attendances.php))
- **FE service:** [attendanceService.checkIn()](resources/js/services/attendanceService.js#L5) ← gọi từ [useCheckIn.js](resources/js/composables/useCheckIn.js#L1)
- **Validate:** [CheckInRequest](app/Http/Requests/Attendance/CheckInRequest.php#L1)
- **Gọi xuống:** [AttendanceService::checkIn()](app/Services/AttendanceService.php#L551)

</details>

<details>
<summary><code>public checkOut()</code> — Chấm công ra</summary>

- **Mã nguồn:** [AttendanceController::checkOut()](app/Http/Controllers/Api/V1/AttendanceController.php#L36)
- **API:** `POST /api/v1/attendances/check-out` — quyền `attendance.check` ([attendances.php](routes/api/v1/attendances.php))
- **FE service:** [attendanceService.checkOut()](resources/js/services/attendanceService.js#L8) ← gọi từ [useCheckIn.js](resources/js/composables/useCheckIn.js#L1)
- **Validate:** [CheckOutRequest](app/Http/Requests/Attendance/CheckOutRequest.php#L1)
- **Gọi xuống:** [AttendanceService::checkOut()](app/Services/AttendanceService.php#L622)

</details>

<details>
<summary><code>public today()</code> — Trạng thái chấm công hôm nay theo TỪNG CA đang gán (mục 14, có thể nhiều ca/ngày) — mảng [{work_shift, attendance}], không còn 1 bản ghi duy nhất cho cả ngày (xem Attend…</summary>

- **Mã nguồn:** [AttendanceController::today()](app/Http/Controllers/Api/V1/AttendanceController.php#L51)
- **API:** `GET /api/v1/attendances/today` — quyền `attendance.check` ([attendances.php](routes/api/v1/attendances.php))
- **FE service:** [attendanceService.today()](resources/js/services/attendanceService.js#L11) ← gọi từ [useCheckIn.js](resources/js/composables/useCheckIn.js#L1)
- **Gọi xuống:** [AttendanceService::listTodayStatusForEmployee()](app/Services/AttendanceService.php#L54)

</details>

<details>
<summary><code>public mine()</code> — Dữ liệu của chính người đăng nhập</summary>

- **Mã nguồn:** [AttendanceController::mine()](app/Http/Controllers/Api/V1/AttendanceController.php#L60)
- **API:** `GET /api/v1/attendances/me` — quyền `attendance.view_own` ([attendances.php](routes/api/v1/attendances.php))
- **FE service:** [attendanceService.myHistory()](resources/js/services/attendanceService.js#L14) ← gọi từ [useCheckIn.js](resources/js/composables/useCheckIn.js#L1)
- **Gọi xuống:** [AttendanceService::listForEmployee()](app/Services/AttendanceService.php#L266)

</details>

<details>
<summary><code>public index()</code> — Danh sách</summary>

- **Mã nguồn:** [AttendanceController::index()](app/Http/Controllers/Api/V1/AttendanceController.php#L69)
- **API:** `GET /api/v1/attendances` — quyền `attendance.view_all` ([attendances.php](routes/api/v1/attendances.php))
- **Gọi xuống:** [AttendanceService::list()](app/Services/AttendanceService.php#L271)

</details>

<details>
<summary><code>public decideApproval()</code> — HR duyệt / từ chối 1 bản ghi chấm công (quyền attendance.approve, xem route) — chưa duyệt thì không tính công/lương.</summary>

- **Mã nguồn:** [AttendanceController::decideApproval()](app/Http/Controllers/Api/V1/AttendanceController.php#L80)
- **API:** `PUT /api/v1/attendances/{attendance}/approval` — quyền `attendance.approve` ([attendances.php](routes/api/v1/attendances.php))
- **FE service:** [attendanceService.decideApproval()](resources/js/services/attendanceService.js#L40) ← gọi từ [AttendanceOverview.vue](resources/js/views/Attendance/AttendanceOverview.vue#L1)
- **Validate:** [DecideAttendanceApprovalRequest](app/Http/Requests/Attendance/DecideAttendanceApprovalRequest.php#L1)
- **Gọi xuống:** [AttendanceService::decideApproval()](app/Services/AttendanceService.php#L440)

</details>

<details>
<summary><code>public bulkDecideApproval()</code> — bulk decide approval</summary>

- **Mã nguồn:** [AttendanceController::bulkDecideApproval()](app/Http/Controllers/Api/V1/AttendanceController.php#L93)
- **API:** `PUT /api/v1/attendances/bulk-approval` — quyền `attendance.approve` ([attendances.php](routes/api/v1/attendances.php))
- **FE service:** [attendanceService.bulkDecideApproval()](resources/js/services/attendanceService.js#L44) ← gọi từ [AttendanceOverview.vue](resources/js/views/Attendance/AttendanceOverview.vue#L1)
- **Validate:** [BulkDecideAttendanceApprovalRequest](app/Http/Requests/Attendance/BulkDecideAttendanceApprovalRequest.php#L1)
- **Gọi xuống:** [AttendanceService::bulkDecideApproval()](app/Services/AttendanceService.php#L475)

</details>

<details>
<summary><code>public overview()</code> — Tổng hợp</summary>

- **Mã nguồn:** [AttendanceController::overview()](app/Http/Controllers/Api/V1/AttendanceController.php#L111)
- **API:** `GET /api/v1/attendances/overview` — quyền `attendance.view_all` ([attendances.php](routes/api/v1/attendances.php))
- **FE service:** [attendanceService.dailyOverview()](resources/js/services/attendanceService.js#L37) ← gọi từ [AttendanceOverview.vue](resources/js/views/Attendance/AttendanceOverview.vue#L1)
- **Gọi xuống:** [AttendanceService::dailyOverview()](app/Services/AttendanceService.php#L286)

</details>

<details>
<summary><code>public historyMine()</code> — Báo cáo "Lịch sử chấm công" (mục 18) CHÍNH MÌNH — chỉ hiển thị, không sửa được gì.</summary>

- **Mã nguồn:** [AttendanceController::historyMine()](app/Http/Controllers/Api/V1/AttendanceController.php#L126)
- **API:** `GET /api/v1/attendances/history/me` — quyền `attendance.view_own` ([attendances.php](routes/api/v1/attendances.php))
- **FE service:** [attendanceService.historyMine()](resources/js/services/attendanceService.js#L17) ← gọi từ [AttendanceHistoryPanel.vue](resources/js/views/Attendance/AttendanceHistoryPanel.vue#L1)

</details>

<details>
<summary><code>public history()</code> — Cùng báo cáo nhưng cho 1 nhân viên BẤT KỲ — phía Admin/quản lý, quyền attendance.view_all (xem route).</summary>

- **Mã nguồn:** [AttendanceController::history()](app/Http/Controllers/Api/V1/AttendanceController.php#L137)
- **API:** `GET /api/v1/attendances/history/{employee}` — quyền `attendance.view_all` ([attendances.php](routes/api/v1/attendances.php))
- **FE service:** [attendanceService.history()](resources/js/services/attendanceService.js#L20) ← gọi từ [AttendanceHistoryPanel.vue](resources/js/views/Attendance/AttendanceHistoryPanel.vue#L1)

</details>

</details>

</details>

<details>
<summary><strong>Chi tiết từng hàm Service</strong> — 21 hàm</summary>

<details>
<summary><code>DeviceInfoParser</code> — 1 hàm — Đọc chuỗi User-Agent của trình duyệt thành tên thiết bị dễ đọc để HR nhìn vào biết nhân v…</summary>

- **Mã nguồn:** [app/Services/Attendance/DeviceInfoParser.php](app/Services/Attendance/DeviceInfoParser.php#L1)

<details>
<summary><code>public describe()</code> — describe</summary>

- **Mã nguồn:** [DeviceInfoParser::describe()](app/Services/Attendance/DeviceInfoParser.php#L18)
- **Được gọi bởi:** [AttendanceService::captureDeviceContext()](app/Services/AttendanceService.php#L679)

</details>

</details>

<details>
<summary><code>ReverseGeocoder</code> — 1 hàm — Đổi tọa độ GPS lúc chấm công thành ĐỊA CHỈ CHỮ qua dịch vụ Nominatim của OpenStreetMap — …</summary>

- **Mã nguồn:** [app/Services/Attendance/ReverseGeocoder.php](app/Services/Attendance/ReverseGeocoder.php#L1)

<details>
<summary><code>public addressFor()</code> — address for</summary>

- **Mã nguồn:** [ReverseGeocoder::addressFor()](app/Services/Attendance/ReverseGeocoder.php#L31)
- **Được gọi bởi:** [AttendanceService::captureDeviceContext()](app/Services/AttendanceService.php#L679)

</details>

</details>

<details>
<summary><code>AttendanceAdjustmentService</code> — 4 hàm</summary>

- **Mã nguồn:** [app/Services/AttendanceAdjustmentService.php](app/Services/AttendanceAdjustmentService.php#L1)

<details>
<summary><code>public requestForEmployee()</code> — 5 loại: 'correction' (sửa 1 bản ghi attendances ĐÃ TỒN TẠI — hành vi cũ), 'supplement' (bổ sung chấm công cho 1 ca+ngày CHƯA từng có bản ghi nào, vd nhân viên quên chấm …</summary>

- **Mã nguồn:** [AttendanceAdjustmentService::requestForEmployee()](app/Services/AttendanceAdjustmentService.php#L58)
- **Gọi xuống:** [AttendanceService::resolveActiveAssignment()](app/Services/AttendanceService.php#L741) · [AttendanceAdjustmentRepository::create()](app/Repositories/AttendanceAdjustmentRepository.php#L12)
- **Được gọi bởi:** [AttendanceAdjustmentController::store()](app/Http/Controllers/Api/V1/AttendanceAdjustmentController.php#L21)

</details>

<details>
<summary><code>public listForEmployee()</code> — Lấy danh sách for employee</summary>

- **Mã nguồn:** [AttendanceAdjustmentService::listForEmployee()](app/Services/AttendanceAdjustmentService.php#L263)
- **Gọi xuống:** [AttendanceAdjustmentRepository::listForEmployee()](app/Repositories/AttendanceAdjustmentRepository.php#L17)
- **Được gọi bởi:** [AttendanceAdjustmentController::mine()](app/Http/Controllers/Api/V1/AttendanceAdjustmentController.php#L37)

</details>

<details>
<summary><code>public list()</code> — Lấy danh sách</summary>

- **Mã nguồn:** [AttendanceAdjustmentService::list()](app/Services/AttendanceAdjustmentService.php#L268)
- **Gọi xuống:** [AttendanceAdjustmentRepository::paginate()](app/Repositories/AttendanceAdjustmentRepository.php#L28)
- **Được gọi bởi:** [AttendanceAdjustmentController::index()](app/Http/Controllers/Api/V1/AttendanceAdjustmentController.php#L46)

</details>

<details>
<summary><code>public decide()</code> — Duyệt/từ chối</summary>

- **Mã nguồn:** [AttendanceAdjustmentService::decide()](app/Services/AttendanceAdjustmentService.php#L280)
- **Gọi xuống:** [EmployeeShiftAssignmentService::createOneOffAssignment()](app/Services/EmployeeShiftAssignmentService.php#L87) · [AttendanceService::findOrCreateAttendanceForShift()](app/Services/AttendanceService.php#L541) · [AttendanceService::applyAdjustment()](app/Services/AttendanceService.php#L506)
- **Hiệu ứng phụ:** `DB::transaction`
- **Được gọi bởi:** [AttendanceAdjustmentController::decide()](app/Http/Controllers/Api/V1/AttendanceAdjustmentController.php#L54)

</details>

</details>

<details>
<summary><code>AttendanceService</code> — 14 hàm</summary>

- **Mã nguồn:** [app/Services/AttendanceService.php](app/Services/AttendanceService.php#L1)

<details>
<summary><code>public listTodayStatusForEmployee()</code> — Trạng thái chấm công hôm nay theo TỪNG CA đang được gán (mục 14, có thể nhiều ca cùng ngày) — mỗi phần tử là 1 ca kèm bản ghi attendances của ca đó nếu đã chấm công (nul…</summary>

- **Mã nguồn:** [AttendanceService::listTodayStatusForEmployee()](app/Services/AttendanceService.php#L54)
- **Gọi xuống:** [AttendanceRepository::findForShift()](app/Repositories/AttendanceRepository.php#L14)
- **Được gọi bởi:** [AttendanceController::today()](app/Http/Controllers/Api/V1/AttendanceController.php#L51)

</details>

<details>
<summary><code>public history()</code> — Báo cáo "Lịch sử chấm công" theo khoảng ngày (mục 18) — đối chiếu TOÀN BỘ ca đang được gán (employee_shift_assignments, không phải chỉ những gì đã có trong attendances) …</summary>

- **Mã nguồn:** [AttendanceService::history()](app/Services/AttendanceService.php#L70)
- **Gọi xuống:** [WorkTimeCalculationService::dayEquivalentFor()](app/Services/WorkTimeCalculationService.php#L38)
- **Được gọi bởi:** [AttendanceController::buildHistory()](app/Http/Controllers/Api/V1/AttendanceController.php#L142) · [DashboardService::monthlyWorkAndRecentAttendance()](app/Services/DashboardService.php#L355)

</details>

<details>
<summary><code>public sumOvertimeMinutesForEmployee()</code> — Tổng phút OT đã CHECK-OUT trong khoảng ngày — dùng riêng cho PayrollService, tách khỏi summarizeHistory() (private, gắn với luồng hiển thị lịch sử chấm công) để không ph…</summary>

- **Mã nguồn:** [AttendanceService::sumOvertimeMinutesForEmployee()](app/Services/AttendanceService.php#L143)

</details>

<details>
<summary><code>public listForEmployee()</code> — Lấy danh sách for employee</summary>

- **Mã nguồn:** [AttendanceService::listForEmployee()](app/Services/AttendanceService.php#L266)
- **Gọi xuống:** [AttendanceRepository::listForEmployee()](app/Repositories/AttendanceRepository.php#L39)
- **Được gọi bởi:** [AttendanceController::mine()](app/Http/Controllers/Api/V1/AttendanceController.php#L60)

</details>

<details>
<summary><code>public list()</code> — Lấy danh sách</summary>

- **Mã nguồn:** [AttendanceService::list()](app/Services/AttendanceService.php#L271)
- **Gọi xuống:** [AttendanceRepository::paginate()](app/Repositories/AttendanceRepository.php#L106)
- **Được gọi bởi:** [AttendanceController::index()](app/Http/Controllers/Api/V1/AttendanceController.php#L69)

</details>

<details>
<summary><code>public dailyOverview()</code> — daily overview</summary>

- **Mã nguồn:** [AttendanceService::dailyOverview()](app/Services/AttendanceService.php#L286)
- **Gọi xuống:** [AttendanceRepository::listAttendancesForDate()](app/Repositories/AttendanceRepository.php#L85)
- **Được gọi bởi:** [AttendanceController::overview()](app/Http/Controllers/Api/V1/AttendanceController.php#L111) · [DashboardService::companyWide()](app/Services/DashboardService.php#L63)

</details>

<details>
<summary><code>public decideApproval()</code> — HR duyệt / từ chối 1 bản ghi chấm công .</summary>

- **Mã nguồn:** [AttendanceService::decideApproval()](app/Services/AttendanceService.php#L440)
- **Hiệu ứng phụ:** [AttendanceApprovalDecided](app/Events/AttendanceApprovalDecided.php#L1)
- **Được gọi bởi:** [AttendanceController::decideApproval()](app/Http/Controllers/Api/V1/AttendanceController.php#L80)

</details>

<details>
<summary><code>public bulkDecideApproval()</code> — Duyệt/Từ chối HÀNG LOẠT — lặp lại decideApproval() cho TỪNG bản ghi thay vì viết logic riêng, để không lệch quy tắc (đã chấm công vào chưa, đã duyệt rồi chưa...).</summary>

- **Mã nguồn:** [AttendanceService::bulkDecideApproval()](app/Services/AttendanceService.php#L475)
- **Được gọi bởi:** [AttendanceController::bulkDecideApproval()](app/Http/Controllers/Api/V1/AttendanceController.php#L93)

</details>

<details>
<summary><code>public applyAdjustment()</code> — Áp dụng adjustment</summary>

- **Mã nguồn:** [AttendanceService::applyAdjustment()](app/Services/AttendanceService.php#L506)
- **Được gọi bởi:** [AttendanceAdjustmentService::decide()](app/Services/AttendanceAdjustmentService.php#L280)

</details>

<details>
<summary><code>public findOrCreateAttendanceForShift()</code> — Dùng cho yêu cầu "Bổ sung chấm công" (mục 17, type=supplement) khi được duyệt — tạo (hoặc tìm nếu đã lỡ có) bản ghi attendances cho đúng ca+ngày đang xin bổ sung, để app…</summary>

- **Mã nguồn:** [AttendanceService::findOrCreateAttendanceForShift()](app/Services/AttendanceService.php#L541)
- **Gọi xuống:** [AttendanceRepository::findOrCreateForShift()](app/Repositories/AttendanceRepository.php#L25)
- **Được gọi bởi:** [AttendanceAdjustmentService::decide()](app/Services/AttendanceAdjustmentService.php#L280)

</details>

<details>
<summary><code>public checkIn()</code> — Chấm công = ghi nhận IP/vị trí/thiết bị của CHÍNH người bấm .</summary>

- **Mã nguồn:** [AttendanceService::checkIn()](app/Services/AttendanceService.php#L551)
- **Gọi xuống:** [AttendanceRepository::findForShift()](app/Repositories/AttendanceRepository.php#L14) · [AttendanceRepository::findOrCreateForShift()](app/Repositories/AttendanceRepository.php#L25) · [AttendanceLogRepository::create()](app/Repositories/AttendanceLogRepository.php#L9)
- **Hiệu ứng phụ:** [AttendanceChecked](app/Events/AttendanceChecked.php#L1), `DB::transaction`
- **Được gọi bởi:** [AttendanceController::checkIn()](app/Http/Controllers/Api/V1/AttendanceController.php#L24)

</details>

<details>
<summary><code>public checkOut()</code> — Chấm công ra</summary>

- **Mã nguồn:** [AttendanceService::checkOut()](app/Services/AttendanceService.php#L622)
- **Gọi xuống:** [AttendanceRepository::findForShift()](app/Repositories/AttendanceRepository.php#L14) · [AttendanceLogRepository::create()](app/Repositories/AttendanceLogRepository.php#L9)
- **Hiệu ứng phụ:** [AttendanceChecked](app/Events/AttendanceChecked.php#L1), `DB::transaction`
- **Được gọi bởi:** [AttendanceController::checkOut()](app/Http/Controllers/Api/V1/AttendanceController.php#L36)

</details>

<details>
<summary><code>public listActiveAssignmentsForDate()</code> — Toàn bộ ca đang được gán cho nhân viên vào ĐÚNG ngày này (mục 14, có thể nhiều ca cùng ngày, vd Ca sáng + Ca chiều) — sắp theo start_time.</summary>

- **Mã nguồn:** [AttendanceService::listActiveAssignmentsForDate()](app/Services/AttendanceService.php#L718)
- **Được gọi bởi:** [DashboardService::weekSchedule()](app/Services/DashboardService.php#L322)

</details>

<details>
<summary><code>public resolveActiveAssignment()</code> — Xác định active assignment</summary>

- **Mã nguồn:** [AttendanceService::resolveActiveAssignment()](app/Services/AttendanceService.php#L741)
- **Được gọi bởi:** [AttendanceAdjustmentService::requestForEmployee()](app/Services/AttendanceAdjustmentService.php#L58) · [AttendanceAdjustmentService::extraShiftDatesNeedingUnlock()](app/Services/AttendanceAdjustmentService.php#L250)

</details>

</details>

<details>
<summary><code>WorkTimeCalculationService</code> — 1 hàm — Dùng CHUNG cho AttendanceService (tổng ngày công hiển thị ở trang Lịch sử chấm công) và P…</summary>

- **Mã nguồn:** [app/Services/WorkTimeCalculationService.php](app/Services/WorkTimeCalculationService.php#L1)

<details>
<summary><code>public dayEquivalentFor()</code> — day equivalent for</summary>

- **Mã nguồn:** [WorkTimeCalculationService::dayEquivalentFor()](app/Services/WorkTimeCalculationService.php#L38)
- **Được gọi bởi:** [AttendanceService::history()](app/Services/AttendanceService.php#L70) · [AttendanceService::summarizeHistory()](app/Services/AttendanceService.php#L239) · [PayrollService::calculateWorkedMetrics()](app/Services/PayrollService.php#L207) · [PayrollService::workdayBreakdown()](app/Services/PayrollService.php#L304)

</details>

</details>

</details>

<details>
<summary><strong>Danh sách test</strong> — 137 test</summary>

<details>
<summary><code>AttendanceApprovalTest.php</code> — 17 test</summary>

- **Mã nguồn:** [tests/Feature/Attendance/AttendanceApprovalTest.php](tests/Feature/Attendance/AttendanceApprovalTest.php#L1)
- [test_new_attendance_defaults_to_pending_approval](tests/Feature/Attendance/AttendanceApprovalTest.php#L78)
- [test_decide_requires_authentication](tests/Feature/Attendance/AttendanceApprovalTest.php#L86)
- [test_hr_can_approve_a_completed_attendance](tests/Feature/Attendance/AttendanceApprovalTest.php#L93)
- [test_hr_can_reject_with_a_reason](tests/Feature/Attendance/AttendanceApprovalTest.php#L115)
- [test_rejecting_requires_a_reason](tests/Feature/Attendance/AttendanceApprovalTest.php#L131)
- [test_status_must_be_approved_or_rejected](tests/Feature/Attendance/AttendanceApprovalTest.php#L142)
- [test_can_decide_an_attendance_that_has_not_checked_out_yet](tests/Feature/Attendance/AttendanceApprovalTest.php#L155)
- [test_cannot_decide_an_attendance_that_has_not_checked_in](tests/Feature/Attendance/AttendanceApprovalTest.php#L166)
- [test_cannot_repeat_the_same_decision](tests/Feature/Attendance/AttendanceApprovalTest.php#L180)
- [test_hr_can_change_a_rejected_attendance_to_approved](tests/Feature/Attendance/AttendanceApprovalTest.php#L189)
- [test_employee_cannot_decide](tests/Feature/Attendance/AttendanceApprovalTest.php#L205)
- [test_manager_cannot_decide](tests/Feature/Attendance/AttendanceApprovalTest.php#L216)
- [test_admin_can_decide](tests/Feature/Attendance/AttendanceApprovalTest.php#L225)
- [test_unknown_attendance_returns_404](tests/Feature/Attendance/AttendanceApprovalTest.php#L234)
- [test_list_can_filter_by_approval_status_and_includes_logs](tests/Feature/Attendance/AttendanceApprovalTest.php#L240)
- [test_hr_approving_a_correction_adjustment_also_approves_the_attendance](tests/Feature/Attendance/AttendanceApprovalTest.php#L264)
- [test_rejected_or_non_time_adjustments_do_not_approve_the_attendance](tests/Feature/Attendance/AttendanceApprovalTest.php#L294)

</details>

<details>
<summary><code>AttendanceBulkApprovalTest.php</code> — 6 test</summary>

- **Mã nguồn:** [tests/Feature/Attendance/AttendanceBulkApprovalTest.php](tests/Feature/Attendance/AttendanceBulkApprovalTest.php#L1)
- [test_requires_authentication](tests/Feature/Attendance/AttendanceBulkApprovalTest.php#L70)
- [test_employee_without_approve_permission_is_forbidden](tests/Feature/Attendance/AttendanceBulkApprovalTest.php#L77)
- [test_hr_can_approve_multiple_attendances_at_once](tests/Feature/Attendance/AttendanceBulkApprovalTest.php#L92)
- [test_rejecting_requires_a_note_even_in_bulk](tests/Feature/Attendance/AttendanceBulkApprovalTest.php#L111)
- [test_one_invalid_record_does_not_block_the_rest](tests/Feature/Attendance/AttendanceBulkApprovalTest.php#L122)
- [test_attendance_id_that_does_not_exist_fails_validation](tests/Feature/Attendance/AttendanceBulkApprovalTest.php#L139)

</details>

<details>
<summary><code>AttendanceHistoryTest.php</code> — 14 test</summary>

- **Mã nguồn:** [tests/Feature/Attendance/AttendanceHistoryTest.php](tests/Feature/Attendance/AttendanceHistoryTest.php#L1)
- [test_history_derives_status_and_summary_correctly](tests/Feature/Attendance/AttendanceHistoryTest.php#L120)
- [test_history_ignores_orphaned_assignment_pointing_to_a_deleted_work_shift](tests/Feature/Attendance/AttendanceHistoryTest.php#L189)
- [test_history_summary_counts_only_approved_attendance](tests/Feature/Attendance/AttendanceHistoryTest.php#L217)
- [test_history_treats_excused_late_as_full_and_excludes_from_late_count](tests/Feature/Attendance/AttendanceHistoryTest.php#L252)
- [test_history_marks_approved_leave_day_as_on_leave](tests/Feature/Attendance/AttendanceHistoryTest.php#L285)
- [test_history_ignores_pending_or_rejected_leave](tests/Feature/Attendance/AttendanceHistoryTest.php#L321)
- [test_history_ignores_hourly_leave_for_on_leave_status](tests/Feature/Attendance/AttendanceHistoryTest.php#L342)
- [test_history_filters_by_work_shift_id](tests/Feature/Attendance/AttendanceHistoryTest.php#L365)
- [test_history_filters_by_status](tests/Feature/Attendance/AttendanceHistoryTest.php#L387)
- [test_history_me_does_not_require_attendance_view_all](tests/Feature/Attendance/AttendanceHistoryTest.php#L409)
- [test_history_for_employee_requires_attendance_view_all](tests/Feature/Attendance/AttendanceHistoryTest.php#L421)
- [test_history_defaults_to_current_month_when_dates_omitted](tests/Feature/Attendance/AttendanceHistoryTest.php#L438)
- [test_history_marks_today_without_checkout_as_in_progress](tests/Feature/Attendance/AttendanceHistoryTest.php#L463)
- [test_history_excludes_future_dates_even_when_requested](tests/Feature/Attendance/AttendanceHistoryTest.php#L496)

</details>

<details>
<summary><code>AttendanceLiveFeedTest.php</code> — 4 test</summary>

- **Mã nguồn:** [tests/Feature/Attendance/AttendanceLiveFeedTest.php](tests/Feature/Attendance/AttendanceLiveFeedTest.php#L1)
- [test_hr_can_authorize_the_live_feed_channel](tests/Feature/Attendance/AttendanceLiveFeedTest.php#L80)
- [test_employee_without_view_all_cannot_authorize_the_live_feed_channel](tests/Feature/Attendance/AttendanceLiveFeedTest.php#L93)
- [test_check_in_dispatches_attendance_checked_event_and_notifies_approvers](tests/Feature/Attendance/AttendanceLiveFeedTest.php#L113)
- [test_deciding_approval_dispatches_attendance_approval_decided_event](tests/Feature/Attendance/AttendanceLiveFeedTest.php#L152)

</details>

<details>
<summary><code>AttendanceOverviewTest.php</code> — 18 test</summary>

- **Mã nguồn:** [tests/Feature/Attendance/AttendanceOverviewTest.php](tests/Feature/Attendance/AttendanceOverviewTest.php#L1)
- [test_requires_authentication](tests/Feature/Attendance/AttendanceOverviewTest.php#L112)
- [test_employee_without_view_all_is_forbidden](tests/Feature/Attendance/AttendanceOverviewTest.php#L117)
- [test_manager_can_view_but_not_approve](tests/Feature/Attendance/AttendanceOverviewTest.php#L127)
- [test_overview_combines_checked_in_absent_and_on_leave_employees](tests/Feature/Attendance/AttendanceOverviewTest.php#L142)
- [test_overview_and_history_report_the_same_status_for_the_same_shift](tests/Feature/Attendance/AttendanceOverviewTest.php#L185)
- [test_overview_ignores_orphaned_assignment_pointing_to_a_deleted_work_shift](tests/Feature/Attendance/AttendanceOverviewTest.php#L225)
- [test_overview_still_shows_attendance_after_same_day_reassignment_and_shift_deletion](tests/Feature/Attendance/AttendanceOverviewTest.php#L252)
- [test_in_progress_shift_today_shows_in_progress_not_insufficient](tests/Feature/Attendance/AttendanceOverviewTest.php#L287)
- [test_summary_prioritizes_in_progress_shift_over_absent_shift](tests/Feature/Attendance/AttendanceOverviewTest.php#L311)
- [test_summary_counts_employees_not_shifts_when_one_employee_has_multiple_shifts](tests/Feature/Attendance/AttendanceOverviewTest.php#L341)
- [test_filters_by_department](tests/Feature/Attendance/AttendanceOverviewTest.php#L386)
- [test_filters_by_work_shift](tests/Feature/Attendance/AttendanceOverviewTest.php#L405)
- [test_filters_by_status](tests/Feature/Attendance/AttendanceOverviewTest.php#L422)
- [test_filters_by_approval_status](tests/Feature/Attendance/AttendanceOverviewTest.php#L442)
- [test_excludes_employees_not_assigned_on_that_day_of_week](tests/Feature/Attendance/AttendanceOverviewTest.php#L466)
- [test_employee_with_two_shifts_same_day_produces_two_rows](tests/Feature/Attendance/AttendanceOverviewTest.php#L481)
- [test_defaults_to_today_when_date_is_omitted](tests/Feature/Attendance/AttendanceOverviewTest.php#L499)
- [test_includes_device_log_data](tests/Feature/Attendance/AttendanceOverviewTest.php#L515)

</details>

<details>
<summary><code>AttendanceTest.php</code> — 26 test</summary>

- **Mã nguồn:** [tests/Feature/Attendance/AttendanceTest.php](tests/Feature/Attendance/AttendanceTest.php#L1)
- [test_check_in_requires_authentication](tests/Feature/Attendance/AttendanceTest.php#L117)
- [test_check_in_without_work_shift_id_is_rejected](tests/Feature/Attendance/AttendanceTest.php#L124)
- [test_check_in_with_unassigned_work_shift_is_rejected](tests/Feature/Attendance/AttendanceTest.php#L136)
- [test_check_in_records_device_ip_address_and_device_name_automatically](tests/Feature/Attendance/AttendanceTest.php#L153)
- [test_check_in_without_location_permission_still_records_ip_and_device](tests/Feature/Attendance/AttendanceTest.php#L181)
- [test_check_in_succeeds_with_null_address_when_geocoding_fails](tests/Feature/Attendance/AttendanceTest.php#L204)
- [test_check_in_with_only_one_coordinate_is_rejected](tests/Feature/Attendance/AttendanceTest.php#L225)
- [test_check_out_also_records_device_info](tests/Feature/Attendance/AttendanceTest.php#L243)
- [test_late_check_in_calculates_late_minutes](tests/Feature/Attendance/AttendanceTest.php#L272)
- [test_cannot_check_in_twice_same_shift_same_day](tests/Feature/Attendance/AttendanceTest.php#L288)
- [test_can_check_in_to_different_shift_same_day_after_completing_first](tests/Feature/Attendance/AttendanceTest.php#L311)
- [test_cannot_check_in_more_than_30_minutes_before_shift_start](tests/Feature/Attendance/AttendanceTest.php#L347)
- [test_can_check_in_exactly_30_minutes_before_shift_start](tests/Feature/Attendance/AttendanceTest.php#L365)
- [test_cannot_check_in_after_shift_has_ended](tests/Feature/Attendance/AttendanceTest.php#L380)
- [test_can_check_in_exactly_at_shift_end_time](tests/Feature/Attendance/AttendanceTest.php#L398)
- [test_check_out_without_check_in_is_rejected](tests/Feature/Attendance/AttendanceTest.php#L413)
- [test_check_out_calculates_actual_minutes_and_completes](tests/Feature/Attendance/AttendanceTest.php#L428)
- [test_check_out_subtracts_lunch_break_overlap_from_actual_minutes](tests/Feature/Attendance/AttendanceTest.php#L459)
- [test_lunch_break_not_subtracted_when_shift_ends_before_it_starts](tests/Feature/Attendance/AttendanceTest.php#L483)
- [test_check_out_exactly_at_shift_end_has_no_overtime_even_if_actual_exceeds_standard](tests/Feature/Attendance/AttendanceTest.php#L509)
- [test_check_out_after_shift_end_calculates_overtime_from_shift_end](tests/Feature/Attendance/AttendanceTest.php#L536)
- [test_today_endpoint_returns_empty_array_without_shift_assignment](tests/Feature/Attendance/AttendanceTest.php#L559)
- [test_today_endpoint_lists_one_entry_per_assigned_shift](tests/Feature/Attendance/AttendanceTest.php#L574)
- [test_listing_own_history_requires_authentication](tests/Feature/Attendance/AttendanceTest.php#L597)
- [test_hr_can_list_all_attendances](tests/Feature/Attendance/AttendanceTest.php#L604)
- [test_employee_cannot_list_all_attendances](tests/Feature/Attendance/AttendanceTest.php#L615)

</details>

<details>
<summary><code>RemindMissingCheckoutTest.php</code> — 4 test</summary>

- **Mã nguồn:** [tests/Feature/Console/RemindMissingCheckoutTest.php](tests/Feature/Console/RemindMissingCheckoutTest.php#L1)
- [test_reminds_when_shift_ended_more_than_5_minutes_ago](tests/Feature/Console/RemindMissingCheckoutTest.php#L69)
- [test_does_not_remind_within_5_minute_grace_period](tests/Feature/Console/RemindMissingCheckoutTest.php#L85)
- [test_does_not_remind_twice_for_the_same_record](tests/Feature/Console/RemindMissingCheckoutTest.php#L99)
- [test_does_not_remind_when_already_checked_out](tests/Feature/Console/RemindMissingCheckoutTest.php#L112)

</details>

<details>
<summary><code>DeviceInfoParserTest.php</code> — 3 test</summary>

- **Mã nguồn:** [tests/Unit/Services/Attendance/DeviceInfoParserTest.php](tests/Unit/Services/Attendance/DeviceInfoParserTest.php#L1)
- [test_describe_reads_common_user_agents](tests/Unit/Services/Attendance/DeviceInfoParserTest.php#L73)
- [test_describe_handles_missing_user_agent](tests/Unit/Services/Attendance/DeviceInfoParserTest.php#L78)
- [test_describe_falls_back_for_unknown_client](tests/Unit/Services/Attendance/DeviceInfoParserTest.php#L86)

</details>

<details>
<summary><code>ReverseGeocoderTest.php</code> — 6 test</summary>

- **Mã nguồn:** [tests/Unit/Services/Attendance/ReverseGeocoderTest.php](tests/Unit/Services/Attendance/ReverseGeocoderTest.php#L1)
- [test_returns_address_and_sends_required_headers_and_params](tests/Unit/Services/Attendance/ReverseGeocoderTest.php#L24)
- [test_second_lookup_for_nearby_coordinates_uses_cache](tests/Unit/Services/Attendance/ReverseGeocoderTest.php#L42)
- [test_returns_null_when_service_responds_with_error](tests/Unit/Services/Attendance/ReverseGeocoderTest.php#L55)
- [test_returns_null_when_connection_fails](tests/Unit/Services/Attendance/ReverseGeocoderTest.php#L62)
- [test_returns_null_when_response_has_no_address](tests/Unit/Services/Attendance/ReverseGeocoderTest.php#L69)
- [test_failed_lookup_is_not_cached](tests/Unit/Services/Attendance/ReverseGeocoderTest.php#L77)

</details>

<details>
<summary><code>AttendanceServiceCalculationTest.php</code> — 21 test</summary>

- **Mã nguồn:** [tests/Unit/Services/AttendanceServiceCalculationTest.php](tests/Unit/Services/AttendanceServiceCalculationTest.php#L1)
- [test_check_in_before_shift_start_is_not_late](tests/Unit/Services/AttendanceServiceCalculationTest.php#L43)
- [test_check_in_within_grace_period_is_not_late](tests/Unit/Services/AttendanceServiceCalculationTest.php#L54)
- [test_check_in_after_grace_period_is_late](tests/Unit/Services/AttendanceServiceCalculationTest.php#L68)
- [test_check_out_after_shift_end_is_not_early_leave](tests/Unit/Services/AttendanceServiceCalculationTest.php#L83)
- [test_check_out_within_grace_period_is_not_early_leave](tests/Unit/Services/AttendanceServiceCalculationTest.php#L94)
- [test_check_out_before_grace_period_is_early_leave](tests/Unit/Services/AttendanceServiceCalculationTest.php#L106)
- [test_check_out_exactly_at_shift_end_has_no_overtime](tests/Unit/Services/AttendanceServiceCalculationTest.php#L120)
- [test_check_out_after_shift_end_calculates_overtime](tests/Unit/Services/AttendanceServiceCalculationTest.php#L133)
- [test_check_out_three_minutes_after_shift_end_has_no_overtime](tests/Unit/Services/AttendanceServiceCalculationTest.php#L146)
- [test_check_out_exactly_at_grace_boundary_has_no_overtime](tests/Unit/Services/AttendanceServiceCalculationTest.php#L157)
- [test_check_out_one_minute_past_grace_boundary_gives_one_minute_overtime](tests/Unit/Services/AttendanceServiceCalculationTest.php#L168)
- [test_check_out_thirty_minutes_after_shift_end_gives_twenty_five_minutes_overtime](tests/Unit/Services/AttendanceServiceCalculationTest.php#L179)
- [test_derive_history_status_null_attendance_is_absent](tests/Unit/Services/AttendanceServiceCalculationTest.php#L192)
- [test_derive_history_status_without_check_in_is_absent](tests/Unit/Services/AttendanceServiceCalculationTest.php#L201)
- [test_derive_history_status_late_and_not_excused_is_late](tests/Unit/Services/AttendanceServiceCalculationTest.php#L211)
- [test_derive_history_status_late_but_excused_falls_through_to_full](tests/Unit/Services/AttendanceServiceCalculationTest.php#L229)
- [test_derive_history_status_missing_check_out_is_insufficient](tests/Unit/Services/AttendanceServiceCalculationTest.php#L245)
- [test_derive_history_status_missing_check_out_today_is_in_progress](tests/Unit/Services/AttendanceServiceCalculationTest.php#L261)
- [test_derive_history_status_missing_check_out_on_past_day_is_insufficient](tests/Unit/Services/AttendanceServiceCalculationTest.php#L276)
- [test_derive_history_status_early_leave_is_insufficient](tests/Unit/Services/AttendanceServiceCalculationTest.php#L291)
- [test_derive_history_status_full_day_is_full](tests/Unit/Services/AttendanceServiceCalculationTest.php#L306)

</details>

<details>
<summary><code>WorkTimeCalculationServiceTest.php</code> — 18 test</summary>

- **Mã nguồn:** [tests/Unit/Services/WorkTimeCalculationServiceTest.php](tests/Unit/Services/WorkTimeCalculationServiceTest.php#L1)
- [test_no_actual_minutes_gives_zero_regardless_of_lateness](tests/Unit/Services/WorkTimeCalculationServiceTest.php#L52)
- [test_missing_standard_work_minutes_gives_zero](tests/Unit/Services/WorkTimeCalculationServiceTest.php#L64)
- [test_null_work_shift_gives_zero](tests/Unit/Services/WorkTimeCalculationServiceTest.php#L74)
- [test_on_time_full_shift_gives_one_full_day](tests/Unit/Services/WorkTimeCalculationServiceTest.php#L83)
- [test_on_time_with_slightly_fewer_actual_minutes_still_gives_full_day](tests/Unit/Services/WorkTimeCalculationServiceTest.php#L97)
- [test_late_exactly_thirty_minutes_is_the_boundary_still_full_day](tests/Unit/Services/WorkTimeCalculationServiceTest.php#L107)
- [test_early_leave_within_threshold_gives_full_day](tests/Unit/Services/WorkTimeCalculationServiceTest.php#L117)
- [test_late_thirty_one_minutes_penalizes_by_continuous_ratio](tests/Unit/Services/WorkTimeCalculationServiceTest.php#L129)
- [test_penalty_depends_only_on_actual_minutes_not_on_how_late](tests/Unit/Services/WorkTimeCalculationServiceTest.php#L145)
- [test_working_over_shift_with_large_lateness_is_still_capped_at_one](tests/Unit/Services/WorkTimeCalculationServiceTest.php#L156)
- [test_late_and_early_leave_together_uses_the_larger_minutes](tests/Unit/Services/WorkTimeCalculationServiceTest.php#L169)
- [test_half_day_shift_worked_fully_gives_half_a_day](tests/Unit/Services/WorkTimeCalculationServiceTest.php#L183)
- [test_two_half_day_shifts_worked_fully_sum_to_one_full_day](tests/Unit/Services/WorkTimeCalculationServiceTest.php#L198)
- [test_penalized_half_day_shift_multiplies_ratio_and_coefficient](tests/Unit/Services/WorkTimeCalculationServiceTest.php#L211)
- [test_missing_work_coefficient_defaults_to_one](tests/Unit/Services/WorkTimeCalculationServiceTest.php#L223)
- [test_late_excused_restores_full_day_even_with_large_lateness](tests/Unit/Services/WorkTimeCalculationServiceTest.php#L235)
- [test_late_excused_still_requires_checkout](tests/Unit/Services/WorkTimeCalculationServiceTest.php#L247)
- [test_late_without_excuse_beyond_threshold_is_penalized](tests/Unit/Services/WorkTimeCalculationServiceTest.php#L259)

</details>

</details>

---

## 7. Điều chỉnh công / OT / làm ngoài lịch

- **Điểm vào:** Dialog trong `/check-in`, `/attendance-adjustments`; API `/attendances/adjustments*` ([`attendances.php`](routes/api/v1/attendances.php)).
- **Danh mục file & hàm:** xem [cuối mục này](#danh-mục-file--hàm-module-7) — mỗi file và mỗi hàm public có link riêng.


**Luồng chính**

| Thao tác | Luồng |
| --- | --- |
| Nộp đơn (mọi loại) | `dialog trong CheckIn → attendanceService.requestAdjustment → POST /attendances/adjustments → StoreAttendanceAdjustmentRequest (rẽ nhánh theo type) → AttendanceAdjustmentService::requestForEmployee → tạo bản ghi pending → notifyApprovers (attendance_adjustment.pending, mọi người có attendance.adjust trừ người nộp) → ResourceChanged('attendance_adjustments')` |
| Duyệt `correction`/`supplement` | `AttendanceAdjustments.vue → decideAdjustment → PUT /adjustments/{id} → AttendanceAdjustmentService::decide → AttendanceService::applyAdjustment (tái dùng công thức trễ/sớm/OT; completed khi đủ giờ vào + ra) + duyệt luôn bản ghi chấm công` |
| Duyệt `excuse` / `overtime` | chỉ set `attendances.late_excused = true` / `overtime_approved = true`, không đổi giờ |
| Duyệt `extra_shift` | `decide → EmployeeShiftAssignmentService::createOneOffAssignment cho từng ngày cần mở khóa` → tới ngày nhân viên tự chấm công bình thường |

**Luồng demo nhanh:** Nhân viên quên chấm ra → "Xin điều chỉnh" (giờ ra đề xuất + lý do) → HR vào `/attendance-adjustments` → Duyệt → bản ghi chấm công được cập nhật giờ ra và tính lại công.

**Quy tắc & bẫy**
- `overtime`: xin được cả trước khi chấm ra (chỉ cần có `first_check_in_at`); đã chấm ra thì chặn khi `overtime_minutes ≤ 0` hoặc đã `overtime_approved`. Bảng lương chỉ trả OT khi đã duyệt **và** ≥ 25 phút (module 10).
- `extra_shift`: đăng ký **trước**, không có giờ đề xuất; ngày từ hôm nay trở đi; tối đa `EXTRA_SHIFT_MAX_DAYS` = 31 ngày liền (`attendance_date_to` NULL = 1 ngày); bỏ qua ngày đã có ca (cả khoảng đều có thì lỗi); chặn đơn `pending` chồng ngày cùng ca. "Tự chọn giờ" tạo `WorkShift` "Ca tùy chỉnh HH:mm-HH:mm". Công tính như ngày thường (không phải OT). Không rút lại được từng ngày sau khi duyệt (muốn hủy phải xóa tay bản gán 1 ngày).
- `GET /work-shifts` dùng `shift.view,attendance.check` để nhân viên đọc được danh sách ca khi xin làm ngoài lịch.
- Chưa báo kết quả duyệt đơn điều chỉnh ngược lại cho nhân viên.

**Liên thông:** thay đổi dữ liệu ở module 6 và mở khóa chấm công qua module 5; báo duyệt qua module 11.

<a id="danh-mục-file--hàm-module-7"></a>

### Danh mục file & hàm — module 7

#### Migration (4 file)

- [2026_01_03_000008_create_attendance_adjustments_table.php](database/migrations/2026_01_03_000008_create_attendance_adjustments_table.php)
- [2026_09_10_000002_add_soft_deletes_to_attendance_adjustments_table.php](database/migrations/2026_09_10_000002_add_soft_deletes_to_attendance_adjustments_table.php)
- [2026_09_11_000002_add_type_and_shift_fields_to_attendance_adjustments_table.php](database/migrations/2026_09_11_000002_add_type_and_shift_fields_to_attendance_adjustments_table.php)
- [2026_09_30_000003_add_attendance_date_to_to_attendance_adjustments_table.php](database/migrations/2026_09_30_000003_add_attendance_date_to_to_attendance_adjustments_table.php)

#### Kiểm thử (2 file, 55 test)

- [AttendanceAdjustmentTest.php](tests/Feature/Attendance/AttendanceAdjustmentTest.php#L1) — 35 test
- [AttendanceExtraShiftTest.php](tests/Feature/Attendance/AttendanceExtraShiftTest.php#L1) — 20 test
<details>
<summary><strong>Danh sách test</strong> — 55 test</summary>

<details>
<summary><code>AttendanceAdjustmentTest.php</code> — 35 test</summary>

- **Mã nguồn:** [tests/Feature/Attendance/AttendanceAdjustmentTest.php](tests/Feature/Attendance/AttendanceAdjustmentTest.php#L1)
- [test_store_requires_authentication](tests/Feature/Attendance/AttendanceAdjustmentTest.php#L88)
- [test_employee_can_request_adjustment_for_own_attendance](tests/Feature/Attendance/AttendanceAdjustmentTest.php#L95)
- [test_new_request_notifies_users_with_attendance_adjust](tests/Feature/Attendance/AttendanceAdjustmentTest.php#L121)
- [test_requester_with_attendance_adjust_is_not_notified_of_own_request](tests/Feature/Attendance/AttendanceAdjustmentTest.php#L146)
- [test_cannot_request_adjustment_for_another_employee_attendance](tests/Feature/Attendance/AttendanceAdjustmentTest.php#L165)
- [test_must_propose_at_least_one_time](tests/Feature/Attendance/AttendanceAdjustmentTest.php#L181)
- [test_proposed_check_out_must_be_after_proposed_check_in](tests/Feature/Attendance/AttendanceAdjustmentTest.php#L197)
- [test_employee_can_request_supplement_for_assigned_shift](tests/Feature/Attendance/AttendanceAdjustmentTest.php#L215)
- [test_supplement_request_requires_shift_assigned_on_that_date](tests/Feature/Attendance/AttendanceAdjustmentTest.php#L248)
- [test_supplement_request_rejected_when_attendance_already_exists](tests/Feature/Attendance/AttendanceAdjustmentTest.php#L271)
- [test_supplement_request_requires_both_check_in_and_check_out](tests/Feature/Attendance/AttendanceAdjustmentTest.php#L302)
- [test_hr_can_approve_supplement_request_and_it_creates_attendance](tests/Feature/Attendance/AttendanceAdjustmentTest.php#L324)
- [test_hr_approving_supplement_subtracts_lunch_break](tests/Feature/Attendance/AttendanceAdjustmentTest.php#L374)
- [test_employee_without_attendance_adjust_cannot_decide](tests/Feature/Attendance/AttendanceAdjustmentTest.php#L408)
- [test_hr_can_approve_adjustment_and_it_updates_attendance](tests/Feature/Attendance/AttendanceAdjustmentTest.php#L431)
- [test_correction_handles_forgotten_checkout](tests/Feature/Attendance/AttendanceAdjustmentTest.php#L476)
- [test_employee_can_request_excuse_for_late_attendance](tests/Feature/Attendance/AttendanceAdjustmentTest.php#L511)
- [test_cannot_request_excuse_when_attendance_is_not_late](tests/Feature/Attendance/AttendanceAdjustmentTest.php#L538)
- [test_cannot_request_excuse_twice_for_already_excused_attendance](tests/Feature/Attendance/AttendanceAdjustmentTest.php#L553)
- [test_hr_can_approve_excuse_sets_late_excused_without_changing_time](tests/Feature/Attendance/AttendanceAdjustmentTest.php#L568)
- [test_hr_reject_excuse_does_not_set_late_excused](tests/Feature/Attendance/AttendanceAdjustmentTest.php#L597)
- [test_employee_can_request_overtime_approval](tests/Feature/Attendance/AttendanceAdjustmentTest.php#L621)
- [test_cannot_request_overtime_approval_when_checked_out_with_no_overtime_minutes](tests/Feature/Attendance/AttendanceAdjustmentTest.php#L649)
- [test_can_request_overtime_approval_in_advance_while_still_checked_in](tests/Feature/Attendance/AttendanceAdjustmentTest.php#L670)
- [test_cannot_request_overtime_approval_without_checking_in](tests/Feature/Attendance/AttendanceAdjustmentTest.php#L690)
- [test_hr_approving_overtime_in_advance_only_sets_the_flag](tests/Feature/Attendance/AttendanceAdjustmentTest.php#L709)
- [test_cannot_request_overtime_approval_twice_for_already_approved_attendance](tests/Feature/Attendance/AttendanceAdjustmentTest.php#L732)
- [test_hr_can_approve_overtime_sets_overtime_approved](tests/Feature/Attendance/AttendanceAdjustmentTest.php#L747)
- [test_hr_reject_overtime_does_not_set_overtime_approved](tests/Feature/Attendance/AttendanceAdjustmentTest.php#L774)
- [test_approving_adjustment_marks_completed_only_when_check_out_also_present](tests/Feature/Attendance/AttendanceAdjustmentTest.php#L796)
- [test_hr_can_reject_adjustment_without_changing_attendance](tests/Feature/Attendance/AttendanceAdjustmentTest.php#L830)
- [test_cannot_decide_already_decided_adjustment](tests/Feature/Attendance/AttendanceAdjustmentTest.php#L858)
- [test_employee_can_list_own_adjustment_requests](tests/Feature/Attendance/AttendanceAdjustmentTest.php#L883)
- [test_employee_cannot_list_all_adjustments](tests/Feature/Attendance/AttendanceAdjustmentTest.php#L907)
- [test_hr_can_list_all_adjustments](tests/Feature/Attendance/AttendanceAdjustmentTest.php#L919)

</details>

<details>
<summary><code>AttendanceExtraShiftTest.php</code> — 20 test</summary>

- **Mã nguồn:** [tests/Feature/Attendance/AttendanceExtraShiftTest.php](tests/Feature/Attendance/AttendanceExtraShiftTest.php#L1)
- [test_employee_can_request_extra_shift_for_a_future_date_not_in_schedule](tests/Feature/Attendance/AttendanceExtraShiftTest.php#L90)
- [test_cannot_request_extra_shift_for_a_past_date](tests/Feature/Attendance/AttendanceExtraShiftTest.php#L109)
- [test_cannot_request_extra_shift_for_inactive_work_shift](tests/Feature/Attendance/AttendanceExtraShiftTest.php#L120)
- [test_cannot_request_extra_shift_when_already_assigned_that_day](tests/Feature/Attendance/AttendanceExtraShiftTest.php#L131)
- [test_cannot_submit_a_second_pending_request_for_the_same_shift_and_date](tests/Feature/Attendance/AttendanceExtraShiftTest.php#L147)
- [test_hr_can_approve_extra_shift_request_and_employee_can_then_check_in](tests/Feature/Attendance/AttendanceExtraShiftTest.php#L160)
- [test_one_off_assignment_does_not_apply_to_the_same_weekday_on_other_dates](tests/Feature/Attendance/AttendanceExtraShiftTest.php#L198)
- [test_hr_can_reject_extra_shift_request_and_check_in_stays_blocked](tests/Feature/Attendance/AttendanceExtraShiftTest.php#L223)
- [test_reason_is_required_for_extra_shift](tests/Feature/Attendance/AttendanceExtraShiftTest.php#L248)
- [test_employee_sees_extra_shift_request_in_own_adjustment_list](tests/Feature/Attendance/AttendanceExtraShiftTest.php#L259)
- [test_employee_can_request_extra_shift_with_a_custom_time_range](tests/Feature/Attendance/AttendanceExtraShiftTest.php#L275)
- [test_custom_time_range_requires_both_start_and_end](tests/Feature/Attendance/AttendanceExtraShiftTest.php#L301)
- [test_custom_end_time_must_be_after_start_time](tests/Feature/Attendance/AttendanceExtraShiftTest.php#L316)
- [test_must_provide_either_work_shift_or_custom_time](tests/Feature/Attendance/AttendanceExtraShiftTest.php#L332)
- [test_hr_can_approve_custom_time_extra_shift_and_employee_can_then_check_in](tests/Feature/Attendance/AttendanceExtraShiftTest.php#L346)
- [test_one_request_can_cover_a_range_of_days_and_approval_unlocks_every_day](tests/Feature/Attendance/AttendanceExtraShiftTest.php#L379)
- [test_end_date_equal_to_start_is_stored_as_a_single_day](tests/Feature/Attendance/AttendanceExtraShiftTest.php#L408)
- [test_end_date_cannot_be_before_start_date_or_span_more_than_31_days](tests/Feature/Attendance/AttendanceExtraShiftTest.php#L420)
- [test_overlapping_pending_range_requests_for_the_same_shift_are_rejected](tests/Feature/Attendance/AttendanceExtraShiftTest.php#L440)
- [test_days_already_in_the_schedule_are_skipped_but_a_fully_covered_range_is_rejected](tests/Feature/Attendance/AttendanceExtraShiftTest.php#L455)

</details>

</details>

---

## 8. Nghỉ phép

**Vai trò:** tạo đơn, duyệt 2 cấp (Manager → HR), tổng hợp quỹ phép, tích lũy phép.

- **Điểm vào:** `/leave-requests`, `/leave-management`, `/leave-overview`; API [`leave-requests.php`](routes/api/v1/leave-requests.php), [`leave-types.php`](routes/api/v1/leave-types.php).
- **Danh mục file & hàm:** xem [cuối mục này](#danh-mục-file--hàm-module-8) — mỗi file và mỗi hàm public có link riêng.


**Luồng chính**

| Thao tác | Luồng |
| --- | --- |
| Nộp đơn | `LeaveRequests.vue → leaveRequestService.create → POST /leave-requests → leave.request → StoreLeaveRequest (loại other bắt buộc đính kèm) → LeaveRequestService::create (resolveOrSyncBalance; calculateTotalDays bỏ T7/CN; findOverlapping; so totalDays với available_days) → LeaveRequest pending → báo manager?->user, không có thì mọi User có leave.approve_hr (leave.pending_manager/hr) + LeaveRequestChanged` |
| Duyệt | `LeaveManagement.vue → leaveRequestService.decide → PUT /leave-requests/{id}/decide → leave.approve_manager,leave.approve_hr → LeaveApprovalService::decide → pending → manager_approved → approved \| rejected (từ chối ở cấp nào cũng dừng; không có quản lý thì HR duyệt thẳng) → duyệt cuối: deductBalance → notifyEmployeeDecision (leave.decided, chỉ web) + báo HR khi manager_approved (trừ người vừa duyệt) + LeaveRequestChanged` |
| Duyệt hàng loạt | `PUT /leave-requests/bulk-decide → LeaveApprovalService::bulkDecide (lặp decide, đơn không đúng cấp vào failed)` |
| Tổng hợp | `LeaveOverview.vue → GET /leave-requests/overview?year=&department_id=&search= → LeaveRequestService::overview (chỉ active/probation; summary toàn bộ khớp bộ lọc)` |
| Tích lũy hằng ngày | `leave:sync-accrual (00:20) → mọi nhân viên active/probation × LeaveType có quota → LeaveAccrualService::resolveOrSyncBalance (chỉ tăng allocated_days)` |

**Luồng demo nhanh:** Nhân viên vào `/leave-requests` → tạo đơn nghỉ phép năm → Manager (`/leave-management`) nhận thông báo → Duyệt → HR duyệt tiếp → nhân viên nhận thông báo kết quả và quỹ phép bị trừ; xem tổng hợp ở `/leave-overview`.

**Quy tắc & bẫy**
- Tích lũy (BLLĐ Điều 113–114): năm đầu cứ đủ 30 ngày làm việc từ `hire_date` +1 ngày; từ năm 2 cấp đủ `annual_entitlement_days` + thâm niên `intdiv(năm làm, 5)` (dùng `diff()->y`, không `diffInYears()`). Một hàm thuần `targetAllocatedDays()`. Không chạy `leave:sync-accrual` trên DB thật khi công thức chưa chốt ("chỉ tăng" không tự hạ).
- Quỹ phép không trừ lúc tạo đơn mà trừ khi duyệt xong; "đủ phép" đã trừ đơn đang `pending`/`manager_approved` cùng loại.
- `LeaveRequests.vue`: thẻ quỹ lọc theo `leave_type.annual_entitlement_days > 0` (không theo `allocated_days > 0`); "Nghỉ phép năm" bị khóa khi hết quỹ (chỉ UI, backend là lưới cuối). Yêu cầu đính kèm cho `other` phải sửa cả `StoreLeaveRequest::withValidator()` và `attachmentRequired` ở FE.
- Cột `decimal` cast `'float'`; không ép `is_active` thành `boolean` (vỡ `StatusChip`). Tên tham số Controller phải khớp đúng tên route model binding.
- Không còn email kết quả duyệt (`LeaveDecisionMail` đã xóa); queue vẫn dùng cho `PasswordResetMail`. Manager có `leave.view_all` nên thấy toàn bộ nhân viên ở tổng hợp.
- Chưa có trang HR xem/chỉnh số dư phép (backlog).

**Liên thông:** nghỉ phép có lương cộng vào công được hưởng ở module 10; che ngày ở module 6; quản lý duyệt lấy từ module 3.

<a id="danh-mục-file--hàm-module-8"></a>

### Danh mục file & hàm — module 8

#### Giao diện (4 file)

- [EmployeeSalaryLeaveTab.vue](resources/js/views/Employee/EmployeeSalaryLeaveTab.vue#L1)
- [LeaveManagement.vue](resources/js/views/Leave/LeaveManagement.vue#L1) — route `/leave-management`
- [LeaveOverview.vue](resources/js/views/Leave/LeaveOverview.vue#L1) — route `/leave-overview`
- [LeaveRequests.vue](resources/js/views/Leave/LeaveRequests.vue#L1) — route `/leave-requests`

#### Controller (2 file, 10 hàm public)

- [LeaveRequestController.php](app/Http/Controllers/Api/V1/LeaveRequestController.php#L1) — 9 hàm: [store()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L28) · [mine()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L44) · [index()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L53) · [overview()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L62) · [balancesMine()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L82) · [balances()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L93) · [downloadEvidence()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L101) · [decide()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L111) · [bulkDecide()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L127)
- [LeaveTypeController.php](app/Http/Controllers/Api/V1/LeaveTypeController.php#L1) — 1 hàm: [index()](app/Http/Controllers/Api/V1/LeaveTypeController.php#L14)

#### Service (3 file, 10 hàm public)

- [LeaveAccrualService.php](app/Services/LeaveAccrualService.php#L1) — 2 hàm: [targetAllocatedDays()](app/Services/LeaveAccrualService.php#L45) · [resolveOrSyncBalance()](app/Services/LeaveAccrualService.php#L110)
- [LeaveApprovalService.php](app/Services/LeaveApprovalService.php#L1) — 2 hàm: [decide()](app/Services/LeaveApprovalService.php#L27) · [bulkDecide()](app/Services/LeaveApprovalService.php#L107)
- [LeaveRequestService.php](app/Services/LeaveRequestService.php#L1) — 6 hàm: [create()](app/Services/LeaveRequestService.php#L40) · [listForEmployee()](app/Services/LeaveRequestService.php#L181) · [listBalancesForEmployee()](app/Services/LeaveRequestService.php#L193) · [overview()](app/Services/LeaveRequestService.php#L234) · [list()](app/Services/LeaveRequestService.php#L328) · [deductBalance()](app/Services/LeaveRequestService.php#L340)

#### Repository (3 file)

- [LeaveApprovalRepository.php](app/Repositories/LeaveApprovalRepository.php#L1) — [create()](app/Repositories/LeaveApprovalRepository.php#L9)
- [LeaveBalanceRepository.php](app/Repositories/LeaveBalanceRepository.php#L1) — [findOrCreateForYear()](app/Repositories/LeaveBalanceRepository.php#L14) · [update()](app/Repositories/LeaveBalanceRepository.php#L22) · [findForYearLocked()](app/Repositories/LeaveBalanceRepository.php#L32)
- [LeaveRequestRepository.php](app/Repositories/LeaveRequestRepository.php#L1) — [create()](app/Repositories/LeaveRequestRepository.php#L12) · [listForEmployee()](app/Repositories/LeaveRequestRepository.php#L17) · [findOverlapping()](app/Repositories/LeaveRequestRepository.php#L31) · [sumPendingDaysForYear()](app/Repositories/LeaveRequestRepository.php#L44) · [approvedDaysByEmployee()](app/Repositories/LeaveRequestRepository.php#L57) · [pendingRequestCounts()](app/Repositories/LeaveRequestRepository.php#L69) · [upcomingApproved()](app/Repositories/LeaveRequestRepository.php#L79) · [paginate()](app/Repositories/LeaveRequestRepository.php#L89)

#### Model (4 file)

- [LeaveApproval.php](app/Models/LeaveApproval.php#L1)
- [LeaveBalance.php](app/Models/LeaveBalance.php#L1)
- [LeaveRequest.php](app/Models/LeaveRequest.php#L1)
- [LeaveType.php](app/Models/LeaveType.php#L1)

#### Request & Resource (3 file)

- [BulkDecideLeaveRequest.php](app/Http/Requests/Leave/BulkDecideLeaveRequest.php#L1) — Duyệt/Từ chối đơn nghỉ phép HÀNG LOẠT — cùng luật với DecideLeaveRequest (bản 1 đơn), chỉ thêm leave_request_…
- [DecideLeaveRequest.php](app/Http/Requests/Leave/DecideLeaveRequest.php#L1)
- [StoreLeaveRequest.php](app/Http/Requests/Leave/StoreLeaveRequest.php#L1)

#### Lệnh, Event, Middleware, hạ tầng BE (1 file)

- [SyncLeaveAccrual.php](app/Console/Commands/SyncLeaveAccrual.php#L1) — "Tích lũy phép năm theo tháng + thưởng thâm niên" — chạy HẰNG NGÀY (không phải hằng tháng) vì mốc thâm niên (…

#### Migration (7 file)

- [2026_01_03_000004_create_leave_types_table.php](database/migrations/2026_01_03_000004_create_leave_types_table.php)
- [2026_01_03_000009_create_leave_balances_table.php](database/migrations/2026_01_03_000009_create_leave_balances_table.php)
- [2026_01_03_000010_create_leave_requests_table.php](database/migrations/2026_01_03_000010_create_leave_requests_table.php)
- [2026_01_03_000011_create_leave_approvals_table.php](database/migrations/2026_01_03_000011_create_leave_approvals_table.php)
- [2026_09_11_000003_add_hourly_fields_to_leave_requests_table.php](database/migrations/2026_09_11_000003_add_hourly_fields_to_leave_requests_table.php)
- [2026_09_24_000002_consolidate_leave_types.php](database/migrations/2026_09_24_000002_consolidate_leave_types.php)
- [2026_09_28_000001_make_approver_employee_id_nullable_in_leave_approvals_table.php](database/migrations/2026_09_28_000001_make_approver_employee_id_nullable_in_leave_approvals_table.php)

#### Kiểm thử (8 file, 89 test)

- [LeaveAccrualSyncTest.php](tests/Feature/Leave/LeaveAccrualSyncTest.php#L1) — 7 test
- [LeaveApprovalTest.php](tests/Feature/Leave/LeaveApprovalTest.php#L1) — 12 test
- [LeaveBulkApprovalTest.php](tests/Feature/Leave/LeaveBulkApprovalTest.php#L1) — 4 test
- [LeaveOverviewTest.php](tests/Feature/Leave/LeaveOverviewTest.php#L1) — 5 test
- [LeaveRequestTest.php](tests/Feature/Leave/LeaveRequestTest.php#L1) — 32 test
- [LeaveTypeTest.php](tests/Feature/Leave/LeaveTypeTest.php#L1) — 3 test
- [LeaveAccrualServiceTest.php](tests/Unit/Services/LeaveAccrualServiceTest.php#L1) — 13 test
- [LeaveRequestServiceCalculationTest.php](tests/Unit/Services/LeaveRequestServiceCalculationTest.php#L1) — 13 test

<details>
<summary><strong>Chi tiết từng màn hình Vue</strong> — 4 file</summary>

<details>
<summary><code>EmployeeSalaryLeaveTab.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Employee/EmployeeSalaryLeaveTab.vue](resources/js/views/Employee/EmployeeSalaryLeaveTab.vue#L1)
- **Gọi API:**
  - `employeeService.payslips()` → `GET /employees/{x}/payslips` → [PayrollController::forEmployee()](app/Http/Controllers/Api/V1/PayrollController.php#L41)
  - `leaveRequestService.balancesForEmployee()` → `GET /leave-requests/balances/{x}` → [LeaveRequestController::balances()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L93)
- **Component con:** [StatusChip.vue](resources/js/components/common/StatusChip.vue#L1), [PayrollPayslipDialog.vue](resources/js/views/Payroll/PayrollPayslipDialog.vue#L1)

</details>

<details>
<summary><code>LeaveManagement.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Leave/LeaveManagement.vue](resources/js/views/Leave/LeaveManagement.vue#L1)
- **Route FE:** `/leave-management`
- **Gọi API:**
  - `leaveRequestService.list()` → `GET /leave-requests` → [LeaveRequestController::index()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L53)
  - `leaveRequestService.decide()` → `PUT /leave-requests/{x}/decide` → [LeaveRequestController::decide()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L111)
  - `leaveRequestService.bulkDecide()` → `PUT /leave-requests/bulk-decide` → [LeaveRequestController::bulkDecide()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L127)
- **Store dùng:** `useLeaveFeedStore`
- **Component con:** [DataTable.vue](resources/js/components/common/DataTable.vue#L1), [PageHeader.vue](resources/js/components/common/PageHeader.vue#L1), [StatusChip.vue](resources/js/components/common/StatusChip.vue#L1)

</details>

<details>
<summary><code>LeaveOverview.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Leave/LeaveOverview.vue](resources/js/views/Leave/LeaveOverview.vue#L1)
- **Route FE:** `/leave-overview`
- **Gọi API:**
  - `leaveRequestService.overview()` → `GET /leave-requests/overview` → [LeaveRequestController::overview()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L62)
- **Store dùng:** `useDepartmentStore`
- **Component con:** [PageHeader.vue](resources/js/components/common/PageHeader.vue#L1), [DataTable.vue](resources/js/components/common/DataTable.vue#L1), [SearchField.vue](resources/js/components/common/SearchField.vue#L1), [StatCards.vue](resources/js/components/dashboard/StatCards.vue#L1)

</details>

<details>
<summary><code>LeaveRequests.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Leave/LeaveRequests.vue](resources/js/views/Leave/LeaveRequests.vue#L1)
- **Route FE:** `/leave-requests`
- **Gọi API:**
  - `leaveRequestService.create()` → `POST /leave-requests` → [LeaveRequestController::store()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L28)
  - `leaveRequestService.mine()` → `GET /leave-requests/me` → [LeaveRequestController::mine()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L44)
  - `leaveRequestService.balancesMine()` → `GET /leave-requests/balances/me` → [LeaveRequestController::balancesMine()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L82)
  - `leaveTypeService.list()` → `GET /leave-types` → [LeaveTypeController::index()](app/Http/Controllers/Api/V1/LeaveTypeController.php#L14)
- **Component con:** [PageHeader.vue](resources/js/components/common/PageHeader.vue#L1), [StatusChip.vue](resources/js/components/common/StatusChip.vue#L1), [SearchSelect.vue](resources/js/components/common/SearchSelect.vue#L1), [InputDate.vue](resources/js/components/common/InputDate.vue#L1), [StatCards.vue](resources/js/components/dashboard/StatCards.vue#L1)

</details>

</details>

<details>
<summary><strong>Chi tiết từng hàm Controller</strong> — 10 hàm</summary>

<details>
<summary><code>LeaveRequestController</code> — 9 hàm</summary>

- **Mã nguồn:** [app/Http/Controllers/Api/V1/LeaveRequestController.php](app/Http/Controllers/Api/V1/LeaveRequestController.php#L1)

<details>
<summary><code>public store()</code> — Luôn tạo đơn cho CHÍNH người gọi API — không nhận employee_id từ client, giống toàn bộ nhóm "chính mình" khác trong dự án (chấm công...).</summary>

- **Mã nguồn:** [LeaveRequestController::store()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L28)
- **API:** `POST /api/v1/leave-requests` — quyền `leave.request` ([leave-requests.php](routes/api/v1/leave-requests.php))
- **FE service:** [leaveRequestService.create()](resources/js/services/leaveRequestService.js#L5) ← gọi từ [LeaveRequests.vue](resources/js/views/Leave/LeaveRequests.vue#L1)
- **Validate:** [StoreLeaveRequest](app/Http/Requests/Leave/StoreLeaveRequest.php#L1)
- **Gọi xuống:** [LeaveRequestService::create()](app/Services/LeaveRequestService.php#L40)

</details>

<details>
<summary><code>public mine()</code> — Dữ liệu của chính người đăng nhập</summary>

- **Mã nguồn:** [LeaveRequestController::mine()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L44)
- **API:** `GET /api/v1/leave-requests/me` — quyền `leave.view_own` ([leave-requests.php](routes/api/v1/leave-requests.php))
- **FE service:** [leaveRequestService.mine()](resources/js/services/leaveRequestService.js#L8) ← gọi từ [LeaveRequests.vue](resources/js/views/Leave/LeaveRequests.vue#L1)
- **Gọi xuống:** [LeaveRequestService::listForEmployee()](app/Services/LeaveRequestService.php#L181)

</details>

<details>
<summary><code>public index()</code> — Danh sách</summary>

- **Mã nguồn:** [LeaveRequestController::index()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L53)
- **API:** `GET /api/v1/leave-requests` — quyền `leave.view_all` ([leave-requests.php](routes/api/v1/leave-requests.php))
- **FE service:** [leaveRequestService.list()](resources/js/services/leaveRequestService.js#L17) ← gọi từ [LeaveManagement.vue](resources/js/views/Leave/LeaveManagement.vue#L1)
- **Gọi xuống:** [LeaveRequestService::list()](app/Services/LeaveRequestService.php#L328)

</details>

<details>
<summary><code>public overview()</code> — Tổng hợp</summary>

- **Mã nguồn:** [LeaveRequestController::overview()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L62)
- **API:** `GET /api/v1/leave-requests/overview` — quyền `leave.view_all` ([leave-requests.php](routes/api/v1/leave-requests.php))
- **FE service:** [leaveRequestService.overview()](resources/js/services/leaveRequestService.js#L21) ← gọi từ [LeaveOverview.vue](resources/js/views/Leave/LeaveOverview.vue#L1)
- **Gọi xuống:** [LeaveRequestService::overview()](app/Services/LeaveRequestService.php#L234)

</details>

<details>
<summary><code>public balancesMine()</code> — Quỹ phép còn lại của CHÍNH MÌNH, theo từng loại phép, năm hiện tại.</summary>

- **Mã nguồn:** [LeaveRequestController::balancesMine()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L82)
- **API:** `GET /api/v1/leave-requests/balances/me` — quyền `leave.view_own` ([leave-requests.php](routes/api/v1/leave-requests.php))
- **FE service:** [leaveRequestService.balancesMine()](resources/js/services/leaveRequestService.js#L11) ← gọi từ [LeaveRequests.vue](resources/js/views/Leave/LeaveRequests.vue#L1)
- **Gọi xuống:** [LeaveRequestService::listBalancesForEmployee()](app/Services/LeaveRequestService.php#L193)

</details>

<details>
<summary><code>public balances()</code> — Quỹ phép của 1 nhân viên BẤT KỲ (khác balancesMine() — chỉ chính mình) — dùng cho tab "Lương / Phép" ở Chi tiết nhân viên phía HR.</summary>

- **Mã nguồn:** [LeaveRequestController::balances()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L93)
- **API:** `GET /api/v1/leave-requests/balances/{employee}` — quyền `leave.view_all` ([leave-requests.php](routes/api/v1/leave-requests.php))
- **FE service:** [leaveRequestService.balancesForEmployee()](resources/js/services/leaveRequestService.js#L14) ← gọi từ [EmployeeSalaryLeaveTab.vue](resources/js/views/Employee/EmployeeSalaryLeaveTab.vue#L1)
- **Gọi xuống:** [LeaveRequestService::listBalancesForEmployee()](app/Services/LeaveRequestService.php#L193)

</details>

<details>
<summary><code>public downloadEvidence()</code> — Tải tài liệu đính kèm (khám bệnh, chứng sinh...) — CHÍNH nhân viên tạo đơn, hoặc HR/Manager có leave.view_all mới xem được, giống hệt cách chặn IDOR ở EmployeeDocumentCo…</summary>

- **Mã nguồn:** [LeaveRequestController::downloadEvidence()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L101)
- **API:** `GET /api/v1/leave-requests/{leaveRequest}/evidence` — quyền `auth` ([leave-requests.php](routes/api/v1/leave-requests.php))
- **Validate:** [LeaveRequest](app/Models/LeaveRequest.php#L1)

</details>

<details>
<summary><code>public decide()</code> — Duyệt/từ chối</summary>

- **Mã nguồn:** [LeaveRequestController::decide()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L111)
- **API:** `PUT /api/v1/leave-requests/{leaveRequest}/decide` — quyền `leave.approve_manager,leave.approve_hr` ([leave-requests.php](routes/api/v1/leave-requests.php))
- **FE service:** [leaveRequestService.decide()](resources/js/services/leaveRequestService.js#L24) ← gọi từ [LeaveManagement.vue](resources/js/views/Leave/LeaveManagement.vue#L1)
- **Validate:** [DecideLeaveRequest](app/Http/Requests/Leave/DecideLeaveRequest.php#L1)
- **Validate:** [LeaveRequest](app/Models/LeaveRequest.php#L1)
- **Gọi xuống:** [LeaveApprovalService::decide()](app/Services/LeaveApprovalService.php#L27)

</details>

<details>
<summary><code>public bulkDecide()</code> — bulk decide</summary>

- **Mã nguồn:** [LeaveRequestController::bulkDecide()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L127)
- **API:** `PUT /api/v1/leave-requests/bulk-decide` — quyền `leave.approve_manager,leave.approve_hr` ([leave-requests.php](routes/api/v1/leave-requests.php))
- **FE service:** [leaveRequestService.bulkDecide()](resources/js/services/leaveRequestService.js#L28) ← gọi từ [LeaveManagement.vue](resources/js/views/Leave/LeaveManagement.vue#L1)
- **Validate:** [BulkDecideLeaveRequest](app/Http/Requests/Leave/BulkDecideLeaveRequest.php#L1)
- **Gọi xuống:** [LeaveApprovalService::bulkDecide()](app/Services/LeaveApprovalService.php#L107)

</details>

</details>

<details>
<summary><code>LeaveTypeController</code> — 1 hàm</summary>

- **Mã nguồn:** [app/Http/Controllers/Api/V1/LeaveTypeController.php](app/Http/Controllers/Api/V1/LeaveTypeController.php#L1)

<details>
<summary><code>public index()</code> — Danh sách</summary>

- **Mã nguồn:** [LeaveTypeController::index()](app/Http/Controllers/Api/V1/LeaveTypeController.php#L14)
- **API:** `GET /api/v1/leave-types` — quyền `auth` ([leave-types.php](routes/api/v1/leave-types.php))
- **FE service:** [leaveTypeService.list()](resources/js/services/leaveTypeService.js#L3) ← gọi từ [LeaveRequests.vue](resources/js/views/Leave/LeaveRequests.vue#L1)

</details>

</details>

</details>

<details>
<summary><strong>Chi tiết từng hàm Service</strong> — 10 hàm</summary>

<details>
<summary><code>LeaveAccrualService</code> — 2 hàm — "Tích lũy phép năm theo tháng làm + thưởng thâm niên" cấp THẲNG nguyên annual_entitlement…</summary>

- **Mã nguồn:** [app/Services/LeaveAccrualService.php](app/Services/LeaveAccrualService.php#L1)

<details>
<summary><code>public targetAllocatedDays()</code> — target allocated days</summary>

- **Mã nguồn:** [LeaveAccrualService::targetAllocatedDays()](app/Services/LeaveAccrualService.php#L45)
- **Được gọi bởi:** [LeaveRequestService::listBalancesForEmployee()](app/Services/LeaveRequestService.php#L193)

</details>

<details>
<summary><code>public resolveOrSyncBalance()</code> — Tạo (nếu chưa có)/đồng bộ TĂNG bản LeaveBalance của 1 nhân viên + loại phép + năm cho ĐÚNG mức "nên có" tại thời điểm asOf — AN TOÀN gọi lại nhiều lần (idempotent: chỉ T…</summary>

- **Mã nguồn:** [LeaveAccrualService::resolveOrSyncBalance()](app/Services/LeaveAccrualService.php#L110)
- **Gọi xuống:** [LeaveBalanceRepository::findOrCreateForYear()](app/Repositories/LeaveBalanceRepository.php#L14) · [LeaveBalanceRepository::update()](app/Repositories/LeaveBalanceRepository.php#L22)
- **Được gọi bởi:** [LeaveRequestService::create()](app/Services/LeaveRequestService.php#L40)

</details>

</details>

<details>
<summary><code>LeaveApprovalService</code> — 2 hàm</summary>

- **Mã nguồn:** [app/Services/LeaveApprovalService.php](app/Services/LeaveApprovalService.php#L1)

<details>
<summary><code>public decide()</code> — Luồng nhiều cấp: pending -> (cấp 1: Manager hoặc HR) -> manager_approved -> (cấp 2: HR) -> approved.</summary>

- **Mã nguồn:** [LeaveApprovalService::decide()](app/Services/LeaveApprovalService.php#L27)
- **Gọi xuống:** [LeaveApprovalRepository::create()](app/Repositories/LeaveApprovalRepository.php#L9) · [LeaveRequestService::deductBalance()](app/Services/LeaveRequestService.php#L340)
- **Hiệu ứng phụ:** [LeaveRequestChanged](app/Events/LeaveRequestChanged.php#L1), `DB::transaction`
- **Được gọi bởi:** [LeaveRequestController::decide()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L111)

</details>

<details>
<summary><code>public bulkDecide()</code> — Duyệt/Từ chối HÀNG LOẠT — lặp decide() cho TỪNG đơn, KHÔNG viết lại luật 2 cấp riêng ở đây.</summary>

- **Mã nguồn:** [LeaveApprovalService::bulkDecide()](app/Services/LeaveApprovalService.php#L107)
- **Được gọi bởi:** [LeaveRequestController::bulkDecide()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L127)

</details>

</details>

<details>
<summary><code>LeaveRequestService</code> — 6 hàm</summary>

- **Mã nguồn:** [app/Services/LeaveRequestService.php](app/Services/LeaveRequestService.php#L1)

<details>
<summary><code>public create()</code> — Quyết định nghiệp vụ đã chốt với người dùng: gửi đơn CHỈ validate còn đủ phép hay không, KHÔNG trừ used_days ở đây — trừ thật diễn ra khi đơn được DUYỆT (Ngày 37, xem de…</summary>

- **Mã nguồn:** [LeaveRequestService::create()](app/Services/LeaveRequestService.php#L40)
- **Gọi xuống:** [LeaveRequestRepository::findOverlapping()](app/Repositories/LeaveRequestRepository.php#L31) · [LeaveAccrualService::resolveOrSyncBalance()](app/Services/LeaveAccrualService.php#L110) · [LeaveRequestRepository::sumPendingDaysForYear()](app/Repositories/LeaveRequestRepository.php#L44) · [LeaveRequestRepository::create()](app/Repositories/LeaveRequestRepository.php#L12)
- **Hiệu ứng phụ:** [LeaveRequestChanged](app/Events/LeaveRequestChanged.php#L1)
- **Được gọi bởi:** [LeaveRequestController::store()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L28)

</details>

<details>
<summary><code>public listForEmployee()</code> — Lấy danh sách for employee</summary>

- **Mã nguồn:** [LeaveRequestService::listForEmployee()](app/Services/LeaveRequestService.php#L181)
- **Gọi xuống:** [LeaveRequestRepository::listForEmployee()](app/Repositories/LeaveRequestRepository.php#L17)
- **Được gọi bởi:** [LeaveRequestController::mine()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L44)

</details>

<details>
<summary><code>public listBalancesForEmployee()</code> — Quỹ phép còn lại của nhân viên, theo TỪNG loại phép đang active, cho đúng năm truyền vào (mặc định năm hiện tại).</summary>

- **Mã nguồn:** [LeaveRequestService::listBalancesForEmployee()](app/Services/LeaveRequestService.php#L193)
- **Gọi xuống:** [LeaveAccrualService::targetAllocatedDays()](app/Services/LeaveAccrualService.php#L45) · [LeaveRequestRepository::sumPendingDaysForYear()](app/Repositories/LeaveRequestRepository.php#L44)
- **Được gọi bởi:** [LeaveRequestController::balancesMine()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L82) · [LeaveRequestController::balances()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L93) · [DashboardService::personal()](app/Services/DashboardService.php#L268)

</details>

<details>
<summary><code>public overview()</code> — Tổng hợp</summary>

- **Mã nguồn:** [LeaveRequestService::overview()](app/Services/LeaveRequestService.php#L234)
- **Gọi xuống:** [LeaveRequestRepository::approvedDaysByEmployee()](app/Repositories/LeaveRequestRepository.php#L57) · [LeaveRequestRepository::pendingRequestCounts()](app/Repositories/LeaveRequestRepository.php#L69) · [LeaveRequestRepository::upcomingApproved()](app/Repositories/LeaveRequestRepository.php#L79)
- **Được gọi bởi:** [LeaveRequestController::overview()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L62)

</details>

<details>
<summary><code>public list()</code> — Lấy danh sách</summary>

- **Mã nguồn:** [LeaveRequestService::list()](app/Services/LeaveRequestService.php#L328)
- **Gọi xuống:** [LeaveRequestRepository::paginate()](app/Repositories/LeaveRequestRepository.php#L89)
- **Được gọi bởi:** [LeaveRequestController::index()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L53)

</details>

<details>
<summary><code>public deductBalance()</code> — Gọi khi 1 đơn được DUYỆT (Ngày 37) — cộng total_days vào used_days của đúng năm+loại phép, bọc lockForUpdate() chống race khi duyệt đồng thời.</summary>

- **Mã nguồn:** [LeaveRequestService::deductBalance()](app/Services/LeaveRequestService.php#L340)
- **Gọi xuống:** [LeaveBalanceRepository::findForYearLocked()](app/Repositories/LeaveBalanceRepository.php#L32)
- **Hiệu ứng phụ:** `DB::transaction`
- **Được gọi bởi:** [LeaveApprovalService::decide()](app/Services/LeaveApprovalService.php#L27)

</details>

</details>

</details>

<details>
<summary><strong>Danh sách test</strong> — 89 test</summary>

<details>
<summary><code>LeaveAccrualSyncTest.php</code> — 7 test</summary>

- **Mã nguồn:** [tests/Feature/Leave/LeaveAccrualSyncTest.php](tests/Feature/Leave/LeaveAccrualSyncTest.php#L1)
- [test_resolve_or_sync_balance_creates_row_with_correct_initial_value](tests/Feature/Leave/LeaveAccrualSyncTest.php#L50)
- [test_resolve_or_sync_balance_bumps_up_existing_row_when_target_increased](tests/Feature/Leave/LeaveAccrualSyncTest.php#L65)
- [test_resolve_or_sync_balance_never_decreases_allocated_days](tests/Feature/Leave/LeaveAccrualSyncTest.php#L84)
- [test_sync_command_creates_balances_for_active_and_probation_employees](tests/Feature/Leave/LeaveAccrualSyncTest.php#L102)
- [test_sync_command_skips_resigned_employees](tests/Feature/Leave/LeaveAccrualSyncTest.php#L118)
- [test_sync_command_skips_leave_types_without_entitlement](tests/Feature/Leave/LeaveAccrualSyncTest.php#L129)
- [test_sync_command_is_idempotent_across_repeated_runs](tests/Feature/Leave/LeaveAccrualSyncTest.php#L140)

</details>

<details>
<summary><code>LeaveApprovalTest.php</code> — 12 test</summary>

- **Mã nguồn:** [tests/Feature/Leave/LeaveApprovalTest.php](tests/Feature/Leave/LeaveApprovalTest.php#L1)
- [test_manager_can_approve_forwarding_to_hr](tests/Feature/Leave/LeaveApprovalTest.php#L93)
- [test_manager_can_reject_directly_without_going_to_hr](tests/Feature/Leave/LeaveApprovalTest.php#L123)
- [test_manager_cannot_decide_for_employee_not_their_subordinate](tests/Feature/Leave/LeaveApprovalTest.php#L145)
- [test_hr_can_finalize_after_manager_approved_and_deducts_balance](tests/Feature/Leave/LeaveApprovalTest.php#L161)
- [test_hr_can_decide_directly_when_employee_has_no_manager](tests/Feature/Leave/LeaveApprovalTest.php#L195)
- [test_manager_cannot_decide_at_hr_stage](tests/Feature/Leave/LeaveApprovalTest.php#L223)
- [test_cannot_decide_already_finalized_request](tests/Feature/Leave/LeaveApprovalTest.php#L238)
- [test_employee_without_approve_permission_cannot_decide](tests/Feature/Leave/LeaveApprovalTest.php#L253)
- [test_no_mail_sent_when_hr_gives_final_approval](tests/Feature/Leave/LeaveApprovalTest.php#L271)
- [test_no_mail_sent_when_rejected](tests/Feature/Leave/LeaveApprovalTest.php#L300)
- [test_user_without_employee_profile_can_decide_leave_request](tests/Feature/Leave/LeaveApprovalTest.php#L321)
- [test_notification_contains_reason_when_rejected_or_approved](tests/Feature/Leave/LeaveApprovalTest.php#L361)

</details>

<details>
<summary><code>LeaveBulkApprovalTest.php</code> — 4 test</summary>

- **Mã nguồn:** [tests/Feature/Leave/LeaveBulkApprovalTest.php](tests/Feature/Leave/LeaveBulkApprovalTest.php#L1)
- [test_requires_authentication](tests/Feature/Leave/LeaveBulkApprovalTest.php#L86)
- [test_manager_can_approve_multiple_subordinate_requests_at_once](tests/Feature/Leave/LeaveBulkApprovalTest.php#L93)
- [test_request_from_a_non_subordinate_fails_without_blocking_others](tests/Feature/Leave/LeaveBulkApprovalTest.php#L116)
- [test_hr_can_reject_multiple_requests_with_a_shared_comment](tests/Feature/Leave/LeaveBulkApprovalTest.php#L137)

</details>

<details>
<summary><code>LeaveOverviewTest.php</code> — 5 test</summary>

- **Mã nguồn:** [tests/Feature/Leave/LeaveOverviewTest.php](tests/Feature/Leave/LeaveOverviewTest.php#L1)
- [test_requires_authentication_and_view_all_permission](tests/Feature/Leave/LeaveOverviewTest.php#L61)
- [test_row_shows_annual_balance_other_unpaid_days_and_pending_requests](tests/Feature/Leave/LeaveOverviewTest.php#L73)
- [test_marks_who_is_on_leave_today_and_shows_the_next_leave](tests/Feature/Leave/LeaveOverviewTest.php#L98)
- [test_filters_by_department_and_summary_covers_all_matching_employees](tests/Feature/Leave/LeaveOverviewTest.php#L116)
- [test_excludes_resigned_employees_and_other_years_do_not_count](tests/Feature/Leave/LeaveOverviewTest.php#L135)

</details>

<details>
<summary><code>LeaveRequestTest.php</code> — 32 test</summary>

- **Mã nguồn:** [tests/Feature/Leave/LeaveRequestTest.php](tests/Feature/Leave/LeaveRequestTest.php#L1)
- [test_store_requires_authentication](tests/Feature/Leave/LeaveRequestTest.php#L104)
- [test_store_requires_leave_type_id_and_dates](tests/Feature/Leave/LeaveRequestTest.php#L111)
- [test_leave_type_must_be_active](tests/Feature/Leave/LeaveRequestTest.php#L121)
- [test_single_day_request_requires_matching_start_and_end_session](tests/Feature/Leave/LeaveRequestTest.php#L138)
- [test_total_days_excludes_weekend](tests/Feature/Leave/LeaveRequestTest.php#L160)
- [test_half_day_request_counts_as_half](tests/Feature/Leave/LeaveRequestTest.php#L185)
- [test_request_covering_only_weekend_is_rejected](tests/Feature/Leave/LeaveRequestTest.php#L207)
- [test_leave_type_without_entitlement_is_not_quota_limited](tests/Feature/Leave/LeaveRequestTest.php#L228)
- [test_overlapping_leave_request_is_rejected](tests/Feature/Leave/LeaveRequestTest.php#L251)
- [test_rejected_leave_request_does_not_block_overlapping_new_request](tests/Feature/Leave/LeaveRequestTest.php#L282)
- [test_multiple_pending_requests_cannot_exceed_available_quota](tests/Feature/Leave/LeaveRequestTest.php#L314)
- [test_multiple_pending_requests_within_available_quota_still_succeed](tests/Feature/Leave/LeaveRequestTest.php#L345)
- [test_request_exceeding_remaining_balance_is_rejected](tests/Feature/Leave/LeaveRequestTest.php#L374)
- [test_creating_request_does_not_deduct_balance_yet](tests/Feature/Leave/LeaveRequestTest.php#L396)
- [test_employee_can_list_own_leave_requests](tests/Feature/Leave/LeaveRequestTest.php#L418)
- [test_hr_can_list_all_leave_requests](tests/Feature/Leave/LeaveRequestTest.php#L439)
- [test_employee_cannot_list_all_leave_requests](tests/Feature/Leave/LeaveRequestTest.php#L448)
- [test_balances_mine_requires_authentication](tests/Feature/Leave/LeaveRequestTest.php#L458)
- [test_balances_mine_shows_full_entitlement_when_no_balance_row_yet](tests/Feature/Leave/LeaveRequestTest.php#L468)
- [test_balances_mine_prorates_annual_leave_for_employee_hired_this_year](tests/Feature/Leave/LeaveRequestTest.php#L487)
- [test_balances_mine_shows_one_accrued_day_after_30_days_worked](tests/Feature/Leave/LeaveRequestTest.php#L502)
- [test_balances_mine_reflects_used_days](tests/Feature/Leave/LeaveRequestTest.php#L515)
- [test_balances_mine_shows_pending_and_available_days](tests/Feature/Leave/LeaveRequestTest.php#L536)
- [test_balances_for_employee_requires_leave_view_all_permission](tests/Feature/Leave/LeaveRequestTest.php#L562)
- [test_hr_can_view_balances_for_any_employee](tests/Feature/Leave/LeaveRequestTest.php#L575)
- [test_hourly_request_calculates_fraction_of_day](tests/Feature/Leave/LeaveRequestTest.php#L600)
- [test_hourly_request_across_two_days_is_rejected](tests/Feature/Leave/LeaveRequestTest.php#L631)
- [test_hourly_request_on_weekend_is_rejected](tests/Feature/Leave/LeaveRequestTest.php#L652)
- [test_other_regulated_leave_requires_evidence_file](tests/Feature/Leave/LeaveRequestTest.php#L677)
- [test_other_regulated_leave_with_evidence_file_succeeds_and_stores_file](tests/Feature/Leave/LeaveRequestTest.php#L694)
- [test_evidence_download_is_restricted_to_owner_or_hr](tests/Feature/Leave/LeaveRequestTest.php#L715)
- [test_cannot_create_leave_request_when_employee_has_no_shift_assignment](tests/Feature/Leave/LeaveRequestTest.php#L747)

</details>

<details>
<summary><code>LeaveTypeTest.php</code> — 3 test</summary>

- **Mã nguồn:** [tests/Feature/Leave/LeaveTypeTest.php](tests/Feature/Leave/LeaveTypeTest.php#L1)
- [test_index_requires_authentication](tests/Feature/Leave/LeaveTypeTest.php#L34)
- [test_index_returns_only_active_leave_types](tests/Feature/Leave/LeaveTypeTest.php#L41)
- [test_index_includes_annual_entitlement_days_and_is_paid](tests/Feature/Leave/LeaveTypeTest.php#L68)

</details>

<details>
<summary><code>LeaveAccrualServiceTest.php</code> — 13 test</summary>

- **Mã nguồn:** [tests/Unit/Services/LeaveAccrualServiceTest.php](tests/Unit/Services/LeaveAccrualServiceTest.php#L1)
- [test_hire_year_accrual_is_zero_on_the_hire_day](tests/Unit/Services/LeaveAccrualServiceTest.php#L39)
- [test_hire_year_accrual_is_still_zero_before_30_days_worked](tests/Unit/Services/LeaveAccrualServiceTest.php#L49)
- [test_hire_year_accrual_earns_first_day_after_exactly_30_days_worked](tests/Unit/Services/LeaveAccrualServiceTest.php#L60)
- [test_hire_year_accrual_grows_every_30_days_worked](tests/Unit/Services/LeaveAccrualServiceTest.php#L71)
- [test_hire_year_accrual_reaches_full_entitlement_by_year_end_when_hired_january_first](tests/Unit/Services/LeaveAccrualServiceTest.php#L82)
- [test_hire_year_accrual_never_exceeds_annual_entitlement](tests/Unit/Services/LeaveAccrualServiceTest.php#L93)
- [test_established_employee_gets_full_entitlement_immediately_without_seniority](tests/Unit/Services/LeaveAccrualServiceTest.php#L106)
- [test_seniority_bonus_not_applied_before_anniversary_date_in_that_year](tests/Unit/Services/LeaveAccrualServiceTest.php#L117)
- [test_seniority_bonus_applies_starting_exact_anniversary_day](tests/Unit/Services/LeaveAccrualServiceTest.php#L128)
- [test_ten_years_of_service_adds_two_extra_days](tests/Unit/Services/LeaveAccrualServiceTest.php#L138)
- [test_used_up_entitlement_is_not_replenished_mid_year_for_established_employee](tests/Unit/Services/LeaveAccrualServiceTest.php#L148)
- [test_leave_type_without_entitlement_always_returns_zero](tests/Unit/Services/LeaveAccrualServiceTest.php#L169)
- [test_year_before_hire_year_returns_zero](tests/Unit/Services/LeaveAccrualServiceTest.php#L179)

</details>

<details>
<summary><code>LeaveRequestServiceCalculationTest.php</code> — 13 test</summary>

- **Mã nguồn:** [tests/Unit/Services/LeaveRequestServiceCalculationTest.php](tests/Unit/Services/LeaveRequestServiceCalculationTest.php#L1)
- [test_single_full_day_counts_as_one](tests/Unit/Services/LeaveRequestServiceCalculationTest.php#L28)
- [test_single_half_day_counts_as_half](tests/Unit/Services/LeaveRequestServiceCalculationTest.php#L40)
- [test_full_work_week_counts_all_five_days](tests/Unit/Services/LeaveRequestServiceCalculationTest.php#L53)
- [test_range_spanning_weekend_excludes_saturday_and_sunday](tests/Unit/Services/LeaveRequestServiceCalculationTest.php#L66)
- [test_range_entirely_on_weekend_counts_zero](tests/Unit/Services/LeaveRequestServiceCalculationTest.php#L79)
- [test_half_day_start_session_only_affects_first_day](tests/Unit/Services/LeaveRequestServiceCalculationTest.php#L92)
- [test_half_day_end_session_only_affects_last_day](tests/Unit/Services/LeaveRequestServiceCalculationTest.php#L105)
- [test_two_hours_is_a_quarter_day](tests/Unit/Services/LeaveRequestServiceCalculationTest.php#L118)
- [test_four_hours_is_half_a_day](tests/Unit/Services/LeaveRequestServiceCalculationTest.php#L128)
- [test_full_eight_hours_is_one_day](tests/Unit/Services/LeaveRequestServiceCalculationTest.php#L138)
- [test_remaining_days_basic_arithmetic](tests/Unit/Services/LeaveRequestServiceCalculationTest.php#L150)
- [test_remaining_days_rounds_to_two_decimals](tests/Unit/Services/LeaveRequestServiceCalculationTest.php#L165)
- [test_remaining_days_can_go_negative_when_over_used](tests/Unit/Services/LeaveRequestServiceCalculationTest.php#L180)

</details>

</details>

---

## 9. Nghỉ việc

- **Điểm vào:** `/resignations`, form trong `/my-profile`; API [`resignations.php`](routes/api/v1/resignations.php).
- **Danh mục file & hàm:** xem [cuối mục này](#danh-mục-file--hàm-module-9) — mỗi file và mỗi hàm public có link riêng.


**Luồng chính**

| Thao tác | Luồng |
| --- | --- |
| Nộp đơn | `MyProfile.vue → resignationService.policy → GET /resignations/policy (số ngày báo trước + earliest_last_working_date) → resignationService.create → POST /resignations → ResignationService::create (chỉ Thử việc/Chính thức, không đơn trùng, ngày cuối ≥ hôm nay) → báo đủ: status notified (requires_approval=false) \| thiếu: pending → báo HR (resignation.view_all) + quản lý trực tiếp (resignation.notice / resignation.pending) + ResourceChanged('resignations')` |
| Duyệt | `Resignations.vue (Xem & duyệt) → GET /resignations/{id} (can_decide) → PUT /resignations/{id}/decide → ResignationService::decide (Manager chỉ đơn cấp dưới trực tiếp; từ chối bắt buộc lý do; đơn notified → 422) → approved → applyIfDue → báo nhân viên (resignation.decided)` |
| Có hiệu lực | `applyIfDue (đã qua ngày cuối) hoặc resignations:apply (00:08) → Employee resigned + termination_date + chấm dứt HĐ active/pending (cập nhật thẳng, không qua terminate()) + EmployeeAccountService::deactivateAccountOf` |

**Luồng demo nhanh:** Nhân viên vào `/my-profile` → "Nộp đơn nghỉ việc": chọn ngày cuối đủ ngày báo trước → nút đổi thành "Gửi thông báo" (không cần duyệt); chọn thiếu ngày → phải chờ HR/Manager duyệt ở `/resignations`.

**Quy tắc & bẫy**
- Báo trước (BLLĐ 2019 Điều 35, từ hợp đồng đang hiệu lực): thử việc 0 ngày; không xác định thời hạn 45; xác định thời hạn 12–36 tháng 30; dưới 12 tháng 3 (tính ngày lịch); chưa có hợp đồng → 45. Báo đủ → chỉ thông báo, không duyệt; báo thiếu → chờ duyệt. Các cột `notice_days_*` lưu lại để đơn cũ không đổi nghĩa. Số ngày là quy định pháp luật — kiểm lại khi luật đổi; ngoại lệ Điều 35 khoản 2 chưa xử lý.
- Quyền `resignation.*` thêm vào DB đang chạy bằng migration chỉ-thêm, **không chạy lại `RolePermissionSeeder`**.
- Mặc định trang lọc "Chờ duyệt"; đơn "Đã thông báo" xem qua chuông hoặc đổi bộ lọc. HR (không có `resignation.request`) chưa tự nộp đơn. Chưa có mục đơn nghỉ việc ở Chi tiết nhân viên.

**Liên thông:** khi có hiệu lực → trạng thái nhân viên + khóa tài khoản (module 1, 4); loại khỏi bảng lương kỳ sau (module 10).

<a id="danh-mục-file--hàm-module-9"></a>

### Danh mục file & hàm — module 9

#### Giao diện (2 file)

- [Resignations.vue](resources/js/views/Resignation/Resignations.vue#L1) — route `/resignations`
- [resignationStatus.js](resources/js/composables/resignationStatus.js#L1)

#### Controller (1 file, 7 hàm public)

- [ResignationRequestController.php](app/Http/Controllers/Api/V1/ResignationRequestController.php#L1) — 7 hàm: [store()](app/Http/Controllers/Api/V1/ResignationRequestController.php#L21) · [policy()](app/Http/Controllers/Api/V1/ResignationRequestController.php#L44) · [mine()](app/Http/Controllers/Api/V1/ResignationRequestController.php#L53) · [cancel()](app/Http/Controllers/Api/V1/ResignationRequestController.php#L62) · [index()](app/Http/Controllers/Api/V1/ResignationRequestController.php#L73) · [show()](app/Http/Controllers/Api/V1/ResignationRequestController.php#L81) · [decide()](app/Http/Controllers/Api/V1/ResignationRequestController.php#L90)

#### Service (1 file, 10 hàm public)

- [ResignationService.php](app/Services/ResignationService.php#L1) — 10 hàm: [listForEmployee()](app/Services/ResignationService.php#L39) · [list()](app/Services/ResignationService.php#L44) · [findVisible()](app/Services/ResignationService.php#L58) · [noticePolicy()](app/Services/ResignationService.php#L73) · [create()](app/Services/ResignationService.php#L98) · [cancel()](app/Services/ResignationService.php#L138) · [decide()](app/Services/ResignationService.php#L157) · [canDecide()](app/Services/ResignationService.php#L197) · [applyIfDue()](app/Services/ResignationService.php#L217) · [applyAllDue()](app/Services/ResignationService.php#L252)

#### Model (1 file)

- [ResignationRequest.php](app/Models/ResignationRequest.php#L1)

#### Request & Resource (1 file)

- [ResignationRequestResource.php](app/Http/Resources/ResignationRequestResource.php#L1)

#### Lệnh, Event, Middleware, hạ tầng BE (1 file)

- [ApplyResignations.php](app/Console/Commands/ApplyResignations.php#L1) — Chạy hằng ngày (routes/console.php) — đơn nghỉ việc ĐÃ DUYỆT mà đã qua ngày làm việc cuối thì chuyển nhân viê…

#### Migration (3 file)

- [2026_09_29_000001_create_resignation_requests_table.php](database/migrations/2026_09_29_000001_create_resignation_requests_table.php)
- [2026_09_29_000002_add_resignation_permissions.php](database/migrations/2026_09_29_000002_add_resignation_permissions.php)
- [2026_09_30_000002_add_notice_fields_to_resignation_requests_table.php](database/migrations/2026_09_30_000002_add_notice_fields_to_resignation_requests_table.php)

#### Kiểm thử (1 file, 18 test)

- [ResignationTest.php](tests/Feature/ResignationTest.php#L1) — 18 test

<details>
<summary><strong>Chi tiết từng màn hình Vue</strong> — 2 file</summary>

<details>
<summary><code>Resignations.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Resignation/Resignations.vue](resources/js/views/Resignation/Resignations.vue#L1)
- **Route FE:** `/resignations`
- **Gọi API:**
  - `resignationService.list()` → `GET /resignations` → [ResignationRequestController::index()](app/Http/Controllers/Api/V1/ResignationRequestController.php#L73)
  - `resignationService.show()` → `GET /resignations/{x}` → [ResignationRequestController::show()](app/Http/Controllers/Api/V1/ResignationRequestController.php#L81)
  - `resignationService.decide()` → `PUT /resignations/{x}/decide` → [ResignationRequestController::decide()](app/Http/Controllers/Api/V1/ResignationRequestController.php#L90)
- **Store dùng:** `useResourceSyncStore`
- **Component con:** [PageHeader.vue](resources/js/components/common/PageHeader.vue#L1), [DataTable.vue](resources/js/components/common/DataTable.vue#L1), [SearchField.vue](resources/js/components/common/SearchField.vue#L1), [StatusChip.vue](resources/js/components/common/StatusChip.vue#L1)

</details>

<details>
<summary><code>resignationStatus.js</code></summary>

- **Mã nguồn:** [resources/js/composables/resignationStatus.js](resources/js/composables/resignationStatus.js#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).

</details>

</details>

<details>
<summary><strong>Chi tiết từng hàm Controller</strong> — 7 hàm</summary>

<details>
<summary><code>ResignationRequestController</code> — 7 hàm</summary>

- **Mã nguồn:** [app/Http/Controllers/Api/V1/ResignationRequestController.php](app/Http/Controllers/Api/V1/ResignationRequestController.php#L1)

<details>
<summary><code>public store()</code> — Luôn nộp cho CHÍNH người gọi API — không nhận employee_id từ client.</summary>

- **Mã nguồn:** [ResignationRequestController::store()](app/Http/Controllers/Api/V1/ResignationRequestController.php#L21)
- **API:** `POST /api/v1/resignations` — quyền `resignation.request` ([resignations.php](routes/api/v1/resignations.php))
- **FE service:** [resignationService.create()](resources/js/services/resignationService.js#L8) ← gọi từ [MyProfile.vue](resources/js/views/Me/MyProfile.vue#L1)
- **Gọi xuống:** [ResignationService::create()](app/Services/ResignationService.php#L98)

</details>

<details>
<summary><code>public policy()</code> — Nhân viên xem TRƯỚC khi nộp: phải báo trước tối thiểu bao nhiêu ngày và ngày làm việc cuối sớm nhất để đơn chỉ cần "thông báo" (không cần duyệt).</summary>

- **Mã nguồn:** [ResignationRequestController::policy()](app/Http/Controllers/Api/V1/ResignationRequestController.php#L44)
- **API:** `GET /api/v1/resignations/policy` — quyền `resignation.request` ([resignations.php](routes/api/v1/resignations.php))
- **FE service:** [resignationService.policy()](resources/js/services/resignationService.js#L12) ← gọi từ [MyProfile.vue](resources/js/views/Me/MyProfile.vue#L1)
- **Gọi xuống:** [ResignationService::noticePolicy()](app/Services/ResignationService.php#L73)

</details>

<details>
<summary><code>public mine()</code> — Dữ liệu của chính người đăng nhập</summary>

- **Mã nguồn:** [ResignationRequestController::mine()](app/Http/Controllers/Api/V1/ResignationRequestController.php#L53)
- **API:** `GET /api/v1/resignations/me` — quyền `resignation.request` ([resignations.php](routes/api/v1/resignations.php))
- **FE service:** [resignationService.mine()](resources/js/services/resignationService.js#L15) ← gọi từ [MyProfile.vue](resources/js/views/Me/MyProfile.vue#L1)
- **Gọi xuống:** [ResignationService::listForEmployee()](app/Services/ResignationService.php#L39)

</details>

<details>
<summary><code>public cancel()</code> — Hủy</summary>

- **Mã nguồn:** [ResignationRequestController::cancel()](app/Http/Controllers/Api/V1/ResignationRequestController.php#L62)
- **API:** `POST /api/v1/resignations/{resignationRequest}/cancel` — quyền `resignation.request` ([resignations.php](routes/api/v1/resignations.php))
- **FE service:** [resignationService.cancel()](resources/js/services/resignationService.js#L18) ← gọi từ [MyProfile.vue](resources/js/views/Me/MyProfile.vue#L1)
- **Validate:** [ResignationRequest](app/Models/ResignationRequest.php#L1)
- **Gọi xuống:** [ResignationService::cancel()](app/Services/ResignationService.php#L138)

</details>

<details>
<summary><code>public index()</code> — HR thấy mọi đơn, Manager chỉ đơn của nhân viên mình quản lý trực tiếp — lọc bên trong ResignationService (không chỉ ẩn ở Frontend).</summary>

- **Mã nguồn:** [ResignationRequestController::index()](app/Http/Controllers/Api/V1/ResignationRequestController.php#L73)
- **API:** `GET /api/v1/resignations` — quyền `resignation.approve` ([resignations.php](routes/api/v1/resignations.php))
- **FE service:** [resignationService.list()](resources/js/services/resignationService.js#L22) ← gọi từ [Resignations.vue](resources/js/views/Resignation/Resignations.vue#L1)
- **Gọi xuống:** [ResignationService::list()](app/Services/ResignationService.php#L44)

</details>

<details>
<summary><code>public show()</code> — Xem chi tiết</summary>

- **Mã nguồn:** [ResignationRequestController::show()](app/Http/Controllers/Api/V1/ResignationRequestController.php#L81)
- **API:** `GET /api/v1/resignations/{id}` — quyền `resignation.approve` ([resignations.php](routes/api/v1/resignations.php))
- **FE service:** [resignationService.show()](resources/js/services/resignationService.js#L25) ← gọi từ [Resignations.vue](resources/js/views/Resignation/Resignations.vue#L1)
- **Gọi xuống:** [ResignationService::findVisible()](app/Services/ResignationService.php#L58) · [ResignationService::canDecide()](app/Services/ResignationService.php#L197)

</details>

<details>
<summary><code>public decide()</code> — Duyệt/từ chối</summary>

- **Mã nguồn:** [ResignationRequestController::decide()](app/Http/Controllers/Api/V1/ResignationRequestController.php#L90)
- **API:** `PUT /api/v1/resignations/{id}/decide` — quyền `resignation.approve` ([resignations.php](routes/api/v1/resignations.php))
- **FE service:** [resignationService.decide()](resources/js/services/resignationService.js#L28) ← gọi từ [Resignations.vue](resources/js/views/Resignation/Resignations.vue#L1)
- **Gọi xuống:** [ResignationService::findVisible()](app/Services/ResignationService.php#L58) · [ResignationService::decide()](app/Services/ResignationService.php#L157)

</details>

</details>

</details>

<details>
<summary><strong>Chi tiết từng hàm Service</strong> — 10 hàm</summary>

<details>
<summary><code>ResignationService</code> — 10 hàm — Đơn xin nghỉ việc . - Ai duyệt: có resignation.view_all (HR/Admin) duyệt mọi đơn; còn lại…</summary>

- **Mã nguồn:** [app/Services/ResignationService.php](app/Services/ResignationService.php#L1)

<details>
<summary><code>public listForEmployee()</code> — Lấy danh sách for employee</summary>

- **Mã nguồn:** [ResignationService::listForEmployee()](app/Services/ResignationService.php#L39)
- **Được gọi bởi:** [ResignationRequestController::mine()](app/Http/Controllers/Api/V1/ResignationRequestController.php#L53)

</details>

<details>
<summary><code>public list()</code> — Lấy danh sách</summary>

- **Mã nguồn:** [ResignationService::list()](app/Services/ResignationService.php#L44)
- **Được gọi bởi:** [ResignationRequestController::index()](app/Http/Controllers/Api/V1/ResignationRequestController.php#L73)

</details>

<details>
<summary><code>public findVisible()</code> — Tìm visible</summary>

- **Mã nguồn:** [ResignationService::findVisible()](app/Services/ResignationService.php#L58)
- **Được gọi bởi:** [ResignationRequestController::show()](app/Http/Controllers/Api/V1/ResignationRequestController.php#L81) · [ResignationRequestController::decide()](app/Http/Controllers/Api/V1/ResignationRequestController.php#L90)

</details>

<details>
<summary><code>public noticePolicy()</code> — Số ngày báo trước TỐI THIỂU theo hợp đồng đang hiệu lực (BLLĐ 2019 Điều 35; thử việc theo Điều 27): thử việc không cần báo trước; không xác định thời hạn (không có ngày …</summary>

- **Mã nguồn:** [ResignationService::noticePolicy()](app/Services/ResignationService.php#L73)
- **Được gọi bởi:** [ResignationRequestController::policy()](app/Http/Controllers/Api/V1/ResignationRequestController.php#L44)

</details>

<details>
<summary><code>public create()</code> — Tạo</summary>

- **Mã nguồn:** [ResignationService::create()](app/Services/ResignationService.php#L98)
- **Được gọi bởi:** [ResignationRequestController::store()](app/Http/Controllers/Api/V1/ResignationRequestController.php#L21)

</details>

<details>
<summary><code>public cancel()</code> — Hủy</summary>

- **Mã nguồn:** [ResignationService::cancel()](app/Services/ResignationService.php#L138)
- **Được gọi bởi:** [ResignationRequestController::cancel()](app/Http/Controllers/Api/V1/ResignationRequestController.php#L62)

</details>

<details>
<summary><code>public decide()</code> — Duyệt/từ chối</summary>

- **Mã nguồn:** [ResignationService::decide()](app/Services/ResignationService.php#L157)
- **Hiệu ứng phụ:** `DB::transaction`
- **Được gọi bởi:** [ResignationRequestController::decide()](app/Http/Controllers/Api/V1/ResignationRequestController.php#L90)

</details>

<details>
<summary><code>public canDecide()</code> — Kiểm tra được phép decide</summary>

- **Mã nguồn:** [ResignationService::canDecide()](app/Services/ResignationService.php#L197)
- **Được gọi bởi:** [ResignationRequestController::show()](app/Http/Controllers/Api/V1/ResignationRequestController.php#L81)

</details>

<details>
<summary><code>public applyIfDue()</code> — Áp dụng if due</summary>

- **Mã nguồn:** [ResignationService::applyIfDue()](app/Services/ResignationService.php#L217)
- **Gọi xuống:** [EmployeeAccountService::deactivateAccountOf()](app/Services/EmployeeAccountService.php#L94)
- **Hiệu ứng phụ:** `Realtime` (tín hiệu realtime), `DB::transaction`

</details>

<details>
<summary><code>public applyAllDue()</code> — Áp dụng all due</summary>

- **Mã nguồn:** [ResignationService::applyAllDue()](app/Services/ResignationService.php#L252)

</details>

</details>

</details>

<details>
<summary><strong>Danh sách test</strong> — 18 test</summary>

<details>
<summary><code>ResignationTest.php</code> — 18 test</summary>

- **Mã nguồn:** [tests/Feature/ResignationTest.php](tests/Feature/ResignationTest.php#L1)
- [test_employee_submits_request_and_hr_is_notified](tests/Feature/ResignationTest.php#L62)
- [test_cannot_submit_second_request_while_one_is_open](tests/Feature/ResignationTest.php#L78)
- [test_last_working_date_cannot_be_in_the_past](tests/Feature/ResignationTest.php#L89)
- [test_employee_can_cancel_own_pending_request](tests/Feature/ResignationTest.php#L100)
- [test_plain_employee_cannot_list_or_decide](tests/Feature/ResignationTest.php#L112)
- [test_manager_only_sees_and_decides_requests_of_direct_reports](tests/Feature/ResignationTest.php#L121)
- [test_detail_contains_full_employee_information_for_review](tests/Feature/ResignationTest.php#L140)
- [test_approving_after_last_working_date_marks_employee_resigned_immediately](tests/Feature/ResignationTest.php#L154)
- [test_approved_future_request_is_applied_by_daily_job_after_last_working_date](tests/Feature/ResignationTest.php#L176)
- [test_reject_requires_reason_and_keeps_employee_status](tests/Feature/ResignationTest.php#L195)
- [test_cannot_decide_twice](tests/Feature/ResignationTest.php#L210)
- [test_indefinite_contract_with_45_days_notice_is_only_a_notice](tests/Feature/ResignationTest.php#L240)
- [test_indefinite_contract_with_short_notice_waits_for_approval](tests/Feature/ResignationTest.php#L257)
- [test_fixed_term_contract_notice_days_follow_the_contract_length](tests/Feature/ResignationTest.php#L271)
- [test_probation_contract_needs_no_advance_notice](tests/Feature/ResignationTest.php#L282)
- [test_policy_endpoint_tells_the_employee_the_earliest_last_working_date](tests/Feature/ResignationTest.php#L292)
- [test_notice_only_request_cannot_be_decided_but_can_be_withdrawn](tests/Feature/ResignationTest.php#L303)
- [test_notice_only_request_makes_the_employee_resigned_after_the_last_day](tests/Feature/ResignationTest.php#L317)

</details>

</details>

---

## 10. Bảng lương

**Vai trò:** tạo bảng lương theo kỳ, phiếu lương từng người, xuất PDF, kiểm tra ngày công.

- **Điểm vào:** `/payrolls`, `/payrolls/:id`, tab phiếu lương ở `/my-profile`; API [`payrolls.php`](routes/api/v1/payrolls.php).
- **Danh mục file & hàm:** xem [cuối mục này](#danh-mục-file--hàm-module-10) — mỗi file và mỗi hàm public có link riêng.


**Luồng chính**

| Thao tác | Luồng |
| --- | --- |
| Tạo bảng lương | `PayrollGenerateDialog.vue → payrollService.generate → POST /payrolls/generate → payroll.manage → GeneratePayrollRequest → PayrollService::generateForPeriod (chặn 422 nếu kỳ còn bản ghi đã chấm ra chưa duyệt; chặn trùng kỳ) → mỗi nhân viên active/probation có hợp đồng: contractDuringPeriod + calculateWorkedMetrics (chỉ approved) + leaveDaysFor + insuranceApplies + PersonalIncomeTaxCalculator → Payroll (processing) + PayrollDetail → ResourceChanged('payrolls') + tín hiệu riêng từng nhân viên` |
| Chốt / đã trả | `PayrollList.vue → POST /payrolls/{id}/close → PayrollService::close (processing → closed)`; `POST /{id}/mark-paid (closed → paid)` |
| Xem phiếu lương | `PayrollDetail.vue → GET /payrolls/{id} → PayrollDetailResource (kèm position_name/department_name) → PayrollPayslipDialog.vue (mẫu I–IV, Xuất PDF)`; nhân viên: `MyProfilePayslipsTab.vue → GET /payrolls/me` |
| Kiểm tra ngày công | `PayrollDetail.vue → PayrollWorkdaysDialog.vue → payrollService.workdays(payrollId, detailId) → GET /payrolls/{p}/details/{d}/workdays → PayrollController::workdays → PayrollService::workdayBreakdown (dựng lại theo cùng quy tắc, liệt kê cả bản ghi không tính kèm lý do pending/rejected/shift_deleted; summary.matches_payslip)` |

**Luồng demo nhanh:** HR vào `/payrolls` → Tạo bảng lương tháng có dữ liệu (đã duyệt hết chấm công) → mở chi tiết → "Kiểm tra ngày công" ở 1 nhân viên: banner xanh "Khớp"; "Xem phiếu lương" → Xuất PDF → Chốt bảng lương → Đã trả.

**Quy tắc & bẫy**
- `standard_work_days` = T2–T6 của tháng trừ ngày lễ rơi ngày thường (mỗi tháng một số). `dailyRate = lương HĐ / ngày chuẩn`; `base_salary = dailyRate × min(chuẩn, công thực tế + ngày phép có lương)` (làm tròn 0,01); `gross = base + OT`; `net = gross − bảo hiểm − thuế`; `total_payroll_amount` = tổng `net`. OT đồng giá 150%. Bảng `holidays` còn rỗng nên ngày lễ chưa được trừ.
- OT chỉ trả khi `overtime_approved` **và** `overtime_minutes ≥ OVERTIME_MINIMUM_PAYABLE_MINUTES = 25` (= 30 phút thực tế trước khi trừ ân hạn 5 phút); không đạt thì `overtime_minutes` và `overtime_amount` của `PayrollDetail` đều 0 (cột gốc ở `attendances` giữ số thực). Đơn giá giờ OT theo `standard_work_minutes` của ca hôm đó.
- Bảo hiểm 10,5% × `insurance_salary` chỉ khi ≥ 14 ngày hưởng lương (công thực tế + phép có lương), nghỉ không lương < 14 ngày, hợp đồng có `end_date` dài ≥ 1 tháng, và **không phải** hợp đồng thử việc (`thu_viec`). Chưa xử lý thai sản. BHXH/BHYT/BHTN gộp 1 số `insurance_amount`.
- Bảng lương tạo **1 lần/kỳ** (processing → closed → paid), **không tự tính lại** khi chấm công đổi; "Không khớp" ở dialog kiểm tra nghĩa là dữ liệu đã đổi sau lúc tính. Ca đã xóa mềm → 0 công (`workShift` null).
- `contractDuringPeriod()` lọc theo ngày hiệu lực của kỳ, không theo `status` hiện tại. `generate()` không trả kèm `details` (khác `show()`). `PayrollDetail` phải khai `overtime_amount` trong `$fillable`/`$casts`. `Payroll::createdBy()/closedBy()` truyền tay tên cột.
- PDF: không dùng `fontStyle: "bold"/"italic"` cho ô tiếng Việt (chỉ nhúng style normal — rơi về Helvetica làm vỡ dấu); nhấn mạnh bằng `fillColor`/`textColor`.
- Giả định cần xác nhận: bậc thuế TNCN, giảm trừ bản thân 11tr, bảo hiểm 10,5% là số liệu pháp luật; chưa trừ giảm trừ người phụ thuộc; chưa có CRUD `salary_components`/`holidays`; các khoản chi tiết (ăn trưa, thưởng KPI, tạm ứng) hiện gộp vào `total_allowance`/`overtime_amount`.

**Liên thông:** đọc chấm công `approved` (module 6), nghỉ phép có/không lương (module 8), hợp đồng (module 4), ca (module 5); nhân viên nghỉ việc loại khỏi kỳ sau (module 9).

<a id="danh-mục-file--hàm-module-10"></a>

### Danh mục file & hàm — module 10

#### Giao diện (5 file)

- [PayrollDetail.vue](resources/js/views/Payroll/PayrollDetail.vue#L1) — route `/payrolls/:id`
- [PayrollGenerateDialog.vue](resources/js/views/Payroll/PayrollGenerateDialog.vue#L1)
- [PayrollList.vue](resources/js/views/Payroll/PayrollList.vue#L1) — route `/payrolls`
- [PayrollPayslipDialog.vue](resources/js/views/Payroll/PayrollPayslipDialog.vue#L1)
- [PayrollWorkdaysDialog.vue](resources/js/views/Payroll/PayrollWorkdaysDialog.vue#L1)

#### Controller (1 file, 8 hàm public)

- [PayrollController.php](app/Http/Controllers/Api/V1/PayrollController.php#L1) — 8 hàm: [index()](app/Http/Controllers/Api/V1/PayrollController.php#L23) · [mine()](app/Http/Controllers/Api/V1/PayrollController.php#L31) · [forEmployee()](app/Http/Controllers/Api/V1/PayrollController.php#L41) · [workdays()](app/Http/Controllers/Api/V1/PayrollController.php#L47) · [show()](app/Http/Controllers/Api/V1/PayrollController.php#L54) · [generate()](app/Http/Controllers/Api/V1/PayrollController.php#L59) · [close()](app/Http/Controllers/Api/V1/PayrollController.php#L75) · [markAsPaid()](app/Http/Controllers/Api/V1/PayrollController.php#L80)

#### Service (2 file, 9 hàm public)

- [PersonalIncomeTaxCalculator.php](app/Services/Payroll/PersonalIncomeTaxCalculator.php#L1) — 1 hàm: [calculate()](app/Services/Payroll/PersonalIncomeTaxCalculator.php#L26)
- [PayrollService.php](app/Services/PayrollService.php#L1) — 8 hàm: [generateForPeriod()](app/Services/PayrollService.php#L58) · [standardWorkDaysFor()](app/Services/PayrollService.php#L280) · [workdayBreakdown()](app/Services/PayrollService.php#L304) · [list()](app/Services/PayrollService.php#L405) · [listPayslipsForEmployee()](app/Services/PayrollService.php#L413) · [find()](app/Services/PayrollService.php#L418) · [close()](app/Services/PayrollService.php#L423) · [markAsPaid()](app/Services/PayrollService.php#L441)

#### Repository (2 file)

- [PayrollDetailRepository.php](app/Repositories/PayrollDetailRepository.php#L1) — [createForPayroll()](app/Repositories/PayrollDetailRepository.php#L12) · [listForPayroll()](app/Repositories/PayrollDetailRepository.php#L17) · [listForEmployee()](app/Repositories/PayrollDetailRepository.php#L27)
- [PayrollRepository.php](app/Repositories/PayrollRepository.php#L1) — [paginate()](app/Repositories/PayrollRepository.php#L10) · [find()](app/Repositories/PayrollRepository.php#L20) · [findByPeriod()](app/Repositories/PayrollRepository.php#L25) · [create()](app/Repositories/PayrollRepository.php#L30) · [update()](app/Repositories/PayrollRepository.php#L35)

#### Model (4 file)

- [Payroll.php](app/Models/Payroll.php#L1)
- [PayrollDetail.php](app/Models/PayrollDetail.php#L1)
- [PayrollDetailComponent.php](app/Models/PayrollDetailComponent.php#L1)
- [SalaryComponent.php](app/Models/SalaryComponent.php#L1)

#### Request & Resource (3 file)

- [GeneratePayrollRequest.php](app/Http/Requests/Payroll/GeneratePayrollRequest.php#L1)
- [PayrollDetailResource.php](app/Http/Resources/PayrollDetailResource.php#L1)
- [PayrollResource.php](app/Http/Resources/PayrollResource.php#L1)

#### Migration (6 file)

- [2026_01_04_000001_create_salary_components_table.php](database/migrations/2026_01_04_000001_create_salary_components_table.php)
- [2026_01_04_000002_create_payrolls_table.php](database/migrations/2026_01_04_000002_create_payrolls_table.php)
- [2026_01_04_000003_create_payroll_details_table.php](database/migrations/2026_01_04_000003_create_payroll_details_table.php)
- [2026_01_04_000004_create_payroll_detail_components_table.php](database/migrations/2026_01_04_000004_create_payroll_detail_components_table.php)
- [2026_09_14_000001_add_soft_deletes_to_salary_components_table.php](database/migrations/2026_09_14_000001_add_soft_deletes_to_salary_components_table.php)
- [2026_09_16_000002_add_overtime_amount_to_payroll_details_table.php](database/migrations/2026_09_16_000002_add_overtime_amount_to_payroll_details_table.php)

#### Kiểm thử (3 file, 55 test)

- [PayrollControllerTest.php](tests/Feature/Payroll/PayrollControllerTest.php#L1) — 19 test
- [PayrollTest.php](tests/Feature/Payroll/PayrollTest.php#L1) — 30 test
- [PersonalIncomeTaxCalculatorTest.php](tests/Unit/Services/Payroll/PersonalIncomeTaxCalculatorTest.php#L1) — 6 test

<details>
<summary><strong>Chi tiết từng màn hình Vue</strong> — 5 file</summary>

<details>
<summary><code>PayrollDetail.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Payroll/PayrollDetail.vue](resources/js/views/Payroll/PayrollDetail.vue#L1)
- **Route FE:** `/payrolls/:id`
- **Gọi API:**
  - `payrollService.show()` → `GET /payrolls/{x}` → [PayrollController::show()](app/Http/Controllers/Api/V1/PayrollController.php#L54)
- **Component con:** [DataTable.vue](resources/js/components/common/DataTable.vue#L1), [PageHeader.vue](resources/js/components/common/PageHeader.vue#L1), [PayrollPayslipDialog.vue](resources/js/views/Payroll/PayrollPayslipDialog.vue#L1), [PayrollWorkdaysDialog.vue](resources/js/views/Payroll/PayrollWorkdaysDialog.vue#L1)

</details>

<details>
<summary><code>PayrollGenerateDialog.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Payroll/PayrollGenerateDialog.vue](resources/js/views/Payroll/PayrollGenerateDialog.vue#L1)
- **Gọi API:**
  - `payrollService.generate()` → `POST /payrolls/generate` → [PayrollController::generate()](app/Http/Controllers/Api/V1/PayrollController.php#L59)
- **Component con:** [FormDialog.vue](resources/js/components/common/FormDialog.vue#L1), [FormSection.vue](resources/js/components/common/FormSection.vue#L1)

</details>

<details>
<summary><code>PayrollList.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Payroll/PayrollList.vue](resources/js/views/Payroll/PayrollList.vue#L1)
- **Route FE:** `/payrolls`
- **Gọi API:**
  - `payrollService.list()` → `GET /payrolls` → [PayrollController::index()](app/Http/Controllers/Api/V1/PayrollController.php#L23)
  - `payrollService.close()` → `POST /payrolls/{x}/close` → [PayrollController::close()](app/Http/Controllers/Api/V1/PayrollController.php#L75)
  - `payrollService.markAsPaid()` → `POST /payrolls/{x}/mark-paid` → [PayrollController::markAsPaid()](app/Http/Controllers/Api/V1/PayrollController.php#L80)
- **Store dùng:** `useAuthStore`, `useResourceSyncStore`
- **Component con:** [DataTable.vue](resources/js/components/common/DataTable.vue#L1), [PageHeader.vue](resources/js/components/common/PageHeader.vue#L1), [StatusChip.vue](resources/js/components/common/StatusChip.vue#L1), [PayrollGenerateDialog.vue](resources/js/views/Payroll/PayrollGenerateDialog.vue#L1)

</details>

<details>
<summary><code>PayrollPayslipDialog.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Payroll/PayrollPayslipDialog.vue](resources/js/views/Payroll/PayrollPayslipDialog.vue#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).

</details>

<details>
<summary><code>PayrollWorkdaysDialog.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Payroll/PayrollWorkdaysDialog.vue](resources/js/views/Payroll/PayrollWorkdaysDialog.vue#L1)
- **Gọi API:**
  - `payrollService.workdays()` → `GET /payrolls/{x}/details/{x}/workdays` → [PayrollController::workdays()](app/Http/Controllers/Api/V1/PayrollController.php#L47)

</details>

</details>

<details>
<summary><strong>Chi tiết từng hàm Controller</strong> — 8 hàm</summary>

<details>
<summary><code>PayrollController</code> — 8 hàm</summary>

- **Mã nguồn:** [app/Http/Controllers/Api/V1/PayrollController.php](app/Http/Controllers/Api/V1/PayrollController.php#L1)

<details>
<summary><code>public index()</code> — Danh sách</summary>

- **Mã nguồn:** [PayrollController::index()](app/Http/Controllers/Api/V1/PayrollController.php#L23)
- **API:** `GET /api/v1/payrolls` — quyền `payroll.view_all` ([payrolls.php](routes/api/v1/payrolls.php))
- **FE service:** [payrollService.list()](resources/js/services/payrollService.js#L5) ← gọi từ [PayrollList.vue](resources/js/views/Payroll/PayrollList.vue#L1)
- **Gọi xuống:** [PayrollService::list()](app/Services/PayrollService.php#L405)

</details>

<details>
<summary><code>public mine()</code> — Phiếu lương của CHÍNH người đang đăng nhập — cùng cách các mine() khác (EmployeeContractController, EmployeeTransferController...) không gắn permission:payroll.view_all.</summary>

- **Mã nguồn:** [PayrollController::mine()](app/Http/Controllers/Api/V1/PayrollController.php#L31)
- **API:** `GET /api/v1/payrolls/me` — quyền `payroll.view_own` ([payrolls.php](routes/api/v1/payrolls.php))
- **FE service:** [payrollService.mine()](resources/js/services/payrollService.js#L23) ← gọi từ [MyProfilePayslipsTab.vue](resources/js/views/Me/MyProfilePayslipsTab.vue#L1)
- **Gọi xuống:** [PayrollService::listPayslipsForEmployee()](app/Services/PayrollService.php#L413)

</details>

<details>
<summary><code>public forEmployee()</code> — Lịch sử phiếu lương của 1 nhân viên BẤT KỲ (khác mine() — chỉ chính mình) — dùng cho tab "Lương / Phép" ở Chi tiết nhân viên phía HR.</summary>

- **Mã nguồn:** [PayrollController::forEmployee()](app/Http/Controllers/Api/V1/PayrollController.php#L41)
- **API:** `GET /api/v1/employees/{employee}/payslips` — quyền `payroll.view_all` ([employees.php](routes/api/v1/employees.php))
- **FE service:** [employeeService.payslips()](resources/js/services/employeeService.js#L51) ← gọi từ [EmployeeSalaryLeaveTab.vue](resources/js/views/Employee/EmployeeSalaryLeaveTab.vue#L1)
- **Gọi xuống:** [PayrollService::listPayslipsForEmployee()](app/Services/PayrollService.php#L413)

</details>

<details>
<summary><code>public workdays()</code> — Chi tiết ngày công của 1 dòng trong bảng lương — xem PayrollService::workdayBreakdown().</summary>

- **Mã nguồn:** [PayrollController::workdays()](app/Http/Controllers/Api/V1/PayrollController.php#L47)
- **API:** `GET /api/v1/payrolls/{payroll}/details/{payrollDetail}/workdays` — quyền `payroll.view_all` ([payrolls.php](routes/api/v1/payrolls.php))
- **FE service:** [payrollService.workdays()](resources/js/services/payrollService.js#L20) ← gọi từ [PayrollWorkdaysDialog.vue](resources/js/views/Payroll/PayrollWorkdaysDialog.vue#L1)
- **Gọi xuống:** [PayrollService::workdayBreakdown()](app/Services/PayrollService.php#L304)

</details>

<details>
<summary><code>public show()</code> — Xem chi tiết</summary>

- **Mã nguồn:** [PayrollController::show()](app/Http/Controllers/Api/V1/PayrollController.php#L54)
- **API:** `GET /api/v1/payrolls/{payroll}` — quyền `payroll.view_all` ([payrolls.php](routes/api/v1/payrolls.php))
- **FE service:** [payrollService.show()](resources/js/services/payrollService.js#L17) ← gọi từ [PayrollDetail.vue](resources/js/views/Payroll/PayrollDetail.vue#L1)
- **Gọi xuống:** [PayrollService::find()](app/Services/PayrollService.php#L418)

</details>

<details>
<summary><code>public generate()</code> — Sinh</summary>

- **Mã nguồn:** [PayrollController::generate()](app/Http/Controllers/Api/V1/PayrollController.php#L59)
- **API:** `POST /api/v1/payrolls/generate` — quyền `payroll.manage` ([payrolls.php](routes/api/v1/payrolls.php))
- **FE service:** [payrollService.generate()](resources/js/services/payrollService.js#L8) ← gọi từ [PayrollGenerateDialog.vue](resources/js/views/Payroll/PayrollGenerateDialog.vue#L1)
- **Validate:** [GeneratePayrollRequest](app/Http/Requests/Payroll/GeneratePayrollRequest.php#L1)
- **Gọi xuống:** [PayrollService::generateForPeriod()](app/Services/PayrollService.php#L58)

</details>

<details>
<summary><code>public close()</code> — Chốt</summary>

- **Mã nguồn:** [PayrollController::close()](app/Http/Controllers/Api/V1/PayrollController.php#L75)
- **API:** `POST /api/v1/payrolls/{payroll}/close` — quyền `payroll.manage` ([payrolls.php](routes/api/v1/payrolls.php))
- **FE service:** [payrollService.close()](resources/js/services/payrollService.js#L11) ← gọi từ [PayrollList.vue](resources/js/views/Payroll/PayrollList.vue#L1)
- **Gọi xuống:** [PayrollService::close()](app/Services/PayrollService.php#L423)

</details>

<details>
<summary><code>public markAsPaid()</code> — Đánh dấu as paid</summary>

- **Mã nguồn:** [PayrollController::markAsPaid()](app/Http/Controllers/Api/V1/PayrollController.php#L80)
- **API:** `POST /api/v1/payrolls/{payroll}/mark-paid` — quyền `payroll.manage` ([payrolls.php](routes/api/v1/payrolls.php))
- **FE service:** [payrollService.markAsPaid()](resources/js/services/payrollService.js#L14) ← gọi từ [PayrollList.vue](resources/js/views/Payroll/PayrollList.vue#L1)
- **Gọi xuống:** [PayrollService::markAsPaid()](app/Services/PayrollService.php#L441)

</details>

</details>

</details>

<details>
<summary><strong>Chi tiết từng hàm Service</strong> — 9 hàm</summary>

<details>
<summary><code>PersonalIncomeTaxCalculator</code> — 1 hàm — CẢNH BÁO: các mốc bậc thuế và mức giảm trừ bản thân dưới đây là quy định PHÁP LUẬT thuế T…</summary>

- **Mã nguồn:** [app/Services/Payroll/PersonalIncomeTaxCalculator.php](app/Services/Payroll/PersonalIncomeTaxCalculator.php#L1)

<details>
<summary><code>public calculate()</code> — Tính</summary>

- **Mã nguồn:** [PersonalIncomeTaxCalculator::calculate()](app/Services/Payroll/PersonalIncomeTaxCalculator.php#L26)

</details>

</details>

<details>
<summary><code>PayrollService</code> — 8 hàm</summary>

- **Mã nguồn:** [app/Services/PayrollService.php](app/Services/PayrollService.php#L1)

<details>
<summary><code>public generateForPeriod()</code> — Tạo bảng lương cho kỳ</summary>

- **Mã nguồn:** [PayrollService::generateForPeriod()](app/Services/PayrollService.php#L58)
- **Gọi xuống:** [PayrollRepository::findByPeriod()](app/Repositories/PayrollRepository.php#L25) · [PayrollRepository::create()](app/Repositories/PayrollRepository.php#L30) · [PayrollDetailRepository::createForPayroll()](app/Repositories/PayrollDetailRepository.php#L12) · [PayrollRepository::update()](app/Repositories/PayrollRepository.php#L35)
- **Hiệu ứng phụ:** `DB::transaction`
- **Được gọi bởi:** [PayrollController::generate()](app/Http/Controllers/Api/V1/PayrollController.php#L59)

</details>

<details>
<summary><code>public standardWorkDaysFor()</code> — Ngày công chuẩn = ngày thường (T2-T6) trong tháng, trừ đi ngày lễ RƠI ĐÚNG vào ngày thường (lễ trùng T7/CN không trừ thêm vì vốn dĩ đã nghỉ).</summary>

- **Mã nguồn:** [PayrollService::standardWorkDaysFor()](app/Services/PayrollService.php#L280)
- **Được gọi bởi:** [DashboardService::monthlyWorkAndRecentAttendance()](app/Services/DashboardService.php#L355)

</details>

<details>
<summary><code>public workdayBreakdown()</code> — workday breakdown</summary>

- **Mã nguồn:** [PayrollService::workdayBreakdown()](app/Services/PayrollService.php#L304)
- **Gọi xuống:** [WorkTimeCalculationService::dayEquivalentFor()](app/Services/WorkTimeCalculationService.php#L38)
- **Được gọi bởi:** [PayrollController::workdays()](app/Http/Controllers/Api/V1/PayrollController.php#L47)

</details>

<details>
<summary><code>public list()</code> — Lấy danh sách</summary>

- **Mã nguồn:** [PayrollService::list()](app/Services/PayrollService.php#L405)
- **Gọi xuống:** [PayrollRepository::paginate()](app/Repositories/PayrollRepository.php#L10)
- **Được gọi bởi:** [PayrollController::index()](app/Http/Controllers/Api/V1/PayrollController.php#L23)

</details>

<details>
<summary><code>public listPayslipsForEmployee()</code> — Lịch sử phiếu lương của 1 nhân viên — dùng chung cho cả nhân viên tự xem (PayrollController::mine()) lẫn HR xem 1 nhân viên bất kỳ ở tab "Lương / Phép" của Chi tiết nhân…</summary>

- **Mã nguồn:** [PayrollService::listPayslipsForEmployee()](app/Services/PayrollService.php#L413)
- **Gọi xuống:** [PayrollDetailRepository::listForEmployee()](app/Repositories/PayrollDetailRepository.php#L27)
- **Được gọi bởi:** [PayrollController::mine()](app/Http/Controllers/Api/V1/PayrollController.php#L31) · [PayrollController::forEmployee()](app/Http/Controllers/Api/V1/PayrollController.php#L41) · [DashboardService::latestPayslip()](app/Services/DashboardService.php#L423)

</details>

<details>
<summary><code>public find()</code> — Tìm</summary>

- **Mã nguồn:** [PayrollService::find()](app/Services/PayrollService.php#L418)
- **Gọi xuống:** [PayrollRepository::find()](app/Repositories/PayrollRepository.php#L20)
- **Được gọi bởi:** [PayrollController::show()](app/Http/Controllers/Api/V1/PayrollController.php#L54)

</details>

<details>
<summary><code>public close()</code> — Chốt</summary>

- **Mã nguồn:** [PayrollService::close()](app/Services/PayrollService.php#L423)
- **Hiệu ứng phụ:** `DB::transaction`
- **Được gọi bởi:** [PayrollController::close()](app/Http/Controllers/Api/V1/PayrollController.php#L75)

</details>

<details>
<summary><code>public markAsPaid()</code> — Đánh dấu as paid</summary>

- **Mã nguồn:** [PayrollService::markAsPaid()](app/Services/PayrollService.php#L441)
- **Hiệu ứng phụ:** `DB::transaction`
- **Được gọi bởi:** [PayrollController::markAsPaid()](app/Http/Controllers/Api/V1/PayrollController.php#L80)

</details>

</details>

</details>

<details>
<summary><strong>Danh sách test</strong> — 55 test</summary>

<details>
<summary><code>PayrollControllerTest.php</code> — 19 test</summary>

- **Mã nguồn:** [tests/Feature/Payroll/PayrollControllerTest.php](tests/Feature/Payroll/PayrollControllerTest.php#L1)
- [test_unauthenticated_cannot_list_payrolls](tests/Feature/Payroll/PayrollControllerTest.php#L65)
- [test_employee_cannot_generate_payroll](tests/Feature/Payroll/PayrollControllerTest.php#L72)
- [test_employee_cannot_list_payrolls](tests/Feature/Payroll/PayrollControllerTest.php#L83)
- [test_manager_cannot_generate_payroll](tests/Feature/Payroll/PayrollControllerTest.php#L94)
- [test_manager_cannot_close_payroll](tests/Feature/Payroll/PayrollControllerTest.php#L107)
- [test_hr_can_generate_payroll](tests/Feature/Payroll/PayrollControllerTest.php#L126)
- [test_admin_can_generate_payroll](tests/Feature/Payroll/PayrollControllerTest.php#L146)
- [test_generate_requires_month_and_year](tests/Feature/Payroll/PayrollControllerTest.php#L158)
- [test_generate_rejects_month_out_of_range](tests/Feature/Payroll/PayrollControllerTest.php#L169)
- [test_generating_duplicate_period_returns_422](tests/Feature/Payroll/PayrollControllerTest.php#L180)
- [test_hr_can_list_payrolls](tests/Feature/Payroll/PayrollControllerTest.php#L198)
- [test_hr_can_view_payroll_detail_with_nested_employee_info](tests/Feature/Payroll/PayrollControllerTest.php#L214)
- [test_show_returns_404_for_nonexistent_payroll](tests/Feature/Payroll/PayrollControllerTest.php#L231)
- [test_hr_can_close_and_then_mark_payroll_as_paid](tests/Feature/Payroll/PayrollControllerTest.php#L244)
- [test_closing_an_already_closed_payroll_returns_422](tests/Feature/Payroll/PayrollControllerTest.php#L264)
- [test_marking_as_paid_before_closing_returns_422](tests/Feature/Payroll/PayrollControllerTest.php#L281)
- [test_hr_can_view_payslip_history_for_an_employee](tests/Feature/Payroll/PayrollControllerTest.php#L297)
- [test_employee_without_payroll_view_all_cannot_view_payslip_history](tests/Feature/Payroll/PayrollControllerTest.php#L323)
- [test_workdays_endpoint_checks_permission_and_that_detail_belongs_to_payroll](tests/Feature/Payroll/PayrollControllerTest.php#L335)

</details>

<details>
<summary><code>PayrollTest.php</code> — 30 test</summary>

- **Mã nguồn:** [tests/Feature/Payroll/PayrollTest.php](tests/Feature/Payroll/PayrollTest.php#L1)
- [test_generates_correct_payroll_for_a_standard_scenario](tests/Feature/Payroll/PayrollTest.php#L144)
- [test_salary_reflects_actual_attendance_when_no_leave_is_filed](tests/Feature/Payroll/PayrollTest.php#L196)
- [test_only_approved_attendance_counts_toward_salary](tests/Feature/Payroll/PayrollTest.php#L223)
- [test_cannot_generate_payroll_while_checked_out_attendance_awaits_approval](tests/Feature/Payroll/PayrollTest.php#L244)
- [test_pending_attendance_in_another_month_does_not_block_payroll](tests/Feature/Payroll/PayrollTest.php#L264)
- [test_day_equivalent_is_capped_at_one_per_day](tests/Feature/Payroll/PayrollTest.php#L278)
- [test_unapproved_overtime_is_not_paid](tests/Feature/Payroll/PayrollTest.php#L300)
- [test_approved_overtime_below_minimum_minutes_is_not_paid](tests/Feature/Payroll/PayrollTest.php#L318)
- [test_approved_overtime_meeting_minimum_minutes_is_paid](tests/Feature/Payroll/PayrollTest.php#L337)
- [test_cannot_generate_duplicate_period](tests/Feature/Payroll/PayrollTest.php#L356)
- [test_probation_employee_is_included](tests/Feature/Payroll/PayrollTest.php#L368)
- [test_resigned_employee_is_excluded](tests/Feature/Payroll/PayrollTest.php#L378)
- [test_employee_without_active_contract_is_skipped_not_failed](tests/Feature/Payroll/PayrollTest.php#L388)
- [test_uses_the_contract_valid_during_the_period_not_the_current_status](tests/Feature/Payroll/PayrollTest.php#L407)
- [test_paid_leave_does_not_create_unpaid_deduction](tests/Feature/Payroll/PayrollTest.php#L435)
- [test_standard_work_days_excludes_holiday_falling_on_weekday](tests/Feature/Payroll/PayrollTest.php#L452)
- [test_close_transitions_from_processing_to_closed](tests/Feature/Payroll/PayrollTest.php#L466)
- [test_cannot_close_a_payroll_that_is_already_closed](tests/Feature/Payroll/PayrollTest.php#L480)
- [test_mark_as_paid_transitions_from_closed_to_paid](tests/Feature/Payroll/PayrollTest.php#L492)
- [test_cannot_mark_as_paid_before_closing](tests/Feature/Payroll/PayrollTest.php#L505)
- [test_insurance_is_charged_when_the_employee_has_at_least_14_paid_days](tests/Feature/Payroll/PayrollTest.php#L551)
- [test_insurance_is_waived_when_the_employee_has_fewer_than_14_paid_days](tests/Feature/Payroll/PayrollTest.php#L556)
- [test_paid_leave_days_count_toward_the_14_day_threshold](tests/Feature/Payroll/PayrollTest.php#L561)
- [test_insurance_is_waived_when_unpaid_leave_reaches_14_days](tests/Feature/Payroll/PayrollTest.php#L569)
- [test_insurance_is_waived_for_a_contract_shorter_than_one_month](tests/Feature/Payroll/PayrollTest.php#L578)
- [test_insurance_is_charged_for_a_contract_of_exactly_one_month](tests/Feature/Payroll/PayrollTest.php#L586)
- [test_insurance_is_waived_for_a_probation_contract_even_with_full_attendance](tests/Feature/Payroll/PayrollTest.php#L593)
- [test_insurance_starts_again_once_the_employee_is_on_an_official_contract](tests/Feature/Payroll/PayrollTest.php#L600)
- [test_workday_breakdown_matches_the_payslip_and_flags_uncounted_rows](tests/Feature/Payroll/PayrollTest.php#L609)
- [test_workday_breakdown_detects_stale_payslip_and_deleted_shift](tests/Feature/Payroll/PayrollTest.php#L632)

</details>

<details>
<summary><code>PersonalIncomeTaxCalculatorTest.php</code> — 6 test</summary>

- **Mã nguồn:** [tests/Unit/Services/Payroll/PersonalIncomeTaxCalculatorTest.php](tests/Unit/Services/Payroll/PersonalIncomeTaxCalculatorTest.php#L1)
- [test_income_at_or_below_personal_deduction_pays_no_tax](tests/Unit/Services/Payroll/PersonalIncomeTaxCalculatorTest.php#L19)
- [test_taxable_income_exactly_at_first_bracket_ceiling](tests/Unit/Services/Payroll/PersonalIncomeTaxCalculatorTest.php#L28)
- [test_taxable_income_spanning_three_brackets](tests/Unit/Services/Payroll/PersonalIncomeTaxCalculatorTest.php#L36)
- [test_taxable_income_exactly_at_bracket_boundary](tests/Unit/Services/Payroll/PersonalIncomeTaxCalculatorTest.php#L45)
- [test_taxable_income_reaching_top_bracket](tests/Unit/Services/Payroll/PersonalIncomeTaxCalculatorTest.php#L54)
- [test_negative_income_after_insurance_does_not_produce_negative_tax](tests/Unit/Services/Payroll/PersonalIncomeTaxCalculatorTest.php#L65)

</details>

</details>

---

## 11. Thông báo & Realtime

**Vai trò:** thông báo trong app (chuông + trang) và đồng bộ realtime (Laravel Reverb) để trang tự làm mới khi người khác thao tác; trạng thái online.

- **Điểm vào:** Chuông ở header, `/notifications`; WebSocket `localhost:6001`; API [`notifications.php`](routes/api/v1/notifications.php), `POST /broadcasting/auth`.
- **Danh mục file & hàm:** xem [cuối mục này](#danh-mục-file--hàm-module-11) — mỗi file và mỗi hàm public có link riêng.


**Luồng chính**

| Thao tác | Luồng |
| --- | --- |
| Gửi thông báo cá nhân | `Service nghiệp vụ (sau commit) → NotificationService::send → Notification + NotificationDelivery(in_app) → NotificationCreated → kênh private notifications.{userId} → useNotificationStore (unreadCount +1)` |
| Mở chuông / đọc | `NotificationCenter.vue (mở dropdown) → notificationService.list → GET /notifications → attachActors (ACTOR_SOURCES → actor + chấm online từ usePresenceStore) → bấm dòng → PATCH /notifications/{id}/read → router.push theo TYPE_ROUTES (useNotificationNavigation)` |
| Trang quản lý tự làm mới | `Model (BroadcastsChanges) tạo/sửa/xóa → Realtime (gom + khử trùng, gửi sau response) → ResourceChanged (kênh resource-sync.{resource}, shared) và/hoặc UserDataChanged (kênh user-sync.{userId}, chủ dữ liệu) → useResourceSyncStore/useMySyncStore tăng signals[resource] → useRealtimeRefresh (debounce 300ms) → loadData({silent:true})` |
| Online/Offline | `App.vue (watch isAuthenticated) → usePresenceStore.connect → Echo.join('presence.online-employees') → .here/.joining/.leaving → Employees.vue: presence.isOnline(item.id) → v-badge dot` |
| Live-feed chấm công | `AttendanceService::checkIn/checkOut → AttendanceChecked → kênh private attendance.live-feed (attendance.view_all) → useAttendanceFeedStore (≤ 20 sự kiện, không lưu DB) → dải chip ở AttendanceOverview` |

**Luồng demo nhanh:** Mở 2 trình duyệt: nhân viên (A) và HR (B). A nộp đơn nghỉ phép → chuông của B nhảy số + chấm xanh online của A hiện ở `/employees` → B bấm vào thông báo → mở đúng trang duyệt.

**Quy tắc & bẫy**
- Thông báo cá nhân có lưu DB; live-feed chấm công **không** lưu DB. Chấm công VÀO có thêm thông báo cá nhân tới nhóm `attendance.approve`. Mailable nào muốn theo dõi gửi lỗi khai `withNotificationDelivery(int $id)` + `failed(Throwable)`; hiện không Mailable nào dùng email.
- Thêm loại thông báo có người gửi → thêm 1 dòng vào `NotificationService::ACTOR_SOURCES`; thêm loại mới → thêm vào `TYPE_ROUTES` (`NotificationCenter.vue`) và `TYPE_LABELS` (`Notifications.vue`).
- Bảng ánh xạ resource → permission trong `channels.php` **phải khớp** `meta.permission` của route (sai là rò rỉ/chặn nhầm); resource lạ luôn bị chặn. Kênh `work_shifts_public` cho mọi user đăng nhập.
- `->update()` bằng query builder không phát event Eloquent — phải gọi `Realtime::*` tay (đã làm: `contracts:expire`, `ResignationService`, `DepartmentService::syncHeadPosition`). `increment()` chỉ phát `updated`; trait bắt `created/updated/deleted/restored` (không bắt `saved`). Cache `user_id` theo nhân viên chỉ dùng trong HTTP.
- Echo: mỗi store chỉ rời kênh của mình; **chỉ `App.vue` được gọi `disconnectEcho()`**. App.vue tách watcher: presence nối ngay khi `isAuthenticated`, notification/feed đợi `auth.user` (cần `user.id`/`permissions`). Đăng xuất gọi `resourceSync.resetAll()`.
- Hai bộ biến Reverb: PHP (`REVERB_HOST=reverb`, đổi xong restart) và frontend (`VITE_REVERB_*`, đọc lúc build — đổi xong `npm run build`). Đổi `FORWARD_REVERB_PORT` thì đổi `VITE_REVERB_PORT`. Demo qua tunnel/điện thoại cần mở cả cổng 6001.
- Test kênh: `phpunit.xml` đặt `BROADCAST_CONNECTION=null`; test auth kênh phải `config(['broadcasting.default' => 'reverb'])` **và** `require base_path('routes/channels.php')` lần nữa (xem `AttendanceLiveFeedTest::setUp`). Kịch bản Node + raw `pusher-js` phải `bind()` tên sự kiện không có dấu chấm đầu.
- 1 instance Reverb (chưa bật `REVERB_SCALING_ENABLED`); chưa có độ trễ ân hạn khi mất mạng chập chờn. Chưa chuyển Phòng ban/Chức vụ/Ca sang trait (còn dispatch tay ở Service).

**Liên thông:** mọi module nghiệp vụ gọi `NotificationService` và bắn tín hiệu realtime; trang quản lý (2–10) nghe `useRealtimeRefresh`.

<a id="danh-mục-file--hàm-module-11"></a>

### Danh mục file & hàm — module 11

#### Giao diện (9 file)

- [Notifications.vue](resources/js/views/Notification/Notifications.vue#L1) — route `/notifications`
- [NotificationCenter.vue](resources/js/components/layout/NotificationCenter.vue#L1)
- [useAttendanceFeedStore.js](resources/js/stores/useAttendanceFeedStore.js#L1)
- [useLeaveFeedStore.js](resources/js/stores/useLeaveFeedStore.js#L1)
- [useMySyncStore.js](resources/js/stores/useMySyncStore.js#L1)
- [useNotificationStore.js](resources/js/stores/useNotificationStore.js#L1)
- [usePresenceStore.js](resources/js/stores/usePresenceStore.js#L1)
- [useResourceSyncStore.js](resources/js/stores/useResourceSyncStore.js#L1)
- [useNotificationNavigation.js](resources/js/composables/useNotificationNavigation.js#L1)

#### Controller (1 file, 4 hàm public)

- [NotificationController.php](app/Http/Controllers/Api/V1/NotificationController.php#L1) — 4 hàm: [index()](app/Http/Controllers/Api/V1/NotificationController.php#L18) · [all()](app/Http/Controllers/Api/V1/NotificationController.php#L29) · [markRead()](app/Http/Controllers/Api/V1/NotificationController.php#L37) · [markAllRead()](app/Http/Controllers/Api/V1/NotificationController.php#L44)

#### Service (1 file, 6 hàm public)

- [NotificationService.php](app/Services/NotificationService.php#L1) — 6 hàm: [listForUser()](app/Services/NotificationService.php#L25) · [unreadCountForUser()](app/Services/NotificationService.php#L33) · [listAll()](app/Services/NotificationService.php#L38) · [markRead()](app/Services/NotificationService.php#L81) · [markAllRead()](app/Services/NotificationService.php#L93) · [send()](app/Services/NotificationService.php#L103)

#### Repository (1 file)

- [NotificationRepository.php](app/Repositories/NotificationRepository.php#L1) — [paginateForUser()](app/Repositories/NotificationRepository.php#L12) · [unreadCountForUser()](app/Repositories/NotificationRepository.php#L17) · [findForUser()](app/Repositories/NotificationRepository.php#L24) · [create()](app/Repositories/NotificationRepository.php#L29) · [createDelivery()](app/Repositories/NotificationRepository.php#L34) · [markRead()](app/Repositories/NotificationRepository.php#L39) · [markAllRead()](app/Repositories/NotificationRepository.php#L48) · [paginateAll()](app/Repositories/NotificationRepository.php#L56)

#### Model (3 file)

- [Notification.php](app/Models/Notification.php#L1)
- [NotificationDelivery.php](app/Models/NotificationDelivery.php#L1)
- [BroadcastsChanges.php](app/Models/Concerns/BroadcastsChanges.php#L1)

#### Request & Resource (1 file)

- [NotificationResource.php](app/Http/Resources/NotificationResource.php#L1)

#### Lệnh, Event, Middleware, hạ tầng BE (7 file)

- [AttendanceApprovalDecided.php](app/Events/AttendanceApprovalDecided.php#L1) — — bắn khi TRẠNG THÁI DUYỆT của 1 bản ghi chấm công đổi (AttendanceService::decideApproval()) để báo cho các p…
- [AttendanceChecked.php](app/Events/AttendanceChecked.php#L1) — Sự kiện thuần cho HR xem trực tiếp (live-feed) trên trang Tổng quan chấm công — KHÔNG lưu DB, KHÔNG đi qua No…
- [LeaveRequestChanged.php](app/Events/LeaveRequestChanged.php#L1) — ... nhưng bên tài khoản nhân sự phải F5 lại mới thấy" — bắn khi 1 đơn nghỉ phép được TẠO MỚI hoặc ĐỔI TRẠNG T…
- [NotificationCreated.php](app/Events/NotificationCreated.php#L1)
- [ResourceChanged.php](app/Events/ResourceChanged.php#L1) — — mở rộng mục 34 CODE_MAP (trước đó chỉ làm riêng Tổng hợp chấm công/Duyệt nghỉ phép, mỗi trang 1 Event tay) …
- [UserDataChanged.php](app/Events/UserDataChanged.php#L1) — Tín hiệu "dữ liệu CỦA BẠN vừa đổi" gửi riêng cho 1 user (kênh user-sync.{userId}) — để trang tự phục vụ (Dash…
- [Realtime.php](app/Support/Realtime.php#L1) — Cửa duy nhất để báo "dữ liệu vừa đổi" cho trình duyệt đang mở (WebSocket).

#### Migration (2 file)

- [2026_01_05_000001_create_notifications_table.php](database/migrations/2026_01_05_000001_create_notifications_table.php)
- [2026_01_05_000002_create_notification_deliveries_table.php](database/migrations/2026_01_05_000002_create_notification_deliveries_table.php)

#### Kiểm thử (7 file, 43 test)

- [AttendanceCheckInNotificationTest.php](tests/Feature/Notification/AttendanceCheckInNotificationTest.php#L1) — 3 test
- [LeaveLiveFeedTest.php](tests/Feature/Notification/LeaveLiveFeedTest.php#L1) — 4 test
- [LeaveNotificationTest.php](tests/Feature/Notification/LeaveNotificationTest.php#L1) — 5 test
- [NotificationAdminViewTest.php](tests/Feature/Notification/NotificationAdminViewTest.php#L1) — 7 test
- [NotificationInboxTest.php](tests/Feature/Notification/NotificationInboxTest.php#L1) — 8 test
- [UserSyncTest.php](tests/Feature/Realtime/UserSyncTest.php#L1) — 6 test
- [ResourceSyncTest.php](tests/Feature/ResourceSyncTest.php#L1) — 10 test

<details>
<summary><strong>Chi tiết từng màn hình Vue</strong> — 9 file</summary>

<details>
<summary><code>Notifications.vue</code></summary>

- **Mã nguồn:** [resources/js/views/Notification/Notifications.vue](resources/js/views/Notification/Notifications.vue#L1)
- **Route FE:** `/notifications`
- **Gọi API:**
  - `notificationService.list()` → `GET /notifications` → [NotificationController::index()](app/Http/Controllers/Api/V1/NotificationController.php#L18)
  - `notificationService.listAll()` → `GET /notifications/all` → —
- **Store dùng:** `useAuthStore`, `useNotificationStore`
- **Component con:** [DataTable.vue](resources/js/components/common/DataTable.vue#L1), [PageHeader.vue](resources/js/components/common/PageHeader.vue#L1), [SearchField.vue](resources/js/components/common/SearchField.vue#L1), [StatusChip.vue](resources/js/components/common/StatusChip.vue#L1)

</details>

<details>
<summary><code>NotificationCenter.vue</code></summary>

- **Mã nguồn:** [resources/js/components/layout/NotificationCenter.vue](resources/js/components/layout/NotificationCenter.vue#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).
- **Store dùng:** `useNotificationStore`, `usePresenceStore`

</details>

<details>
<summary><code>useAttendanceFeedStore.js</code></summary>

- **Mã nguồn:** [resources/js/stores/useAttendanceFeedStore.js](resources/js/stores/useAttendanceFeedStore.js#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).
- **Store dùng:** `useNotificationStore`, `useAttendanceFeedStore`

</details>

<details>
<summary><code>useLeaveFeedStore.js</code></summary>

- **Mã nguồn:** [resources/js/stores/useLeaveFeedStore.js](resources/js/stores/useLeaveFeedStore.js#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).
- **Store dùng:** `useAttendanceFeedStore`, `useNotificationStore`, `useLeaveFeedStore`

</details>

<details>
<summary><code>useMySyncStore.js</code></summary>

- **Mã nguồn:** [resources/js/stores/useMySyncStore.js](resources/js/stores/useMySyncStore.js#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).
- **Store dùng:** `useResourceSyncStore`, `useMySyncStore`

</details>

<details>
<summary><code>useNotificationStore.js</code></summary>

- **Mã nguồn:** [resources/js/stores/useNotificationStore.js](resources/js/stores/useNotificationStore.js#L1)
- **Gọi API:**
  - `notificationService.list()` → `GET /notifications` → [NotificationController::index()](app/Http/Controllers/Api/V1/NotificationController.php#L18)
  - `notificationService.markRead()` → `PATCH /notifications/{x}/read` → [NotificationController::markRead()](app/Http/Controllers/Api/V1/NotificationController.php#L37)
  - `notificationService.markAllRead()` → `PATCH /notifications/read-all` → [NotificationController::markAllRead()](app/Http/Controllers/Api/V1/NotificationController.php#L44)
- **Store dùng:** `usePresenceStore`, `useNotificationStore`

</details>

<details>
<summary><code>usePresenceStore.js</code></summary>

- **Mã nguồn:** [resources/js/stores/usePresenceStore.js](resources/js/stores/usePresenceStore.js#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).
- **Store dùng:** `usePresenceStore`

</details>

<details>
<summary><code>useResourceSyncStore.js</code></summary>

- **Mã nguồn:** [resources/js/stores/useResourceSyncStore.js](resources/js/stores/useResourceSyncStore.js#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).
- **Store dùng:** `useNotificationStore`, `useAttendanceFeedStore`, `useResourceSyncStore`

</details>

<details>
<summary><code>useNotificationNavigation.js</code></summary>

- **Mã nguồn:** [resources/js/composables/useNotificationNavigation.js](resources/js/composables/useNotificationNavigation.js#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).
- **Store dùng:** `useNotificationStore`

</details>

</details>

<details>
<summary><strong>Chi tiết từng hàm Controller</strong> — 4 hàm</summary>

<details>
<summary><code>NotificationController</code> — 4 hàm</summary>

- **Mã nguồn:** [app/Http/Controllers/Api/V1/NotificationController.php](app/Http/Controllers/Api/V1/NotificationController.php#L1)

<details>
<summary><code>public index()</code> — Danh sách</summary>

- **Mã nguồn:** [NotificationController::index()](app/Http/Controllers/Api/V1/NotificationController.php#L18)
- **API:** `GET /api/v1/notifications` — quyền `auth` ([notifications.php](routes/api/v1/notifications.php))
- **FE service:** [notificationService.list()](resources/js/services/notificationService.js#L4) ← gọi từ [Notifications.vue](resources/js/views/Notification/Notifications.vue#L1), [useNotificationStore.js](resources/js/stores/useNotificationStore.js#L1)
- **Gọi xuống:** [NotificationService::listForUser()](app/Services/NotificationService.php#L25) · [NotificationService::unreadCountForUser()](app/Services/NotificationService.php#L33)

</details>

<details>
<summary><code>public all()</code> — Toàn bộ thông báo trong công ty (không lọc theo user hiện tại) — gate "notification.view_all" ở route, dùng cho trang admin "Toàn công ty".</summary>

- **Mã nguồn:** [NotificationController::all()](app/Http/Controllers/Api/V1/NotificationController.php#L29)
- **API:** không có route trực tiếp.
- **Gọi xuống:** [NotificationService::listAll()](app/Services/NotificationService.php#L38)

</details>

<details>
<summary><code>public markRead()</code> — Đánh dấu read</summary>

- **Mã nguồn:** [NotificationController::markRead()](app/Http/Controllers/Api/V1/NotificationController.php#L37)
- **API:** `PATCH /api/v1/notifications/{notification}/read` — quyền `auth` ([notifications.php](routes/api/v1/notifications.php))
- **FE service:** [notificationService.markRead()](resources/js/services/notificationService.js#L12) ← gọi từ [useNotificationStore.js](resources/js/stores/useNotificationStore.js#L1)
- **Gọi xuống:** [NotificationService::markRead()](app/Services/NotificationService.php#L81)

</details>

<details>
<summary><code>public markAllRead()</code> — Đánh dấu all read</summary>

- **Mã nguồn:** [NotificationController::markAllRead()](app/Http/Controllers/Api/V1/NotificationController.php#L44)
- **API:** `PATCH /api/v1/notifications/read-all` — quyền `auth` ([notifications.php](routes/api/v1/notifications.php))
- **FE service:** [notificationService.markAllRead()](resources/js/services/notificationService.js#L15) ← gọi từ [useNotificationStore.js](resources/js/stores/useNotificationStore.js#L1)
- **Gọi xuống:** [NotificationService::markAllRead()](app/Services/NotificationService.php#L93)

</details>

</details>

</details>

<details>
<summary><strong>Chi tiết từng hàm Service</strong> — 6 hàm</summary>

<details>
<summary><code>NotificationService</code> — 6 hàm</summary>

- **Mã nguồn:** [app/Services/NotificationService.php](app/Services/NotificationService.php#L1)

<details>
<summary><code>public listForUser()</code> — Lấy danh sách for user</summary>

- **Mã nguồn:** [NotificationService::listForUser()](app/Services/NotificationService.php#L25)
- **Gọi xuống:** [NotificationRepository::paginateForUser()](app/Repositories/NotificationRepository.php#L12)
- **Được gọi bởi:** [NotificationController::index()](app/Http/Controllers/Api/V1/NotificationController.php#L18)

</details>

<details>
<summary><code>public unreadCountForUser()</code> — unread count for user</summary>

- **Mã nguồn:** [NotificationService::unreadCountForUser()](app/Services/NotificationService.php#L33)
- **Gọi xuống:** [NotificationRepository::unreadCountForUser()](app/Repositories/NotificationRepository.php#L17)
- **Được gọi bởi:** [NotificationController::index()](app/Http/Controllers/Api/V1/NotificationController.php#L18)

</details>

<details>
<summary><code>public listAll()</code> — Lấy danh sách all</summary>

- **Mã nguồn:** [NotificationService::listAll()](app/Services/NotificationService.php#L38)
- **Gọi xuống:** [NotificationRepository::paginateAll()](app/Repositories/NotificationRepository.php#L56)
- **Được gọi bởi:** [NotificationController::all()](app/Http/Controllers/Api/V1/NotificationController.php#L29)

</details>

<details>
<summary><code>public markRead()</code> — Đánh dấu read</summary>

- **Mã nguồn:** [NotificationService::markRead()](app/Services/NotificationService.php#L81)
- **Gọi xuống:** [NotificationRepository::findForUser()](app/Repositories/NotificationRepository.php#L24) · [NotificationRepository::markRead()](app/Repositories/NotificationRepository.php#L39)
- **Được gọi bởi:** [NotificationController::markRead()](app/Http/Controllers/Api/V1/NotificationController.php#L37)

</details>

<details>
<summary><code>public markAllRead()</code> — Đánh dấu all read</summary>

- **Mã nguồn:** [NotificationService::markAllRead()](app/Services/NotificationService.php#L93)
- **Gọi xuống:** [NotificationRepository::markAllRead()](app/Repositories/NotificationRepository.php#L48)
- **Được gọi bởi:** [NotificationController::markAllRead()](app/Http/Controllers/Api/V1/NotificationController.php#L44)

</details>

<details>
<summary><code>public send()</code> — Tạo 1 thông báo trong-app (luôn), kèm gửi email nếu có $emailMailable (dùng đúng địa chỉ $emailAddress nếu truyền vào — vd email công ty của nhân viên, KHÔNG phải lúc nà…</summary>

- **Mã nguồn:** [NotificationService::send()](app/Services/NotificationService.php#L103)
- **Gọi xuống:** [NotificationRepository::create()](app/Repositories/NotificationRepository.php#L29) · [NotificationRepository::createDelivery()](app/Repositories/NotificationRepository.php#L34)
- **Hiệu ứng phụ:** [NotificationCreated](app/Events/NotificationCreated.php#L1), `DB::transaction`
- **Được gọi bởi:** [AttendanceAdjustmentService::notifyApprovers()](app/Services/AttendanceAdjustmentService.php#L171) · [AttendanceService::notifyApprovers()](app/Services/AttendanceService.php#L611) · [LeaveApprovalService::notifyHrTurn()](app/Services/LeaveApprovalService.php#L139) · [LeaveApprovalService::notifyEmployeeDecision()](app/Services/LeaveApprovalService.php#L155) · [LeaveRequestService::notifySubmission()](app/Services/LeaveRequestService.php#L163) · [ResignationService::notifyApprovers()](app/Services/ResignationService.php#L279) · [ResignationService::notifyEmployeeDecision()](app/Services/ResignationService.php#L307) · [RemindMissingCheckout::handle()](app/Console/Commands/RemindMissingCheckout.php#L41)

</details>

</details>

</details>

<details>
<summary><strong>Danh sách test</strong> — 43 test</summary>

<details>
<summary><code>AttendanceCheckInNotificationTest.php</code> — 3 test</summary>

- **Mã nguồn:** [tests/Feature/Notification/AttendanceCheckInNotificationTest.php](tests/Feature/Notification/AttendanceCheckInNotificationTest.php#L1)
- [test_check_in_notifies_hr_and_admin_who_can_approve](tests/Feature/Notification/AttendanceCheckInNotificationTest.php#L83)
- [test_manager_without_approve_permission_is_not_notified](tests/Feature/Notification/AttendanceCheckInNotificationTest.php#L103)
- [test_check_out_does_not_create_an_additional_notification](tests/Feature/Notification/AttendanceCheckInNotificationTest.php#L118)

</details>

<details>
<summary><code>LeaveLiveFeedTest.php</code> — 4 test</summary>

- **Mã nguồn:** [tests/Feature/Notification/LeaveLiveFeedTest.php](tests/Feature/Notification/LeaveLiveFeedTest.php#L1)
- [test_hr_can_authorize_the_leave_live_feed_channel](tests/Feature/Notification/LeaveLiveFeedTest.php#L107)
- [test_employee_without_approval_permission_cannot_authorize_the_channel](tests/Feature/Notification/LeaveLiveFeedTest.php#L120)
- [test_submitting_a_leave_request_dispatches_leave_request_changed](tests/Feature/Notification/LeaveLiveFeedTest.php#L133)
- [test_deciding_a_leave_request_dispatches_leave_request_changed](tests/Feature/Notification/LeaveLiveFeedTest.php#L158)

</details>

<details>
<summary><code>LeaveNotificationTest.php</code> — 5 test</summary>

- **Mã nguồn:** [tests/Feature/Notification/LeaveNotificationTest.php](tests/Feature/Notification/LeaveNotificationTest.php#L1)
- [test_submitting_request_notifies_direct_manager](tests/Feature/Notification/LeaveNotificationTest.php#L115)
- [test_submitting_request_without_manager_notifies_hr](tests/Feature/Notification/LeaveNotificationTest.php#L136)
- [test_manager_approval_notifies_hr_excluding_the_approver](tests/Feature/Notification/LeaveNotificationTest.php#L157)
- [test_final_decision_creates_in_app_notification_only_no_email](tests/Feature/Notification/LeaveNotificationTest.php#L186)
- [test_intermediate_manager_approved_status_does_not_notify_employee](tests/Feature/Notification/LeaveNotificationTest.php#L224)

</details>

<details>
<summary><code>NotificationAdminViewTest.php</code> — 7 test</summary>

- **Mã nguồn:** [tests/Feature/Notification/NotificationAdminViewTest.php](tests/Feature/Notification/NotificationAdminViewTest.php#L1)
- [test_unauthenticated_is_rejected](tests/Feature/Notification/NotificationAdminViewTest.php#L52)
- [test_user_without_notification_view_all_is_forbidden](tests/Feature/Notification/NotificationAdminViewTest.php#L57)
- [test_admin_sees_notifications_of_every_user_not_just_their_own](tests/Feature/Notification/NotificationAdminViewTest.php#L68)
- [test_response_includes_recipient_info](tests/Feature/Notification/NotificationAdminViewTest.php#L86)
- [test_can_filter_by_type](tests/Feature/Notification/NotificationAdminViewTest.php#L105)
- [test_per_page_query_param_controls_page_size](tests/Feature/Notification/NotificationAdminViewTest.php#L122)
- [test_can_search_by_recipient_employee_name](tests/Feature/Notification/NotificationAdminViewTest.php#L140)

</details>

<details>
<summary><code>NotificationInboxTest.php</code> — 8 test</summary>

- **Mã nguồn:** [tests/Feature/Notification/NotificationInboxTest.php](tests/Feature/Notification/NotificationInboxTest.php#L1)
- [test_unauthenticated_is_rejected](tests/Feature/Notification/NotificationInboxTest.php#L54)
- [test_user_only_sees_own_notifications](tests/Feature/Notification/NotificationInboxTest.php#L59)
- [test_index_includes_unread_count](tests/Feature/Notification/NotificationInboxTest.php#L75)
- [test_can_mark_own_notification_as_read](tests/Feature/Notification/NotificationInboxTest.php#L87)
- [test_cannot_mark_another_users_notification_as_read](tests/Feature/Notification/NotificationInboxTest.php#L102)
- [test_per_page_query_param_controls_page_size](tests/Feature/Notification/NotificationInboxTest.php#L120)
- [test_index_includes_actor_derived_from_source_record](tests/Feature/Notification/NotificationInboxTest.php#L138)
- [test_mark_all_read_only_affects_own_notifications](tests/Feature/Notification/NotificationInboxTest.php#L166)

</details>

<details>
<summary><code>UserSyncTest.php</code> — 6 test</summary>

- **Mã nguồn:** [tests/Feature/Realtime/UserSyncTest.php](tests/Feature/Realtime/UserSyncTest.php#L1)
- [test_user_sync_channel_is_only_open_to_its_owner](tests/Feature/Realtime/UserSyncTest.php#L76)
- [test_new_shared_resources_follow_the_permission_of_their_page](tests/Feature/Realtime/UserSyncTest.php#L85)
- [test_attendance_change_notifies_the_owner_and_the_management_pages](tests/Feature/Realtime/UserSyncTest.php#L99)
- [test_employee_without_a_login_account_only_notifies_management_pages](tests/Feature/Realtime/UserSyncTest.php#L119)
- [test_updating_a_leave_balance_with_increment_still_notifies_the_owner](tests/Feature/Realtime/UserSyncTest.php#L136)
- [test_closing_a_payroll_notifies_every_employee_on_it_about_payslips](tests/Feature/Realtime/UserSyncTest.php#L152)

</details>

<details>
<summary><code>ResourceSyncTest.php</code> — 10 test</summary>

- **Mã nguồn:** [tests/Feature/ResourceSyncTest.php](tests/Feature/ResourceSyncTest.php#L1)
- [test_admin_can_authorize_every_resource_channel](tests/Feature/ResourceSyncTest.php#L109)
- [test_plain_employee_cannot_authorize_any_management_resource_channel](tests/Feature/ResourceSyncTest.php#L118)
- [test_unknown_resource_name_is_always_forbidden](tests/Feature/ResourceSyncTest.php#L127)
- [test_department_mutations_dispatch_resource_changed](tests/Feature/ResourceSyncTest.php#L134)
- [test_position_mutations_dispatch_resource_changed](tests/Feature/ResourceSyncTest.php#L147)
- [test_role_and_permission_mutations_dispatch_resource_changed_as_roles](tests/Feature/ResourceSyncTest.php#L160)
- [test_work_shift_mutations_dispatch_resource_changed](tests/Feature/ResourceSyncTest.php#L175)
- [test_attendance_adjustment_request_and_decision_dispatch_resource_changed](tests/Feature/ResourceSyncTest.php#L188)
- [test_employee_update_and_delete_dispatch_resource_changed](tests/Feature/ResourceSyncTest.php#L216)
- [test_payroll_close_and_mark_as_paid_dispatch_resource_changed](tests/Feature/ResourceSyncTest.php#L227)

</details>

</details>

---

## 12. Thành phần dùng chung (FE)

- **Điểm vào:** Dùng ở mọi màn hình; không có route riêng.
- **Danh mục file & hàm:** xem [cuối mục này](#danh-mục-file--hàm-module-12) — mỗi file và mỗi hàm public có link riêng.


**Quy ước UI cần nhớ**
- **Form**: `validate-on="blur invalid-input lazy"` — không đỏ sẵn lúc mở, báo lỗi khi rời ô, hết lỗi ngay khi sửa đúng, sửa ô nào thì lỗi 422 cũ của ô đó biến mất. `FormDialog` chỉ cần thêm `:rules` phản chiếu rule Backend; dialog tự dựng đổi `<v-card-text>` thành `<v-form class="v-card-text">` + `validateThen(formRef, submit)`. Form sửa: `guard = useChangeGuard(() => ({...}))` (bọc ngoặc object literal), `guard.takeSnapshot()` sau khi nạp, `guard.skipIfUnchanged()` đầu hàm lưu.
- **Nút**: thao tác chính `color="primary"`, nút phụ để trống màu; không dùng `secondary` cho nút (nhìn như chữ tĩnh); success/warning/error chỉ cho trạng thái. Phần tử tự làm "bấm được" (không phải `v-btn`) cần `cursor: pointer` + hover. Hover mờ chỉnh ở `plugins/vuetify.js`.
- **Responsive**: Check-in tách `CheckInDesktop`/`CheckInMobile` dùng chung `useCheckIn`; các màn nhân viên khác dùng `useDisplay().mobile` trong cùng file (`v-if` danh sách thẻ, `v-else` `<v-table>`). Dialog `fullscreen` không dùng `.glass-panel` (nền trong suốt làm chữ phía sau xuyên qua). Chưa làm: `MyProfileTransfersTab.vue` và các trang admin dùng `DataTable`.
- **Lịch chọn ngày**: rule `.v-date-picker-controls .v-btn.qlns-btn:not(.v-btn--icon)` padding 8px — nếu không, `.qlns-btn` (padding 16px) làm lịch bị cắt cột CN + mũi tên chuyển năm.
- `FilePreviewDialog`: `pdfDoc` phải là `shallowRef`, giải phóng bằng `destroy()`, hủy `renderTask` cũ trước khi vẽ lại. Controller khai namespace `Api\V1`: Linux phân biệt hoa/thường, kiểm lại khi thêm Controller mới.
- Test: dựng `Attendance` có cast `datetime` phải kế thừa `Tests\TestCase` (không dùng `PHPUnit\Framework\TestCase` trần); gọi hàm `private` qua trait `InteractsWithPrivateMethods`.

<a id="danh-mục-file--hàm-module-12"></a>

### Danh mục file & hàm — module 12

#### Giao diện (19 file)

- [AppLoadingBar.vue](resources/js/components/common/AppLoadingBar.vue#L1)
- [AppToast.vue](resources/js/components/common/AppToast.vue#L1)
- [DataTable.vue](resources/js/components/common/DataTable.vue#L1)
- [FilePreviewDialog.vue](resources/js/components/common/FilePreviewDialog.vue#L1)
- [FormDialog.vue](resources/js/components/common/FormDialog.vue#L1)
- [FormField.vue](resources/js/components/common/FormField.vue#L1)
- [FormSection.vue](resources/js/components/common/FormSection.vue#L1)
- [InputDate.vue](resources/js/components/common/InputDate.vue#L1)
- [InputFile.vue](resources/js/components/common/InputFile.vue#L1)
- [InputMoney.vue](resources/js/components/common/InputMoney.vue#L1)
- [PageHeader.vue](resources/js/components/common/PageHeader.vue#L1)
- [SearchField.vue](resources/js/components/common/SearchField.vue#L1)
- [SearchSelect.vue](resources/js/components/common/SearchSelect.vue#L1)
- [StatusChip.vue](resources/js/components/common/StatusChip.vue#L1)
- [useLoadingStore.js](resources/js/stores/useLoadingStore.js#L1)
- [useToastStore.js](resources/js/stores/useToastStore.js#L1)
- [useChangeGuard.js](resources/js/composables/useChangeGuard.js#L1)
- [useRealtimeRefresh.js](resources/js/composables/useRealtimeRefresh.js#L1)
- [validationRules.js](resources/js/composables/validationRules.js#L1)

#### Migration (2 file)

- [0001_01_01_000001_create_cache_table.php](database/migrations/0001_01_01_000001_create_cache_table.php)
- [0001_01_01_000002_create_jobs_table.php](database/migrations/0001_01_01_000002_create_jobs_table.php)

#### Kiểm thử (2 file, 2 test)

- [ExampleTest.php](tests/Feature/ExampleTest.php#L1) — 1 test
- [ExampleTest.php](tests/Unit/ExampleTest.php#L1) — 1 test

<details>
<summary><strong>Chi tiết từng màn hình Vue</strong> — 19 file</summary>

<details>
<summary><code>AppLoadingBar.vue</code></summary>

- **Mã nguồn:** [resources/js/components/common/AppLoadingBar.vue](resources/js/components/common/AppLoadingBar.vue#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).

</details>

<details>
<summary><code>AppToast.vue</code></summary>

- **Mã nguồn:** [resources/js/components/common/AppToast.vue](resources/js/components/common/AppToast.vue#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).

</details>

<details>
<summary><code>DataTable.vue</code></summary>

- **Mã nguồn:** [resources/js/components/common/DataTable.vue](resources/js/components/common/DataTable.vue#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).

</details>

<details>
<summary><code>FilePreviewDialog.vue</code></summary>

- **Mã nguồn:** [resources/js/components/common/FilePreviewDialog.vue](resources/js/components/common/FilePreviewDialog.vue#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).

</details>

<details>
<summary><code>FormDialog.vue</code></summary>

- **Mã nguồn:** [resources/js/components/common/FormDialog.vue](resources/js/components/common/FormDialog.vue#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).

</details>

<details>
<summary><code>FormField.vue</code></summary>

- **Mã nguồn:** [resources/js/components/common/FormField.vue](resources/js/components/common/FormField.vue#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).

</details>

<details>
<summary><code>FormSection.vue</code></summary>

- **Mã nguồn:** [resources/js/components/common/FormSection.vue](resources/js/components/common/FormSection.vue#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).

</details>

<details>
<summary><code>InputDate.vue</code></summary>

- **Mã nguồn:** [resources/js/components/common/InputDate.vue](resources/js/components/common/InputDate.vue#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).

</details>

<details>
<summary><code>InputFile.vue</code></summary>

- **Mã nguồn:** [resources/js/components/common/InputFile.vue](resources/js/components/common/InputFile.vue#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).

</details>

<details>
<summary><code>InputMoney.vue</code></summary>

- **Mã nguồn:** [resources/js/components/common/InputMoney.vue](resources/js/components/common/InputMoney.vue#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).

</details>

<details>
<summary><code>PageHeader.vue</code></summary>

- **Mã nguồn:** [resources/js/components/common/PageHeader.vue](resources/js/components/common/PageHeader.vue#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).

</details>

<details>
<summary><code>SearchField.vue</code></summary>

- **Mã nguồn:** [resources/js/components/common/SearchField.vue](resources/js/components/common/SearchField.vue#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).

</details>

<details>
<summary><code>SearchSelect.vue</code></summary>

- **Mã nguồn:** [resources/js/components/common/SearchSelect.vue](resources/js/components/common/SearchSelect.vue#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).

</details>

<details>
<summary><code>StatusChip.vue</code></summary>

- **Mã nguồn:** [resources/js/components/common/StatusChip.vue](resources/js/components/common/StatusChip.vue#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).

</details>

<details>
<summary><code>useLoadingStore.js</code></summary>

- **Mã nguồn:** [resources/js/stores/useLoadingStore.js](resources/js/stores/useLoadingStore.js#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).

</details>

<details>
<summary><code>useToastStore.js</code></summary>

- **Mã nguồn:** [resources/js/stores/useToastStore.js](resources/js/stores/useToastStore.js#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).

</details>

<details>
<summary><code>useChangeGuard.js</code></summary>

- **Mã nguồn:** [resources/js/composables/useChangeGuard.js](resources/js/composables/useChangeGuard.js#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).

</details>

<details>
<summary><code>useRealtimeRefresh.js</code></summary>

- **Mã nguồn:** [resources/js/composables/useRealtimeRefresh.js](resources/js/composables/useRealtimeRefresh.js#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).
- **Store dùng:** `useAuthStore`, `useMySyncStore`, `useResourceSyncStore`

</details>

<details>
<summary><code>validationRules.js</code></summary>

- **Mã nguồn:** [resources/js/composables/validationRules.js](resources/js/composables/validationRules.js#L1)
- **Gọi API:** không gọi trực tiếp (qua component/store bên dưới hoặc chỉ hiển thị).

</details>

</details>

<details>
<summary><strong>Danh sách test</strong> — 2 test</summary>

<details>
<summary><code>ExampleTest.php</code> — 1 test</summary>

- **Mã nguồn:** [tests/Feature/ExampleTest.php](tests/Feature/ExampleTest.php#L1)
- [test_the_application_entry_page_contains_the_vue_mount_point](tests/Feature/ExampleTest.php#L13)

</details>

<details>
<summary><code>ExampleTest.php</code> — 1 test</summary>

- **Mã nguồn:** [tests/Unit/ExampleTest.php](tests/Unit/ExampleTest.php#L1)
- [test_that_true_is_true](tests/Unit/ExampleTest.php#L12)

</details>

</details>

---


---

## Liên thông giữa module

Dùng khi lỗi xuất hiện ở module sau nhưng nguyên nhân nằm ở dữ liệu nguồn của module trước.

| Từ → Tới | Ghi chú |
| --- | --- |
| Chấm công (6) → Lương (10) | Chỉ bản ghi `approved` thành công; còn bản ghi đã chấm ra mà chưa duyệt thì không tạo được bảng lương |
| Nghỉ phép (8) → Chấm công (6) / Lương (10) | Ngày có đơn đã duyệt hiện "Nghỉ phép", không tính vắng; phép có lương cộng vào công được hưởng, phép không lương dùng để xét bảo hiểm |
| Ca (5) → Chấm công (6), Lương (10) | Giờ, giờ nghỉ trưa, `work_coefficient`; ca xóa mềm → 0 công |
| Điều chỉnh (7) → Chấm công (6) | `applyAdjustment`, `late_excused`, `overtime_approved`; `extra_shift` mở khóa chấm công qua bản gán 1 ngày |
| Hợp đồng / Nghỉ việc (4, 9) → Tài khoản (1) | Thay đổi hiệu lực → khóa/mở đăng nhập và trạng thái nhân viên |
| Phòng ban (3) → Duyệt (8, 9) | Trưởng phòng = quản lý trực tiếp = người duyệt cấp 1 |
| Mọi module → Thông báo (11) | Sau commit gọi `NotificationService`; model tự phát tín hiệu realtime |

**Ví dụ xuyên module — một nhân viên làm việc đến khi nhận lương:**
`Hợp đồng (4) → Ca mặc định (5) → Chấm công vào/ra (6) → HR duyệt (6) [+ đơn điều chỉnh (7), nghỉ phép (8)] → Tạo bảng lương (10) → Chốt → Nhân viên xem phiếu ở Hồ sơ của tôi (4) → thông báo/realtime xuyên suốt (11)`.

## Tra cứu theo chức năng (API → Controller → màn hình)

> Sinh tự động từ `routes/api/v1/*.php`, Controller và `resources/js/services/*.js` (cột "FE service" và "Màn hình gọi" tìm được khi service dùng đường dẫn trực tiếp). Mở nhóm theo module API. Dùng khi chỉ nhớ chức năng, chưa biết tên file.

<details>
<summary><strong>Mở bảng tra cứu (106 API)</strong></summary>

#### dashboard-search

| API | Controller (dòng) | Quyền | FE service | Màn hình/Component gọi |
| --- | --- | --- | --- | --- |
| `GET /dashboard` | [DashboardController::index()](app/Http/Controllers/Api/V1/DashboardController.php) | `auth` | `dashboardService.get` | `Dashboard.vue` |
| `GET /search` | [SearchController::index()](app/Http/Controllers/Api/V1/SearchController.php) | `auth` (SearchService tự lọc theo quyền) | `searchService.search` | `components/layout/QuickSearch.vue` |

#### addresses

| API | Controller (dòng) | Quyền | FE service | Màn hình/Component gọi |
| --- | --- | --- | --- | --- |
| `GET /addresses/provinces` | [AddressController::provinces()](app/Http/Controllers/Api/V1/AddressController.php#L16) | `auth` | `addressService.provinces` | `Employee/EmployeeForm.vue`, `Me/MyProfileInfoTab.vue` |
| `GET /addresses/communes` | [AddressController::communes()](app/Http/Controllers/Api/V1/AddressController.php#L21) | `auth` | `addressService.communes` | `Employee/EmployeeForm.vue`, `Me/MyProfileInfoTab.vue` |

#### attendances

| API | Controller (dòng) | Quyền | FE service | Màn hình/Component gọi |
| --- | --- | --- | --- | --- |
| `POST /attendances/check-in` | [AttendanceController::checkIn()](app/Http/Controllers/Api/V1/AttendanceController.php#L24) | `attendance.check` | `attendanceService.checkIn` | `composables/useCheckIn.js` |
| `POST /attendances/check-out` | [AttendanceController::checkOut()](app/Http/Controllers/Api/V1/AttendanceController.php#L36) | `attendance.check` | `attendanceService.checkOut` | `composables/useCheckIn.js` |
| `GET /attendances/today` | [AttendanceController::today()](app/Http/Controllers/Api/V1/AttendanceController.php#L51) | `attendance.check` | `attendanceService.today` | `composables/useCheckIn.js` |
| `GET /attendances/me` | [AttendanceController::mine()](app/Http/Controllers/Api/V1/AttendanceController.php#L60) | `attendance.view_own` | `attendanceService.myHistory` | `composables/useCheckIn.js` |
| `GET /attendances/history/me` | [AttendanceController::historyMine()](app/Http/Controllers/Api/V1/AttendanceController.php#L126) | `attendance.view_own` | `attendanceService.historyMine` | `Attendance/AttendanceHistoryPanel.vue` |
| `GET /attendances/history/{employee}` | [AttendanceController::history()](app/Http/Controllers/Api/V1/AttendanceController.php#L137) | `attendance.view_all` | `attendanceService.history` | `Attendance/AttendanceHistoryPanel.vue` |
| `GET /attendances/overview` | [AttendanceController::overview()](app/Http/Controllers/Api/V1/AttendanceController.php#L111) | `attendance.view_all` | `attendanceService.dailyOverview` | `Attendance/AttendanceOverview.vue` |
| `GET /attendances` | [AttendanceController::index()](app/Http/Controllers/Api/V1/AttendanceController.php#L69) | `attendance.view_all` | — | — |
| `POST /attendances/adjustments` | [AttendanceAdjustmentController::store()](app/Http/Controllers/Api/V1/AttendanceAdjustmentController.php#L21) | `attendance.check` | `attendanceService.requestAdjustment` | `composables/useCheckIn.js` |
| `GET /attendances/adjustments/me` | [AttendanceAdjustmentController::mine()](app/Http/Controllers/Api/V1/AttendanceAdjustmentController.php#L37) | `attendance.view_own` | `attendanceService.myAdjustments` | `composables/useCheckIn.js` |
| `GET /attendances/adjustments` | [AttendanceAdjustmentController::index()](app/Http/Controllers/Api/V1/AttendanceAdjustmentController.php#L46) | `attendance.adjust` | `attendanceService.listAdjustments` | `Attendance/AttendanceAdjustments.vue` |
| `PUT /attendances/adjustments/{adjustment}` | [AttendanceAdjustmentController::decide()](app/Http/Controllers/Api/V1/AttendanceAdjustmentController.php#L54) | `attendance.adjust` | `attendanceService.decideAdjustment` | `Attendance/AttendanceAdjustments.vue` |
| `PUT /attendances/bulk-approval` | [AttendanceController::bulkDecideApproval()](app/Http/Controllers/Api/V1/AttendanceController.php#L93) | `attendance.approve` | `attendanceService.bulkDecideApproval` | `Attendance/AttendanceOverview.vue` |
| `PUT /attendances/{attendance}/approval` | [AttendanceController::decideApproval()](app/Http/Controllers/Api/V1/AttendanceController.php#L80) | `attendance.approve` | `attendanceService.decideApproval` | `Attendance/AttendanceOverview.vue` |

#### auth

| API | Controller (dòng) | Quyền | FE service | Màn hình/Component gọi |
| --- | --- | --- | --- | --- |
| `POST /auth/login` | [AuthController::login()](app/Http/Controllers/Api/V1/AuthController.php#L60) | `throttle` | `authService.login` | `stores/authStore.js` |
| `POST /auth/refresh` | [AuthController::refresh()](app/Http/Controllers/Api/V1/AuthController.php#L86) | `throttle` | `authService.refresh` | `stores/authStore.js` |
| `POST /auth/forgot-password` | [PasswordResetController::forgot()](app/Http/Controllers/Api/V1/PasswordResetController.php#L34) | `throttle` | `authService.forgotPassword` | `ForgotPassword.vue` |
| `POST /auth/reset-password` | [PasswordResetController::reset()](app/Http/Controllers/Api/V1/PasswordResetController.php#L63) | `throttle` | `authService.resetPassword` | `ResetPassword.vue` |
| `GET /auth/me` | [AuthController::me()](app/Http/Controllers/Api/V1/AuthController.php#L135) | `auth` | `authService.me` | `stores/authStore.js` |
| `POST /auth/logout` | [AuthController::logout()](app/Http/Controllers/Api/V1/AuthController.php#L116) | `auth` | `authService.logout` | `stores/authStore.js` |

#### departments

| API | Controller (dòng) | Quyền | FE service | Màn hình/Component gọi |
| --- | --- | --- | --- | --- |
| `GET /departments` | [DepartmentController::index()](app/Http/Controllers/Api/V1/DepartmentController.php#L20) | `department.view` | `departmentService.list` | `stores/useDepartmentStore.js` |
| `POST /departments` | [DepartmentController::store()](app/Http/Controllers/Api/V1/DepartmentController.php#L25) | `department.manage` | `departmentService.create` | `stores/useDepartmentStore.js` |
| `GET /departments/tree` | [DepartmentController::tree()](app/Http/Controllers/Api/V1/DepartmentController.php#L47) | `department.view` | `departmentService.tree` | `Employee/EmployeeTransfersTab.vue`, `stores/useDepartmentStore.js` |
| `PUT /departments/{department}` | [DepartmentController::update()](app/Http/Controllers/Api/V1/DepartmentController.php#L33) | `department.manage` | `departmentService.update` | `stores/useDepartmentStore.js` |
| `DELETE /departments/{department}` | [DepartmentController::destroy()](app/Http/Controllers/Api/V1/DepartmentController.php#L41) | `department.manage` | `departmentService.remove` | `stores/useDepartmentStore.js` |

#### employees

| API | Controller (dòng) | Quyền | FE service | Màn hình/Component gọi |
| --- | --- | --- | --- | --- |
| `GET /employees` | [EmployeeController::index()](app/Http/Controllers/Api/V1/EmployeeController.php#L28) | `employee.view` | `employeeService.list` | `stores/useEmployeeStore.js` |
| `POST /employees` | [EmployeeController::store()](app/Http/Controllers/Api/V1/EmployeeController.php#L90) | `employee.create` | `employeeService.create` | `stores/useEmployeeStore.js` |
| `GET /employees/stats` | [EmployeeController::stats()](app/Http/Controllers/Api/V1/EmployeeController.php#L55) | `employee.view` | `employeeService.stats` | `Employee/Employees.vue` |
| `GET /employees/check-unique` | [EmployeeController::checkUnique()](app/Http/Controllers/Api/V1/EmployeeController.php#L39) | `employee.create,employee.update` | `employeeService.checkUnique` | `Employee/EmployeeForm.vue` |
| `GET /employees/me` | [EmployeeController::me()](app/Http/Controllers/Api/V1/EmployeeController.php#L64) | `auth` | `employeeService.me` | `Me/MyProfile.vue` |
| `PUT /employees/me` | [EmployeeController::updateMine()](app/Http/Controllers/Api/V1/EmployeeController.php#L76) | `auth` | `employeeService.updateMe` | `Me/MyProfileInfoTab.vue` |
| `POST /employees/me/avatar` | [EmployeeController::uploadMyAvatar()](app/Http/Controllers/Api/V1/EmployeeController.php#L120) | `auth` | `employeeService.uploadMyAvatar` | `Me/MyProfile.vue` |
| `GET /employees/me/contracts` | [EmployeeContractController::mine()](app/Http/Controllers/Api/V1/EmployeeContractController.php#L31) | `auth` | `employeeService.myContracts` | `Me/MyProfileContractsTab.vue` |
| `GET /employees/me/documents` | [EmployeeDocumentController::mine()](app/Http/Controllers/Api/V1/EmployeeDocumentController.php#L32) | `auth` | `employeeService.myDocuments` | `Me/MyProfileDocumentsTab.vue` |
| `POST /employees/me/documents` | [EmployeeDocumentController::storeMine()](app/Http/Controllers/Api/V1/EmployeeDocumentController.php#L44) | `auth` | `employeeService.uploadMyDocument` | `Me/MyProfileDocumentsTab.vue` |
| `GET /employees/me/shift-assignments` | [EmployeeShiftAssignmentController::mine()](app/Http/Controllers/Api/V1/EmployeeShiftAssignmentController.php#L27) | `auth` | `employeeService.myShiftAssignments` | `Attendance/AttendanceHistoryPanel.vue`, `Me/MyProfileShiftsTab.vue`, `composables/useCheckIn.js` |
| `GET /employees/me/transfers` | [EmployeeTransferController::mine()](app/Http/Controllers/Api/V1/EmployeeTransferController.php#L36) | `auth` | `employeeService.myTransfers` | `Me/MyProfileTransfersTab.vue` |
| `GET /employees/{employee}` | [EmployeeController::show()](app/Http/Controllers/Api/V1/EmployeeController.php#L86) | `employee.view` | `employeeService.get` | `Employee/EmployeeDetail.vue` |
| `PUT /employees/{employee}` | [EmployeeController::update()](app/Http/Controllers/Api/V1/EmployeeController.php#L96) | `employee.update` | `employeeService.update` | `stores/useEmployeeStore.js` |
| `DELETE /employees/{employee}` | [EmployeeController::destroy()](app/Http/Controllers/Api/V1/EmployeeController.php#L102) | `employee.delete` | — | — |
| `POST /employees/{employee}/avatar` | [EmployeeController::uploadAvatar()](app/Http/Controllers/Api/V1/EmployeeController.php#L108) | `employee.update` | — | — |
| `POST /employees/{employee}/account` | [EmployeeAccountController::store()](app/Http/Controllers/Api/V1/EmployeeAccountController.php#L17) | `employee.update` | `employeeService.createAccount` | `Employee/EmployeeForm.vue`, `Employee/EmployeeProfileTab.vue` |
| `GET /employees/{employee}/contracts` | [EmployeeContractController::index()](app/Http/Controllers/Api/V1/EmployeeContractController.php#L23) | `employee.view` | `employeeService.contracts` | `Employee/EmployeeContractsTab.vue` |
| `POST /employees/{employee}/contracts` | [EmployeeContractController::store()](app/Http/Controllers/Api/V1/EmployeeContractController.php#L40) | `employee.update` | `employeeService.createContract` | `Employee/EmployeeContractsTab.vue` |
| `POST /employees/{employee}/contracts/{contract}/terminate` | [EmployeeContractController::terminate()](app/Http/Controllers/Api/V1/EmployeeContractController.php#L46) | `employee.update` | `employeeService.terminateContract` | `Employee/EmployeeContractsTab.vue` |
| `GET /employees/{employee}/contracts/{contract}/download` | [EmployeeContractController::download()](app/Http/Controllers/Api/V1/EmployeeContractController.php#L57) | `auth` | — | — |
| `GET /employees/{employee}/documents` | [EmployeeDocumentController::index()](app/Http/Controllers/Api/V1/EmployeeDocumentController.php#L23) | `employee.view` | `employeeService.documents` | `Employee/EmployeeDocumentsTab.vue` |
| `POST /employees/{employee}/documents` | [EmployeeDocumentController::store()](app/Http/Controllers/Api/V1/EmployeeDocumentController.php#L60) | `employee.update` | — | — |
| `DELETE /employees/{employee}/documents/{document}` | [EmployeeDocumentController::destroy()](app/Http/Controllers/Api/V1/EmployeeDocumentController.php#L72) | `employee.update` | `employeeService.deleteDocument` | `Employee/EmployeeDocumentsTab.vue` |
| `GET /employees/{employee}/documents/{document}/download` | [EmployeeDocumentController::download()](app/Http/Controllers/Api/V1/EmployeeDocumentController.php#L83) | `auth` | — | — |
| `GET /employees/{employee}/transfers` | [EmployeeTransferController::index()](app/Http/Controllers/Api/V1/EmployeeTransferController.php#L23) | `employee.view` | `employeeService.transfers` | `Employee/EmployeeTransfersTab.vue` |
| `POST /employees/{employee}/transfers` | [EmployeeTransferController::store()](app/Http/Controllers/Api/V1/EmployeeTransferController.php#L45) | `employee.update` | `employeeService.createTransfer` | `Employee/EmployeeTransfersTab.vue` |
| `GET /employees/{employee}/transfers/{transfer}/download` | [EmployeeTransferController::download()](app/Http/Controllers/Api/V1/EmployeeTransferController.php#L57) | `auth` | — | — |
| `GET /employees/{employee}/payslips` | [PayrollController::forEmployee()](app/Http/Controllers/Api/V1/PayrollController.php#L41) | `payroll.view_all` | `employeeService.payslips` | `Employee/EmployeeSalaryLeaveTab.vue` |
| `GET /employees/{employee}/shift-assignments` | [EmployeeShiftAssignmentController::index()](app/Http/Controllers/Api/V1/EmployeeShiftAssignmentController.php#L20) | `shift.view` | `employeeService.shiftAssignments` | `Attendance/AttendanceHistoryPanel.vue`, `Employee/EmployeeShiftAssignmentsTab.vue` |
| `POST /employees/{employee}/shift-assignments` | [EmployeeShiftAssignmentController::store()](app/Http/Controllers/Api/V1/EmployeeShiftAssignmentController.php#L36) | `shift.manage` | `employeeService.createShiftAssignment` | `Employee/EmployeeShiftAssignmentsTab.vue` |
| `PUT /employees/{employee}/shift-assignments/{shiftAssignment}` | [EmployeeShiftAssignmentController::update()](app/Http/Controllers/Api/V1/EmployeeShiftAssignmentController.php#L44) | `shift.manage` | `employeeService.updateShiftAssignment` | `Employee/EmployeeShiftAssignmentsTab.vue` |
| `DELETE /employees/{employee}/shift-assignments/{shiftAssignment}` | [EmployeeShiftAssignmentController::destroy()](app/Http/Controllers/Api/V1/EmployeeShiftAssignmentController.php#L56) | `shift.manage` | `employeeService.deleteShiftAssignment` | `Employee/EmployeeShiftAssignmentsTab.vue` |

#### leave-requests

| API | Controller (dòng) | Quyền | FE service | Màn hình/Component gọi |
| --- | --- | --- | --- | --- |
| `POST /leave-requests` | [LeaveRequestController::store()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L28) | `leave.request` | `leaveRequestService.create` | `Leave/LeaveRequests.vue` |
| `GET /leave-requests/me` | [LeaveRequestController::mine()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L44) | `leave.view_own` | `leaveRequestService.mine` | `Leave/LeaveRequests.vue` |
| `GET /leave-requests/balances/me` | [LeaveRequestController::balancesMine()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L82) | `leave.view_own` | `leaveRequestService.balancesMine` | `Leave/LeaveRequests.vue` |
| `GET /leave-requests/balances/{employee}` | [LeaveRequestController::balances()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L93) | `leave.view_all` | `leaveRequestService.balancesForEmployee` | `Employee/EmployeeSalaryLeaveTab.vue` |
| `GET /leave-requests` | [LeaveRequestController::index()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L53) | `leave.view_all` | `leaveRequestService.list` | `Leave/LeaveManagement.vue` |
| `GET /leave-requests/overview` | [LeaveRequestController::overview()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L62) | `leave.view_all` | `leaveRequestService.overview` | `Leave/LeaveOverview.vue` |
| `PUT /leave-requests/bulk-decide` | [LeaveRequestController::bulkDecide()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L127) | `leave.approve_manager,leave.approve_hr` | `leaveRequestService.bulkDecide` | `Leave/LeaveManagement.vue` |
| `PUT /leave-requests/{leaveRequest}/decide` | [LeaveRequestController::decide()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L111) | `leave.approve_manager,leave.approve_hr` | `leaveRequestService.decide` | `Leave/LeaveManagement.vue` |
| `GET /leave-requests/{leaveRequest}/evidence` | [LeaveRequestController::downloadEvidence()](app/Http/Controllers/Api/V1/LeaveRequestController.php#L101) | `auth` | — | — |

#### leave-types

| API | Controller (dòng) | Quyền | FE service | Màn hình/Component gọi |
| --- | --- | --- | --- | --- |
| `GET /leave-types` | [LeaveTypeController::index()](app/Http/Controllers/Api/V1/LeaveTypeController.php#L14) | `auth` | `leaveTypeService.list` | `Leave/LeaveRequests.vue` |

#### notifications

| API | Controller (dòng) | Quyền | FE service | Màn hình/Component gọi |
| --- | --- | --- | --- | --- |
| `GET /notifications` | [NotificationController::index()](app/Http/Controllers/Api/V1/NotificationController.php#L18) | `auth` | `notificationService.list` | `Notification/Notifications.vue`, `stores/useNotificationStore.js` |
| `PATCH /notifications/read-all` | [NotificationController::markAllRead()](app/Http/Controllers/Api/V1/NotificationController.php#L44) | `auth` | `notificationService.markAllRead` | `stores/useNotificationStore.js` |
| `PATCH /notifications/{notification}/read` | [NotificationController::markRead()](app/Http/Controllers/Api/V1/NotificationController.php#L37) | `auth` | `notificationService.markRead` | `stores/useNotificationStore.js` |

#### payrolls

| API | Controller (dòng) | Quyền | FE service | Màn hình/Component gọi |
| --- | --- | --- | --- | --- |
| `GET /payrolls` | [PayrollController::index()](app/Http/Controllers/Api/V1/PayrollController.php#L23) | `payroll.view_all` | `payrollService.list` | `Payroll/PayrollList.vue` |
| `GET /payrolls/me` | [PayrollController::mine()](app/Http/Controllers/Api/V1/PayrollController.php#L31) | `payroll.view_own` | `payrollService.mine` | `Me/MyProfilePayslipsTab.vue` |
| `GET /payrolls/{payroll}` | [PayrollController::show()](app/Http/Controllers/Api/V1/PayrollController.php#L54) | `payroll.view_all` | `payrollService.show` | `Payroll/PayrollDetail.vue` |
| `GET /payrolls/{payroll}/details/{payrollDetail}/workdays` | [PayrollController::workdays()](app/Http/Controllers/Api/V1/PayrollController.php#L47) | `payroll.view_all` | `payrollService.workdays` | `Payroll/PayrollWorkdaysDialog.vue` |
| `POST /payrolls/generate` | [PayrollController::generate()](app/Http/Controllers/Api/V1/PayrollController.php#L59) | `payroll.manage` | `payrollService.generate` | `Payroll/PayrollGenerateDialog.vue` |
| `POST /payrolls/{payroll}/close` | [PayrollController::close()](app/Http/Controllers/Api/V1/PayrollController.php#L75) | `payroll.manage` | `payrollService.close` | `Payroll/PayrollList.vue` |
| `POST /payrolls/{payroll}/mark-paid` | [PayrollController::markAsPaid()](app/Http/Controllers/Api/V1/PayrollController.php#L80) | `payroll.manage` | `payrollService.markAsPaid` | `Payroll/PayrollList.vue` |

#### permissions

| API | Controller (dòng) | Quyền | FE service | Màn hình/Component gọi |
| --- | --- | --- | --- | --- |
| `GET /permissions` | [PermissionController::index()](app/Http/Controllers/Api/V1/PermissionController.php#L19) | `rbac.manage` | `permissionService.list` | `stores/usePermissionStore.js` |
| `POST /permissions` | [PermissionController::store()](app/Http/Controllers/Api/V1/PermissionController.php#L27) | `rbac.manage` | `permissionService.create` | `stores/usePermissionStore.js` |
| `PUT /permissions/{permission}` | [PermissionController::update()](app/Http/Controllers/Api/V1/PermissionController.php#L34) | `rbac.manage` | `permissionService.update` | — |
| `DELETE /permissions/{permission}` | [PermissionController::destroy()](app/Http/Controllers/Api/V1/PermissionController.php#L41) | `rbac.manage` | `permissionService.remove` | `stores/usePermissionStore.js` |

#### positions

| API | Controller (dòng) | Quyền | FE service | Màn hình/Component gọi |
| --- | --- | --- | --- | --- |
| `GET /positions` | [PositionController::index()](app/Http/Controllers/Api/V1/PositionController.php#L19) | `department.view` | `positionService.list` | `Employee/EmployeeForm.vue`, `Employee/EmployeeTransfersTab.vue`, `Position/Positions.vue` |
| `POST /positions` | [PositionController::store()](app/Http/Controllers/Api/V1/PositionController.php#L27) | `department.manage` | `positionService.create` | `Position/PositionForm.vue` |
| `PUT /positions/{position}` | [PositionController::update()](app/Http/Controllers/Api/V1/PositionController.php#L34) | `department.manage` | `positionService.update` | `Position/PositionForm.vue` |
| `DELETE /positions/{position}` | [PositionController::destroy()](app/Http/Controllers/Api/V1/PositionController.php#L41) | `department.manage` | `positionService.remove` | `Position/Positions.vue` |

#### resignations

| API | Controller (dòng) | Quyền | FE service | Màn hình/Component gọi |
| --- | --- | --- | --- | --- |
| `POST /resignations` | [ResignationRequestController::store()](app/Http/Controllers/Api/V1/ResignationRequestController.php#L21) | `resignation.request` | `resignationService.create` | `Me/MyProfile.vue` |
| `GET /resignations/me` | [ResignationRequestController::mine()](app/Http/Controllers/Api/V1/ResignationRequestController.php#L53) | `resignation.request` | `resignationService.mine` | `Me/MyProfile.vue` |
| `GET /resignations/policy` | [ResignationRequestController::policy()](app/Http/Controllers/Api/V1/ResignationRequestController.php#L44) | `resignation.request` | `resignationService.policy` | `Me/MyProfile.vue` |
| `POST /resignations/{resignationRequest}/cancel` | [ResignationRequestController::cancel()](app/Http/Controllers/Api/V1/ResignationRequestController.php#L62) | `resignation.request` | `resignationService.cancel` | `Me/MyProfile.vue` |
| `GET /resignations` | [ResignationRequestController::index()](app/Http/Controllers/Api/V1/ResignationRequestController.php#L73) | `resignation.approve` | `resignationService.list` | `Resignation/Resignations.vue` |
| `GET /resignations/{id}` | [ResignationRequestController::show()](app/Http/Controllers/Api/V1/ResignationRequestController.php#L81) | `resignation.approve` | `resignationService.show` | `Resignation/Resignations.vue` |
| `PUT /resignations/{id}/decide` | [ResignationRequestController::decide()](app/Http/Controllers/Api/V1/ResignationRequestController.php#L90) | `resignation.approve` | `resignationService.decide` | `Resignation/Resignations.vue` |

#### roles

| API | Controller (dòng) | Quyền | FE service | Màn hình/Component gọi |
| --- | --- | --- | --- | --- |
| `GET /roles` | [RoleController::index()](app/Http/Controllers/Api/V1/RoleController.php#L24) | `employee.update` | `roleService.list` | `Employee/EmployeeForm.vue`, `Employee/EmployeeProfileTab.vue`, `stores/useRoleStore.js` |
| `POST /roles` | [RoleController::store()](app/Http/Controllers/Api/V1/RoleController.php#L44) | `rbac.manage` | `roleService.create` | `stores/useRoleStore.js` |
| `GET /roles/{role}` | [RoleController::show()](app/Http/Controllers/Api/V1/RoleController.php#L37) | `rbac.manage` | `roleService.show` | `Role/RolePermissionsDialog.vue` |
| `PUT /roles/{role}` | [RoleController::update()](app/Http/Controllers/Api/V1/RoleController.php#L51) | `rbac.manage` | `roleService.update` | `stores/useRoleStore.js` |
| `DELETE /roles/{role}` | [RoleController::destroy()](app/Http/Controllers/Api/V1/RoleController.php#L58) | `rbac.manage` | `roleService.remove` | `stores/useRoleStore.js` |
| `PUT /roles/{role}/permissions` | [RoleController::updatePermissions()](app/Http/Controllers/Api/V1/RoleController.php#L65) | `rbac.manage` | `roleService.updatePermissions` | `Role/RolePermissionsDialog.vue` |

#### work-shifts

| API | Controller (dòng) | Quyền | FE service | Màn hình/Component gọi |
| --- | --- | --- | --- | --- |
| `GET /work-shifts/default` | [WorkShiftController::showDefault()](app/Http/Controllers/Api/V1/WorkShiftController.php#L29) | `shift.view` | `workShiftService.getDefault` | `Settings/Settings.vue` |
| `GET /work-shifts` | [WorkShiftController::index()](app/Http/Controllers/Api/V1/WorkShiftController.php#L19) | `shift.view,attendance.check` | `workShiftService.list` | `Attendance/AttendanceOverview.vue`, `Employee/EmployeeShiftAssignmentsTab.vue`, `WorkShift/WorkShifts.vue`, `composables/useCheckIn.js` |
| `POST /work-shifts` | [WorkShiftController::store()](app/Http/Controllers/Api/V1/WorkShiftController.php#L34) | `shift.manage` | `workShiftService.create` | `Settings/Settings.vue`, `WorkShift/WorkShiftForm.vue` |
| `PUT /work-shifts/{workShift}` | [WorkShiftController::update()](app/Http/Controllers/Api/V1/WorkShiftController.php#L41) | `shift.manage` | `workShiftService.update` | `Settings/Settings.vue`, `WorkShift/WorkShiftForm.vue` |
| `DELETE /work-shifts/{workShift}` | [WorkShiftController::destroy()](app/Http/Controllers/Api/V1/WorkShiftController.php#L48) | `shift.manage` | `workShiftService.remove` | `WorkShift/WorkShifts.vue` |

</details>

## Checklist sửa code an toàn

**Trước khi sửa**
- Ghi lại URL, payload, tài khoản/vai trò đang dùng, và dữ liệu trước lỗi (ví dụ bản ghi `attendances` nào, kỳ lương nào).
- Xác định nguồn sự thật: công ở `attendances` (chỉ `approved`), quỹ phép ở `leave_balances`, lương đã chốt ở `payroll_details` (snapshot, không tự tính lại).
- Đọc "Quy tắc & bẫy" của module và tìm test gần nhất; thêm test tái hiện nếu chưa có.

**Sau khi sửa**
- Thử happy path, validation (422), quyền (403 với vai trò thấp hơn), và trạng thái trung gian.
- Thử bấm đúp/gửi lại để không tạo bản ghi hoặc thông báo trùng.
- Chạy test module (`docker exec qlns-app-1 php artisan test --filter=...`), `vendor/bin/pint`, rồi `npm run build`.
- Đổi route/quyền/trạng thái/điểm ghi dữ liệu → cập nhật mục module trong file này.

<details>
<summary><strong>Lệnh tìm kiếm thường dùng</strong></summary>

```bash
# Tìm chữ đang hiển thị trên giao diện
rg -n "Nội dung cần tìm" resources/js

# Tìm endpoint từ DevTools > Network
rg -n "attendances/overview" routes app resources/js tests

# Tìm mọi nơi dùng một permission
rg -n "attendance.approve" routes app resources/js database tests

# Xem route đã đăng ký
docker exec qlns-app-1 php artisan route:list --path=api/v1/payrolls

# Chạy test đúng module
docker exec qlns-app-1 php artisan test --filter=PayrollTest
```

</details>

## Quy tắc cập nhật

Khi thêm module hoặc chuyển vị trí code, cập nhật file này trong cùng lần sửa. Chỉ ghi file thật sự tồn tại, ưu tiên link tương đối bấm được. Mỗi module giữ khung: **Vai trò → Điểm vào → bảng file → Luồng chính → Luồng demo nhanh → Quy tắc & bẫy → Liên thông**. Không ghi lịch sử theo ngày hay lý do thảo luận (đã có ở `git log`/comment code). Bảng "Tra cứu theo chức năng" sinh bằng script — khi đổi route/service FE cần sinh lại để không lệch.
