// Cảnh báo nhanh cho 1 nhân viên — hiện ngay trên danh sách để HR thấy việc cần làm mà
// không phải mở từng hồ sơ. Tính hoàn toàn từ dữ liệu dòng (EmployeeResource), không gọi API.
const CONTRACT_WARN_DAYS = 30;
const PROBATION_WARN_DAYS = 14;
const WORKING_STATUSES = ["active", "probation", "intern"];

// Số ngày từ hôm nay tới ngày "YYYY-MM-DD" (âm = đã qua), so theo NGÀY lịch địa phương.
export function daysUntil(isoDate) {
    if (!isoDate) return null;
    const [y, m, d] = String(isoDate).slice(0, 10).split("-").map(Number);
    const target = new Date(y, m - 1, d);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    return Math.round((target - today) / 86400000);
}

/** @returns {Array<{ text: string, color: string, icon: string }>} */
export function employeeAlerts(employee) {
    if (!employee || !WORKING_STATUSES.includes(employee.employment_status)) {
        return [];
    }
    const alerts = [];
    // Đang có hợp đồng thử việc thì ngày hết hợp đồng CHÍNH LÀ ngày hết thử việc
    // (hợp đồng là căn cứ, ô trong hồ sơ chỉ dùng khi chưa có hợp đồng thử việc)
    // — chỉ hiện 1 chip thử việc, không hiện thêm chip "HĐ hết hạn" trùng ý.
    const onProbationContract = employee.contract_type === "thu_viec" && !!employee.contract_end_date;

    const contractDays = onProbationContract ? null : daysUntil(employee.contract_end_date);
    if (contractDays !== null && contractDays < 0) {
        alerts.push({ text: "HĐ đã hết hạn", color: "error", icon: "mdi-file-alert-outline" });
    } else if (contractDays !== null && contractDays <= CONTRACT_WARN_DAYS) {
        alerts.push({
            text: contractDays === 0 ? "HĐ hết hạn hôm nay" : `HĐ hết hạn sau ${contractDays} ngày`,
            color: contractDays <= 7 ? "error" : "warning",
            icon: "mdi-file-clock-outline",
        });
    }

    const probationEnd = onProbationContract ? employee.contract_end_date : employee.probation_end_date;
    const probationDays = employee.employment_status === "probation" || onProbationContract ? daysUntil(probationEnd) : null;
    // Theo hợp đồng thì giữ ngưỡng báo của hợp đồng (30 ngày) để không mất cảnh báo cũ.
    const probationWarnDays = onProbationContract ? CONTRACT_WARN_DAYS : PROBATION_WARN_DAYS;
    if (probationDays !== null && probationDays <= probationWarnDays) {
        alerts.push({
            text: probationDays < 0 ? "Đã quá hạn thử việc" : `Hết thử việc sau ${probationDays} ngày`,
            color: probationDays < 0 ? "error" : "info",
            icon: "mdi-account-clock-outline",
        });
    }

    return alerts;
}
