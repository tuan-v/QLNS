// Nhãn + màu trạng thái 1 ca chấm công — DÙNG CHUNG cho "Tổng hợp chấm công"
// (AttendanceOverview.vue, HR), "Lịch sử chấm công" (AttendanceHistoryPanel.vue)
// và "Bảng công gần nhất" (Dashboard.vue) — 2026-09-29, theo yêu cầu người
// dùng đồng bộ 2 màn. Backend trả đúng các khóa này từ
// AttendanceService::displayStatusFor(): chưa duyệt -> pending_approval, bị
// từ chối -> rejected, đã duyệt -> in_progress/full/late/insufficient, không
// có lượt chấm vào -> absent/on_leave. Thêm/đổi nhãn CHỈ sửa ở file này.
export const ATTENDANCE_STATUS_MAP = {
    pending_approval: { label: "Chờ duyệt", color: "warning" },
    rejected: { label: "Từ chối", color: "error" },
    in_progress: { label: "Đang trong ca", color: "primary" },
    full: { label: "Đủ công", color: "success" },
    late: { label: "Đi muộn", color: "amber-darken-2" },
    insufficient: { label: "Thiếu công", color: "error" },
    absent: { label: "Vắng", color: "default" },
    on_leave: { label: "Nghỉ phép", color: "purple" },
};

// Lựa chọn cho ô lọc "Trạng thái" — cùng thứ tự với bảng trên, thêm "Tất cả".
export const ATTENDANCE_STATUS_OPTIONS = [
    { title: "Tất cả", value: null },
    ...Object.entries(ATTENDANCE_STATUS_MAP).map(([value, { label }]) => ({ title: label, value })),
];
