// Nhãn + màu dùng chung cho module Dự án & công việc (khớp hằng số ở
// app/Models/Project.php và app/Models/ProjectTask.php).

export const PROJECT_STATUS_MAP = {
    planning: { label: "Lên kế hoạch", color: "info" },
    active: { label: "Đang thực hiện", color: "primary" },
    on_hold: { label: "Tạm dừng", color: "warning" },
    completed: { label: "Hoàn thành", color: "success" },
    cancelled: { label: "Đã hủy", color: "default" },
};

export const TASK_STATUS_MAP = {
    todo: { label: "Cần làm", color: "default" },
    in_progress: { label: "Đang làm", color: "primary" },
    in_review: { label: "Chờ duyệt", color: "warning" },
    done: { label: "Hoàn thành", color: "success" },
    cancelled: { label: "Đã hủy", color: "default" },
};

// Cột bảng công việc (Kanban) — việc đã hủy không hiện thành cột riêng.
export const TASK_BOARD_COLUMNS = ["todo", "in_progress", "in_review", "done"];

export const TASK_PRIORITY_MAP = {
    low: { label: "Thấp", color: "default", icon: "mdi-chevron-down" },
    medium: { label: "Trung bình", color: "info", icon: "mdi-equal" },
    high: { label: "Cao", color: "warning", icon: "mdi-chevron-up" },
    urgent: { label: "Khẩn cấp", color: "error", icon: "mdi-chevron-double-up" },
};

// Độ khó kiểu Fibonacci — trọng số khi tính KPI (việc 8 điểm nặng gấp 8 việc 1 điểm).
export const TASK_POINT_OPTIONS = [
    { value: 1, title: "1 — Rất nhỏ (vài giờ)" },
    { value: 2, title: "2 — Nhỏ (khoảng 1 ngày)" },
    { value: 3, title: "3 — Vừa (2–3 ngày)" },
    { value: 5, title: "5 — Lớn (khoảng 1 tuần)" },
    { value: 8, title: "8 — Rất lớn (hơn 1 tuần)" },
];

export const toOptions = (map) => Object.entries(map).map(([value, { label }]) => ({ title: label, value }));

export function formatDate(value) {
    return value ? new Date(value).toLocaleDateString("vi-VN") : "";
}

export function formatDateTime(value) {
    return value
        ? new Date(value).toLocaleString("vi-VN", {
              hour: "2-digit",
              minute: "2-digit",
              day: "2-digit",
              month: "2-digit",
              year: "numeric",
          })
        : "";
}

// Số ngày còn lại tới hạn (âm = đã quá hạn), tính theo ngày lịch.
export function daysLeft(dueDate) {
    if (!dueDate) {
        return null;
    }
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const due = new Date(`${dueDate}T00:00:00`);
    return Math.round((due - today) / 86400000);
}

// Nhãn hạn chót gọn: "Quá hạn 2 ngày" / "Hôm nay" / "Còn 3 ngày".
export function dueLabel(task) {
    if (!task?.due_date || ["done", "cancelled"].includes(task.status)) {
        return null;
    }
    const days = daysLeft(task.due_date);
    if (days < 0) {
        return { text: `Quá hạn ${-days} ngày`, color: "error" };
    }
    if (days === 0) {
        return { text: "Hạn hôm nay", color: "warning" };
    }
    if (days <= 2) {
        return { text: `Còn ${days} ngày`, color: "warning" };
    }
    return null;
}
