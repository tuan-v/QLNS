// Nhãn/màu trạng thái tuyển dụng — mirror RecruitmentOpening::STATUS_* và
// RecruitmentCandidate::STATUS_* ở backend.
export const OPENING_STATUS_MAP = {
    open: { label: "Đang nhận CV", color: "success" },
    full: { label: "Đủ CV", color: "info" },
    closed: { label: "Đã đóng", color: "default" },
};

export const CANDIDATE_STATUS_MAP = {
    pending: { label: "Chờ duyệt", color: "warning" },
    approved: { label: "Đã duyệt", color: "primary" },
    rejected: { label: "Từ chối", color: "error" },
    interview_scheduled: { label: "Đã hẹn PV", color: "indigo" },
    passed: { label: "Đạt", color: "success" },
    failed: { label: "Không đạt", color: "default" },
    hired: { label: "Đã nhận việc", color: "teal" },
};

export const CONTRACT_TYPE_LABELS = {
    thuc_tap: "Thực tập",
    thu_viec: "Thử việc",
    chinh_thuc: "Chính thức",
};
