<template>
    <div>
        <PageHeader
            title="Hồ sơ của tôi"
            subtitle="Thông tin cá nhân, hợp đồng, tài liệu và ca làm việc của bạn."
        />

        <v-alert
            v-if="loadError"
            type="error"
            variant="tonal"
            density="compact"
            class="mb-4"
            icon="mdi-alert-circle-outline"
        >
            {{ loadError }}
        </v-alert>

        <template v-if="employee">
            <!-- Thẻ hồ sơ đầu trang -->
            <v-sheet
                class="border rounded-lg pa-5 mb-4 glass-panel profile-hero"
                color="transparent"
            >
                <div class="d-flex align-center flex-wrap ga-4">
                    <!-- Bấm vào ảnh để tự đổi ảnh đại diện (2026-09-29). Nút máy
                         ảnh nhỏ luôn hiện (màn hình cảm ứng không có hover). -->
                    <div
                        class="avatar-upload"
                        role="button"
                        tabindex="0"
                        aria-label="Đổi ảnh đại diện"
                        @click="pickAvatar"
                        @keydown.enter="pickAvatar"
                    >
                        <v-avatar size="80" color="surface-variant">
                            <v-img
                                v-if="employee.avatar_url"
                                :src="employee.avatar_url"
                                cover
                            />
                            <v-icon v-else icon="mdi-account" size="40" />
                        </v-avatar>
                        <div
                            class="avatar-upload__overlay"
                            :class="{ 'avatar-upload__overlay--busy': uploadingAvatar }"
                        >
                            <v-progress-circular
                                v-if="uploadingAvatar"
                                indeterminate
                                size="24"
                                width="2"
                                color="white"
                            />
                            <v-icon v-else color="white" size="24">mdi-camera-outline</v-icon>
                        </div>
                        <span class="avatar-upload__badge bg-primary">
                            <v-icon size="14">mdi-camera</v-icon>
                        </span>
                        <v-tooltip activator="parent" location="bottom">
                            Đổi ảnh đại diện (JPG, PNG, WEBP — tối đa 2MB)
                        </v-tooltip>
                    </div>
                    <input
                        ref="avatarInput"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        hidden
                        @change="onAvatarSelected"
                    />
                    <div class="flex-grow-1">
                        <div class="text-h6 font-weight-bold">
                            {{ employee.full_name }}
                        </div>
                        <div class="text-body-2" style="opacity: 0.75">
                            {{ employee.position?.name ?? "Chưa xếp chức vụ" }}
                            <template v-if="employee.department">
                                — {{ employee.department.name }}
                            </template>
                        </div>
                        <div class="d-flex align-center ga-2 mt-2 flex-wrap">
                            <StatusChip
                                :status="employee.employment_status"
                                :map="EMPLOYMENT_STATUS_MAP"
                            />
                            <v-chip
                                size="small"
                                variant="tonal"
                                color="default"
                            >
                                {{ employee.code }}
                            </v-chip>
                        </div>
                    </div>
                    <!-- Đơn xin nghỉ việc (2026-09-29) — HR/quản lý trực tiếp duyệt ở
                         trang "Đơn nghỉ việc", duyệt xong qua ngày làm việc cuối thì
                         trạng thái tự chuyển "Đã nghỉ việc". -->
                    <v-btn
                        v-if="canSubmitResignation"
                        variant="outlined"
                        prepend-icon="mdi-account-arrow-right-outline"
                        @click="openResignationDialog"
                    >
                        Nộp đơn nghỉ việc
                    </v-btn>
                </div>
            </v-sheet>

            <v-alert
                v-if="resignationBanner"
                :type="resignationBanner.type"
                variant="tonal"
                class="mb-4"
                :icon="resignationBanner.icon"
            >
                <div class="d-flex align-center flex-wrap ga-3">
                    <div class="flex-grow-1">{{ resignationBanner.text }}</div>
                    <v-btn
                        v-if="['pending', 'notified'].includes(latestResignation?.status)"
                        size="small"
                        variant="outlined"
                        :loading="cancellingResignation"
                        @click="cancelResignation"
                    >
                        Rút đơn
                    </v-btn>
                </div>
            </v-alert>

            <v-dialog v-model="resignationDialog" max-width="560">
                <v-card rounded="xl">
                    <v-card-title class="text-h6 font-weight-bold pt-5 px-6">Nộp đơn xin nghỉ việc</v-card-title>
                    <v-form ref="resignationFormRef" validate-on="blur invalid-input lazy" @submit.prevent="submitResignation" class="v-card-text px-6">
                        <p class="text-body-2 text-medium-emphasis mb-2">
                            Đơn được gửi tới HR và quản lý trực tiếp của bạn.
                        </p>
                        <v-alert v-if="resignationPolicy" type="info" variant="tonal" density="compact" class="mb-4">
                            {{ resignationPolicy.basis }}
                            <template v-if="resignationPolicy.required_days > 0">
                                Nếu ngày làm việc cuối từ
                                <strong>{{ formatDateVi(resignationPolicy.earliest_last_working_date) }}</strong>
                                trở đi thì đơn chỉ là <strong>thông báo</strong> (không cần duyệt); sớm hơn thì phải chờ
                                HR/quản lý đồng ý.
                            </template>
                        </v-alert>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Ngày làm việc cuối cùng <span class="text-error">*</span>
                        </div>
                        <InputDate
                            v-model="resignationForm.last_working_date"
                            :min="todayIso()"
                            :rules="[notEmpty('Ngày làm việc cuối cùng')]"
                        :error-messages="resignationErrors.last_working_date"
                        />
                        <v-alert
                            v-if="resignationOutcome"
                            :type="resignationOutcome.enough ? 'success' : 'warning'"
                            variant="tonal"
                            density="compact"
                            class="mt-2"
                        >
                            {{ resignationOutcome.text }}
                        </v-alert>
                        <div class="text-body-2 font-weight-medium mb-1 mt-4">
                            Lý do nghỉ việc <span class="text-error">*</span>
                        </div>
                        <v-textarea
                            v-model="resignationForm.reason"
                            rows="4"
                            auto-grow
                            counter="2000"
                            :rules="[notEmpty('Lý do nghỉ việc'), maxLength(2000, 'Lý do nghỉ việc')]"
                        :error-messages="resignationErrors.reason"
                        />
                    </v-form>
                    <v-card-actions class="px-6 pb-5">
                        <v-spacer />
                        <v-btn variant="text" :disabled="submittingResignation" @click="resignationDialog = false">
                            Hủy
                        </v-btn>
                        <v-btn color="primary" :loading="submittingResignation" @click="submitResignation">
                            {{ resignationOutcome?.enough ? "Gửi thông báo" : "Gửi đơn" }}
                        </v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>

            <!-- Thẻ thông tin nhanh -->
            <StatCards :stats="quickStats" />

            <v-sheet
                class="border rounded-lg mb-4 mt-4 glass-panel"
                color="transparent"
            >
                <v-tabs v-model="tab">
                    <v-tab value="info" prepend-icon="mdi-account-outline">Thông tin cá nhân</v-tab>
                    <v-tab value="contracts" prepend-icon="mdi-file-document-outline">Hợp đồng</v-tab>
                    <v-tab value="documents" prepend-icon="mdi-folder-outline">Tài liệu</v-tab>
                    <v-tab value="shifts" prepend-icon="mdi-calendar-clock-outline">Ca làm việc</v-tab>
                    <v-tab value="transfers" prepend-icon="mdi-swap-horizontal">Luân chuyển</v-tab>
                    <v-tab value="payslips" prepend-icon="mdi-cash-multiple">Phiếu lương</v-tab>
                </v-tabs>
            </v-sheet>

            <v-window v-model="tab">
                <v-window-item value="info">
                    <MyProfileInfoTab
                        :employee="employee"
                        @updated="employee = $event"
                    />
                </v-window-item>

                <v-window-item value="contracts">
                    <MyProfileContractsTab
                        v-if="tabsOpened.contracts"
                        @preview="openPreview"
                    />
                </v-window-item>

                <v-window-item value="documents">
                    <MyProfileDocumentsTab
                        v-if="tabsOpened.documents"
                        @preview="openPreview"
                    />
                </v-window-item>

                <v-window-item value="shifts">
                    <MyProfileShiftsTab v-if="tabsOpened.shifts" />
                </v-window-item>

                <v-window-item value="transfers">
                    <MyProfileTransfersTab v-if="tabsOpened.transfers" />
                </v-window-item>

                <v-window-item value="payslips">
                    <MyProfilePayslipsTab v-if="tabsOpened.payslips" />
                </v-window-item>
            </v-window>
        </template>

        <div v-else-if="loading" class="d-flex justify-center py-10">
            <v-progress-circular indeterminate size="32" />
        </div>

        <FilePreviewDialog
            v-model="previewDialog"
            :file-url="previewFile.url"
            :file-name="previewFile.name"
        />
    </div>
