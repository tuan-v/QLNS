// Thang chấm 1-5 dùng chung (2026-10-19): thay ngôi sao bằng MỨC CÓ CHỮ — người dùng thấy
// sao vàng trông như app mua sắm, lệch tông hệ thống và "3 sao" phải đoán nghĩa.
// Dữ liệu vẫn lưu điểm 1-5 như cũ (quality_score, score của phiếu phỏng vấn), chỉ đổi cách hiển thị.
// Màu: đỏ/cam = chưa đạt, xám = đạt, xanh lá = tốt, xanh dương = xuất sắc.

// Lead chấm chất lượng khi duyệt công việc dự án.
export const QUALITY_LEVELS = [
    { value: 1, label: "Chưa đạt", hint: "Kém, phải làm lại nhiều", color: "error" },
    { value: 2, label: "Dưới yêu cầu", hint: "Còn thiếu sót, cần cải thiện", color: "warning" },
    { value: 3, label: "Đạt", hint: "Đạt yêu cầu", color: "grey" },
    { value: 4, label: "Tốt", hint: "Hoàn thành tốt yêu cầu", color: "success" },
    { value: 5, label: "Xuất sắc", hint: "Vượt mong đợi", color: "primary" },
];

// Người phỏng vấn chấm từng câu hỏi của phiếu chấm điểm.
export const INTERVIEW_LEVELS = [
    { value: 1, label: "Rất kém", hint: "Không trả lời được", color: "error" },
    { value: 2, label: "Kém", hint: "Trả lời sai hoặc thiếu nhiều", color: "warning" },
    { value: 3, label: "Đạt", hint: "Trả lời được ý chính", color: "grey" },
    { value: 4, label: "Tốt", hint: "Trả lời đầy đủ, rõ ràng", color: "success" },
    { value: 5, label: "Rất tốt", hint: "Trả lời sâu, có ví dụ thực tế", color: "primary" },
];

export function levelOf(levels, value) {
    return levels.find((level) => level.value === Number(value)) ?? null;
}
