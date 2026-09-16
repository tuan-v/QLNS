<template>
    <v-dialog v-model="open" max-width="720">
        <v-card
            rounded="xl"
            elevation="12"
            class="glass-panel payslip-print-area"
        >
            <v-card-text class="pa-6">
                <div class="text-center mb-1">
                    <div class="text-h5 font-weight-bold">
                        BẢNG LƯƠNG NHÂN VIÊN
                    </div>
                    <div class="text-body-2 text-medium-emphasis">
                        Tháng {{ payroll?.period_month }} năm
                        {{ payroll?.period_year }}
                    </div>
                </div>

                <table class="payslip-table mt-4">
                    <tbody>
                        <!-- I. Thông tin chung -->
                        <tr>
                            <td class="col-roman" rowspan="5">I</td>
                            <td class="col-section" rowspan="5">
                                Thông tin chung
                            </td>
                            <td class="col-label">Họ và tên</td>
                            <td class="col-value">
                                {{ detail?.employee?.full_name ?? "—" }}
                            </td>
                        </tr>
                        <tr>
                            <td class="col-label">Vị trí/chức vụ</td>
                            <td class="col-value">
                                {{ detail?.employee?.position_name ?? "—" }}
                            </td>
                        </tr>
                        <tr>
                            <td class="col-label">Phòng ban</td>
                            <td class="col-value">
                                {{ detail?.employee?.department_name ?? "—" }}
                            </td>
                        </tr>
                        <tr>
                            <td class="col-label">Ngày công thực tế</td>
                            <td class="col-value">
                                {{ detail?.actual_work_days ?? "—" }} /
                                {{ detail?.standard_work_days ?? "—" }}
                            </td>
                        </tr>
                        <tr>
                            <td class="col-label">Lương cơ bản</td>
                            <td class="col-value font-weight-medium">
                                {{ formatMoney(detail?.base_salary) }}
                            </td>
                        </tr>

                        <!-- II. Phụ cấp -->
                        <tr>
                            <td class="col-roman">II</td>
                            <td class="col-section">Phụ cấp (P)</td>
                            <td class="col-label font-italic">Tổng phụ cấp</td>
                            <td class="col-value font-weight-medium">
                                {{ formatMoney(detail?.total_allowance) }}
                            </td>
                        </tr>

                        <!-- III. Lương làm thêm giờ / Thưởng -->
                        <tr>
                            <td class="col-roman">III</td>
                            <td class="col-section">Lương thêm giờ - Thưởng</td>
                            <td class="col-label font-italic">
                                Lương làm thêm giờ - OT
                                {{ formatMinutesAsHours(detail?.overtime_minutes) }}
                            </td>
                            <td class="col-value font-weight-medium">
                                {{ formatMoney(detail?.overtime_amount) }}
                            </td>
                        </tr>

                        <!-- IV. Khoản trừ -->
                        <tr>
                            <td class="col-roman" rowspan="5">IV</td>
                            <td class="col-section" rowspan="5">Khoản trừ</td>
                            <td class="col-label">
                                BHXH, BHYT, BHTN (10,5%)
                            </td>
                            <td class="col-value">
                                {{ formatMoney(detail?.insurance_amount) }}
                            </td>
                        </tr>
                        <tr>
                            <td class="col-label">Thuế TNCN</td>
                            <td class="col-value">
                                {{ formatMoney(detail?.personal_income_tax) }}
                            </td>
                        </tr>
                        <tr>
                            <td class="col-label">Nghỉ không lương</td>
                            <td class="col-value">
                                {{
                                    formatMoney(detail?.unpaid_leave_deduction)
                                }}
                            </td>
                        </tr>
                        <tr>
                            <td class="col-label">Khấu trừ khác</td>
                            <td class="col-value">
                                {{ formatMoney(detail?.other_deduction) }}
                            </td>
                        </tr>
                        <tr>
                            <td class="col-label font-weight-bold font-italic">
                                Tổng giảm trừ
                            </td>
                            <td class="col-value font-weight-bold">
                                {{ formatMoney(totalDeduction) }}
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Thực nhận luôn lấy THẲNG detail.net_salary do Backend đã
                     tính (bảo hiểm/thuế/nghỉ không lương/OT...) — không tự
                     cộng trừ lại I/II/III/IV ở đây để tránh lệch nếu công
                     thức Backend đổi sau này, xem PayrollService.php. -->
                <div class="payslip-total mt-0">
                    <span class="text-h6 font-weight-bold">THỰC NHẬN</span>
                    <span class="text-h6 font-weight-bold">
                        {{ formatMoney(detail?.net_salary) }}
                    </span>
                </div>
            </v-card-text>
            <v-card-actions class="px-6 pb-6 no-print">
                <v-spacer />
                <v-btn variant="text" @click="open = false">Đóng</v-btn>
                <v-btn
                    color="primary"
                    variant="flat"
                    prepend-icon="mdi-file-pdf-box"
                    :loading="exporting"
                    @click="exportPdf"
                >
                    Xuất PDF
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { computed, ref } from "vue";
import { formatMinutesAsHours } from "../../composables/useCheckIn.js";
import { useToastStore } from "../../stores/useToastStore";

