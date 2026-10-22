// Nhãn / màu dùng chung cho trang KPI (công thức và ngưỡng xếp loại lấy từ API — KpiService).

// C = Đạt nhưng sát ngưỡng -> vàng cam (trước là xám, trông như bị vô hiệu); D = chưa đạt -> đỏ.
export const KPI_GRADE_COLORS = { A: "primary", B: "success", C: "warning", D: "error" };

export const KPI_METRICS = [
    {
        key: "completion",
        rate: "completion_rate",
        label: "Hoàn thành",
        hint: "Điểm độ khó của việc đã hoàn thành / việc có hạn trong tháng",
    },
    {
        key: "on_time",
        rate: "on_time_rate",
        label: "Đúng hạn",
        hint: "Trong các việc đã hoàn thành, phần nộp không trễ hạn",
    },
    {
        key: "quality",
        rate: "quality_rate",
        label: "Chất lượng",
        hint: "Mức chất lượng trung bình Lead chấm khi duyệt, quy ra phần trăm",
    },
];

export const formatPercent = (value) =>
    value === null || value === undefined
        ? "—"
        : `${Number(value).toLocaleString("vi-VN", { maximumFractionDigits: 1 })}%`;

export const formatScore = (value) =>
    value === null || value === undefined ? "—" : Number(value).toLocaleString("vi-VN", { maximumFractionDigits: 1 });

export const monthLabel = (year, month) => `Tháng ${month}/${year}`;
