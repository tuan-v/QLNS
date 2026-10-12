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
    failed: { label: "Không đạt", color: "error" },
    hired: { label: "Đã nhận việc", color: "teal" },
    offer_declined: { label: "Từ chối offer", color: "default" },
    interview_declined: { label: "Từ chối phỏng vấn", color: "default" },
};

// Phản hồi của ứng viên với lịch phỏng vấn (HR ghi nhận) — mirror RecruitmentInterview::CONFIRMATION_*.
export const INTERVIEW_CONFIRMATION_MAP = {
    pending: { label: "Chờ xác nhận", color: "warning" },
    confirmed: { label: "Đồng ý", color: "success" },
    reschedule_requested: { label: "Xin đổi lịch", color: "info" },
    withdrawn: { label: "Không tham gia", color: "error" },
};

// Thư mời nhận việc — mirror RecruitmentOffer::STATUS_*.
export const OFFER_STATUS_MAP = {
    pending_approval: { label: "Offer chờ duyệt", color: "warning" },
    rejected: { label: "Offer không duyệt", color: "error" },
    sent: { label: "Đã gửi offer", color: "info" },
    accepted: { label: "Đã nhận offer", color: "success" },
    declined: { label: "Từ chối offer", color: "error" },
    expired: { label: "Offer hết hạn", color: "default" },
    withdrawn: { label: "Đã rút offer", color: "default" },
};

// Loại hợp đồng THẬT (offer, nhận việc). Đợt tuyển còn có thêm "tất cả" — xem dưới.
export const CONTRACT_TYPE_LABELS = {
    thuc_tap: "Thực tập",
    thu_viec: "Thử việc",
    chinh_thuc: "Chính thức",
};

// Đợt tuyển không cố định loại hợp đồng — chọn loại cụ thể khi soạn offer cho từng
// ứng viên (khớp RecruitmentOpening::CONTRACT_TYPE_ANY).
export const ANY_CONTRACT_TYPE = "tat_ca";

export const OPENING_CONTRACT_TYPE_LABELS = {
    [ANY_CONTRACT_TYPE]: "Tất cả loại hợp đồng",
    ...CONTRACT_TYPE_LABELS,
};