const toast = useToastStore();

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    detail: { type: Object, default: null },
    payroll: { type: Object, default: null },
});
const emit = defineEmits(["update:modelValue"]);

const open = computed({
    get: () => props.modelValue,
    set: (v) => emit("update:modelValue", v),
});

function formatMoney(value) {
    if (value === null || value === undefined) return "—";
    return new Intl.NumberFormat("vi-VN").format(Number(value)) + " ₫";
}

const totalDeduction = computed(() => {
    if (!props.detail) return 0;
    return (
        Number(props.detail.insurance_amount ?? 0) +
        Number(props.detail.personal_income_tax ?? 0) +
        Number(props.detail.unpaid_leave_deduction ?? 0) +
        Number(props.detail.other_deduction ?? 0)
    );
});

/* ---------------------------------------------------------------------------
 * Xuất PDF THẬT (tải file .pdf về máy trực tiếp) — khác hẳn nút "In" cũ dùng
 * window.print() (chỉ mở hộp thoại in của trình duyệt, người dùng phải tự
 * chọn "Lưu dưới dạng PDF" mới ra file, không phải xuất PDF trực tiếp).
 * jsPDF mặc định KHÔNG có font hỗ trợ dấu tiếng Việt — phải nhúng riêng 1 font
 * Unicode đầy đủ (Noto Sans, đặt sẵn ở public/fonts/, tải 1 lần rồi cache như
 * mọi file tĩnh khác) qua addFileToVFS()/addFont(), nếu không chữ có dấu sẽ
 * ra ô vuông/ký tự rác thay vì đúng chữ.
 * ------------------------------------------------------------------------- */
const exporting = ref(false);

function arrayBufferToBase64(buffer) {
    let binary = "";
    const bytes = new Uint8Array(buffer);
    const chunkSize = 0x8000;
    for (let i = 0; i < bytes.length; i += chunkSize) {
        binary += String.fromCharCode(...bytes.subarray(i, i + chunkSize));
    }
    return window.btoa(binary);
}

