# Bản đồ Mã nguồn dự án QLNS

Tra cứu nhanh "tính năng nằm ở file nào" — theo yêu cầu [`docs/YEU_CAU_DU_AN_QLNS.html`](docs/YEU_CAU_DU_AN_QLNS.html) mục 6.1 (bắt buộc trình bày dạng **bảng chỉ mục tính năng**) và [`docs/CODING_STANDARDS.md`](docs/CODING_STANDARDS.md). Cập nhật ngay khi thêm/sửa 1 chức năng, không dồn lại.

## Quy trình thêm 1 chức năng mới

Theo khuôn các module đã có (`Position` gọn nhất, `Employee` đầy đủ nhất).

**Backend:**

| Bước | Làm gì | Bỏ qua khi nào | Ví dụ |
| --- | --- | --- | --- |
| 1. Migration | Tạo bảng mới | Dùng lại bảng có sẵn | [`create_provinces_table.php`](database/migrations/2026_09_07_000001_create_provinces_table.php) |
| 2. Model | `$fillable`, quan hệ | Không bỏ qua nếu có bảng mới | [`Position.php`](app/Models/Position.php) |
| 3. Request | Validate input | Chức năng chỉ đọc | [`StorePositionRequest.php`](app/Http/Requests/Position/StorePositionRequest.php) |
| 4. Repository | Hàm truy vấn DB | Hiếm khi bỏ qua | [`PositionRepository.php`](app/Repositories/PositionRepository.php) |
| 5. Service | Logic nghiệp vụ | Vẫn nên có bản mỏng cho nhất quán | [`PositionService.php`](app/Services/PositionService.php) |
| 6. Controller | Nhận request → gọi Service → trả response | Không bỏ qua | [`PositionController.php`](app/Http/Controllers/Api/V1/PositionController.php) |
| 7. Route | Đăng ký URL + `permission:xxx` | Không bỏ qua | [`routes/api/v1/positions.php`](routes/api/v1/positions.php) |
| 8. Resource | Định dạng JSON / ẩn field nhạy cảm | Không có field nhạy cảm | [`EmployeeResource.php`](app/Http/Resources/EmployeeResource.php) |
| 9. Test | Quyền hạn, validate, ca chính | Không nên bỏ qua | [`PositionTest.php`](tests/Feature/Position/PositionTest.php) |

**Frontend:**

| Bước | Làm gì | Ví dụ |
| --- | --- | --- |
| 1. Service (`xxxService.js`) | Hàm gọi API | [`positionService.js`](resources/js/services/positionService.js) |
| 2. Store (Pinia) | Chỉ khi nhiều trang cùng cần | [`useEmployeeStore.js`](resources/js/stores/useEmployeeStore.js) |
| 3. View danh sách | Dùng lại [`DataTable.vue`](resources/js/components/common/DataTable.vue) | [`Employees.vue`](resources/js/views/Employee/Employees.vue) |
| 4. Form Thêm/Sửa | Dùng lại `FormDialog`/`FormSection`/`SearchSelect`/`InputDate`/`InputMoney` (mục 10) | [`EmployeeForm.vue`](resources/js/views/Employee/EmployeeForm.vue) |
| 5. Router | Route mới + `meta.title` | [`router/index.js`](resources/js/router/index.js) |
| 6. Cập nhật file này | 1 mục mới đúng khuôn | — |

### Ví dụ 1 luồng chạy thật: "Thêm nhân viên"

```
Frontend (gửi)   Employees.vue → EmployeeForm.vue → useEmployeeStore.js → employeeService.js
Backend (xử lý)  routes → auth:api → permission:employee.create → StoreEmployeeRequest
                 → EmployeeController → EmployeeService → EmployeeRepository → Employee
                 → EmployeeResource → JSON
Frontend (nhận)  store nhận response → toast + đóng modal → Employees.vue gọi lại GET, làm mới bảng
```

**4 chỗ có thể dừng sớm** (sai thì dừng ngay, không đi tiếp): (1) validate phía client (`formRef.value.validate()`) — sai thì 0 request gửi lên; (2) middleware `auth:api` — token sai → 401; (3) middleware `permission:employee.create` — thiếu quyền → 403; (4) `StoreEmployeeRequest` — data sai → 422. Càng dừng sớm càng đỡ tốn (mạng, Service/Repository, công viết lại validate ở Controller).

---

## 1. Xác thực (JWT Authentication)

| Hạng mục | Chi tiết |
| --- | --- |
| Giao diện Frontend | [`Login.vue`](resources/js/views/Login.vue), [`ForgotPassword.vue`](resources/js/views/ForgotPassword.vue), [`ResetPassword.vue`](resources/js/views/ResetPassword.vue) (đọc `token`/`email` từ query string), [`authStore.js`](resources/js/stores/authStore.js), [`authService.js`](resources/js/services/authService.js) |
| Xử lý Backend | [`AuthController.php`](app/Http/Controllers/Api/V1/AuthController.php), [`PasswordResetController.php`](app/Http/Controllers/Api/V1/PasswordResetController.php), [`AuthService.php`](app/Services/AuthService.php), [`PasswordResetService.php`](app/Services/PasswordResetService.php), [`Jwt/JwtService.php`](app/Services/Jwt/JwtService.php), [`Auth/JwtGuard.php`](app/Auth/JwtGuard.php), [`UserRepository.php`](app/Repositories/UserRepository.php), [`RefreshTokenRepository.php`](app/Repositories/RefreshTokenRepository.php), [`PasswordResetRepository.php`](app/Repositories/PasswordResetRepository.php) |
| Mail | [`PasswordResetMail.php`](app/Mail/PasswordResetMail.php) — gửi **đồng bộ** (chưa có queue worker lúc đó, xem mục 21) |
| Database & API | `users`, `refresh_tokens`, `password_reset_tokens`, `password_histories`; `POST /auth/login`, `/refresh`, `GET /auth/me`, `POST /auth/logout`, `/forgot-password`, `/reset-password` ([`routes/api/v1/auth.php`](routes/api/v1/auth.php)) — 2 route quên/đặt lại mật khẩu không cần `auth:api` |
| Test | [`AuthTest.php`](tests/Feature/Auth/AuthTest.php), [`LoginValidationTest.php`](tests/Feature/Auth/LoginValidationTest.php), [`PasswordResetTest.php`](tests/Feature/Auth/PasswordResetTest.php) |
| Ghi chú | Quên mật khẩu luôn trả thành công dù email không tồn tại (chống dò email — OWASP). Đặt lại mật khẩu xong thu hồi **mọi** refresh token của user. `JwtGuard` cache theo **token đã giải mã**, không phải cờ `bool` — cần thiết để `actingAs()`/đổi user giữa 2 lệnh gọi HTTP trong cùng 1 test hoạt động đúng. **Access token hết hạn giữa phiên KHÔNG phải bug** — `bootstrap.js` tự bắt 401 → gọi `/auth/refresh` → gửi lại request gốc; dòng "401 (Unauthorized)" đỏ trên console chỉ là trình duyệt tự log request thất bại, kể cả khi code đã retry thành công ngay sau đó. **Bug thật đã sửa (Ngày 44)**: `JwtService::decodeAccessToken()` trước đó chỉ bắt `ExpiredException`/`SignatureInvalidException`/`UnexpectedValueException` — token hỏng dạng khác (payload giải mã base64 ra không phải JSON hợp lệ) khiến `firebase/php-jwt` ném `DomainException`, không nằm trong danh sách bắt cũ nên vỡ thành 500 thay vì 401 sạch. Đã thêm bắt cả `DomainException`/`InvalidArgumentException`, kèm test `test_access_token_with_invalid_payload_encoding_is_rejected` |
| Danh sách tệp cần sửa khi bảo trì | Toàn bộ các file trên |

## 2. Phân quyền (RBAC)

| Hạng mục | Chi tiết |
| --- | --- |
| Giao diện Frontend | [`AppSidebar.vue`](resources/js/components/layout/AppSidebar.vue) ẩn menu qua `can(code)`; [`router/index.js`](resources/js/router/index.js) mỗi route khai `meta.permission`, 1 `beforeEach` guard đá về Dashboard nếu thiếu quyền — **chỉ là lớp UX phụ**, quyền thật luôn do backend middleware `permission:xxx` enforce |
| Xử lý Backend | [`EnsurePermission.php`](app/Http/Middleware/EnsurePermission.php) (alias `permission`), [`RoleController.php`](app/Http/Controllers/Api/V1/RoleController.php) (chỉ đọc) |
| Model | [`Role.php`](app/Models/Role.php), [`Permission.php`](app/Models/Permission.php) |
| Database & API | `roles`, `permissions`, `role_permissions`, `user_roles`; [`PermissionSeeder.php`](database/seeders/PermissionSeeder.php), [`RolePermissionSeeder.php`](database/seeders/RolePermissionSeeder.php) |
| Test | [`PermissionMiddlewareTest.php`](tests/Feature/PermissionMiddlewareTest.php) |
| Ghi chú | Role Employee **không có** `employee.view` (chỉ xem hồ sơ chính mình). `RolePermissionSeeder` tự dọn quyền thừa mỗi lần chạy lại (đồng bộ thật, không cộng dồn), bọc `DB::transaction()` |
| Danh sách tệp cần sửa khi bảo trì | Toàn bộ các file trên |

## 3. Audit Log

| Hạng mục | Chi tiết |
| --- | --- |
| Xử lý Backend | [`AuditLog.php`](app/Models/AuditLog.php), [`AuditObserver.php`](app/Observers/AuditObserver.php), [`Concerns/Auditable.php`](app/Models/Concerns/Auditable.php) |
| Database | `audit_logs` |
| Danh sách tệp cần sửa khi bảo trì | Muốn 1 Model tự ghi log created/updated/deleted: thêm `use Auditable;` |

## 4. Layout chính & Điều hướng

| Hạng mục | Chi tiết |
| --- | --- |
| Giao diện Frontend | [`App.vue`](resources/js/App.vue) (chọn layout theo `route.meta.layout`), [`AppLayout.vue`](resources/js/components/layout/AppLayout.vue), [`AppHeader.vue`](resources/js/components/layout/AppHeader.vue), [`AppSidebar.vue`](resources/js/components/layout/AppSidebar.vue), [`AppBreadcrumbs.vue`](resources/js/components/layout/AppBreadcrumbs.vue) |
| Theme | [`plugins/vuetify.js`](resources/js/plugins/vuetify.js) (`qlnsDark`/`qlnsLight`), đổi qua `useTheme()` ở `AppHeader.vue` |
| Router | Route không dùng layout chính khai `meta.layout: 'blank'`; mỗi route khai `meta.title` (tiếng Việt, cho breadcrumb) và `meta.parent` nếu cần chèn cấp giữa |
| Ghi chú | Responsive mobile (sidebar overlay, breakpoint) — xem mục 26 |
| Danh sách tệp cần sửa khi bảo trì | Toàn bộ các file trên |

