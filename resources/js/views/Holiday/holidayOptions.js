// Danh sách lựa chọn dùng chung cho form ngày nghỉ lễ — chọn từ danh sách thay vì
// gõ số tay, nên không thể nhập chữ hay giá trị ngoài khoảng hợp lệ.

const range = (from, to) => Array.from({ length: to - from + 1 }, (_, i) => from + i);

export const MONTH_OPTIONS = range(1, 12).map((m) => ({ title: `Tháng ${m}`, value: m }));

// Số ngày tối đa của tháng dương lịch (tháng 2 cho phép 29 — năm không nhuận
// thì hệ thống tự bỏ qua ngày 29/2).
const SOLAR_MAX_DAYS = [31, 29, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

export function dayOptionsFor(calendar, month) {
    const max = calendar === "lunar" ? 30 : month ? SOLAR_MAX_DAYS[month - 1] : 31;
    return range(1, max).map((d) => ({
        title: calendar === "lunar" && d <= 10 ? `Mùng ${d}` : `Ngày ${d}`,
        value: d,
    }));
}

export const OFFSET_OPTIONS = range(-15, 15).map((n) => ({
    title: n === 0 ? "Đúng ngày gốc" : n < 0 ? `Trước ngày gốc ${-n} ngày` : `Sau ngày gốc ${n} ngày`,
    value: n,
}));

export const DURATION_OPTIONS = range(1, 30).map((n) => ({ title: `${n} ngày`, value: n }));

export const NOTIFY_OPTIONS = range(0, 60).map((n) => ({
    title: n === 0 ? "Báo ngay ngày bắt đầu nghỉ" : `Trước ${n} ngày`,
    value: n,
}));

// Năm âm lịch cho form thêm ngày nghỉ: năm trước tới 5 năm sau.
export function lunarYearOptions(baseYear) {
    return range(baseYear - 1, baseYear + 5).map((y) => ({ title: `Năm ${y}`, value: y }));
}

export const REMIND_OPTIONS = [7, 14, 21, 30, 45, 60, 90, 120].map((n) => ({
    title: `Trước ${n} ngày`,
    value: n,
}));
