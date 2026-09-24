# Môi trường phát triển local QLNS

## Thành phần

| Dịch vụ | Phiên bản cấu hình | Cổng mặc định |
|---|---:|---:|
| Nginx | 1.28 Alpine | `8080` |
| PHP-FPM | 8.3 Bookworm | Nội bộ `9000` |
| MySQL | 8.4 | `3306` |
| Redis | 7.4 Alpine | `6379` |
| Reverb (WebSocket) | laravel/reverb ^1.12 | `6001` |

PHP image có Composer và các extension cần thiết cho Laravel: `bcmath`, `exif`, `gd`, `intl`, `opcache`, `pcntl`, `pdo_mysql`, `redis` và `zip`.

## Khởi tạo lần đầu

Yêu cầu Docker Desktop đang chạy và Docker Compose khả dụng.

```text
docker compose build app
docker compose run --rm app composer install
docker compose run --rm app php artisan key:generate
docker compose up -d
docker compose exec app php artisan migrate
```

Ứng dụng chạy tại `http://localhost:8080`. Có thể đổi cổng bằng `APP_PORT` trong `.env`.

Nếu máy đã có MySQL/Redis chạy sẵn ở host (ví dụ Laragon, XAMPP) chiếm cổng
`3306`/`6379`, đổi `FORWARD_DB_PORT`/`FORWARD_REDIS_PORT` trong `.env` sang
cổng khác (ví dụ `3307`) — không ảnh hưởng tới kết nối nội bộ giữa các
container vì Laravel trong container `app` luôn dùng hostname `mysql`/`redis`
theo mạng Docker, chỉ có công cụ ở host (DBeaver, TablePlus...) mới cần cổng
forward này.

## Lệnh thường dùng

```text
docker compose ps
docker compose logs -f app nginx
docker compose exec app php artisan test
docker compose exec app composer lint:test
docker compose exec app php artisan migrate:fresh --seed
docker compose down
```

Không dùng `docker compose down -v` nếu muốn giữ dữ liệu MySQL và Redis local.

## Kết nối từ Laravel

Trong mạng Compose, Laravel sử dụng hostname theo tên service:

- MySQL: `mysql:3306`
- Redis: `redis:6379`
- Reverb (từ container `app`/`queue` khi backend chủ động broadcast sự kiện): `reverb:6001`

Các giá trị mẫu đã được cấu hình trong `.env.example`. Mật khẩu mặc định chỉ dành cho local; môi trường staging/production phải dùng secret riêng.

### Realtime (Laravel Reverb)

Container `reverb` chạy `php artisan reverb:start --host=0.0.0.0 --port=6001`, khởi động cùng `docker compose up -d`. Trình duyệt (Echo/Pusher-js ở frontend) kết nối trực tiếp tới `localhost:6001` (cấu hình qua `VITE_REVERB_*` trong `.env`) — khác với backend, vốn dùng hostname `reverb` theo mạng Docker (`REVERB_HOST`). Nếu đổi `FORWARD_REVERB_PORT` để tránh xung đột cổng, phải đổi `VITE_REVERB_PORT` tương ứng rồi `npm run build` lại. Xem `routes/channels.php` (định nghĩa kênh) và `resources/js/echo.js` (kết nối phía frontend, tự đính JWT Bearer token qua axios interceptor sẵn có thay vì cơ chế auth mặc định của Echo).

## Tiêu chí hoàn thành Ngày 01

- `docker compose config` hợp lệ.
- Bốn service `app`, `nginx`, `mysql`, `redis` khởi động được.
- MySQL và Redis đạt trạng thái healthy.
- Trang `/up` trả về HTTP 200 qua Nginx.
- Laravel nhận kết nối MySQL và Redis trong mạng Docker.
- Quy chuẩn PSR-12 và quy tắc đặt tên đã được ghi nhận.