</template>

<script setup>
// Trang tự phục vụ (self-service) — CHÍNH nhân viên xem hồ sơ/hợp đồng/tài
// liệu/ca làm việc của MÌNH, dựng riêng thay vì dùng chung EmployeeDetail.vue
// (khác EmployeeDetail ở 2 điểm): (1) gọi các endpoint /employees/me/... —
// không phụ thuộc employee.view/shift.view (role Employee không có 2 mã
// quyền này, xem CODE_MAP mục 8/14); (2) gần như chỉ đọc — chỉ 2 việc tự làm
// được: sửa thông tin LIÊN HỆ và tự tải tài liệu cá nhân lên.
//
// Nội dung từng tab tách thành component riêng (giống EmployeeDetail.vue) —
// file này chỉ còn giữ: tải hồ sơ chính, thẻ hero + StatCards đầu trang,
// khung v-tabs/v-window, và FilePreviewDialog dùng CHUNG cho tab Hợp đồng/
// Tài liệu.
import { computed, onMounted, reactive, ref, watch } from "vue";
import { useRoute } from "vue-router";
import employeeService from "../../services/employeeService";
import { useToastStore } from "../../stores/useToastStore";
import { useAuthStore } from "../../stores/authStore";
import PageHeader from "../../components/common/PageHeader.vue";
import StatusChip from "../../components/common/StatusChip.vue";
import StatCards from "../../components/dashboard/StatCards.vue";
import FilePreviewDialog from "../../components/common/FilePreviewDialog.vue";
import MyProfileInfoTab from "./MyProfileInfoTab.vue";
import MyProfileContractsTab from "./MyProfileContractsTab.vue";
import MyProfileDocumentsTab from "./MyProfileDocumentsTab.vue";
import MyProfileShiftsTab from "./MyProfileShiftsTab.vue";
import MyProfileTransfersTab from "./MyProfileTransfersTab.vue";
import MyProfilePayslipsTab from "./MyProfilePayslipsTab.vue";
import { EMPLOYMENT_STATUS_MAP } from "../../composables/employmentStatus";
import resignationService from "../../services/resignationService";
import InputDate, { todayIso } from "../../components/common/InputDate.vue";
import { useRealtimeRefresh } from "../../composables/useRealtimeRefresh";
import { maxLength, notEmpty, useClearErrorsOnEdit } from "../../composables/validationRules";

