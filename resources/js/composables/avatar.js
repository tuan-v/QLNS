// Avatar chữ cái đầu cho người chưa có ảnh — dùng chung Employees.vue và
// chuông thông báo (NotificationCenter.vue). Màu tô theo tên nên cùng 1
// người luôn ra cùng 1 màu, không đổi ngẫu nhiên mỗi lần render.
const AVATAR_COLORS = [
    "primary",
    "success",
    "info",
    "warning",
    "purple",
    "teal",
    "indigo",
    "deep-orange",
];

export function initials(fullName) {
    const parts = String(fullName ?? "")
        .trim()
        .split(/\s+/)
        .filter(Boolean);
    if (!parts.length) {
        return "?";
    }
    const first = parts[0][0];
    const last = parts[parts.length - 1][0];
    return (parts.length > 1 ? first + last : first).toUpperCase();
}

export function avatarColor(fullName) {
    const text = String(fullName ?? "");
    let hash = 0;
    for (let i = 0; i < text.length; i += 1) {
        hash = (hash * 31 + text.charCodeAt(i)) >>> 0;
    }
    return AVATAR_COLORS[hash % AVATAR_COLORS.length];
}
