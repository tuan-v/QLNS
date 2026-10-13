// Trang công khai (không cần đăng nhập). Ở các trang này: không gọi API tài khoản, không bao
// giờ đẩy về /dang-nhap (máy người xem có thể còn token cũ hết hạn của người khác).
// Hiện chưa có trang nào: trang trả lời thư mời nhận việc đã bỏ (2026-10-13, thư mời không
// còn link — ứng viên liên hệ HR). Thêm tiền tố vào đây khi có trang công khai mới.
const PUBLIC_PATH_PREFIXES = [];

export function isPublicPath(pathname = window.location.pathname) {
    return PUBLIC_PATH_PREFIXES.some((prefix) => pathname.startsWith(prefix));
}
