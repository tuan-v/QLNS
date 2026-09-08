<template>
    <!-- @after-leave: dọn dẹp SAU KHI hiệu ứng đóng chạy xong. Dọn ngay lúc
         `open` vừa thành false thì người dùng nhìn thấy bảng/trang trắng trơn
         trong lúc dialog mờ dần — trông như tài liệu bị mất. -->
    <v-dialog
        v-model="open"
        max-width="1100"
        scrollable
        @after-leave="resetState"
    >
        <v-card rounded="xl" class="glass-panel preview-card">
            <!-- Đầu dialog: icon theo loại tệp + tên + đuôi + nút đóng -->
            <v-card-title class="d-flex align-center ga-3 py-3 pr-3">
                <v-avatar
                    :color="fileMeta.color"
                    variant="tonal"
                    rounded="lg"
                    size="40"
                >
                    <v-icon :icon="fileMeta.icon" size="22" />
                </v-avatar>
                <div class="flex-grow-1 min-width-0">
                    <div class="text-subtitle-1 font-weight-bold text-truncate">
                        {{ fileName }}
                    </div>
                    <div class="text-caption text-medium-emphasis">
                        {{ fileMeta.label }}
                    </div>
                </div>
                <v-chip
                    v-if="extension"
                    size="x-small"
                    label
                    variant="tonal"
                    :color="fileMeta.color"
                    class="font-weight-bold text-uppercase"
                >
                    {{ extension }}
                </v-chip>
                <v-btn
                    icon="mdi-close"
                    variant="text"
                    size="small"
                    aria-label="Đóng"
                    @click="open = false"
                />
            </v-card-title>

            <!-- Thanh công cụ PDF: lật trang + phóng to/thu nhỏ.
                 Trước đây PDF nhúng bằng <iframe> nên phải mượn trình xem của
                 trình duyệt — mỗi trình duyệt một kiểu và lạc hẳn với giao diện
                 kính của app (mục 6 tài liệu yêu cầu: Premium UX/UI). Nay tự vẽ
                 bằng pdf.js nên thanh công cụ này là của app. -->
            <template v-if="previewType === 'pdf' && pdfDoc">
                <v-divider />
                <div
                    class="d-flex align-center justify-space-between flex-wrap ga-2 px-4 py-2 preview-toolbar"
                >
                    <div class="d-flex align-center ga-1">
                        <v-btn
                            icon="mdi-chevron-left"
                            variant="text"
                            size="small"
                            :disabled="currentPage <= 1"
                            aria-label="Trang trước"
                            @click="goToPage(currentPage - 1)"
                        />
                        <span class="text-caption font-weight-bold px-1">
                            Trang {{ currentPage }} / {{ totalPages }}
                        </span>
                        <v-btn
                            icon="mdi-chevron-right"
                            variant="text"
                            size="small"
                            :disabled="currentPage >= totalPages"
                            aria-label="Trang sau"
                            @click="goToPage(currentPage + 1)"
                        />
                    </div>

                    <div class="d-flex align-center ga-1">
                        <v-btn
                            icon="mdi-magnify-minus-outline"
                            variant="text"
                            size="small"
                            :disabled="scale <= MIN_SCALE"
                            aria-label="Thu nhỏ"
                            @click="changeScale(-SCALE_STEP)"
                        />
                        <span
                            class="text-caption font-weight-bold px-1 preview-zoom-value"
                        >
                            {{ Math.round(scale * 100) }}%
                        </span>
                        <v-btn
                            icon="mdi-magnify-plus-outline"
                            variant="text"
                            size="small"
                            :disabled="scale >= MAX_SCALE"
                            aria-label="Phóng to"
                            @click="changeScale(SCALE_STEP)"
                        />
                        <v-btn
                            icon="mdi-fit-to-page-outline"
                            variant="text"
                            size="small"
                            aria-label="Vừa khung"
                            @click="fitToWidth"
                        />
                    </div>
                </div>
            </template>

            <!-- Tab chọn sheet: workbook nhiều sheet thì trước đây chỉ xem được
                 sheet đầu tiên, các sheet sau coi như mất. -->
            <template v-if="previewType === 'xlsx' && sheetNames.length > 1">
                <v-divider />
                <div class="px-3 py-2 preview-toolbar">
                    <v-chip-group
                        v-model="selectedSheet"
                        selected-class="text-primary"
                        mandatory
                        column
                    >
                        <v-chip
                            v-for="name in sheetNames"
                            :key="name"
                            :value="name"
                            size="small"
                            label
                            variant="tonal"
                            prepend-icon="mdi-table"
                        >
                            {{ name }}
                        </v-chip>
                    </v-chip-group>
                </div>
            </template>

            <v-divider />

            <v-card-text class="preview-body">
                <!-- Đang tải tệp về máy người dùng -->
                <div
                    v-if="loading"
                    class="d-flex flex-column align-center justify-center ga-3 py-12"
                >
                    <v-progress-circular indeterminate color="primary" />
                    <span class="text-caption text-medium-emphasis">
                        Đang tải tài liệu...
                    </span>
                </div>

                <!-- Tải lỗi (mất mạng, 401 hết phiên, file hỏng...) — báo rõ
                     thay vì để Promise văng lỗi ra console: tiêu chí nghiệm thu
                     của dự án yêu cầu "không xuất hiện lỗi Javascript ở console". -->
                <v-alert
                    v-else-if="errorMessage"
                    type="warning"
                    variant="tonal"
                    density="comfortable"
                    class="ma-2"
                >
                    {{ errorMessage }}
                </v-alert>

                <template v-else>
                    <!-- PDF: canvas do pdf.js vẽ -->
                    <div
                        v-if="previewType === 'pdf'"
                        class="preview-stage d-flex justify-center"
                    >
                        <canvas ref="pdfCanvas" class="preview-page" />
                    </div>

                    <!-- Ảnh -->
                    <div
                        v-else-if="previewType === 'image'"
                        class="preview-stage d-flex align-center justify-center"
                    >
                        <img :src="blobUrl" :alt="fileName" class="preview-image" />
                    </div>

                    <!-- Word: docx-preview tự vẽ DOM vào div này, không dùng v-html.
                         Thẻ chứa nằm TRONG nhánh này nên chỉ tồn tại khi đã tải
                         xong — vì vậy phải render xong nhánh rồi mới gọi
                         renderAsync (xem nextTick trong loadPreview). -->
                    <div v-else-if="previewType === 'docx'" class="preview-stage">
                        <div
                            v-if="rendering"
                            class="d-flex justify-center py-10"
                        >
                            <v-progress-circular indeterminate color="primary" />
                        </div>
                        <div ref="docxContainer" class="preview-docx"></div>
                    </div>

                    <!-- Excel: tự dựng bảng từ dữ liệu, KHÔNG dùng v-html -->
                    <div
                        v-else-if="previewType === 'xlsx'"
                        class="preview-sheet-wrapper"
                    >
                        <table class="preview-sheet">
                            <thead>
                                <tr>
                                    <th class="preview-sheet-index">#</th>
                                    <th
                                        v-for="(header, index) in sheet.headers"
                                        :key="index"
                                    >
                                        {{ header }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(row, rowIndex) in sheet.rows"
                                    :key="rowIndex"
                                >
                                    <td class="preview-sheet-index">
                                        {{ rowIndex + 1 }}
                                    </td>
                                    <td
                                        v-for="(cell, cellIndex) in row"
                                        :key="cellIndex"
                                        :class="{
                                            'preview-sheet-number':
                                                isNumeric(cell),
                                        }"
                                    >
                                        {{ formatCell(cell) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <div
                            v-if="sheet.rows.length === 0"
                            class="text-caption text-medium-emphasis pa-4"
                        >
                            Sheet này không có dữ liệu.
                        </div>
                    </div>

                    <div v-else class="text-medium-emphasis pa-4">
                        {{ unsupportedMessage }}
                    </div>
                </template>
            </v-card-text>
        </v-card>
    </v-dialog>
</template>

<script setup>
import {
    ref,
    shallowRef,
    reactive,
    computed,
    watch,
    nextTick,
    onBeforeUnmount,
} from "vue";
// Chỉ import sẵn ĐƯỜNG DẪN worker (một chuỗi vài chục byte). Bản thân pdf.js,
// docx-preview và SheetJS đều nạp động ở dưới — mục 2 tài liệu yêu cầu Lazy
// Loading để "mở đến màn hình nào mới tải code màn hình đó"; nhét cả 3 thư viện
// vào chunk EmployeeDetail thì ai mở hồ sơ nhân viên cũng phải tải, kể cả khi
// không bao giờ bấm xem tài liệu.
import pdfWorkerUrl from "pdfjs-dist/build/pdf.worker.min.mjs?url";

const props = defineProps({
    modelValue: Boolean,
    fileUrl: String, // download_url có sẵn từ API
    fileName: String, // để suy ra đuôi file
});
const emit = defineEmits(["update:modelValue"]);

const open = computed({
    get: () => props.modelValue,
    set: (v) => emit("update:modelValue", v),
});

const MIN_SCALE = 0.5;
const MAX_SCALE = 3;
const SCALE_STEP = 0.2;

const extension = computed(
    () => (props.fileName ?? "").split(".").pop()?.toLowerCase() ?? "",
);

// Content-Type theo đuôi file, chỉ dùng làm phương án dự phòng khi phản hồi
// không kèm content-type (xem ghi chú ở loadPreview).
const MIME_BY_EXTENSION = {
    jpg: "image/jpeg",
    jpeg: "image/jpeg",
    png: "image/png",
};

const previewType = computed(() => {
    const ext = extension.value;
    if (ext === "pdf") return "pdf";
    if (["jpg", "jpeg", "png"].includes(ext)) return "image";
    if (ext === "docx") return "docx";
    if (ext === "xlsx") return "xlsx";
    return null;
});

const fileMeta = computed(() => {
    switch (previewType.value) {
        case "pdf":
            return { icon: "mdi-file-pdf-box", color: "error", label: "Tài liệu PDF" };
        case "docx":
            return { icon: "mdi-file-word-box", color: "primary", label: "Tài liệu Word" };
        case "xlsx":
            return { icon: "mdi-file-excel-box", color: "success", label: "Bảng tính Excel" };
        case "image":
            return { icon: "mdi-file-image-box", color: "purple", label: "Hình ảnh" };
        default:
            return { icon: "mdi-file-outline", color: "grey", label: "Tệp đính kèm" };
    }
});

// Đuôi file cũ dạng nhị phân: thư viện xem trước không đọc được, nói thẳng lý do
// thay vì báo chung chung "không xem trước được".
const LEGACY_OFFICE_EXTENSIONS = {
    doc: "Word 97-2003",
    xls: "Excel 97-2003",
};

const unsupportedMessage = computed(() => {
    const legacy = LEGACY_OFFICE_EXTENSIONS[extension.value];
    if (legacy) {
        return `Không xem trước được định dạng .${extension.value} (${legacy}) — hãy lưu lại thành .${extension.value}x rồi tải lên, hoặc tải tệp xuống để mở bằng Office.`;
    }

    return `Chưa hỗ trợ xem trước định dạng .${extension.value} — vui lòng tải tệp xuống để xem.`;
});

const blobUrl = ref(null);
const docxContainer = ref(null); // khớp với ref="docxContainer" trong template
const loading = ref(false);
const rendering = ref(false);
const errorMessage = ref("");

// ------------------------------------------------------------------- PDF
const pdfCanvas = ref(null);
// shallowRef chứ KHÔNG phải ref: ref() bọc đối tượng trong Proxy phản ứng sâu,
// mà PDFDocumentProxy của pdf.js dùng private field (#) — gọi method qua Proxy
// sẽ ném TypeError vì "this" lúc đó là Proxy chứ không phải đối tượng gốc.
const pdfDoc = shallowRef(null);
const currentPage = ref(1);
const totalPages = ref(0);
const scale = ref(1.2);
let renderTask = null; // để hủy lượt vẽ đang chạy dở
// Giữ lại "loading task" chứ không chỉ giữ document: ở pdf.js v6, hàm giải
// phóng tài nguyên là destroy() CỦA LOADING TASK — PDFDocumentProxy chỉ có
// cleanup(). Gọi nhầm pdfDoc.destroy() sẽ ném "destroy is not a function" và
// làm hỏng lần mở dialog kế tiếp.
let pdfLoadingTask = null;

// ----------------------------------------------------------------- Excel
let workbook = null;
const sheetNames = ref([]);
const selectedSheet = ref(null);
const sheet = reactive({ headers: [], rows: [] });

async function loadPreview() {
    // Mở tài liệu khác khi state cũ chưa kịp dọn (đóng rồi mở lại thật nhanh)
    // thì nội dung cũ sẽ loé lên một nhịp — dọn lại cho chắc.
    resetState();

    if (!previewType.value) {
        return; // để template hiện unsupportedMessage, không gọi API vô ích
    }

    loading.value = true;

    try {
        // Ảnh: tải dạng "blob" rồi biến thành URL tạm để nhúng thẳng vào <img>
        // — giống hệt cách downloadContract() đã làm ở EmployeeDetail.vue.
        if (previewType.value === "image") {
            const response = await window.axios.get(props.fileUrl, {
                responseType: "blob",
            });
            // Dùng thẳng Blob của axios, KHÔNG bọc lại bằng new Blob([...]):
            // bọc lại làm mất content-type.
            const blob = response.data.type
                ? response.data
                : new Blob([response.data], {
                      type: MIME_BY_EXTENSION[extension.value] ?? "",
                  });
            blobUrl.value = window.URL.createObjectURL(blob);
            return;
        }

        // PDF / Word / Excel đều cần dữ liệu thô (ArrayBuffer), không phải Blob.
        const response = await window.axios.get(props.fileUrl, {
            responseType: "arraybuffer",
        });

        if (previewType.value === "pdf") {
            await loadPdf(response.data);
            return;
        }

        if (previewType.value === "docx") {
            const { renderAsync } = await import("docx-preview");
            // Tắt cờ loading TRƯỚC rồi mới đợi nextTick: thẻ <div ref="docxContainer">
            // nằm trong nhánh chỉ hiển thị khi loading = false, nếu render lúc
            // còn loading thì ref vẫn là null.
            loading.value = false;
            rendering.value = true;
            await nextTick();
            if (!docxContainer.value) {
                errorMessage.value = "Không mở được bản xem trước tài liệu Word.";
                return;
            }
            docxContainer.value.innerHTML = ""; // xóa nội dung file cũ nếu mở lại dialog cho file khác
            await renderAsync(response.data, docxContainer.value);
            return;
        }

        if (previewType.value === "xlsx") {
            await loadWorkbook(response.data);
        }
    } catch (error) {
        errorMessage.value =
            error?.response?.status === 401
                ? "Phiên đăng nhập đã hết hạn — vui lòng đăng nhập lại rồi thử mở tài liệu."
                : "Không mở được bản xem trước — tệp có thể đã hỏng hoặc không tải về được.";
    } finally {
        loading.value = false;
        rendering.value = false;
    }
}

// --------------------------------------------------------------- PDF logic

async function loadPdf(data) {
    const pdfjs = await import("pdfjs-dist");
    // pdf.js giải mã PDF trong Web Worker; không trỏ workerSrc thì nó chạy dồn
    // vào luồng chính và treo giao diện với file nhiều trang.
    pdfjs.GlobalWorkerOptions.workerSrc = pdfWorkerUrl;

    // pdf.js GIỮ và tiêu thụ chính ArrayBuffer được truyền vào (detach), nên
    // đưa bản sao để dữ liệu gốc không bị vô hiệu nếu cần dùng lại.
    pdfLoadingTask = pdfjs.getDocument({ data: data.slice(0) });
    const document = await pdfLoadingTask.promise;

    pdfDoc.value = document;
    totalPages.value = document.numPages;
    currentPage.value = 1;

    loading.value = false;
    await nextTick(); // đợi canvas xuất hiện rồi mới vẽ
    await renderPdfPage();
}

async function renderPdfPage() {
    if (!pdfDoc.value || !pdfCanvas.value) return;

    // Hủy lượt vẽ trước: bấm lật trang/zoom nhanh mà không hủy thì pdf.js ném
    // "Cannot use the same canvas during multiple render() operations" ra console.
    if (renderTask) {
        renderTask.cancel();
        renderTask = null;
    }

    try {
        const page = await pdfDoc.value.getPage(currentPage.value);
        const viewport = page.getViewport({ scale: scale.value });
        const canvas = pdfCanvas.value;
        const context = canvas.getContext("2d");

        // Vẽ theo devicePixelRatio rồi thu lại bằng CSS: trên màn hình retina /
        // Windows scale 125-150%, nếu vẽ đúng kích thước CSS thì chữ trong PDF
        // bị mờ như ảnh phóng to.
        const outputScale = window.devicePixelRatio || 1;
        canvas.width = Math.floor(viewport.width * outputScale);
        canvas.height = Math.floor(viewport.height * outputScale);
        canvas.style.width = `${Math.floor(viewport.width)}px`;
        canvas.style.height = `${Math.floor(viewport.height)}px`;

        renderTask = page.render({
            canvasContext: context,
            canvas,
            viewport,
            transform:
                outputScale !== 1
                    ? [outputScale, 0, 0, outputScale, 0, 0]
                    : undefined,
        });
        await renderTask.promise;
        renderTask = null;
    } catch (error) {
        // Lượt vẽ bị hủy là chuyện bình thường khi người dùng bấm nhanh — không
        // phải lỗi, không báo ra giao diện.
        if (error?.name !== "RenderingCancelledException") {
            errorMessage.value = "Không hiển thị được trang PDF này.";
        }
        renderTask = null;
    }
}

function goToPage(page) {
    if (page < 1 || page > totalPages.value) return;
    currentPage.value = page;
    renderPdfPage();
}

function changeScale(delta) {
    const next = Math.min(MAX_SCALE, Math.max(MIN_SCALE, scale.value + delta));
    if (next === scale.value) return;
    scale.value = Number(next.toFixed(2));
    renderPdfPage();
}

// Canh trang PDF vừa bề ngang khung xem — file khổ A4 dọc mở ở scale mặc định
// thường tràn ngang trên laptop, phải kéo ngang mới đọc được.
async function fitToWidth() {
    if (!pdfDoc.value || !pdfCanvas.value) return;
    const available = pdfCanvas.value.parentElement?.clientWidth ?? 0;
    if (!available) return;

    const page = await pdfDoc.value.getPage(currentPage.value);
    const baseWidth = page.getViewport({ scale: 1 }).width;
    const next = Math.min(
        MAX_SCALE,
        Math.max(MIN_SCALE, (available - 32) / baseWidth),
    );
    scale.value = Number(next.toFixed(2));
    renderPdfPage();
}

// ------------------------------------------------------------- Excel logic

async function loadWorkbook(data) {
    const XLSX = await import("xlsx");
    // cellDates: trả về đối tượng Date thay vì số serial của Excel (45000...),
    // để formatCell() in ra ngày người đọc hiểu được.
    workbook = XLSX.read(data, { type: "array", cellDates: true });
    sheetNames.value = workbook.SheetNames;
    selectedSheet.value = workbook.SheetNames[0] ?? null;
    parseSheet(XLSX, selectedSheet.value);
}

function parseSheet(XLSX, name) {
    sheet.headers = [];
    sheet.rows = [];
    if (!workbook || !name) return;

    const worksheet = workbook.Sheets[name];
    if (!worksheet) return;

    const raw = XLSX.utils.sheet_to_json(worksheet, {
        header: 1,
        blankrows: false,
        defval: "",
    });
    if (raw.length === 0) return;

    // Dòng tiêu đề không phải lúc nào cũng là dòng 1 (file thật hay có tên công
    // ty / tiêu đề bảng ở trên) — chọn dòng có nhiều ô không rỗng nhất làm tiêu
    // đề, giống cách trình xem của dự án MBT đang làm.
    let headerIndex = 0;
    let maxFilled = 0;
    raw.forEach((row, index) => {
        const filled = row.filter(
            (cell) => cell !== "" && cell !== null && cell !== undefined,
        ).length;
        if (filled > maxFilled) {
            maxFilled = filled;
            headerIndex = index;
        }
    });

    const headerRow = raw[headerIndex] ?? [];
    const bodyRows = raw.slice(headerIndex + 1);
    const columnCount = Math.max(
        headerRow.length,
        ...bodyRows.map((row) => row.length),
        1,
    );

    sheet.headers = Array.from({ length: columnCount }, (_, index) => {
        const value = headerRow[index];
        return value === "" || value === null || value === undefined
            ? `Cột ${index + 1}`
            : String(value);
    });

    // Đệm cho mọi dòng đủ số cột, nếu không các ô cuối sẽ lệch khỏi tiêu đề.
    sheet.rows = bodyRows.map((row) =>
        Array.from({ length: columnCount }, (_, index) => row[index] ?? ""),
    );
}

const numberFormatter = new Intl.NumberFormat("vi-VN");

function isNumeric(value) {
    return typeof value === "number" && Number.isFinite(value);
}

function formatCell(value) {
    if (value === null || value === undefined) return "";
    if (value instanceof Date) return value.toLocaleDateString("vi-VN");
    // Chỉ chấm phân cách từ 1000 trở lên: năm (2026) hay số thứ tự mà cũng
    // chấm thành "2.026" thì phản tác dụng.
    if (isNumeric(value)) {
        return Math.abs(value) >= 1000
            ? numberFormatter.format(value)
            : String(value);
    }
    return String(value);
}

watch(selectedSheet, async (name) => {
    if (!workbook || !name) return;
    const XLSX = await import("xlsx");
    parseSheet(XLSX, name);
});

// ------------------------------------------------------------------ Vòng đời

function resetState() {
    if (renderTask) {
        renderTask.cancel();
        renderTask = null;
    }
    if (pdfLoadingTask) {
        // destroy() trả về Promise; nuốt lỗi để không sinh "unhandled rejection"
        // khi đóng dialog lúc tệp còn đang tải dở.
        pdfLoadingTask.destroy().catch(() => {});
        pdfLoadingTask = null;
    }
    pdfDoc.value = null;
    if (blobUrl.value) {
        window.URL.revokeObjectURL(blobUrl.value);
        blobUrl.value = null;
    }
    if (docxContainer.value) {
        docxContainer.value.innerHTML = "";
    }
    workbook = null;
    sheetNames.value = [];
    selectedSheet.value = null;
    sheet.headers = [];
    sheet.rows = [];
    currentPage.value = 1;
    totalPages.value = 0;
    scale.value = 1.2;
    loading.value = false;
    rendering.value = false;
    errorMessage.value = "";
}

watch(open, (isOpen) => {
    if (isOpen) {
        loadPreview();
    }
});

onBeforeUnmount(resetState);
</script>

<style scoped>
.preview-card {
    /* Khóa chiều cao để vùng nội dung tự cuộn, thay vì dialog dài vô tận. */
    max-height: 90vh;
}

.min-width-0 {
    min-width: 0;
}

.preview-toolbar {
    background: rgba(var(--v-theme-surface), 0.6);
}

.preview-zoom-value {
    min-width: 44px;
    text-align: center;
}

.preview-body {
    padding: 0;
    /* Nền chìm hơn thân dialog để trang giấy / bảng nổi lên phía trước. */
    background: rgba(var(--v-theme-on-surface), 0.04);
}

.preview-stage {
    padding: 16px;
    min-height: 200px;
}

.preview-page,
.preview-image {
    max-width: 100%;
    border-radius: 10px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.18);
}

/* pdf.js vẽ nền trong suốt, không tự tô trắng — thiếu dòng này thì trong lúc
   canvas chưa vẽ xong, nền tối của dialog lộ qua và trang giấy nhìn xám xịt. */
.preview-page {
    background: #fff;
}

.preview-image {
    max-height: 70vh;
    object-fit: contain;
}

/* docx-preview tự sinh nền trắng khổ giấy; chỉ cần canh giữa và bỏ khoảng
   trắng thừa của wrapper để trang không lệch trái trong dialog. */
.preview-docx :deep(.docx-wrapper) {
    background: transparent;
    padding: 0;
}

.preview-docx :deep(.docx-wrapper > section.docx) {
    margin: 0 auto 16px;
    max-width: 100%;
    border-radius: 10px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.18);
}

