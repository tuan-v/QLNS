// Trạng thái đơn xin nghỉ việc — dùng chung trang duyệt (Resignations.vue) và
// "Hồ sơ của tôi" (MyProfile.vue).
export const RESIGNATION_STATUS_MAP = {
    pending: { label: "Chờ duyệt", color: "warning" },
    // Báo trước đủ ngày theo luật -> chỉ thông báo cho công ty, không cần duyệt.
    notified: { label: "Đã thông báo", color: "info" },
    approved: { label: "Đã duyệt", color: "success" },
    rejected: { label: "Từ chối", color: "error" },
    cancelled: { label: "Đã rút đơn", color: "default" },
};

export const RESIGNATION_STATUS_OPTIONS = [
    { title: "Tất cả", value: null },
    ...Object.entries(RESIGNATION_STATUS_MAP).map(([value, { label }]) => ({ title: label, value })),
];