## 5. Dashboard (tạm thời)

| Hạng mục | Chi tiết |
| --- | --- |
| Giao diện Frontend | [`Dashboard.vue`](resources/js/views/Dashboard.vue), [`StatCards.vue`](resources/js/components/dashboard/StatCards.vue) |
| Ghi chú | Dữ liệu **minh họa**, chưa nối API thật — Dashboard thật thuộc Phase Báo cáo (Ngày 51-60) |

## 6. Phòng ban (Departments)

| Hạng mục | Chi tiết |
| --- | --- |
| Giao diện Frontend | [`Departments.vue`](resources/js/views/Department/Departments.vue) (cây thụt lề, tìm kiếm, lọc trạng thái, cột Trưởng phòng), [`DepartmentForm.vue`](resources/js/views/Department/DepartmentForm.vue), [`useDepartmentStore.js`](resources/js/stores/useDepartmentStore.js), [`departmentService.js`](resources/js/services/departmentService.js) |
| Xử lý Backend | [`DepartmentController.php`](app/Http/Controllers/Api/V1/DepartmentController.php), [`DepartmentService.php`](app/Services/DepartmentService.php) (`generateCode()`, `syncHeadPosition()`), [`DepartmentRepository.php`](app/Repositories/DepartmentRepository.php) (`tree()`, `wouldCreateCycle()`) |
| Model | [`Department.php`](app/Models/Department.php) — tự tham chiếu `parent()`/`children()`, `manager()` → Employee |
| Validation | [`StoreDepartmentRequest.php`](app/Http/Requests/Department/StoreDepartmentRequest.php)/[`UpdateDepartmentRequest.php`](app/Http/Requests/Department/UpdateDepartmentRequest.php) — không có rule cho `code` (tự sinh) |
| Output / Resource | [`DepartmentResource.php`](app/Http/Resources/DepartmentResource.php) — `manager` bọc qua `EmployeeResource` (ẩn field nhạy cảm đúng logic), `children` đệ quy |
| Database & API | `departments`; `GET/POST /departments`, `GET /departments/tree`, `PUT/DELETE /departments/{id}` ([`routes/api/v1/departments.php`](routes/api/v1/departments.php)); quyền `department.view`/`department.manage` |
| Test | [`DepartmentTest.php`](tests/Feature/Department/DepartmentTest.php) |
| Ghi chú | Gán/đổi `manager_id` tự đồng bộ Chức vụ qua `syncHeadPosition()` (mục 7, dựa cột `positions.type`, không so tên chuỗi). Xóa phòng ban còn con bị chặn ở backend, không chỉ ở UI |
| Danh sách tệp cần sửa khi bảo trì | Toàn bộ các file trên |

## 7. Chức vụ (Position)

| Hạng mục | Chi tiết |
| --- | --- |
| Giao diện Frontend | [`Positions.vue`](resources/js/views/Position/Positions.vue), [`PositionForm.vue`](resources/js/views/Position/PositionForm.vue) — không dùng Pinia store (chỉ 1 trang dùng) |
| Xử lý Backend | [`PositionController.php`](app/Http/Controllers/Api/V1/PositionController.php), [`PositionService.php`](app/Services/PositionService.php) (`generateCode()`, `ensureHeadPosition()`/`ensureDefaultPosition()`), [`PositionRepository.php`](app/Repositories/PositionRepository.php) |
| Model | [`Position.php`](app/Models/Position.php) — `belongsTo` Department, cột `type` (`null`/`head`/`default`) |
| Database & API | `positions`; `GET/POST /positions`, `PUT/DELETE /positions/{id}` ([`routes/api/v1/positions.php`](routes/api/v1/positions.php)); dùng chung quyền `department.view`/`department.manage` |
| Test | [`PositionTest.php`](tests/Feature/Position/PositionTest.php) |
| Ghi chú | 2 bản ghi hệ thống (`type=head`/`default`) bị khóa Sửa/Xóa trên UI, tránh phá đồng bộ tự động với Department/EmployeeTransfer |
| Danh sách tệp cần sửa khi bảo trì | Toàn bộ các file trên |

## 8. Nhân viên (Employee)

| Hạng mục | Chi tiết |
| --- | --- |
| Giao diện Frontend | [`Employees.vue`](resources/js/views/Employee/Employees.vue) (phân trang server-side, `StatCards` 4 thẻ), [`EmployeeForm.vue`](resources/js/views/Employee/EmployeeForm.vue) (Chức vụ lọc theo Phòng ban, Quản lý trực tiếp dùng ref riêng không qua store chung, Tỉnh/Xã cascading, nút "Tạo nhanh" Phòng ban/Chức vụ ngay trong form), [`useEmployeeStore.js`](resources/js/stores/useEmployeeStore.js), [`employeeService.js`](resources/js/services/employeeService.js) |
| Chi tiết nhân viên (6 tab) | [`EmployeeDetail.vue`](resources/js/views/Employee/EmployeeDetail.vue) — chỉ còn khung `v-tabs`/`v-window` + tải `employee` chính + `FilePreviewDialog` dùng chung. Mỗi tab tách thành component riêng (dễ bảo trì hơn 1 file gộp cả 1865 dòng): [`EmployeeProfileTab.vue`](resources/js/views/Employee/EmployeeProfileTab.vue) (Sơ yếu lý lịch + dialog tạo tài khoản đăng nhập), [`EmployeeContractsTab.vue`](resources/js/views/Employee/EmployeeContractsTab.vue), [`EmployeeDocumentsTab.vue`](resources/js/views/Employee/EmployeeDocumentsTab.vue), [`EmployeeTransfersTab.vue`](resources/js/views/Employee/EmployeeTransfersTab.vue), [`EmployeeShiftAssignmentsTab.vue`](resources/js/views/Employee/EmployeeShiftAssignmentsTab.vue); tab "Chấm công" dùng `AttendanceHistoryPanel.vue` (mục 18), tab "Lương/Phép" dùng [`EmployeeSalaryLeaveTab.vue`](resources/js/views/Employee/EmployeeSalaryLeaveTab.vue) (bổ sung 2026-09-16 — trước đó là placeholder tĩnh từ Ngày 27, để chờ 2 module Payroll/Nghỉ phép làm xong; giờ ghép lại bằng 2 API mới: `GET /leave-requests/balances/{employee}` và `GET /employees/{employee}/payslips`, dialog "Xem phiếu lương" tái dùng `PayrollPayslipDialog.vue` — mục 27). Mỗi tab con tự gọi API riêng của nó (`onMounted`), component cha chỉ `v-if` mount lần đầu khi mở đúng tab (không cần cờ "đã tải" nữa vì `v-window` không unmount lại tab đã mở); tab con nào cần mở `FilePreviewDialog`/báo tải lại `employee` thì `emit` sự kiện lên cha (`preview`, `account-created`, `transferred`) |
| Xử lý Backend | [`EmployeeController.php`](app/Http/Controllers/Api/V1/EmployeeController.php), [`EmployeeService.php`](app/Services/EmployeeService.php) (`generateCode()`, `wouldCreateCycle()`, `updateAvatar()`, `stats()`), [`EmployeeRepository.php`](app/Repositories/EmployeeRepository.php) |
| Hợp đồng | [`EmployeeContractController.php`](app/Http/Controllers/Api/V1/EmployeeContractController.php)/[`EmployeeContractService.php`](app/Services/EmployeeContractService.php)/[`EmployeeContractRepository.php`](app/Repositories/EmployeeContractRepository.php) — Tạo+Xem+Tải+Chấm dứt (không Sửa/Xóa thô), IDOR check ở `download()`/`terminate()` (so `employee_id` trước khi thao tác). Số hợp đồng TỰ SINH theo `contract_type` (`HDTV-xxx` thử việc / `HDCT-xxx` chính thức, `EmployeeContractService::generateContractNumber()`), client gửi lên bị bỏ qua. **4 trạng thái vòng đời hợp đồng** — `pending` (đã ký, chưa tới ngày bắt đầu) → `active` (đang hiệu lực) → `expired`/`terminated` (kết thúc tự nhiên/chủ động). **4 cơ chế quản lý** (bổ sung 2026-09-16, cập nhật thêm `pending` cùng ngày sau khi phát hiện ký hợp đồng TRƯỚC ngày bắt đầu làm auto-supersede đụng nhầm hợp đồng đang thật sự áp dụng): (1) *Tạo hợp đồng ký trước hạn* — `EmployeeContractService::create()` so `start_date` với hôm nay: còn ở tương lai thì tạo với `status=pending` và KHÔNG đụng gì tới hợp đồng `active` hiện có; đã tới/qua hạn thì tạo `active` luôn và mới auto-supersede (2) hợp đồng `active` cũ sang `expired` ngay trong transaction; (3) *Tự động kích hoạt hợp đồng pending* — Artisan command [`ActivatePendingContracts.php`](app/Console/Commands/ActivatePendingContracts.php) (`contracts:activate-pending`), chạy hằng ngày 00:05 (TRƯỚC `contracts:expire` chạy 00:06 — xem [`routes/console.php`](routes/console.php)), chuyển `pending` → `active` cho hợp đồng đã tới `start_date`, đồng thời supersede hợp đồng `active` khác của cùng nhân viên đúng lúc đó (không sớm hơn); (4) *Tự động hết hạn* — Artisan command [`ExpireEmployeeContracts.php`](app/Console/Commands/ExpireEmployeeContracts.php) (`contracts:expire`), chuyển hợp đồng `end_date` < hôm nay + `status=active` sang `expired`; (5) *Chấm dứt chủ động* — `EmployeeContractService::terminate()` (chỉ cho phép từ `active` — `pending`/`expired`/`terminated` đều bị chặn) đặt `status=terminated` + `terminated_at=hôm nay`, gọi qua `POST /employees/{employee}/contracts/{contract}/terminate` (`permission:employee.update`), nút "Chấm dứt hợp đồng" chỉ hiện ở [`EmployeeContractsTab.vue`](resources/js/views/Employee/EmployeeContractsTab.vue) (phía HR, `v-if status===active` nên tự động không hiện cho `pending`), KHÔNG có ở `MyProfileContractsTab.vue` (tự phục vụ, chỉ đọc). `StoreEmployeeContractRequest`'s `status` rule chỉ để tài liệu hóa enum hợp lệ — client gửi gì cũng bị Service ghi đè. Sau khi tạo hợp đồng mới, Frontend gọi lại `loadContracts()` (tải lại cả danh sách) thay vì chỉ nối thêm bản ghi mới — cần thiết để dòng hợp đồng cũ hiển thị đúng `expired` do bị auto-supersede, nối thêm đơn thuần sẽ để dòng cũ hiện sai "Còn hiệu lực" cho tới khi tải lại trang (bug thật đã vấp khi kiểm thử bằng Playwright). |
| Tài liệu | [`EmployeeDocumentController.php`](app/Http/Controllers/Api/V1/EmployeeDocumentController.php)/Service/Repository — nhận PDF/JPG/PNG/DOCX/XLSX, có Xóa mềm, tên tải xuống giữ đúng đuôi qua [`EmployeeDocument::downloadFileName()`](app/Models/EmployeeDocument.php) |
| Luân chuyển | [`EmployeeTransferController.php`](app/Http/Controllers/Api/V1/EmployeeTransferController.php)/Service/Repository — áp dụng NGAY LẬP TỨC (không chờ `effective_date`, chưa có queue/lịch chạy nền), chặn chọn trùng phòng ban hiện tại, điều chuyển 1 Trưởng phòng thì tự dọn `manager_id` phòng cũ + hạ Chức vụ mặc định ở phòng mới. **"Quản lý mới" chỉ được là Trưởng phòng của phòng ban MỚI** (2026-09-21, theo yêu cầu người dùng): `EmployeeTransferService::create()` kiểm `Department.manager_id == new_manager_id` (422 nếu khác, hoặc nếu phòng đó chưa có Trưởng phòng) TRƯỚC bước kiểm vòng lặp; dropdown ở `EmployeeTransfersTab.vue` chỉ liệt kê đúng `manager` của phòng ban đang chọn (lấy từ cây `departmentService.tree()`, không gọi thêm API), khóa lại khi chưa chọn phòng/phòng chưa có Trưởng phòng, và reset khi đổi phòng ban. Bỏ để trống thì vẫn giữ nguyên quản lý hiện tại như cũ |
| Model | [`Employee.php`](app/Models/Employee.php), [`EmployeeContract.php`](app/Models/EmployeeContract.php), [`EmployeeBankAccount.php`](app/Models/EmployeeBankAccount.php), [`EmployeeDocument.php`](app/Models/EmployeeDocument.php), [`EmployeeTransfer.php`](app/Models/EmployeeTransfer.php) (không `SoftDeletes` — bản ghi lịch sử) |
| Validation | [`StoreEmployeeRequest.php`](app/Http/Requests/Employee/StoreEmployeeRequest.php)/[`UpdateEmployeeRequest.php`](app/Http/Requests/Employee/UpdateEmployeeRequest.php) — 13 field bắt buộc (hồ sơ tạo sau khi đã ký hợp đồng), `commune_code` phải thuộc đúng `province_code` (mục 9) |
| Output / Resource | [`EmployeeResource.php`](app/Http/Resources/EmployeeResource.php) — ẩn field nhạy cảm (CCCD, SĐT, ngày sinh...) khi người xem là cấp dưới, `manager` lồng đệ quy qua chính Resource, cache `ancestorIds` theo viewer tránh N+1 |
| Database & API | `employees` (+ index `full_name`/`employment_status`), `positions`, `employee_contracts`, `employee_bank_accounts`, `employee_documents`, `employee_transfers`; `GET/POST /employees`, `/employees/stats`, `/employees/me`, `GET/PUT/DELETE /employees/{id}`, `POST /employees/{id}/avatar`, `.../contracts`, `.../documents`, `.../transfers` ([`routes/api/v1/employees.php`](routes/api/v1/employees.php) — **`/stats` và `/me` phải khai TRƯỚC `/{employee}`**); 4 quyền riêng `employee.view/create/update/delete` |
| Lưu trữ file | Avatar → disk `public` (tự xóa file cũ); hợp đồng/tài liệu → disk `local` (riêng tư, chỉ tải qua route có kiểm IDOR) |
| Giới hạn dung lượng tải lên (2026-09-21) | Giới hạn thực tế là mức THẤP NHẤT trong 3 tầng: Laravel validation (`max:` tính bằng KB) < PHP (`docker/php/php.ini`: `upload_max_filesize=20M`, `post_max_size=25M`) ≈ nginx (`docker/nginx/default.conf`: `client_max_body_size 20m`) — nên Backend là bên chặn thật. Hợp đồng **5MB** (PDF), tài liệu cá nhân **10MB** (PDF/JPG/PNG/DOCX/XLSX, dùng chung tab Tài liệu của HR + "Hồ sơ của tôi"), quyết định điều động **10MB** (PDF), giấy tờ đính kèm đơn nghỉ phép **5MB** (JPG/PNG/PDF). Ảnh đại diện có rule 2MB ở `EmployeeController::uploadAvatar` nhưng chưa có giao diện tải lên. **Frontend hiển thị giới hạn ở MỌI ô tải tệp** qua [`InputFile.vue`](resources/js/components/common/InputFile.vue) (bọc `v-file-input`, dòng gợi ý "Định dạng …, tối đa …MB" luôn hiện kể cả sau khi chọn file) — số MB/định dạng chỉ khai báo MỘT chỗ trong `UPLOAD_LIMITS` (export từ chính file đó), **mỗi mục phải khớp rule Backend tương ứng** (ghi rõ Request nào ngay trong comment), đổi 1 bên phải đổi cả bên kia. Thêm chỗ upload mới → dùng `<InputFile :limit="UPLOAD_LIMITS.xxx" />`, không tự viết `v-file-input` + `placeholder` ghi số MB. Chưa chặn dung lượng ở phía Frontend (chỉ hiển thị); file >20MB bị nginx trả 413 không kèm JSON nên giao diện chỉ hiện lỗi chung |
| Test | [`EmployeeTest.php`](tests/Feature/Employee/EmployeeTest.php), [`EmployeeContractTest.php`](tests/Feature/Employee/EmployeeContractTest.php) (bao gồm auto-supersede/pending/terminate), [`EmployeeDocumentTest.php`](tests/Feature/Employee/EmployeeDocumentTest.php), [`EmployeeTransferTest.php`](tests/Feature/Employee/EmployeeTransferTest.php), [`ExpireEmployeeContractsTest.php`](tests/Feature/Console/ExpireEmployeeContractsTest.php) (Artisan command `contracts:expire`), [`ActivatePendingContractsTest.php`](tests/Feature/Console/ActivatePendingContractsTest.php) (Artisan command `contracts:activate-pending`) |
| Ghi chú | Không chặn xóa nhân viên còn cấp dưới (khác Department). Docker: tạo thư mục `storage/app/private/...` bằng tinker/root trước sẽ khiến request thật (`www-data`) bị Permission denied |
| Danh sách tệp cần sửa khi bảo trì | Toàn bộ các file trên |