function formatDate(value) {
    if (!value) {
        return null;
    }
    return new Date(value).toLocaleDateString("vi-VN");
}

// "2 năm 3 tháng" tính từ ngày vào làm tới hôm nay — chỉ hiển thị, không gửi
// lên API nào nên không cần chính xác tuyệt đối theo lịch âm/dương.
function formatTenure(hireDate) {
    if (!hireDate) {
        return "—";
    }
    const start = new Date(hireDate);
    const now = new Date();
    let months =
        (now.getFullYear() - start.getFullYear()) * 12 +
        (now.getMonth() - start.getMonth());
    if (now.getDate() < start.getDate()) {
        months -= 1;
    }
    if (months < 0) {
        return "—";
    }
    const years = Math.floor(months / 12);
    const remMonths = months % 12;
    if (years === 0) {
        return `${remMonths} tháng`;
    }
    if (remMonths === 0) {
        return `${years} năm`;
    }
    return `${years} năm ${remMonths} tháng`;
}

const route = useRoute();
const toast = useToastStore();
const auth = useAuthStore();
const VALID_TABS = ["info", "contracts", "documents", "shifts", "transfers", "payslips"];

const employee = ref(null);
const loading = ref(true);
const loadError = ref("");
// Cho phép chỗ khác (vd Dashboard.vue -> "Xem phiếu lương") đưa thẳng tới
// đúng tab qua query ?tab=... — mặc định "info" như cũ nếu không có/không hợp lệ.
const tab = ref(VALID_TABS.includes(route.query.tab) ? route.query.tab : "info");

