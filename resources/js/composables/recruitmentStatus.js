// Nhãn/màu trạng thái tuyển dụng — mirror RecruitmentOpening::STATUS_*, giai đoạn ứng viên
// (RecruitmentCandidate::STAGES) và RecruitmentOffer::STATUS_* ở backend.
export const OPENING_STATUS_MAP = {
    open: { label: "Đang nhận CV", color: "success" },
    full: { label: "Đủ CV", color: "info" },
    closed: { label: "Đã đóng", color: "default" },
};

// GIAI ĐOẠN ứng viên — 1 trạng thái thống nhất hiển thị cho người dùng (backend tính sẵn
// `candidate.stage = { key, label, reason }` từ mã trạng thái + offer gần nhất, xem
// RecruitmentCandidate::stageFor()). Mã trạng thái gốc (`candidate.status`) chỉ dùng cho logic.
export const CANDIDATE_STAGE_MAP = {
    screening: { label: "Chờ duyệt CV", color: "warning" },
    awaiting_schedule: { label: "Chờ hẹn phỏng vấn", color: "primary" },
    invited: { label: "Đã hẹn – chờ xác nhận", color: "warning" },
    awaiting_interview: { label: "Chờ phỏng vấn", color: "info" },
    interviewing: { label: "Đang phỏng vấn", color: "indigo" },
    passed: { label: "Đạt phỏng vấn", color: "success" },
    offering: { label: "Đang đề nghị (offer)", color: "info" },
    awaiting_onboard: { label: "Chờ nhận việc", color: "teal" },
    hired: { label: "Đã tuyển", color: "teal" },
    not_passed: { label: "Không đạt", color: "error" },
    withdrawn: { label: "Đã rút lui", color: "default" },
};

// Phản hồi của ứng viên với lịch phỏng vấn (HR ghi nhận) — mirror RecruitmentInterview::CONFIRMATION_*.
export const INTERVIEW_CONFIRMATION_MAP = {
    pending: { label: "Chờ xác nhận", color: "warning" },
    confirmed: { label: "Đồng ý", color: "success" },
    reschedule_requested: { label: "Xin đổi lịch", color: "info" },
    withdrawn: { label: "Không tham gia", color: "error" },
};

// Thư mời nhận việc — mirror RecruitmentOffer::STATUS_*.
// Tên nói rõ AI làm gì: Admin duyệt nội bộ (Chờ duyệt / Không được duyệt) tách khỏi phản hồi
// của ứng viên (Ứng viên chấp nhận / từ chối).
export const OFFER_STATUS_MAP = {
    pending_approval: { label: "Chờ duyệt", color: "warning" },
    rejected: { label: "Không được duyệt", color: "error" },
    sent: { label: "Đã gửi – chờ phản hồi", color: "info" },
    accepted: { label: "Ứng viên chấp nhận", color: "success" },
    declined: { label: "Ứng viên từ chối", color: "error" },
    expired: { label: "Hết hạn", color: "default" },
    withdrawn: { label: "Đã hủy", color: "default" },
};

// Nguồn ứng viên (kênh nhận CV) — khớp RecruitmentCandidate::SOURCES ở backend.
export const CANDIDATE_SOURCE_LABELS = {
    topcv: "TopCV",
    vietnamworks: "VietnamWorks",
    itviec: "ITviec",
    linkedin: "LinkedIn",
    facebook: "Facebook / mạng xã hội",
    website: "Website công ty",
    referral: "Người giới thiệu",
    job_fair: "Ngày hội việc làm / trường học",
    other: "Khác",
};
export const CANDIDATE_SOURCE_OPTIONS = Object.entries(CANDIDATE_SOURCE_LABELS).map(([value, title]) => ({ title, value }));

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