## 9. Địa chỉ hành chính (Tỉnh/Xã)

| Hạng mục | Chi tiết |
| --- | --- |
| Giao diện Frontend | [`addressService.js`](resources/js/services/addressService.js) (`provinces()`, `communes(provinceCode)`) — dùng trong `EmployeeForm.vue` |
| Xử lý Backend | [`AddressController.php`](app/Http/Controllers/Api/V1/AddressController.php) — chỉ đọc |
| Model | [`Province.php`](app/Models/Province.php), [`Commune.php`](app/Models/Commune.php) — PK là `code`, không tự tăng |
| Database & API | `provinces`, `communes`; `GET /addresses/provinces`, `/addresses/communes?province_code=` |
| Nguồn dữ liệu | Tải nguyên trạng từ [provinces.open-api.vn](https://provinces.open-api.vn) (34 Tỉnh, 3321 Xã — theo sáp nhập 2025, không còn Huyện), lưu tĩnh ở [`database/seeders/data/`](database/seeders/data), nạp qua [`ProvinceCommuneSeeder.php`](database/seeders/ProvinceCommuneSeeder.php) |
| Test | Trong [`EmployeeTest.php`](tests/Feature/Employee/EmployeeTest.php) |
| Danh sách tệp cần sửa khi bảo trì | Toàn bộ các file trên |

## 10. Hạ tầng chung

| Hạng mục | Chi tiết |
| --- | --- |
| Axios | [`bootstrap.js`](resources/js/bootstrap.js) — tự gắn `Authorization`, tự xử lý 401/403 |
| Component dùng chung | [`components/common/`](resources/js/components/common): `FormDialog.vue` (khung dialog — cần prop **`scrollable`** để form dài cuộn đúng, thiếu thì bị cắt cụt), `FormSection.vue`, `DataTable.vue` (prop `actions` tự dựng cột Thao tác + hộp xác nhận, xem `confirm.input`), `SearchField.vue`, `PageHeader.vue`, `StatusChip.vue` (so khớp qua `String(status)`), `SearchSelect.vue` (bọc `v-autocomplete`, tìm không dấu qua `normalizeVietnamese()`, không khai prop `modelValue` — rơi qua `$attrs`), `InputDate.vue` (bọc `VDateInput` của `vuetify/labs`, export thêm `parseIsoDate`/`toIsoDate`/`todayIso`/`shiftIsoDate`), `InputMoney.vue`, `InputFile.vue` (ô chọn tệp — kèm dòng hiển thị giới hạn dung lượng, xem mục 8 "Giới hạn dung lượng tải lên"), `FilePreviewDialog.vue` (xem trước PDF/ảnh/DOCX/XLSX, 3 thư viện `import()` động để lazy-load), `AppToast.vue` + [`useToastStore.js`](resources/js/stores/useToastStore.js) (không dùng `v-snackbar-queue` — không tự tắt ở Vuetify 3.13.3, tự viết state máy), `AppLoadingBar.vue` + [`useLoadingStore.js`](resources/js/stores/useLoadingStore.js) (đếm số việc đang chạy, không phải cờ bool) |
| Glassmorphism | Class `.glass-panel` tại [`resources/css/app.css`](resources/css/app.css) |
| Docker | [`docker-compose.yml`](docker-compose.yml), chi tiết ở [`docs/LOCAL_DEVELOPMENT.md`](docs/LOCAL_DEVELOPMENT.md) |
| Swagger | [`config/l5-swagger.php`](config/l5-swagger.php), xem tại `/api/documentation`, sinh lại bằng `php artisan l5-swagger:generate` |
| Ghi chú | `FilePreviewDialog`: `pdfDoc` phải là `shallowRef` (không phải `ref`), giải phóng bằng `destroy()` của loading task (không phải `cleanup()`), hủy `renderTask` cũ trước khi vẽ lại. Namespace hoa/thường: Controller khai `Api\V1` nhưng git từng track thư mục `Api/v1/` — đã sửa bằng `git mv` 2 bước; NTFS không phân biệt hoa/thường nhưng Linux (CI/server) thì có, nhớ kiểm tra lại nếu thêm Controller mới. `DataTable.vue` dùng Vuetify 3 (không phải 4) — slot `#bottom` không có `setPage`/`itemsLength`, bảng tự quản lý `page` qua `v-model:page` + đọc `serverItemsLength` tự truyền vào |
| Danh sách tệp cần sửa khi bảo trì | Toàn bộ các file trên |

## 11. Tạo tài khoản đăng nhập cho Nhân viên

| Hạng mục | Chi tiết |
| --- | --- |
| Giao diện Frontend | [`EmployeeDetail.vue`](resources/js/views/Employee/EmployeeDetail.vue) (nút "Tạo tài khoản đăng nhập"), [`EmployeeForm.vue`](resources/js/views/Employee/EmployeeForm.vue) (checkbox tạo ngay lúc Thêm mới), [`roleService.js`](resources/js/services/roleService.js) |
| Xử lý Backend | [`EmployeeAccountController.php`](app/Http/Controllers/Api/V1/EmployeeAccountController.php), [`EmployeeAccountService.php`](app/Services/EmployeeAccountService.php) (chặn tạo trùng, transaction tạo User + attach Role), [`RoleController.php`](app/Http/Controllers/Api/V1/RoleController.php) |
| Gợi ý Role theo Chức vụ | `PositionService::suggestRole()` ghi vào `role_positions` khi tự sinh Position lần đầu (Trưởng phòng → Manager, Nhân viên → Employee) |
| Database & API | `role_positions`; `POST /employees/{id}/account`, `GET /roles` — quyền `employee.update` |
| Test | [`EmployeeAccountTest.php`](tests/Feature/Employee/EmployeeAccountTest.php) |
| Ghi chú | Mật khẩu ngẫu nhiên nội bộ, tái dùng luồng "quên mật khẩu" (mục 1) để gửi email đặt mật khẩu lần đầu — không đặt/lộ mật khẩu trần |
| Danh sách tệp cần sửa khi bảo trì | Toàn bộ các file trên |

## 12. Ca làm việc (Work Shift)

| Hạng mục | Chi tiết |
| --- | --- |
| Giao diện Frontend | [`WorkShifts.vue`](resources/js/views/WorkShift/WorkShifts.vue), [`WorkShiftForm.vue`](resources/js/views/WorkShift/WorkShiftForm.vue) (giờ dùng native `type="time"`) |
| Xử lý Backend | [`WorkShiftController.php`](app/Http/Controllers/Api/V1/WorkShiftController.php), [`WorkShiftService.php`](app/Services/WorkShiftService.php) (`generateCode()`; tắt `is_active` thì tự gỡ Ca khỏi mọi nhân viên đang gán), [`WorkShiftRepository.php`](app/Repositories/WorkShiftRepository.php) |
| Model | [`WorkShift.php`](app/Models/WorkShift.php) |
| Database & API | `work_shifts`; `GET/POST /work-shifts`, `PUT/DELETE /work-shifts/{id}` ([`routes/api/v1/work-shifts.php`](routes/api/v1/work-shifts.php)); quyền `shift.view`/`shift.manage` (Manager chỉ có `view`) |
| Test | [`WorkShiftTest.php`](tests/Feature/WorkShift/WorkShiftTest.php) |
| Ghi chú | Chưa xử lý ca qua đêm (`end_time < start_time`) |
| Danh sách tệp cần sửa khi bảo trì | Toàn bộ các file trên |

## 13. Điểm chấm công (Attendance Location)

| Hạng mục | Chi tiết |
| --- | --- |
| Giao diện Frontend | [`AttendanceLocations.vue`](resources/js/views/AttendanceLocation/AttendanceLocations.vue), [`AttendanceLocationForm.vue`](resources/js/views/AttendanceLocation/AttendanceLocationForm.vue) (form đổi field theo `method`) |
| Xử lý Backend | [`AttendanceLocationController.php`](app/Http/Controllers/Api/V1/AttendanceLocationController.php), [`AttendanceLocationService.php`](app/Services/AttendanceLocationService.php) (`generateCode()`, tự sinh `qr_secret` — không nhận từ client) |
| Model | [`AttendanceLocation.php`](app/Models/AttendanceLocation.php) |
| Database & API | `attendance_locations`; `GET/POST /attendance-locations`, `PUT/DELETE .../{id}`; quyền `location.view`/`location.manage` (chỉ HR, Manager không có) |
| Test | [`AttendanceLocationTest.php`](tests/Feature/AttendanceLocation/AttendanceLocationTest.php) |
| Ghi chú | Từ 2026-09-21 đây là danh sách địa điểm của công ty **để đối chiếu** với IP/vị trí/QR của thiết bị chấm công (xem mục 16), không phải "phương thức nhân viên chọn". Giao diện đổi chữ tương ứng: cột/ô "Phương thức" → "Cách nhận diện" (giá trị `method` vẫn là `wifi`/`gps`/`qr`) |
| Danh sách tệp cần sửa khi bảo trì | Toàn bộ các file trên |

## 14. Gán ca làm việc cho nhân viên

| Hạng mục | Chi tiết |
| --- | --- |
| Giao diện Frontend | Tab "Ca làm việc" trong [`EmployeeDetail.vue`](resources/js/views/Employee/EmployeeDetail.vue) |
| Xử lý Backend | [`EmployeeShiftAssignmentController.php`](app/Http/Controllers/Api/V1/EmployeeShiftAssignmentController.php)/Service/Repository |
| Model | [`EmployeeShiftAssignment.php`](app/Models/EmployeeShiftAssignment.php) (`work_days` cast `array`) |
| Quy tắc nghiệp vụ | 1 nhân viên gán được **nhiều ca**, chỉ cấm 2 ca **chồng giờ + chồng ngày trong tuần + chồng khoảng hiệu lực** (`assertNoConflict()`) |
| Database & API | `employee_shift_assignments`; `.../employees/{id}/shift-assignments`; quyền `shift.view`/`shift.manage`; có `mine()` |
| Test | [`EmployeeShiftAssignmentTest.php`](tests/Feature/Employee/EmployeeShiftAssignmentTest.php) |
| Ghi chú | `work_shift_id` phải đang `is_active=1` (không chỉ tồn tại), IDOR check ở update/destroy |
| Danh sách tệp cần sửa khi bảo trì | Toàn bộ các file trên |

## 15. Hồ sơ của tôi (My Profile)

| Hạng mục | Chi tiết |
| --- | --- |
| Giao diện Frontend | [`MyProfile.vue`](resources/js/views/Me/MyProfile.vue) — chỉ còn khung `v-tabs`/`v-window` + thẻ hero/`StatCards` đầu trang + `FilePreviewDialog` dùng chung. 5 tab tách component riêng (cùng khuôn `EmployeeDetail.vue`, mục 8): [`MyProfileInfoTab.vue`](resources/js/views/Me/MyProfileInfoTab.vue) (Thông tin cá nhân + dialog sửa liên hệ), [`MyProfileContractsTab.vue`](resources/js/views/Me/MyProfileContractsTab.vue), [`MyProfileDocumentsTab.vue`](resources/js/views/Me/MyProfileDocumentsTab.vue) (có tự nộp tài liệu), [`MyProfileShiftsTab.vue`](resources/js/views/Me/MyProfileShiftsTab.vue), [`MyProfileTransfersTab.vue`](resources/js/views/Me/MyProfileTransfersTab.vue) (2 tab sau chỉ đọc, không có thao tác) |
| Xử lý Backend | `mine()` thêm vào 4 Controller (Contract/Document/ShiftAssignment/Transfer), `EmployeeController::updateMine()`, `EmployeeDocumentController::storeMine()` |
| Validation | [`UpdateMyProfileRequest.php`](app/Http/Requests/Employee/UpdateMyProfileRequest.php) — chỉ 5 field liên hệ (`phone`/`personal_email`/`address_detail`/`province_code`/`commune_code`), không cho tự sửa field định danh/công việc |
| Database & API | `/employees/me`, `PUT /employees/me`, `.../me/documents`, `.../me/contracts`, `.../me/shift-assignments`, `.../me/transfers` — chỉ cần `auth:api`, không cần permission |
| Test | Rải trong `EmployeeTest.php`/`EmployeeContractTest.php`/`EmployeeDocumentTest.php`/`EmployeeShiftAssignmentTest.php`/`EmployeeTransferTest.php` |
| Ghi chú | Route tải Hợp đồng/Tài liệu bỏ `permission:` ở route, tự kiểm tra "chính chủ HOẶC có quyền" bên trong hàm |
| Danh sách tệp cần sửa khi bảo trì | Toàn bộ các file trên |

## 16. Chấm công (Attendance check-in/out)

| Hạng mục | Chi tiết |
| --- | --- |
| Giao diện Frontend | [`CheckIn.vue`](resources/js/views/Attendance/CheckIn.vue) — nay là switcher desktop/mobile, xem mục 26 |
| Xử lý Backend | [`AttendanceController.php`](app/Http/Controllers/Api/V1/AttendanceController.php) (`checkIn`/`checkOut`/`today`/`mine`/`index`), [`AttendanceService.php`](app/Services/AttendanceService.php) (khớp điểm/ca, tính trễ-sớm-OT, `captureDeviceContext()`), [`Services/Attendance/DeviceInfoParser.php`](app/Services/Attendance/DeviceInfoParser.php) (User-Agent → tên thiết bị), [`Services/Attendance/ReverseGeocoder.php`](app/Services/Attendance/ReverseGeocoder.php) (tọa độ → địa chỉ chữ qua Nominatim/OpenStreetMap), [`AttendanceRepository.php`](app/Repositories/AttendanceRepository.php), [`AttendanceLogRepository.php`](app/Repositories/AttendanceLogRepository.php) |
| Model | [`Attendance.php`](app/Models/Attendance.php), [`AttendanceLog.php`](app/Models/AttendanceLog.php) (`const UPDATED_AT = null`, không `SoftDeletes` — log bất biến) |
| Validation | [`CheckInRequest.php`](app/Http/Requests/Attendance/CheckInRequest.php)/[`CheckOutRequest.php`](app/Http/Requests/Attendance/CheckOutRequest.php) — **không còn field `method`** (2026-09-21): chỉ `work_shift_id` bắt buộc; `latitude`/`longitude` tùy chọn nhưng phải đi cặp đôi (`required_with`), `qr_reference` tùy chọn |
| Database & API | `attendances` (unique `employee_id`+`attendance_date`+`work_shift_id` — 1 dòng/CA/ngày, cho phép nhiều ca cùng ngày), `attendance_logs` (thêm 2026-09-21 [`..._add_device_info_to_attendance_logs_table.php`](database/migrations/2026_09_21_000002_add_device_info_to_attendance_logs_table.php): `address`, `device_name`, `user_agent`, đều nullable); `POST /check-in`, `/check-out`, `GET /today`, `/me`, `/` (HR) ([`routes/api/v1/attendances.php`](routes/api/v1/attendances.php)); quyền `attendance.check`/`view_own`/`view_all` |
| Test | [`AttendanceTest.php`](tests/Feature/Attendance/AttendanceTest.php) (có nhóm test ghi IP/địa chỉ/thiết bị, luôn `Http::preventStrayRequests()` + `fakeNominatim()` — test không bao giờ gọi mạng thật), [`AttendanceServiceCalculationTest.php`](tests/Unit/Services/AttendanceServiceCalculationTest.php) (Unit — bao gồm ranh giới ân hạn OT), [`DeviceInfoParserTest.php`](tests/Unit/Services/Attendance/DeviceInfoParserTest.php), [`ReverseGeocoderTest.php`](tests/Unit/Services/Attendance/ReverseGeocoderTest.php), [`AttendanceApprovalTest.php`](tests/Feature/Attendance/AttendanceApprovalTest.php) (duyệt chấm công) |
| Hiển thị giờ | [`composables/useCheckIn.js`](resources/js/composables/useCheckIn.js) export `formatMinutesAsHours()` — Trễ/Về sớm/OT hiện dạng `"0.5h (30 phút)"` để dễ đối chiếu khi tính lương sau này (Phase 4). Chỉ đổi hiển thị — backend vẫn lưu/cộng dồn nguyên phút, chỉ quy đổi ra giờ 1 lần lúc tính lương để tránh sai số làm tròn tích lũy qua nhiều ngày |
| **Duyệt chấm công** (2026-09-21, theo yêu cầu người dùng: mọi lượt chấm công phải được HR duyệt, chưa duyệt thì không tính công/lương) | **Đơn vị duyệt = 1 bản ghi `attendances` (ca + ngày)**, gồm cả lượt vào lẫn ra — không duyệt riêng từng log. Cột mới (migration [`2026_09_21_000003_add_approval_to_attendances_table.php`](database/migrations/2026_09_21_000003_add_approval_to_attendances_table.php)): `approval_status` (`pending`/`approved`/`rejected`, mặc định `pending`, hằng số `Attendance::APPROVAL_*`), `approved_by`, `approved_at`, `approval_note`. **TÁCH RIÊNG khỏi `status`** (`pending`/`completed`/`needs_review` = trạng thái CA làm việc): 2 trục độc lập, 1 bản ghi có thể `completed` mà vẫn chờ duyệt. Migration **tự chuyển mọi bản ghi CÓ SẴN sang `approved`** (ghi chú "Tự động duyệt…") để lịch sử và bảng lương các tháng trước không bị mất công — chỉ lượt chấm công SAU thời điểm này mới phải duyệt. Quyền mới **`attendance.approve`** (Admin + HR; Manager/Employee không có) trong `PermissionSeeder`/`RolePermissionSeeder` — DB đã có sẵn phải chạy lại 2 seeder này. API: `PUT /attendances/{attendance}/approval` (`whereNumber`, body `status`=`approved`/`rejected` + `decision_note`, **từ chối bắt buộc có lý do**) → `AttendanceController::decideApproval()` → `AttendanceService::decideApproval()`; `GET /attendances?approval_status=` lọc danh sách (nạp kèm `logs.attendanceLocation` + `approvedBy`). Quy tắc: chỉ duyệt được bản ghi ĐÃ chấm công ra (quên chấm ra → nhân viên "Xin điều chỉnh công"); được ĐỔI quyết định (duyệt ↔ từ chối, vì HR có thể bấm nhầm), chặn quyết định trùng trạng thái hiện tại. **Duyệt yêu cầu điều chỉnh/bổ sung công (`correction`/`supplement`) = duyệt luôn bản ghi** (`AttendanceAdjustmentService::decide()`, tránh bắt HR duyệt 2 lần; `excuse`/`overtime`/từ chối thì KHÔNG đụng). **Ảnh hưởng tính công**: `PayrollService::calculateWorkedMetrics()` chỉ lấy `approval_status=approved`; `AttendanceService::summarizeHistory()` cũng chỉ cộng `total_work_days`/`total_work_minutes` từ bản ghi đã duyệt (cùng luật để số ở màn lịch sử khớp phiếu lương) và trả thêm `unapproved_count` (thẻ "Chưa được duyệt" ở lịch sử). **Chốt chặn tạo bảng lương**: `PayrollService::generateForPeriod()` báo 422 nếu kỳ đó còn bản ghi ĐÃ chấm ra mà `approval_status=pending` — vì bảng lương chỉ tính MỘT LẦN, không tự tính lại khi duyệt sau (bản ghi quên chấm ra không bị tính để khỏi chặn bảng lương vô thời hạn). Frontend: [`AttendanceApprovals.vue`](resources/js/views/Attendance/AttendanceApprovals.vue) (route `/attendance-approvals`, menu "Duyệt chấm công", dùng `DataTable` `actions` + `confirm.input` như màn Duyệt điều chỉnh công), component dùng chung [`components/attendance/AttendanceLogList.vue`](resources/js/components/attendance/AttendanceLogList.vue) (khối thiết bị/IP/vị trí từng lượt — dùng cả ở dialog chi tiết của `AttendanceHistoryPanel.vue`), `APPROVAL_STATUS_MAP` export từ `useCheckIn.js`; nhân viên thấy chip "Chờ duyệt/Đã duyệt/Bị từ chối" (+ lý do từ chối) ở thẻ ca hôm nay, lịch sử gần đây (Desktop + Mobile) và cột "Duyệt công" của Lịch sử chấm công. Test: [`AttendanceApprovalTest.php`](tests/Feature/Attendance/AttendanceApprovalTest.php) + test thêm ở `AttendanceHistoryTest`/`PayrollTest`/`AttendanceTest`. **Chưa làm**: duyệt hàng loạt (mỗi ca 1 lượt bấm — khối lượng lớn nếu nhiều nhân viên), thông báo cho nhân viên khi bị từ chối. |
| Ghi chú | **Chấm công = ghi nhận dữ liệu của CHÍNH thiết bị đang bấm** (2026-09-21, theo yêu cầu người dùng: dùng điện thoại thì ghi IP điện thoại, địa chỉ nơi bấm, tên thiết bị — trước đó bị hiểu nhầm là nhân viên chọn 1 trong 3 phương thức Wifi/GPS/QR). Mỗi lượt vào/ra TỰ ghi vào `attendance_logs`: `ip_address` (server đọc `request()->ip()`), `device_name` (`DeviceInfoParser::describe()`, VD "iPhone (iOS 17.2) · Safari"; **giới hạn của trình duyệt** — không lấy được tên thương mại như "iPhone 15 Pro", Android hay chỉ còn "Android 13" vì Chrome mới che mã máy) + `user_agent` gốc, tọa độ `latitude`/`longitude`/`accuracy_meters` do trình duyệt gửi lên, `address` (`ReverseGeocoder` đổi tọa độ → địa chỉ chữ qua Nominatim/OpenStreetMap, cấu hình `config/services.php` → `nominatim`, biến `NOMINATIM_URL`/`NOMINATIM_USER_AGENT`; timeout 3s, cache 1 ngày theo tọa độ làm tròn 4 chữ số). **Fail-soft có chủ đích**: từ chối quyền vị trí / http không phải https / dịch vụ địa chỉ sập → vẫn chấm công được, chỉ thiếu `address` (tọa độ thô vẫn lưu nếu có); tra địa chỉ chạy TRƯỚC `DB::transaction()` để không giữ khóa DB lúc chờ mạng. **Lưu ý riêng tư**: tọa độ nhân viên được gửi sang máy chủ OpenStreetMap (chỉ tọa độ, không kèm danh tính); Nominatim yêu cầu User-Agent nhận diện ứng dụng và ~1 request/giây — nên đổi `NOMINATIM_USER_AGENT` thành tên+email liên hệ thật khi triển khai. **"Điểm chấm công" của công ty (mục 13) giờ chỉ để ĐỐI CHIẾU/gắn nhãn** — `matchLocation()` thu cả IP + tọa độ + QR rồi tự khớp theo thứ tự ưu tiên QR > Wifi (IP trong `allowed_ip_cidr`) > GPS (trong bán kính); khớp → `status=pending` + ghi điểm, không khớp → `needs_review`. Cột `attendance_logs.method` đổi nghĩa thành "khớp bằng cách nào" (`wifi`/`gps`/`qr` theo điểm đã khớp, `device` = không khớp điểm nào; bản ghi cũ trước thay đổi vẫn là lựa chọn của nhân viên). Frontend: bỏ 3 tab Wifi/GPS/QR ở `CheckInDesktop.vue`/`CheckInMobile.vue`, `useCheckIn.js` tự xin vị trí mỗi lần bấm (bị từ chối → toast cảnh báo rồi vẫn gửi), ô mã QR còn lại là tùy chọn; [`AttendanceHistoryPanel.vue`](resources/js/views/Attendance/AttendanceHistoryPanel.vue) dialog "Chi tiết chấm công" hiện mỗi lượt là 1 khối (Thiết bị / IP / Vị trí+tọa độ+sai số / Điểm chấm công). **Chưa kiểm chứng với Nominatim thật** (máy phát triển không phân giải được tên miền openstreetmap.org) — nhánh thành công chỉ được test bằng `Http::fake()`. Ngoài ra: `matchLocation()` không khớp điểm nào (wifi/gps/qr) thì **vẫn cho chấm công**, chỉ đánh dấu `status=needs_review` (không chặn cứng). OT tính từ phần **SAU `end_time`**, trừ thêm `OVERTIME_GRACE_MINUTES=5` phút ân hạn CỐ ĐỊNH toàn hệ thống (2026-09-21, theo yêu cầu người dùng — ra trễ vài phút do dọn dẹp/di chuyển không tính OT ngay; VD ca kết thúc 17:00: check-out 17:05 → 0 phút OT, 17:06 → 1 phút, 17:30 → 25 phút), không phải `actual - standard`. `overtime_minutes` lưu **luôn đúng số thực đã trừ ân hạn**, kể cả khi chưa đạt ngưỡng trả lương — xem mục 27 (PayrollService) cho ngưỡng đó + cổng duyệt OT (mục 17). **Khung giờ check-in** (Ngày 44 — bug người dùng phát hiện): trước đó không so giờ hiện tại với giờ ca, cho phép chấm công 1 ca cách xa giờ thật (vd chấm Ca chiều lúc 8h sáng) — đã sửa bằng `assertWithinCheckInWindow()` (chỉ cho vào sớm tối đa 30 phút trước `start_time`, chặn hẳn sau `end_time`, dùng "Xin bổ sung chấm công" thay thế, mục 17). WiFi "mất điểm" khi test qua Docker Desktop là do NAT (source IP bị đổi thành gateway container), không phải bug — dev local cần đặt `allowed_ip_cidr` theo đúng subnet Docker, **đổi lại khi triển khai thật** |
| Danh sách tệp cần sửa khi bảo trì | Toàn bộ các file trên |

## 17. Điều chỉnh công (Attendance Adjustment)

| Hạng mục | Chi tiết |
| --- | --- |
| Giao diện Frontend | [`CheckIn.vue`](resources/js/views/Attendance/CheckIn.vue) — "Xin điều chỉnh" (sửa bản ghi có sẵn), "Xin bổ sung chấm công" (tạo bản ghi cho ca+ngày chưa từng chấm công), "Xin miễn trừ đi muộn" (mục 24), "Xin duyệt OT" (2026-09-21, cùng khuôn "Xin miễn trừ đi muộn" — nút chỉ hiện khi `overtime_minutes > 0 && !overtime_approved`); [`AttendanceAdjustments.vue`](resources/js/views/Attendance/AttendanceAdjustments.vue) — HR duyệt, dùng `confirm.input` của `DataTable.vue` |
| Xử lý Backend | [`AttendanceAdjustmentController.php`](app/Http/Controllers/Api/V1/AttendanceAdjustmentController.php) (`store`/`mine`/`index`/`decide`), [`AttendanceAdjustmentService.php`](app/Services/AttendanceAdjustmentService.php) (`requestForEmployee()`/`decide()` rẽ nhánh theo `type`: `correction`/`supplement`/`excuse`/`overtime`), [`AttendanceAdjustmentRepository.php`](app/Repositories/AttendanceAdjustmentRepository.php) |
| Model | [`AttendanceAdjustment.php`](app/Models/AttendanceAdjustment.php) — `employee_id`/`work_shift_id`/`attendance_date` set cho cả 4 loại (đọc trực tiếp không cần qua `attendance`) |
| Validation | [`StoreAttendanceAdjustmentRequest.php`](app/Http/Requests/Attendance/StoreAttendanceAdjustmentRequest.php) (rẽ nhánh theo `type`), [`DecideAttendanceAdjustmentRequest.php`](app/Http/Requests/Attendance/DecideAttendanceAdjustmentRequest.php) |
| Database & API | `attendance_adjustments`; `POST /adjustments`, `GET /adjustments/me`, `GET/PUT /adjustments`, `/{id}` ([`routes/api/v1/attendances.php`](routes/api/v1/attendances.php) — `/adjustments/me` phải khai TRƯỚC `/adjustments/{id}`) |
| Test | [`AttendanceAdjustmentTest.php`](tests/Feature/Attendance/AttendanceAdjustmentTest.php) (bao gồm type `overtime`) |
| Ghi chú | Duyệt `correction`/`supplement` thì áp giờ đề xuất vào `Attendance` qua `AttendanceService::applyAdjustment()` (tái dùng công thức trễ/sớm/OT), `status=completed` chỉ khi đủ cả giờ vào lẫn ra. Duyệt `excuse` **chỉ** set `late_excused=true`, duyệt `overtime` (2026-09-21) **chỉ** set `overtime_approved=true` — cả 2 đều không đổi giờ (mục 24). Từ chối ở loại nào cũng không đụng `Attendance`. `overtime` type: nhân viên CHỦ ĐỘNG gửi yêu cầu (không phải HR tự duyệt hàng loạt) — chặn nếu `overtime_minutes<=0` hoặc đã `overtime_approved`; PayrollService (mục 27) chỉ trả lương OT khi cờ này = true VÀ đạt ngưỡng phút tối thiểu |
| Danh sách tệp cần sửa khi bảo trì | Toàn bộ các file trên |

## 18. Lịch sử chấm công (báo cáo theo tháng)

| Hạng mục | Chi tiết |
| --- | --- |
| Giao diện Frontend | [`AttendanceHistoryPanel.vue`](resources/js/views/Attendance/AttendanceHistoryPanel.vue) — dùng chung cho [`AttendanceHistory.vue`](resources/js/views/Attendance/AttendanceHistory.vue) (tự xem) và tab "Chấm công" trong `EmployeeDetail.vue` (Admin xem theo nhân viên) |
| Xử lý Backend | `AttendanceController::historyMine()`/`history()`, `AttendanceService::history()`/`deriveHistoryStatus()`/`summarizeHistory()` — không có migration riêng, tính từ dữ liệu đã có |
| Database & API | `GET /attendances/history/me`, `/history/{employee}` (phải khai `/me` TRƯỚC); quyền `attendance.view_own`/`view_all` |
| Test | [`AttendanceHistoryTest.php`](tests/Feature/Attendance/AttendanceHistoryTest.php) |
| Ghi chú | Nhãn suy theo thứ tự ưu tiên `on_leave` > `late` > `insufficient` > `full`/`absent` — đơn nghỉ phép `approved` (không phải `hourly`) tự che ngày thành `on_leave`, loại khỏi cả "Vắng" lẫn thống kê giờ công. `total_work_days` trong `summary` (2026-09-16, cập nhật): tính qua [`WorkTimeCalculationService`](app/Services/WorkTimeCalculationService.php) dùng CHUNG với `PayrollService` (mục 27) — KHÔNG còn dùng `WorkShift.work_coefficient` nữa (xem mục 12) |
| Danh sách tệp cần sửa khi bảo trì | Toàn bộ các file trên |

## 19. Nghỉ phép — Tạo đơn (Leave Request)

| Hạng mục | Chi tiết |
| --- | --- |
| Xử lý Backend | [`LeaveRequestController.php`](app/Http/Controllers/Api/V1/LeaveRequestController.php), [`LeaveRequestService.php`](app/Services/LeaveRequestService.php) (`calculateTotalDays()`, `calculateHourlyDays()`, kiểm quỹ phép), [`LeaveRequestRepository.php`](app/Repositories/LeaveRequestRepository.php) (`findOverlapping()`, `sumPendingDaysForYear()`), [`LeaveBalanceRepository.php`](app/Repositories/LeaveBalanceRepository.php) |
| Model | [`LeaveType.php`](app/Models/LeaveType.php), [`LeaveBalance.php`](app/Models/LeaveBalance.php), [`LeaveRequest.php`](app/Models/LeaveRequest.php) |
| Seeder | [`LeaveTypeSeeder.php`](database/seeders/LeaveTypeSeeder.php) — 5 loại (`annual`/`sick`/`maternity`/`paternity`/`unpaid`) |
| Validation | [`StoreLeaveRequest.php`](app/Http/Requests/Leave/StoreLeaveRequest.php) |
| Database & API | `leave_types`, `leave_balances`, `leave_requests`, `leave_approvals`; `POST /leave-requests`, `GET /leave-requests/me`, `/balances/me`, `/` (HR) ([`routes/api/v1/leave-requests.php`](routes/api/v1/leave-requests.php)); quyền `leave.request`/`view_own`/`view_all` |
| Test | [`LeaveRequestTest.php`](tests/Feature/Leave/LeaveRequestTest.php) |
| Ghi chú | `total_days` bỏ qua Thứ 7/CN; chỉ loại `annual` bị giới hạn quỹ (5 loại kia `annual_entitlement_days=0`, tính theo chế độ riêng). Quỹ phép **không trừ lúc tạo đơn** — chỉ trừ khi duyệt xong (`deductBalance()`, mục 20); lúc kiểm tra "đủ phép" đã trừ cả `total_days` của các đơn đang `pending`/`manager_approved` cùng loại (`available_days`), tránh cộng dồn vượt quỹ. Chặn đơn phép trùng ngày qua `findOverlapping()` (mọi đơn chưa bị từ chối). Mọi cột `decimal` phải cast `'float'` trong Model (tránh hiện `"1.00"`) — nhưng **không** ép `is_active` thành `boolean` (vỡ `StatusChip` tra theo khóa số nguyên `1`/`0`) |
| Danh sách tệp cần sửa khi bảo trì | Toàn bộ các file trên |

## 20. Nghỉ phép — Duyệt nhiều cấp (Leave Approval)

| Hạng mục | Chi tiết |
| --- | --- |
| Xử lý Backend | `LeaveRequestController::decide()`, [`LeaveApprovalService.php`](app/Services/LeaveApprovalService.php), [`LeaveApprovalRepository.php`](app/Repositories/LeaveApprovalRepository.php) |
| Permission | Tách `leave.approve` (gốc) thành `leave.approve_manager`/`leave.approve_hr` |
| Model | [`LeaveApproval.php`](app/Models/LeaveApproval.php) |
| Test | [`LeaveApprovalTest.php`](tests/Feature/Leave/LeaveApprovalTest.php) |
| Ghi chú | Luồng `pending` → (Manager) → `manager_approved` → (HR) → `approved`; **từ chối ở cấp nào cũng kết thúc luôn**; không có quản lý trực tiếp thì HR duyệt thẳng; duyệt xong cấp cuối mới gọi `deductBalance()`. Tham số Controller phải khớp CHÍNH XÁC tên route model binding (không có fallback theo vị trí — gõ nhầm tên sẽ âm thầm nhận 1 Model rỗng) |
| Danh sách tệp cần sửa khi bảo trì | Toàn bộ các file trên |

## 21. Nghỉ phép — Email thông báo kết quả duyệt (Queue)

| Hạng mục | Chi tiết |
| --- | --- |
| Xử lý Backend | [`LeaveDecisionMail.php`](app/Mail/LeaveDecisionMail.php) (`ShouldQueue`) + view [`leave-decision.blade.php`](resources/views/emails/leave-decision.blade.php) |
| Hạ tầng | Thêm service `queue` vào [`docker-compose.yml`](docker-compose.yml) (`php artisan queue:work`) |
| Test | `Mail::assertQueued()` trong `LeaveApprovalTest.php` |
| Ghi chú | Chỉ gửi khi trạng thái CUỐI (`approved`/`rejected`, không gửi ở `manager_approved`), gọi **sau khi** transaction đã commit |
| Danh sách tệp cần sửa khi bảo trì | Toàn bộ các file trên |

## 22. Nghỉ phép — Form tạo đơn (Frontend, tự phục vụ)

| Hạng mục | Chi tiết |
| --- | --- |
| Giao diện Frontend | [`LeaveRequests.vue`](resources/js/views/Leave/LeaveRequests.vue) — dialog tạo đơn (nghỉ theo giờ, đính kèm bắt buộc với ốm/thai sản/chế độ cha-mẹ), thẻ quỹ phép còn lại, bảng "Đơn của tôi"; [`leaveRequestService.js`](resources/js/services/leaveRequestService.js), [`leaveTypeService.js`](resources/js/services/leaveTypeService.js) |
| Xử lý Backend | [`LeaveTypeController::index()`](app/Http/Controllers/Api/V1/LeaveTypeController.php) (chỉ đọc), migration thêm `start_time`/`end_time` cho nghỉ theo giờ ([`...000003_add_hourly_fields...`](database/migrations/2026_09_11_000003_add_hourly_fields_to_leave_requests_table.php)) |
| Database & API | `GET /leave-types`; `GET /leave-requests/{id}/evidence` (IDOR check trong hàm, không qua middleware) |
| Ghi chú | Dropdown loại phép ghép sẵn "Có lương/Không lương + giới hạn N ngày" vào tên lựa chọn, không cần component riêng |
| Danh sách tệp cần sửa khi bảo trì | Toàn bộ các file trên |

## 23. Nghỉ phép — Duyệt (Frontend, Manager/HR)

| Hạng mục | Chi tiết |
| --- | --- |
| Giao diện Frontend | [`LeaveManagement.vue`](resources/js/views/Leave/LeaveManagement.vue) — theo khuôn `AttendanceAdjustments.vue` (mục 17), không tự đoán cấp Manager/HR ở Frontend (để Service tự quyết theo danh tính người bấm) |
| Test | Kiểm chứng qua Playwright — logic duyệt đã có 10 test ở mục 20 |
| Danh sách tệp cần sửa khi bảo trì | Toàn bộ các file trên |

## 24. Ngày 42 — Edge case chấm công

3 edge case theo kế hoạch, đã xác nhận qua `AskUserQuestion` trước khi làm:

| Hạng mục | Chi tiết |
| --- | --- |
| "Chấm công quên Check-out" | Không cần thêm code — cơ chế `type=correction` sẵn có (mục 17) đã xử lý đúng (chỉ đề xuất `proposed_check_out_at`, giữ nguyên giờ vào) |
| "Đơn phép trùng ngày" | Đã xử lý ở mục 19 (`findOverlapping()`) |
| "Đi muộn có lý do" | Type mới `excuse` trong `attendance_adjustments` + cột `attendances.late_excused` ([migration `...000004`](database/migrations/2026_09_11_000004_add_late_excused_to_attendances_table.php)). Nhân viên gửi `type=excuse` (chỉ cần `attendance_id`+`reason`, không sửa giờ) — validate `late_minutes > 0` và chưa từng được miễn trừ. HR duyệt chỉ set `late_excused=true`, không gọi `applyAdjustment()`. Ảnh hưởng `deriveHistoryStatus()`/`summarizeHistory()` (mục 18) — ngày được miễn trừ không còn tính là "Đi muộn" |
| Giao diện Frontend | `CheckIn.vue` nút "Xin miễn trừ đi muộn" (hiện khi `late_minutes > 0 && !late_excused`), `AttendanceAdjustments.vue` thêm `excuse` vào `ADJUSTMENT_TYPE_MAP` |
| Test | `AttendanceAdjustmentTest.php`, `AttendanceHistoryTest.php` |
| Danh sách tệp cần sửa khi bảo trì | Toàn bộ các file trên |

## 25. Ngày 43 — Unit Test & Integration Test cho Attendance & Leave

| Hạng mục | Chi tiết |
| --- | --- |
| Integration Test | Phần lớn đã có sẵn từ các ngày trước (99 test) — vá 1 lỗ hổng: [`LeaveTypeTest.php`](tests/Feature/Leave/LeaveTypeTest.php) (3 test, `LeaveTypeController` chưa từng được test riêng) |
| Unit Test (mới) | [`tests/Unit/Services/AttendanceServiceCalculationTest.php`](tests/Unit/Services/AttendanceServiceCalculationTest.php) (21 test), [`LeaveRequestServiceCalculationTest.php`](tests/Unit/Services/LeaveRequestServiceCalculationTest.php) (13 test) — gọi thẳng các hàm `private` qua Reflection, dùng chung trait [`InteractsWithPrivateMethods.php`](tests/Unit/Concerns/InteractsWithPrivateMethods.php) |
| Ghi chú | Test dựng `Attendance` với field cast `datetime` (gán giá trị `Carbon`) phải kế thừa `Tests\TestCase` (boot Laravel, không cần `RefreshDatabase`) chứ không phải `PHPUnit\Framework\TestCase` trần — Eloquent cần connection resolver để tính `getDateFormat()` dù không thật sự chạm DB |
| Danh sách tệp cần sửa khi bảo trì | Không áp dụng — bổ sung Test cho code đã có ở mục 16-24 |

## 26. Ngày 44 — Responsive UI cho Check-in mobile

| Hạng mục | Chi tiết |
| --- | --- |
| Sidebar | [`AppLayout.vue`](resources/js/components/layout/AppLayout.vue)/[`AppSidebar.vue`](resources/js/components/layout/AppSidebar.vue) — `v-navigation-drawer` chuyển `temporary` (overlay, ẩn mặc định) dưới breakpoint mobile của Vuetify (1280px mặc định), `permanent` + `rail` như cũ ở desktop |
| Check-in | [`composables/useCheckIn.js`](resources/js/composables/useCheckIn.js) (toàn bộ state/logic dùng chung) + [`CheckInDesktop.vue`](resources/js/views/Attendance/CheckInDesktop.vue) (bảng) + [`CheckInMobile.vue`](resources/js/views/Attendance/CheckInMobile.vue) (danh sách thẻ, dialog fullscreen) + [`CheckIn.vue`](resources/js/views/Attendance/CheckIn.vue) (switcher theo `useDisplay().mobile`) — tách UI theo thiết bị nhưng dùng chung 1 lớp logic, tránh rủi ro lệch code giữa 2 bản |
| Ghi chú | Dialog `fullscreen` dùng `.glass-panel` (nền trong suốt) → chữ trang phía sau xuyên qua vì `fullscreen` không có scrim — sửa dùng nền đặc mặc định của `v-card`/`v-toolbar`. Khởi tạo `drawerOpen = ref(false)` cố định làm sidebar ẩn ở **mọi** kích thước kể cả desktop — Vuetify chỉ tự ép `permanent` hiện lại khi component KHÔNG nhận `v-model` tường minh, đã sửa bằng `ref(!mobile.value)` + `watch(mobile, ...)` tự đồng bộ khi resize qua breakpoint |
| Danh sách tệp cần sửa khi bảo trì | Toàn bộ các file trên |

## 27. Ngày 46-48 — Payroll (Bảng lương): Model, Repository, Service, Controller/Route, tính lương tự động

| Hạng mục | Chi tiết |
| --- | --- |
| Database | 4 bảng đã có sẵn từ migration Ngày 03-04 (`salary_components`, `payrolls`, `payroll_details`, `payroll_detail_components` — thiết kế snapshot, xem Model), cộng 1 migration mới bổ sung `deleted_at` cho `salary_components` ([`...000001_add_soft_deletes_to_salary_components_table.php`](database/migrations/2026_09_14_000001_add_soft_deletes_to_salary_components_table.php)) và 1 bảng `holidays` (đã có từ đầu, migration Ngày 03) |
| Model | [`Payroll.php`](app/Models/Payroll.php) (`createdBy()`/`closedBy()` — `belongsTo` phải truyền tay tên cột vì không theo quy ước `user_id`), [`PayrollDetail.php`](app/Models/PayrollDetail.php), [`PayrollDetailComponent.php`](app/Models/PayrollDetailComponent.php) (snapshot `component_name_snapshot`/`calculation_snapshot`, KHÔNG đọc live qua `salaryComponent()` để hiển thị phiếu lương cũ), [`SalaryComponent.php`](app/Models/SalaryComponent.php), [`Holiday.php`](app/Models/Holiday.php) (mới, trước đó bảng `holidays` chưa có Model — hiện vẫn chưa có CRUD/seeder, đang rỗng) |
| Xử lý Backend | [`PayrollRepository.php`](app/Repositories/PayrollRepository.php), [`PayrollDetailRepository.php`](app/Repositories/PayrollDetailRepository.php), [`PayrollService.php`](app/Services/PayrollService.php) (`generateForPeriod()` tự tính lương cho toàn bộ nhân viên active+probation có hợp đồng active — kéo dữ liệu chấm công/nghỉ phép/hợp đồng thật; `calculateWorkedMetrics()` tự tính ngày công quy đổi + tiền OT trực tiếp từ `actual_work_minutes`/`overtime_minutes` theo GIỜ, KHÔNG qua `AttendanceService` (xem Ghi chú thiết kế bên dưới); `close()`/`markAsPaid()` state machine processing→closed→paid), [`PersonalIncomeTaxCalculator.php`](app/Services/Payroll/PersonalIncomeTaxCalculator.php) (biểu thuế TNCN lũy tiến 7 bậc, tách riêng để unit-test độc lập) |
| Giao diện API | [`PayrollController.php`](app/Http/Controllers/Api/V1/PayrollController.php) (`index`/`show`/`generate`/`close`/`markAsPaid`/`mine`), [`GeneratePayrollRequest.php`](app/Http/Requests/Payroll/GeneratePayrollRequest.php), [`PayrollResource.php`](app/Http/Resources/PayrollResource.php)/[`PayrollDetailResource.php`](app/Http/Resources/PayrollDetailResource.php), route [`routes/api/v1/payrolls.php`](routes/api/v1/payrolls.php) (`/me` đặt TRƯỚC `/{payroll}` để tránh khớp nhầm route) — quyền `payroll.view_all`/`payroll.manage`/`payroll.view_own` đã có sẵn từ Ngày 08, không cần thêm permission mới |
| Test | [`PersonalIncomeTaxCalculatorTest.php`](tests/Unit/Services/Payroll/PersonalIncomeTaxCalculatorTest.php) (6 test Unit thuần), [`WorkTimeCalculationServiceTest.php`](tests/Unit/Services/WorkTimeCalculationServiceTest.php) (25 test Unit thuần — mọi ranh giới bậc thang + trần phút trễ + `work_coefficient`/ca chia đôi, dùng chung Attendance/Payroll), [`PayrollTest.php`](tests/Feature/Payroll/PayrollTest.php) (17 test — gọi thẳng `PayrollService`, số liệu kịch bản chính đối chiếu đúng tay tính qua tinker, gồm test khóa lại bug lương-theo-công đã sửa + test khóa quy tắc giới hạn 1.0 ngày công/ngày + test khóa bug chọn nhầm hợp đồng theo status hiện tại + 3 test khóa cổng duyệt OT), [`PayrollControllerTest.php`](tests/Feature/Payroll/PayrollControllerTest.php) (18 test — đi qua HTTP thật + phân quyền theo role, gồm `forEmployee()`/tab Lương-Phép) |
| Thiết kế "ngày công quy đổi" (2026-09-16, theo góp ý người dùng — bản đầu; refactor cùng ngày sang bậc thang, xem dưới) | Lưu/tính NỘI BỘ theo GIỜ, hiển thị/tính lương theo NGÀY CÔNG cho nhân viên lương tháng. Đơn giá giờ OT tính riêng theo `standard_work_minutes` của đúng ca hôm OT xảy ra, không còn hằng số 8h cố định cho mọi nhân viên — phần này KHÔNG đổi. |
| **Refactor "ngày công quy đổi" → bậc thang dùng chung** (2026-09-16, theo yêu cầu người dùng; sửa lại cùng ngày sau khi phát hiện bug ca chia đôi) | Thay công thức tuyến tính (`actual_work_minutes ÷ standard_work_minutes`, tối đa 1.0/ngày) bằng [`WorkTimeCalculationService::dayEquivalentFor()`](app/Services/WorkTimeCalculationService.php) — **bậc thang theo % giờ làm** so với `standard_work_minutes` CỦA ĐÚNG CA hôm đó (100%→1.0, 75%→0.75, 50%→0.5, 25%→0.25, dưới 25%→0), kết hợp **trần theo phút đi muộn/về sớm** (≤15p không phạt, 15-30p trần 0.75, 30-60p trần 0.5, >60p trần 0.25), **NHÂN thêm với `work_coefficient` của ca đó** — công thức đầy đủ: `min(bậc giờ làm, trần phút trễ) × work_coefficient`. `work_coefficient` **VẪN đang được dùng** (bản đầu tiên định bỏ hẳn, nhưng bug thật đã vấp: nhân viên chia ca sáng+chiều mỗi ca 0.5 công làm đủ CẢ 2 ca bị cộng thành 2.0 thay vì đúng 1.0 nếu bỏ nó — 2 khái niệm ĐỘC LẬP: bậc thang trả lời "làm đủ/thiếu bao nhiêu SO VỚI CHÍNH ca đó", `work_coefficient` trả lời "ca này đáng bao nhiêu phần của 1 ngày", phải nhân chứ không thay thế nhau). Hằng số đặt cứng trong Service (không có bảng DB/trang Admin). **Dùng CHUNG cho cả 2 module** — thay thế công thức đọc `work_coefficient` trực tiếp cũ trong `AttendanceService::summarizeHistory()` (mục 18) LẪN công thức tuyến tính cũ trong `PayrollService::calculateWorkedMetrics()` (mục 27), giải quyết dứt điểm phân kỳ "2 module tính ngày công khác nhau" đã ghi chú trước đó. OT vẫn tính riêng qua `overtime_minutes`, không đổi bởi refactor này (xem dòng "Cổng duyệt OT" bên dưới cho thay đổi khác của OT) |
| **Cổng duyệt chấm công** (2026-09-21, theo yêu cầu người dùng) | `calculateWorkedMetrics()` chỉ tính bản ghi `attendances.approval_status=approved`; `generateForPeriod()` chặn tạo bảng lương khi kỳ đó còn bản ghi đã chấm công ra mà chưa duyệt (422, thông báo số bản ghi còn lại). Chi tiết + lý do xem mục 16 hàng "Duyệt chấm công". Test: `PayrollTest::test_only_approved_attendance_counts_toward_salary` và 2 test chốt chặn |
| **Cổng duyệt OT** (2026-09-21, theo yêu cầu người dùng) | OT chỉ được **TRẢ LƯƠNG** khi (1) `Attendance.overtime_approved=true` (nhân viên gửi yêu cầu qua `AttendanceAdjustment` type `overtime`, HR duyệt — mục 17) VÀ (2) đạt `PayrollService::OVERTIME_MINIMUM_PAYABLE_MINUTES=25` phút (tương đương 30 phút THỰC TẾ trước khi trừ `AttendanceService::OVERTIME_GRACE_MINUTES=5` phút ân hạn — mục 16, phương án B: ngưỡng áp trên số phút thực tế TRƯỚC khi trừ ân hạn). Không đạt 1 trong 2 điều kiện thì CẢ `overtime_minutes` LẪN `overtime_amount` trả về trong `PayrollDetail` đều = 0 (tránh lệch số phút hiện ra với số tiền thực trả trên phiếu lương) — nhưng cột gốc `Attendance.overtime_minutes` vẫn giữ nguyên số thực, không bị xóa/zero, HR vẫn thấy đúng dữ liệu chấm công gốc |
| Ghi chú — các giả định cần xác nhận lại trước khi dùng số liệu thật | (1) Mốc bậc thuế TNCN + giảm trừ bản thân 11tr + bảo hiểm 10.5% là số liệu **pháp luật**, có thể đã lỗi thời — chưa kiểm tra lại quy định hiện hành. (2) Chưa trừ giảm trừ người phụ thuộc (Employee/Contract chưa có cột lưu số người phụ thuộc). (3) OT tính đồng giá 150%, chưa phân biệt ngày thường/cuối tuần/lễ. (4) `total_payroll_amount` = tổng `net_salary`, không phải gross. (5) Bảng `holidays` đang rỗng, chưa có CRUD/seeder — `standard_work_days` hiện chỉ trừ T7/CN, chưa trừ được ngày lễ thật nào. (6) `generate()` cố tình KHÔNG trả kèm `details` trong JSON — khác `show()` có tải kèm. (7) BHXH/BHYT/BHTN vẫn gộp chung 1 số `insurance_amount` (10.5%) ở Backend — `PayrollPayslipDialog.vue` hiển thị nguyên 1 dòng gộp "BHXH, BHYT, BHTN (10,5%)" (khớp mẫu phiếu lương công ty cung cấp), không tách 3 dòng riêng nữa, không đổi gì DB. (8) Bảo hiểm vẫn tính trên NGUYÊN `insurance_salary` hợp đồng bất kể đi làm bao nhiêu ngày trong tháng — chưa prorate theo công như `base_salary`, có thể ra `net_salary` âm nếu 1 tháng đi làm quá ít |
| Bug đã sửa | (1) `PayrollController::generate()` ban đầu dùng `response()->json(...)` không bọc `"data"` — sửa thành `->response()->setStatusCode(201)`. (2) **Bug tính lương thật** (phát hiện qua người dùng thật báo lỗi, không phải test): công thức cũ lấy NGUYÊN lương hợp đồng rồi chỉ trừ nếu có đơn xin nghỉ phép KHÔNG LƯƠNG đã duyệt — nhân viên đi làm rất ít nhưng không nộp đơn nghỉ phép nào vẫn được tính đủ lương. Sửa: `base_salary` giờ = đơn giá ngày công × (số ngày thực tế đi làm + số ngày nghỉ phép CÓ LƯƠNG đã duyệt). (3) `overtime_amount` quên khai trong `$fillable`/`$casts` của `PayrollDetail.php` lúc mới thêm cột — Eloquent âm thầm bỏ qua khi lưu, luôn ra 0 dù Service tính đúng. (4) Đơn giá giờ OT ban đầu chia cố định cho 8h — sửa theo đúng `standard_work_minutes` của từng ca (phát hiện qua người dùng thật hỏi lại, dẫn tới thiết kế "ngày công quy đổi" ở trên). (5) **Chọn nhầm hợp đồng khi chốt lương retroactive** (phát hiện qua tự rà soát code sau khi thêm tính năng quản lý trạng thái hợp đồng — mục 8): `generateForPeriod()` chọn hợp đồng bằng `where('status','active')` (trạng thái HIỆN TẠI) thay vì hợp đồng hiệu lực TRONG ĐÚNG KỲ đang tính — từ khi có auto-expire (job hằng ngày)/auto-supersede (mục 8), hợp đồng của kỳ vừa qua có thể đã đổi status trước khi HR kịp chốt lương (quy trình bình thường: chốt lương SAU khi tháng đã qua), khiến Payroll vớ nhầm hợp đồng của kỳ SAU (sai lương/BH dùng để tính, sai luôn cả `employee_contract_id` lưu vào chi tiết lương). Sửa bằng hàm riêng `contractDuringPeriod()`: lọc theo `start_date <= kỳ kết thúc` và (`end_date` null hoặc `>= kỳ bắt đầu`), bỏ hẳn điều kiện `status` khỏi câu query này |
| Phiếu lương (Payslip) — giao diện + Xuất PDF (2026-09-16) | [`PayrollPayslipDialog.vue`](resources/js/views/Payroll/PayrollPayslipDialog.vue) dựng lại theo đúng mẫu phiếu lương công ty cung cấp (bảng I. Thông tin chung / II. Phụ cấp / III. Lương thêm giờ - Thưởng / IV. Khoản trừ, rowSpan cột STT/Mục, thanh "THỰC NHẬN" tô xanh cuối bảng) — tái dùng bởi cả [`PayrollDetail.vue`](resources/js/views/Payroll/PayrollDetail.vue) (HR) lẫn [`MyProfilePayslipsTab.vue`](resources/js/views/Me/MyProfilePayslipsTab.vue) (tự phục vụ). Nút "Xuất PDF" tạo file `.pdf` THẬT tải thẳng về máy (khác nút "In / Xuất PDF" cũ chỉ gọi `window.print()` — người dùng phải tự chọn "Lưu dưới dạng PDF" ở hộp thoại in, không phải xuất trực tiếp), dùng `jspdf` + `jspdf-autotable` (thêm mới, xem `package.json`). jsPDF mặc định không có font hỗ trợ dấu tiếng Việt — nhúng riêng font Noto Sans (`public/fonts/NotoSans-Regular.ttf`, tải về từ `google/fonts` repo, ~2MB, chỉ 1 style "normal") qua `addFileToVFS()`/`addFont()`, fetch 1 lần lúc xuất (không bundle vào JS chính). **Bẫy đã tự phát hiện qua kiểm thử thật (render PDF ra ảnh so sánh)**: KHÔNG được dùng `fontStyle: "bold"/"italic"` cho bất kỳ ô nào chứa chữ tiếng Việt khi chỉ nhúng đúng 1 style "normal" — `jspdf-autotable` âm thầm rơi về font mặc định (Helvetica, không dấu) cho ô đó, làm chữ có dấu vỡ dòng/giãn cách sai; nhấn mạnh bằng `fillColor`/`textColor` (không đổi font) thay vì đổi độ đậm chữ. Backend bổ sung `position_name`/`department_name` vào `employee` lồng trong [`PayrollDetailResource.php`](app/Http/Resources/PayrollDetailResource.php) (eager-load thêm ở `PayrollRepository::find()` và `PayrollController::mine()`) để có đủ dữ liệu cho khung "I. Thông tin chung". |
| Chưa làm | `PayrollList.vue`/`PayrollDetail.vue` cho `salary_components`/`holidays` (quản lý qua CRUD); các dòng chi tiết theo từng khoản riêng (Ăn trưa/Xăng xe/Điện thoại/Đi lại, Lương khoán/Thưởng KD/Thưởng KPI, Truy thu/Tạm ứng lương) trong mẫu phiếu lương công ty — hiện gộp chung vào `total_allowance`/`overtime_amount` có sẵn, chưa làm itemized (quyết định 2026-09-16: ưu tiên lên giao diện trước, itemized để sau) |
| Danh sách tệp cần sửa khi bảo trì | Toàn bộ các file trên |

---

**Quy ước cập nhật file này**: mỗi khi thêm 1 tính năng/module mới, thêm 1 mục mới theo đúng bảng 5 cột như trên (Giao diện Frontend / Xử lý Backend / Database & API / Test / Danh sách tệp cần sửa khi bảo trì) — không cần đủ cả 5 cột nếu module đó không có, nhưng phải cập nhật ngay trong ngày làm, không để dồn. Đường dẫn file viết dạng link Markdown `[tên](đường/dẫn)` để bấm mở trực tiếp. Cột "Ghi chú" chỉ ghi bẫy/quyết định thật sự ảnh hưởng tới việc bảo trì sau này, không chép lại toàn bộ quá trình thảo luận.