const quickStats = computed(() => {
    if (!employee.value) {
        return [];
    }
    const e = employee.value;
    return [
        {
            label: "Thâm niên",
            value: formatTenure(e.hire_date),
            color: "primary",
            icon: "mdi-clock-star-four-points-outline",
        },
        {
            label: "Ngày vào làm",
            value: formatDate(e.hire_date) ?? "—",
            color: "info",
            icon: "mdi-calendar-check-outline",
        },
        {
            label: "Chức vụ",
            value: e.position?.name ?? "—",
            color: "success",
            icon: "mdi-badge-account-outline",
        },
        {
            label: "Quản lý trực tiếp",
            value: e.manager?.full_name ?? "Không có",
            color: "warning",
            icon: "mdi-account-supervisor-outline",
        },
    ];
});

async function loadProfile(opts) {
    const silent = opts?.silent === true;
    if (!silent) {
        loading.value = true;
    }
    loadError.value = "";
    try {
        const response = await employeeService.me();
        employee.value = response.data.data;
    } catch (e) {
        loadError.value =
            e.response?.status === 404
                ? "Tài khoản này chưa được liên kết với hồ sơ nhân viên nào. Liên hệ phòng Nhân sự để được hỗ trợ."
                : "Không thể tải hồ sơ cá nhân, vui lòng thử lại.";
    } finally {
        loading.value = false;
    }
}

// Mỗi tab (trừ "Thông tin cá nhân" — hiện ngay vì chỉ đọc `employee` đã tải
// sẵn ở trên) chỉ mount component con lần đầu khi người dùng thật sự mở tab
// đó, giống EmployeeDetail.vue.
const tabsOpened = reactive({
    contracts: false,
    documents: false,
    shifts: false,
    transfers: false,
    payslips: false,
});

watch(tab, (value) => {
    if (value in tabsOpened) {
        tabsOpened[value] = true;
    }
});

// Vào thẳng bằng ?tab=payslips (vd từ Dashboard) thì tab đã được chọn ngay lúc
// khởi tạo nên watch(tab) ở trên KHÔNG chạy — phải tự đánh dấu "đã mở", nếu
// không nội dung tab (v-if="tabsOpened.x") không bao giờ được dựng = tab trống.
if (tab.value in tabsOpened) {
    tabsOpened[tab.value] = true;
}

// Đang ở sẵn trang này mà bấm link ?tab=... khác (vd thông báo) thì đổi tab.
watch(
    () => route.query.tab,
    (value) => {
        if (VALID_TABS.includes(value)) {
            tab.value = value;
        }
    },
);

// --- Tự đổi ảnh đại diện (POST /employees/me/avatar). Kiểm tra loại/dung
// lượng ngay ở đây để báo lỗi tức thì, Backend vẫn kiểm tra lại (cùng luật).
const AVATAR_TYPES = ["image/jpeg", "image/png", "image/webp"];
const AVATAR_MAX_BYTES = 2 * 1024 * 1024;
const avatarInput = ref(null);
const uploadingAvatar = ref(false);

function pickAvatar() {
    if (!uploadingAvatar.value) {
        avatarInput.value?.click();
    }
}

