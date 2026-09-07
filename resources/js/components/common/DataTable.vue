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
    //   tooltip  String  chữ hiện khi rê chuột; cũng là nhãn mặc định của hộp xác nhận
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
});

const emit = defineEmits([
    "update:page",
    "update:itemsPerPage",
    "action-error",
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
            align: "end",
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
    const label = action.tooltip || action.label || "thực hiện";

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
</script>

<template>
    <div>
        <component
            :is="tableComponent"
            v-model:items-per-page="perPage"
            v-model:page="internalPage"
            v-bind="modeProps"
            :headers="tableHeaders"
            :items="items"
            :loading="loading"
            density="comfortable"
            no-data-text="Không có dữ liệu phù hợp."
            class="border rounded-lg app-data-table"
        >
            <template v-for="(_, slotName) in $slots" #[slotName]="slotProps">
                <slot :name="slotName" v-bind="slotProps ?? {}" />
            </template>

            <template v-if="useBuiltInActions" #item.actions="{ item }">
                <div class="d-flex justify-end ga-2">
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
                                {{ action.tooltip }}
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
