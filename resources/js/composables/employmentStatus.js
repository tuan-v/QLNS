// Trạng thái NHÂN VIÊN (employees.employment_status) — dùng chung mọi màn
// (danh sách/chi tiết nhân viên, hồ sơ của tôi, form). 2026-09-29, theo yêu
// cầu người dùng: đổi tên "Trạng thái làm việc" -> "Trạng thái nhân viên" và
// 'active' "Đang làm việc" -> "Chính thức" (dễ hiểu nhầm với "đang đi làm hôm
// nay"). Trạng thái không còn chọn tay: đi theo loại hợp đồng đang hiệu lực
// (EmployeeContractService::applyEmploymentStatus()) và đơn nghỉ việc.
export const EMPLOYMENT_STATUS_MAP = {
    probation: { label: "Thử việc", color: "warning" },
    active: { label: "Chính thức", color: "success" },
    intern: { label: "Thực tập", color: "info" },
    resigned: { label: "Đã nghỉ việc", color: "default" },
    terminated: { label: "Đã chấm dứt HĐ", color: "error" },
};

// Loại hợp đồng -> trạng thái nhân viên tương ứng (khớp
// EmployeeContractService::EMPLOYMENT_STATUS_BY_CONTRACT_TYPE ở Backend).
export const CONTRACT_TYPE_OPTIONS = [
    { title: "Thử việc", value: "thu_viec" },
    { title: "Chính thức", value: "chinh_thuc" },
    { title: "Thực tập", value: "thuc_tap" },
];