async function onAvatarSelected(event) {
    const file = event.target.files?.[0];
    // Xóa giá trị để lần sau chọn lại ĐÚNG file đó vẫn bắn sự kiện change.
    event.target.value = "";
    if (!file) {
        return;
    }
    if (!AVATAR_TYPES.includes(file.type)) {
        toast.error("Chỉ nhận ảnh JPG, PNG hoặc WEBP.");
        return;
    }
    if (file.size > AVATAR_MAX_BYTES) {
        toast.error("Ảnh tối đa 2MB, vui lòng chọn ảnh nhỏ hơn.");
        return;
    }

    const formData = new FormData();
    formData.append("avatar", file);
    uploadingAvatar.value = true;
    try {
        const response = await employeeService.uploadMyAvatar(formData);
        employee.value = response.data.data;
        // Đồng bộ luôn ảnh trên Header, không phải chờ tải lại trang.
        if (auth.user) {
            auth.user.avatar_url = employee.value.avatar_url;
        }
        toast.success("Đã cập nhật ảnh đại diện.");
    } catch (e) {
        toast.error(
            e.response?.data?.errors?.avatar?.[0] ??
                e.response?.data?.message ??
                "Không tải được ảnh lên, vui lòng thử lại.",
        );
    } finally {
        uploadingAvatar.value = false;
    }
}

const previewDialog = ref(false);
const previewFile = ref({ url: "", name: "" });

function openPreview(url, name) {
    previewFile.value = { url, name };
    previewDialog.value = true;
}

/* ------------------- Đơn xin nghỉ việc (2026-09-29) ------------------- */

const canRequestResignation = computed(() => auth.permissions.includes("resignation.request"));
const resignations = ref([]);
const latestResignation = computed(() => resignations.value[0] ?? null);

// Còn đơn đang chờ duyệt / đã duyệt mà chưa tới hạn thì không cho nộp thêm
// (Backend cũng chặn — ResignationService::create()).
const hasOpenResignation = computed(() =>
    resignations.value.some(
        (r) => r.status === "pending" || (["approved", "notified"].includes(r.status) && !r.applied_at),
    ),
);

const canSubmitResignation = computed(
    () =>
        canRequestResignation.value &&
        ["probation", "active"].includes(employee.value?.employment_status) &&
        !hasOpenResignation.value,
);

function formatDateVi(value) {
    return value ? new Date(value).toLocaleDateString("vi-VN") : "";
}

const resignationBanner = computed(() => {
    const r = latestResignation.value;
    if (!r) {
        return null;
    }
    const lastDay = formatDateVi(r.last_working_date);
    if (r.status === "pending") {
        return { type: "warning", icon: "mdi-clock-outline", text: `Đơn xin nghỉ việc của bạn (ngày làm việc cuối ${lastDay}) đang chờ duyệt.` };
    }
    if (r.status === "notified" && !r.applied_at) {
        return {
            type: "info",
            icon: "mdi-bell-check-outline",
            text: `Bạn đã thông báo nghỉ việc (báo trước ${r.notice_days_given} ngày, đủ theo quy định) — ngày làm việc cuối là ${lastDay}. Đơn không cần duyệt; sau ngày đó bạn sẽ tự chuyển sang "Đã nghỉ việc".`,
        };
    }
    if (r.status === "approved" && !r.applied_at) {
        return {
            type: "success",
            icon: "mdi-check-circle-outline",
            text: `Đơn xin nghỉ việc đã được duyệt — ngày làm việc cuối của bạn là ${lastDay}.${r.decision_note ? ` Ghi chú: ${r.decision_note}` : ""}`,
        };
    }
    if (r.status === "rejected") {
        return {
            type: "error",
            icon: "mdi-close-circle-outline",
            text: `Đơn xin nghỉ việc gần nhất đã bị từ chối.${r.decision_note ? ` Lý do: ${r.decision_note}` : ""}`,
        };
    }
    return null;
});

async function loadResignations() {
    if (!canRequestResignation.value) {
        return;
    }
    try {
        const response = await resignationService.mine();
        resignations.value = response.data.data;
    } catch {
        resignations.value = [];
    }
}

