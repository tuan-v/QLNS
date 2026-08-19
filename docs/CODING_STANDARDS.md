# Quy chuẩn mã nguồn dự án QLNS

Tài liệu này cụ thể hóa yêu cầu PSR-12 và quy tắc đặt tên trong tài liệu nghiệp vụ. Khi có khác biệt, phải đối chiếu lại hai tài liệu nguồn được liệt kê trong `AGENTS.md`.

## Backend Laravel

- PHP tuân thủ PSR-12; kiểm tra bằng `composer lint:test` và định dạng bằng `composer lint`.
- Controller API đặt tại `app/Http/Controllers/Api/V1/` và chỉ điều phối request/response.
- Nghiệp vụ đặt tại `app/Services/`; truy vấn dữ liệu đặt tại `app/Repositories/`.
- Validation đặt tại `app/Http/Requests/`; model đặt tại `app/Models/`.
- Model, Controller, Service, Repository và Request dùng `PascalCase`.
- Controller kết thúc bằng `Controller`; Service kết thúc bằng `Service`; Repository kết thúc bằng `Repository`.
- Bảng và cột MySQL dùng `snake_case`; tên bảng dùng dạng số nhiều.
- Endpoint API có tiền tố `/api/v1` và tài nguyên dùng `kebab-case` dạng số nhiều.
- Migration phải khai báo kiểu dữ liệu, foreign key, index và hành vi xóa rõ ràng. Chỉ dùng soft delete khi dữ liệu cần phục hồi hoặc lưu vết theo nghiệp vụ.
- Logic ghi nhiều bảng phải được đặt trong transaction tại tầng Service.
- Không đặt logic nghiệp vụ dài trong Controller hoặc Model.

## Frontend Vue 3

- Màn hình đặt tại `resources/js/views/`.
- Component tái sử dụng đặt tại `resources/js/components/` và dùng tên file `PascalCase.vue`.
- Pinia store đặt tại `resources/js/stores/`, dùng `camelCase` với mẫu `use...Store`.
- Lớp gọi Axios đặt tại `resources/js/services/` và dùng hậu tố `Service.js`.
- Route đặt tại `resources/js/router/`; route nghiệp vụ dùng `kebab-case`.
- Mỗi form phải hiển thị lỗi validation HTTP 422 tại đúng trường nhập liệu.

## Nguyên tắc chung

- Ưu tiên DRY, KISS và tên thể hiện đúng ý nghĩa nghiệp vụ.
- Mỗi thay đổi chức năng phải có kiểm thử phù hợp.
- Không ghi mật khẩu, token, khóa API hoặc dữ liệu cá nhân thật vào Git.
- Commit theo từng phần việc nhỏ, có thông điệp mô tả kết quả.
- Cập nhật `CODE_MAP.md` ngay khi thêm hoặc di chuyển một chức năng; tệp này sẽ được hình thành từ các module bắt đầu ở những tuần sau.