.preview-sheet-wrapper {
    /* Vùng cuộn của bảng phải là chính nó thì header/cột STT dính mới bám đúng. */
    overflow: auto;
    max-height: 70vh;
    padding: 12px;
}

.preview-sheet {
    border-collapse: separate;
    border-spacing: 0;
    width: max-content;
    min-width: 100%;
    background: rgb(var(--v-theme-surface));
    border-radius: 10px;
    overflow: hidden;
    font-size: 0.8125rem;
}

.preview-sheet th,
.preview-sheet td {
    border-right: 1px solid rgba(var(--v-border-color), 0.16);
    border-bottom: 1px solid rgba(var(--v-border-color), 0.16);
    padding: 6px 12px;
    white-space: nowrap;
}

.preview-sheet th {
    position: sticky;
    top: 0;
    z-index: 2;
    background: rgb(var(--v-theme-surface-light, var(--v-theme-surface)));
    font-weight: 700;
    text-align: left;
}

/* Cột số thứ tự dính khi cuộn ngang — bảng lương/danh sách nhân sự thường rất
   nhiều cột, cuộn sang phải mà mất số dòng thì không biết đang đọc dòng nào. */
.preview-sheet-index {
    position: sticky;
    left: 0;
    z-index: 1;
    width: 48px;
    text-align: center !important;
    color: rgba(var(--v-theme-on-surface), 0.55);
    background: rgb(var(--v-theme-surface-light, var(--v-theme-surface)));
}

.preview-sheet th.preview-sheet-index {
    z-index: 3; /* ô góc trên-trái phải nằm trên cả hàng tiêu đề lẫn cột STT */
}

.preview-sheet tbody tr:hover td {
    background: rgba(var(--v-theme-primary), 0.06);
}

.preview-sheet-number {
    text-align: right;
    font-variant-numeric: tabular-nums;
}
</style>