const resignationDialog = ref(false);
const resignationForm = reactive({ last_working_date: "", reason: "" });
const resignationErrors = ref({});
const submittingResignation = ref(false);
const cancellingResignation = ref(false);

// Quy định báo trước theo hợp đồng (BLLĐ 2019 Điều 35) — Backend tự tính, đây chỉ
// để hiện trước cho nhân viên biết đơn sẽ "chỉ thông báo" hay "phải chờ duyệt".
const resignationPolicy = ref(null);

const resignationOutcome = computed(() => {
    const policy = resignationPolicy.value;
    const date = resignationForm.last_working_date;
    if (!policy || !date) {
        return null;
    }
    const given = Math.round((new Date(date) - new Date(todayIso())) / 86400000);
    if (given >= policy.required_days) {
        return {
            enough: true,
            text: `Báo trước ${given} ngày, đủ theo quy định — đơn chỉ là thông báo cho công ty, không cần duyệt.`,
        };
    }
    return {
        enough: false,
        text: `Báo trước ${given} ngày, chưa đủ ${policy.required_days} ngày tối thiểu — đơn sẽ phải chờ HR/quản lý xem xét và đồng ý.`,
    };
});

async function openResignationDialog() {
    resignationForm.last_working_date = "";
    resignationForm.reason = "";
    resignationErrors.value = {};
    resignationDialog.value = true;
    try {
        resignationPolicy.value = (await resignationService.policy()).data;
    } catch {
        resignationPolicy.value = null;
    }
}

const resignationFormRef = ref(null);
useClearErrorsOnEdit(resignationForm, () => resignationErrors.value);

async function submitResignation() {
    const { valid } = await resignationFormRef.value.validate();
    if (!valid) {
        return;
    }
    resignationErrors.value = {};
    submittingResignation.value = true;
    try {
        const response = await resignationService.create({ ...resignationForm });
        toast.success(
            response.data.data.status === "notified"
                ? "Đã gửi thông báo nghỉ việc cho công ty."
                : "Đã gửi đơn xin nghỉ việc, đang chờ duyệt.",
        );
        resignationDialog.value = false;
        await loadResignations();
    } catch (e) {
        if (e.response?.status === 422) {
            const errors = e.response.data.errors ?? {};
            resignationErrors.value = {
                last_working_date: errors.last_working_date?.[0],
                reason: errors.reason?.[0],
            };
        } else {
            toast.error(e.response?.data?.message ?? "Không thể gửi đơn, vui lòng thử lại.");
        }
    } finally {
        submittingResignation.value = false;
    }
}

async function cancelResignation() {
    cancellingResignation.value = true;
    try {
        await resignationService.cancel(latestResignation.value.id);
        toast.success("Đã rút đơn nghỉ việc.");
        await loadResignations();
    } catch (e) {
        toast.error(e.response?.data?.errors?.status?.[0] ?? e.response?.data?.message ?? "Không thể rút đơn.");
    } finally {
        cancellingResignation.value = false;
    }
}

useRealtimeRefresh(loadProfile, { mine: ["profile", "leave_balances", "contracts"] });
useRealtimeRefresh(loadResignations, { mine: ["resignations"] });

onMounted(() => {
    loadProfile();
    loadResignations();
});
</script>

<style scoped>
.profile-hero {
    background: rgb(var(--v-theme-surface));
}
.avatar-upload {
    position: relative;
    width: 80px;
    height: 80px;
    border-radius: 50%;
    cursor: pointer;
    flex-shrink: 0;
}
.avatar-upload:focus-visible {
    outline: 2px solid rgb(var(--v-theme-primary));
    outline-offset: 2px;
}
.avatar-upload__overlay {
    position: absolute;
    inset: 0;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0, 0, 0, 0.45);
    opacity: 0;
    transition: opacity 0.15s ease;
}
.avatar-upload:hover .avatar-upload__overlay,
.avatar-upload__overlay--busy {
    opacity: 1;
}
.avatar-upload__badge {
    position: absolute;
    right: 0;
    bottom: 0;
    width: 26px;
    height: 26px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid rgb(var(--v-theme-surface));
}
</style>
