// Nhãn dùng chung cho module Onboarding / Offboarding.
export const CHECKLIST_TYPE_LABELS = {
    onboarding: "Nhận việc",
    offboarding: "Nghỉ việc",
};

// Ngày mốc để tính hạn từng việc.
export const REFERENCE_DATE_LABELS = {
    onboarding: "Ngày vào làm",
    offboarding: "Ngày làm việc cuối",
};

export const CHECKLIST_STATUS_MAP = {
    in_progress: { label: "Đang làm", color: "info" },
    completed: { label: "Hoàn thành", color: "success" },
    cancelled: { label: "Đã hủy", color: "default" },
};

export const RESPONSIBLE_LABELS = {
    hr: "Nhân sự",
    manager: "Quản lý trực tiếp",
    employee: "Nhân viên",
};

export const RESPONSIBLE_ICONS = {
    hr: "mdi-account-tie-outline",
    manager: "mdi-account-supervisor-outline",
    employee: "mdi-account-outline",
};

// Việc hệ thống tự đánh dấu xong — khớp ChecklistTemplateItem::AUTO_KEYS (backend).
export const AUTO_KEY_LABELS = {
    account_created: "Khi đã có tài khoản đăng nhập",
    contract_signed: "Khi đã tải hợp đồng đã ký",
    account_locked: "Khi tài khoản đã bị khóa",
    contract_ended: "Khi hợp đồng đã chấm dứt",
};

export const AUTO_KEYS_BY_TYPE = {
    onboarding: ["account_created", "contract_signed"],
    offboarding: ["account_locked", "contract_ended"],
};

export function formatDate(value) {
    if (!value) return "";
    const [y, m, d] = String(value).slice(0, 10).split("-");
    return `${d}/${m}/${y}`;
}
