<script setup>
import { computed, ref, useSlots, watch } from "vue";
import { VDataTable, VDataTableServer } from "vuetify/components";

const props = defineProps({
    headers: {
        type: Array,
        required: true,
    },
    items: {
        type: Array,
        default: () => [],
    },
    loading: {
        type: Boolean,
        default: false,
    },
    search: {
        type: String,
        default: "",
    },
    page: {
        type: Number,
        default: 1,
    },
    itemsPerPage: {
        type: Number,
        default: 10,
    },
    serverItemsLength: {
        type: Number,
        default: null,
    },

    // Danh sách thao tác trên từng dòng. View chỉ khai báo mảng này, bảng tự
    // dựng cột "Thao tác", tự ẩn nút theo quyền và tự lo hộp xác nhận — module
    // mới không phải viết lại markup nút bấm hay dialog xóa nữa.
    //
    // Mỗi phần tử:
    //   icon     String  bắt buộc — tên icon MDI, vd "mdi-pencil-outline"
    //   tooltip  String | (item) => String  chữ hiện khi rê chuột; cũng là nhãn mặc định của hộp xác nhận —
    //            nhận Function khi Ý NGHĨA nút đổi theo trạng thái từng dòng (vd "Duyệt" -> "Đổi sang Duyệt")
    //   label    String  chữ hiện cạnh icon (bỏ trống -> nút chỉ có icon)
    //   color    String  màu Vuetify, mặc định "primary"
    //   hidden   Boolean | (item) => Boolean   ẩn hẳn nút ở dòng đó
    //   disabled Boolean | (item) => Boolean   vẫn hiện nhưng khóa
    //   confirm  Boolean | Object  bật hộp xác nhận trước khi chạy onClick:
    //       title       String | (item) => String
    //       message     String | (item) => String
    //       confirmText String
    //       warning     String | (item) => String|null   hiện cảnh báo vàng trong hộp thoại
    //       disabled    Boolean | (item) => Boolean      khóa nút xác nhận (chặn thao tác)
    //       input       Object|null  ô nhập lý do: { label, required, rows }
    //   onClick  (item, { input }) => any   hỗ trợ async, nút tự hiện loading
    actions: {
        type: Array,
        default: () => [],
    },
    actionsLabel: {
        type: String,
        default: "Thao tác",
    },
    actionsWidth: {
        type: [Number, String],
        default: 120,
    },

    // Chọn nhiều dòng + thanh thao tác hàng loạt (2026-09-25, theo yêu cầu
    // người dùng, kèm ảnh tham khảo) — TẮT mặc định, không ảnh hưởng các
    // trang đang dùng DataTable sẵn có. Bật bằng `selectable`, khai
    // `bulkActions` (CÙNG hình dạng với `actions` ở trên, khác 2 điểm:
    // `onClick(selectedItems, { input })` nhận cả MẢNG item đã chọn thay vì 1
    // item; `disabled`/`confirm.message`... nếu là hàm thì nhận
    // (selectedItems) thay vì (item) — không có "1 item" nào để truyền vào.
    // `disabled: (selectedItems) => Boolean` dùng để khóa nút khi KHÔNG có
    // dòng nào trong số đã chọn thật sự hợp lệ cho ĐÚNG thao tác này (2026-
    // 09-25, theo phản hồi người dùng — vd đã chọn cả dòng đã duyệt rồi thì
    // nút "Duyệt tất cả" phải khóa lại, dù nút "Từ chối tất cả" vẫn dùng được
    // bình thường vì dòng đó vẫn từ chối được).
    selectable: {
        type: Boolean,
        default: false,
    },
    // String (tên field) HOẶC Function (item) => giá trị định danh — cùng
    // kiểu Vuetify's `item-value`. Trang nào không có field `id` phẳng (vd
    // AttendanceOverview.vue — mỗi dòng là {employee, work_shift, attendance,
    // status}, không phải 1 model) PHẢI tự truyền hàm riêng.
    itemValue: {
        type: [String, Function],
        default: "id",
    },
    // String|Function (item) => Boolean — dòng nào trả về false thì ẩn hẳn
    // checkbox (vd dòng "Vắng" chưa có bản ghi chấm công, không có gì để
    // duyệt hàng loạt). Không truyền thì MỌI dòng đều chọn được.
    itemSelectable: {
        type: [String, Function],
        default: null,
    },
    bulkActions: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits([
    "update:page",
    "update:itemsPerPage",
    "action-error",
    "bulk-action-error",
]);

const slots = useSlots();

const perPageOptions = [10, 25, 50, 100];
// Ở chế độ server-side, "Số dòng" phải báo ra ngoài cho component cha gửi lên
// API (giống hệt "page") — bảng không tự cắt lại items, tin hoàn toàn vào những
// gì server trả về, nên chọn "Số dòng" mà không gửi lên thì hoàn toàn vô tác dụng.
const perPage = ref(props.itemsPerPage);
watch(
    () => props.itemsPerPage,
    (value) => {
        perPage.value = value;
    },
);
watch(perPage, (value) => {
    emit("update:itemsPerPage", value);
});

// Vuetify 3 KHÔNG có hàm setPage() trong slot #bottom (chỉ Vuetify 4 mới có) —
// nên bảng tự quản lý trang qua 1 ref nội bộ, đồng bộ 2 chiều với prop "page"
// bằng v-model (đúng API v-model:page mà VDataTable/VDataTableServer đều hỗ trợ
// ở cả 2 phiên bản), thay vì phụ thuộc vào hàm tiện ích lấy từ slot.
const internalPage = ref(props.page);
watch(
    () => props.page,
    (value) => {
        internalPage.value = value;
    },
);
watch(internalPage, (value) => {
    emit("update:page", value);
});

// Một bảng duy nhất cho cả 2 chế độ: truyền serverItemsLength thì dùng
// VDataTableServer (trang do API quyết định), không truyền thì VDataTable
// (lọc/sắp xếp/phân trang ngay trên client). Gộp lại để mọi tính năng thêm sau
// này chỉ phải viết một lần thay vì nhân đôi cho hai nhánh.
const isServerMode = computed(() => props.serverItemsLength !== null);
const tableComponent = computed(() =>
    isServerMode.value ? VDataTableServer : VDataTable,
);
const modeProps = computed(() =>
    isServerMode.value
        ? { itemsLength: props.serverItemsLength }
        : { search: props.search },
);
const totalCount = computed(() =>
    isServerMode.value ? props.serverItemsLength : props.items.length,
);

/* ----------------------------- Thao tác dòng ----------------------------- */

// Cho phép khai báo vừa dạng giá trị tĩnh vừa dạng hàm theo từng dòng.
const resolve = (value, item) =>
    typeof value === "function" ? value(item) : value;

const isHidden = (action, item) => Boolean(resolve(action.hidden, item));
const isDisabled = (action, item) => Boolean(resolve(action.disabled, item));

// Slot #item.actions do view truyền vào luôn được ưu tiên, để những màn hình
// viết tay từ trước vẫn chạy y nguyên khi component này nâng cấp.
const useBuiltInActions = computed(
    () => props.actions.length > 0 && !slots["item.actions"],
);

const hasVisibleActions = computed(() => {
    if (!useBuiltInActions.value) {
        return false;
    }

    if (!props.items.length) {
        // Bảng rỗng thì chưa có item để hỏi hidden(item) — giữ cột nếu còn ít
        // nhất một thao tác không bị tắt cứng.
        return props.actions.some(
            (action) => typeof action.hidden === "function" || !action.hidden,
        );
    }

    return props.items.some((item) =>
        props.actions.some((action) => !isHidden(action, item)),
    );
});

// View không cần khai báo cột "Thao tác" trong headers nữa. Nếu view vẫn tự
// khai báo (muốn đặt cột ở vị trí khác) thì tôn trọng, không chèn thêm.
const tableHeaders = computed(() => {
    if (!props.actions.length || slots["item.actions"]) {
        return props.headers;
    }

    if (!hasVisibleActions.value) {
        return props.headers.filter((header) => header.key !== "actions");
    }

    if (props.headers.some((header) => header.key === "actions")) {
        return props.headers;
    }

    return [
        ...props.headers,
        {
            title: props.actionsLabel,
            key: "actions",
            sortable: false,
            align: "center",
            width: props.actionsWidth,
        },
    ];
});

// So sánh theo tham chiếu: mỗi lần render, action và item đều là cùng object
// nên không cần bịa ra khóa định danh (item có thể không có id).
const running = ref(null);
const isRunning = (action, item) =>
    running.value?.action === action && running.value?.item === item;

const pending = ref(null);
const inputValue = ref("");
const submitting = ref(false);
const actionError = ref("");

const confirmOpen = computed({
    get: () => pending.value !== null,
    set: (value) => {
        if (!value) {
            pending.value = null;
        }
    },
});

const inputInvalid = computed(() => {
    const input = pending.value?.config.input;
    return Boolean(input?.required) && !inputValue.value.trim();
});

function buildConfirm(action, item) {
    if (!action.confirm) {
        return null;
    }

    const config = typeof action.confirm === "object" ? action.confirm : {};
    // tooltip cũng nhận Function (item) => String (2026-09-25 — cần thiết khi
    // 1 nút đổi Ý NGHĨA theo trạng thái từng dòng, vd "Duyệt" biến thành "Đổi
    // sang Duyệt" khi dòng đó đang bị Từ chối — xem AttendanceOverview.vue).
    const label = resolve(action.tooltip, item) || action.label || "thực hiện";

    return {
        title: resolve(config.title, item) ?? `Xác nhận ${label.toLowerCase()}`,
        message:
            resolve(config.message, item) ??
            `Bạn có chắc muốn ${label.toLowerCase()} mục này không?`,
        confirmText: config.confirmText ?? label,
        color: config.color ?? action.color ?? "primary",
        warning: resolve(config.warning, item) ?? null,
        disabled: Boolean(resolve(config.disabled, item)),
        input: config.input ?? null,
    };
}

async function execute(action, item, extra = {}) {
    running.value = { action, item };

    try {
        await action.onClick?.(item, extra);
        return true;
    } catch (error) {
        // Hiển thị ngay trong hộp thoại thay vì để người dùng đoán: v-alert lỗi
        // của trang nằm phía sau lớp phủ dialog nên không nhìn thấy được.
        actionError.value =
            error?.response?.data?.message ??
            error?.message ??
            "Không thực hiện được thao tác.";
        emit("action-error", { action, item, error });
        return false;
    } finally {
        running.value = null;
    }
}

function onActionClick(action, item) {
    const config = buildConfirm(action, item);

    if (!config) {
        void execute(action, item);
        return;
    }

    inputValue.value = "";
    actionError.value = "";
    pending.value = { action, item, config };
}

async function submitConfirm() {
    if (!pending.value || pending.value.config.disabled || inputInvalid.value) {
        return;
    }

    actionError.value = "";
    submitting.value = true;
    const succeeded = await execute(pending.value.action, pending.value.item, {
        input: inputValue.value.trim(),
    });
    submitting.value = false;

    // Thất bại thì giữ hộp thoại mở kèm thông báo lỗi để người dùng thử lại.
    if (succeeded) {
        pending.value = null;
    }
}

/* ------------------------- Chọn nhiều + Hàng loạt ------------------------ */

const selected = ref([]);
const selectedCount = computed(() => selected.value.length);

function resolveItemValue(item) {
    return typeof props.itemValue === "function"
        ? props.itemValue(item)
        : item[props.itemValue];
}

// Vuetify's v-model chỉ trả về MẢNG GIÁ TRỊ đã chọn (theo item-value), không
// trả thẳng item — tự tra ngược lại item đầy đủ để bulkActions.onClick nhận
// được dữ liệu thật (employee, attendance...), không phải chỉ 1 con số/chuỗi.
const selectedItems = computed(() =>
    props.items.filter((item) => selected.value.includes(resolveItemValue(item))),
);

function isBulkActionDisabled(action) {
    return typeof action.disabled === "function"
        ? Boolean(action.disabled(selectedItems.value))
        : Boolean(action.disabled);
}

function clearSelection() {
    selected.value = [];
}

// Xóa lựa chọn khi đổi trang/đổi bộ lọc (items thay đổi hẳn) — tránh giữ lại
// selection "ma" trỏ tới dòng không còn hiển thị, dễ gây hiểu lầm đã chọn
// nhầm dòng khác đang trùng giá trị item-value ở trang mới.
watch(
    () => props.items,
    () => clearSelection(),
);

const bulkRunning = ref(null);
const bulkPending = ref(null);
const bulkInputValue = ref("");
const bulkSubmitting = ref(false);
const bulkActionError = ref("");

const bulkConfirmOpen = computed({
    get: () => bulkPending.value !== null,
    set: (value) => {
        if (!value) {
            bulkPending.value = null;
        }
    },
});

const bulkInputInvalid = computed(() => {
    const input = bulkPending.value?.config.input;
    return Boolean(input?.required) && !bulkInputValue.value.trim();
});

function buildBulkConfirm(action) {
    if (!action.confirm) {
        return null;
    }

    const config = typeof action.confirm === "object" ? action.confirm : {};
    const label = action.tooltip || action.label || "thực hiện";

    return {
        title: config.title ?? `Xác nhận ${label.toLowerCase()}`,
        message: config.message ?? `Áp dụng "${label}" cho ${selectedCount.value} mục đã chọn?`,
        confirmText: config.confirmText ?? label,
        color: config.color ?? action.color ?? "primary",
        warning: config.warning ?? null,
        input: config.input ?? null,
    };
}

async function executeBulk(action, extra = {}) {
    bulkRunning.value = action;

    try {
        await action.onClick?.(selectedItems.value, extra);
        clearSelection();
        return true;
    } catch (error) {
        bulkActionError.value =
            error?.response?.data?.message ??
            error?.message ??
            "Không thực hiện được thao tác.";
        emit("bulk-action-error", { action, error });
        return false;
    } finally {
        bulkRunning.value = null;
    }
}

function onBulkActionClick(action) {
    if (isBulkActionDisabled(action)) {
        return;
    }

    const config = buildBulkConfirm(action);

    if (!config) {
        void executeBulk(action);
        return;
    }

    bulkInputValue.value = "";
    bulkActionError.value = "";
    bulkPending.value = { action, config };
}

async function submitBulkConfirm() {
    if (!bulkPending.value || bulkInputInvalid.value) {
        return;
    }

    bulkActionError.value = "";
    bulkSubmitting.value = true;
    const succeeded = await executeBulk(bulkPending.value.action, {
        input: bulkInputValue.value.trim(),
    });
    bulkSubmitting.value = false;

    if (succeeded) {
        bulkPending.value = null;
    }
}
</script>

<template>
    <div>
        <!-- Thanh thao tác hàng loạt — chỉ hiện khi đã chọn ít nhất 1 dòng
             (2026-09-25, theo yêu cầu người dùng, kèm ảnh tham khảo). -->
        <v-sheet
            v-if="selectable && selectedCount > 0"
            class="d-flex align-center flex-wrap ga-3 pa-3 mb-3 rounded-lg border glass-panel"
        >
            <span class="text-body-2 font-weight-medium">
                Đã chọn {{ selectedCount }} mục
            </span>
            <v-btn
                v-for="(action, index) in bulkActions"
                :key="index"
                :color="action.color ?? 'primary'"
                :loading="bulkRunning === action"
                :disabled="bulkRunning !== null || isBulkActionDisabled(action)"
                variant="tonal"
                size="small"
                rounded="lg"
                @click="onBulkActionClick(action)"
            >
                <v-icon :icon="action.icon" size="18" class="mr-1" />
                {{ action.label ?? action.tooltip }}
            </v-btn>
            <v-spacer />
            <v-btn variant="text" size="small" @click="clearSelection">
                Bỏ chọn
            </v-btn>
        </v-sheet>

        <component
            :is="tableComponent"
            v-model="selected"
            v-model:items-per-page="perPage"
            v-model:page="internalPage"
            v-bind="modeProps"
            :headers="tableHeaders"
            :items="items"
            :loading="loading"
            :show-select="selectable"
            :item-value="itemValue"
            :item-selectable="itemSelectable ?? undefined"
            density="comfortable"
            no-data-text="Không có dữ liệu phù hợp."
            class="border rounded-lg app-data-table"
        >
            <template v-for="(_, slotName) in $slots" #[slotName]="slotProps">
                <slot :name="slotName" v-bind="slotProps ?? {}" />
            </template>

            <template v-if="useBuiltInActions" #item.actions="{ item }">
                <div class="d-flex justify-center ga-2">
                    <template v-for="(action, index) in actions" :key="index">
                        <v-btn
                            v-if="!isHidden(action, item)"
                            :color="action.color ?? 'primary'"
                            :disabled="isDisabled(action, item)"
                            :loading="isRunning(action, item)"
                            variant="tonal"
                            size="small"
                            rounded="lg"
                            @click="onActionClick(action, item)"
                        >
                            <v-icon :icon="action.icon" />
                            <span v-if="action.label" class="ml-1">
                                {{ action.label }}
                            </span>
                            <v-tooltip
                                v-if="action.tooltip"
                                activator="parent"
                                location="top"
                            >
                                {{ resolve(action.tooltip, item) }}
                            </v-tooltip>
                        </v-btn>
                    </template>
                </div>
            </template>

            <!-- Footer gọn: chọn số dòng + tổng số kết quả, thay bộ phân trang mặc định -->
            <template #bottom="{ pageCount }">
                <v-divider />
                <div class="d-flex flex-wrap align-center ga-4 px-4 py-3">
                    <div class="d-flex align-center ga-2">
                        <span class="text-body-2" style="opacity: 0.7">
                            Số dòng:
                        </span>
                        <v-select
                            v-model="perPage"
                            :items="perPageOptions"
                            density="compact"
                            hide-details
                            style="width: 96px"
                        />
                    </div>

                    <div class="text-body-2" style="opacity: 0.7">
                        Tổng <strong>{{ totalCount }}</strong> kết quả
                    </div>

                    <v-spacer />

                    <v-pagination
                        v-if="pageCount > 1"
                        v-model="internalPage"
                        :length="pageCount"
                        density="comfortable"
                        :total-visible="5"
                    />
                </div>
            </template>
        </component>

        <v-dialog v-model="confirmOpen" max-width="480" persistent>
            <v-card
                v-if="pending"
                rounded="xl"
                elevation="12"
                class="glass-panel"
            >
                <v-card-title class="text-h6 font-weight-bold pt-5 px-5">
                    {{ pending.config.title }}
                </v-card-title>

                <v-card-text class="px-5">
                    {{ pending.config.message }}

                    <v-alert
                        v-if="pending.config.warning"
                        type="warning"
                        variant="tonal"
                        density="compact"
                        class="mt-3"
                        icon="mdi-alert-outline"
                    >
                        {{ pending.config.warning }}
                    </v-alert>

                    <v-textarea
                        v-if="pending.config.input"
                        v-model="inputValue"
                        :label="pending.config.input.label ?? 'Lý do'"
                        :rows="pending.config.input.rows ?? 3"
                        class="mt-4"
                        auto-grow
                        hide-details
                    />

                    <v-alert
                        v-if="actionError"
                        type="error"
                        variant="tonal"
                        density="compact"
                        class="mt-3"
                        icon="mdi-alert-circle-outline"
                    >
                        {{ actionError }}
                    </v-alert>
                </v-card-text>

                <v-card-actions class="px-5 pb-5">
                    <v-spacer />
                    <v-btn
                        variant="text"
                        :disabled="submitting"
                        @click="confirmOpen = false"
                    >
                        Hủy
                    </v-btn>
                    <v-btn
                        :color="pending.config.color"
                        variant="flat"
                        :loading="submitting"
                        :disabled="pending.config.disabled || inputInvalid"
                        @click="submitConfirm"
                    >
                        {{ pending.config.confirmText }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Hộp xác nhận RIÊNG cho thao tác hàng loạt — tách khỏi dialog trên
             (thao tác từng dòng) vì message/input không gắn với 1 item cụ thể
             mà gắn với CẢ danh sách đã chọn. -->
        <v-dialog v-model="bulkConfirmOpen" max-width="480" persistent>
            <v-card v-if="bulkPending" rounded="xl" elevation="12" class="glass-panel">
                <v-card-title class="text-h6 font-weight-bold pt-5 px-5">
                    {{ bulkPending.config.title }}
                </v-card-title>

                <v-card-text class="px-5">
                    {{ bulkPending.config.message }}

                    <v-alert
                        v-if="bulkPending.config.warning"
                        type="warning"
                        variant="tonal"
                        density="compact"
                        class="mt-3"
                        icon="mdi-alert-outline"
                    >
                        {{ bulkPending.config.warning }}
                    </v-alert>

                    <v-textarea
                        v-if="bulkPending.config.input"
                        v-model="bulkInputValue"
                        :label="bulkPending.config.input.label ?? 'Lý do'"
                        :rows="bulkPending.config.input.rows ?? 3"
                        class="mt-4"
                        auto-grow
                        hide-details
                    />

                    <v-alert
                        v-if="bulkActionError"
                        type="error"
                        variant="tonal"
                        density="compact"
                        class="mt-3"
                        icon="mdi-alert-circle-outline"
                    >
                        {{ bulkActionError }}
                    </v-alert>
                </v-card-text>

                <v-card-actions class="px-5 pb-5">
                    <v-spacer />
                    <v-btn
                        variant="text"
                        :disabled="bulkSubmitting"
                        @click="bulkConfirmOpen = false"
                    >
                        Hủy
                    </v-btn>
                    <v-btn
                        :color="bulkPending.config.color"
                        variant="flat"
                        :loading="bulkSubmitting"
                        :disabled="bulkInputInvalid"
                        @click="submitBulkConfirm"
                    >
                        {{ bulkPending.config.confirmText }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<style scoped>
.app-data-table :deep(thead th) {
    background: rgba(var(--v-theme-on-surface), 0.03);
    font-size: 0.72rem !important;
    font-weight: 700 !important;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    opacity: 0.7;
}
</style>