async function exportPdf() {
    exporting.value = true;
    try {
        const [{ jsPDF }, autoTableModule, fontResponse] = await Promise.all([
            import("jspdf"),
            import("jspdf-autotable"),
            fetch("/fonts/NotoSans-Regular.ttf"),
        ]);
        const autoTable = autoTableModule.default;
        const fontBase64 = arrayBufferToBase64(
            await fontResponse.arrayBuffer(),
        );

        const doc = new jsPDF();
        doc.addFileToVFS("NotoSans-Regular.ttf", fontBase64);
        doc.addFont("NotoSans-Regular.ttf", "NotoSans", "normal");
        doc.setFont("NotoSans");

        doc.setFontSize(16);
        doc.text("BẢNG LƯƠNG NHÂN VIÊN", 105, 15, { align: "center" });
        doc.setFontSize(11);
        doc.text(
            `Tháng ${props.payroll?.period_month} năm ${props.payroll?.period_year}`,
            105,
            22,
            { align: "center" },
        );

        const d = props.detail;
        const money = (v) => formatMoney(v);

        // rowSpan trên ô đầu (STT/Mục) — các dòng SAU trong cùng 1 mục bỏ hẳn
        // 2 cột đầu (autotable tự hiểu là phần nối dài của ô rowSpan phía trên).
        const body = [
            [
                { content: "I", rowSpan: 5 },
                { content: "Thông tin chung", rowSpan: 5 },
                "Họ và tên",
                d?.employee?.full_name ?? "—",
            ],
            ["Vị trí/chức vụ", d?.employee?.position_name ?? "—"],
            ["Phòng ban", d?.employee?.department_name ?? "—"],
            [
                "Ngày công thực tế",
                `${d?.actual_work_days ?? "—"} / ${d?.standard_work_days ?? "—"}`,
            ],
            ["Lương cơ bản", money(d?.base_salary)],
            ["II", "Phụ cấp (P)", "Tổng phụ cấp", money(d?.total_allowance)],
            [
                "III",
                "Lương thêm giờ - Thưởng",
                `Lương làm thêm giờ - OT ${formatMinutesAsHours(d?.overtime_minutes)}`,
                money(d?.overtime_amount),
            ],
            [
                { content: "IV", rowSpan: 5 },
                { content: "Khoản trừ", rowSpan: 5 },
                "BHXH, BHYT, BHTN (10,5%)",
                money(d?.insurance_amount),
            ],
            ["Thuế TNCN", money(d?.personal_income_tax)],
            ["Nghỉ không lương", money(d?.unpaid_leave_deduction)],
            ["Khấu trừ khác", money(d?.other_deduction)],
            [
                {
                    content: "Tổng giảm trừ",
                    styles: { fillColor: [240, 240, 240] },
                },
                {
                    content: money(totalDeduction.value),
                    styles: { fillColor: [240, 240, 240] },
                },
            ],
        ];

        // KHÔNG dùng fontStyle "bold"/"italic" ở đây — chỉ nhúng đúng 1 style
        // "normal" của font NotoSans (xem addFont() phía trên), autotable sẽ
        // âm thầm rơi về font mặc định (Helvetica, không dấu tiếng Việt) cho
        // ô nào xin "bold" mà font đó chưa đăng ký, làm CHỮ CÓ DẤU bị vỡ dòng/
        // giãn cách sai (đã tự kiểm chứng bằng cách render thử ra ảnh trước
        // khi đưa vào đây, không phải suy đoán) — dùng fillColor/textColor để
        // nhấn mạnh thay vì đổi độ đậm của chữ.
        autoTable(doc, {
            startY: 28,
            body,
            theme: "grid",
            styles: { font: "NotoSans", fontSize: 10, cellPadding: 2.2 },
            columnStyles: {
                0: { cellWidth: 10, halign: "center" },
                1: { cellWidth: 34 },
                2: { cellWidth: 88 },
                3: { cellWidth: 48, halign: "right" },
            },
            didParseCell(data) {
                // Cột 0/1 (STT + Mục) tô nền nhạt để phân biệt nhóm mục,
                // không cần đổi độ đậm chữ.
                if (data.column.index <= 1) {
                    data.cell.styles.fillColor = [245, 245, 245];
                }
            },
        });

        const afterTableY = doc.lastAutoTable.finalY + 4;
        doc.setFillColor(224, 242, 224);
        doc.rect(14, afterTableY, 182, 12, "F");
        doc.setFontSize(13);
        doc.text("THỰC NHẬN", 18, afterTableY + 8);
        doc.text(money(d?.net_salary), 192, afterTableY + 8, {
            align: "right",
        });

        const code = d?.employee?.code ?? "nv";
        doc.save(
            `phieu-luong-${code}-${props.payroll?.period_month}-${props.payroll?.period_year}.pdf`,
        );
    } catch (e) {
        console.error(e);
        toast.error("Không thể xuất PDF, vui lòng thử lại.");
    } finally {
        exporting.value = false;
    }
}
</script>

<style scoped>
.payslip-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}
.payslip-table td {
    border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    padding: 8px 10px;
    vertical-align: middle;
}
.col-roman {
    width: 2.5rem;
    text-align: center;
    font-weight: 700;
}
.col-section {
    width: 9rem;
    font-weight: 700;
}
.col-label {
    width: 40%;
}
.col-value {
    text-align: right;
}
.payslip-total {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem 1rem;
    margin-top: 0;
    background: rgba(76, 175, 80, 0.16);
    border: 1px solid rgba(76, 175, 80, 0.4);
    border-top: none;
    border-radius: 0 0 0.5rem 0.5rem;
}
</style>

<!-- KHÔNG scoped: quy tắc @media print cần chọn theo class trên toàn trang
     (body, các phần tử khác ngoài dialog), style scoped tự thêm data-attribute
     riêng cho component nên không áp dụng được ra ngoài. Class
     "payslip-print-area"/"no-print" đặt tên đủ riêng để không đụng nơi khác.
     Vẫn giữ @media print phòng người dùng tự bấm Ctrl+P — không còn nút "In"
     riêng nhưng không chặn được phím tắt trình duyệt. -->
<style>
@media print {
    body * {
        visibility: hidden;
    }
    .payslip-print-area,
    .payslip-print-area * {
        visibility: visible;
    }
    .payslip-print-area {
        position: fixed;
        left: 0;
        top: 0;
        width: 100%;
    }
    .no-print {
        display: none !important;
    }
}
</style>
