// Trang công khai (không cần đăng nhập) — vd ứng viên mở link thư mời nhận việc trong
// email. Ở các trang này: không gọi API tài khoản, không bao giờ đẩy về /dang-nhap
// (máy ứng viên có thể còn token cũ hết hạn của người khác).
const PUBLIC_PATH_PREFIXES = ["/thu-moi-nhan-viec/"];

export function isPublicPath(pathname = window.location.pathname) {
    return PUBLIC_PATH_PREFIXES.some((prefix) => pathname.startsWith(prefix));
}
