<template>
    <div>
        <v-alert
            v-if="contractsError"
            type="error"
            variant="tonal"
            density="compact"
            class="mb-4"
            icon="mdi-alert-circle-outline"
        >
            {{ contractsError }}
        </v-alert>
        <div class="d-flex justify-end mb-3">
            <v-btn
                color="primary"
                variant="flat"
                prepend-icon="mdi-plus"
                @click="openCreateDialog"
            >
                Thêm hợp đồng
            </v-btn>
        </div>

        <v-sheet class="border rounded-lg glass-panel" color="transparent">
            <v-table density="comfortable">
                <thead>
                    <tr>
                        <th>Số hợp đồng</th>
                        <th>Loại HĐ</th>
                        <th>Bắt đầu</th>
                        <th>Kết thúc</th>
                        <th>Lương thỏa thuận</th>
                        <th>Trạng thái</th>
                        <th class="text-center">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="contractsLoading">
                        <td colspan="7" class="text-center py-6">
                            <v-progress-circular indeterminate size="24" />
                        </td>
                    </tr>
                    <tr v-else-if="!contracts.length">
                        <td
                            colspan="7"
                            class="text-center py-6"
                            style="opacity: 0.6"
                        >
                            Chưa có hợp đồng nào.
                        </td>
                    </tr>
                    <tr v-for="contract in contracts" v-else :key="contract.id">
                        <td>{{ contract.contract_number }}</td>
                        <td>
                            {{
                                CONTRACT_TYPE_MAP[contract.contract_type] ??
                                contract.contract_type
                            }}
                        </td>
                        <td>{{ formatDate(contract.start_date) }}</td>
                        <td>{{ formatDate(contract.end_date) ?? "—" }}</td>
                        <td>{{ formatCurrency(contract.agreed_salary) }}</td>
                        <td>
                            <StatusChip
                                :status="contract.status"
                                :map="CONTRACT_STATUS_MAP"
                            />
                        </td>
                        <td class="text-center">
                            <!-- d-flex justify-center ga-2: bọc 3 nút thành 1
                                 khối canh GIỮA đều khoảng cách — khớp đúng kiểu
                                 EmployeeDocumentsTab.vue đã dùng cho cột thao
                                 tác của nó, thiếu bọc thì 3 nút trôi lệch nhau. -->
                            <div class="d-flex justify-center ga-2">
                                <!-- Xem chi tiết BẢN GHI hợp đồng (mọi field, kể
                                     cả Ngày ký/Lương đóng BH không có cột riêng
                                     trong bảng) — khác 2 nút sau là xem/tải FILE
                                     PDF đính kèm, không phải xem dữ liệu hợp đồng. -->
                                <v-btn
                                    icon="mdi-information-outline"
                                    variant="tonal"
                                    size="small"
                                    rounded="lg"
                                    @click="openDetailDialog(contract)"
                                >
                                    <v-icon icon="mdi-information-outline" />
                                    <v-tooltip activator="parent" location="top"
                                        >Xem chi tiết</v-tooltip
                                    >
                                </v-btn>

                                <v-btn
                                    icon="mdi-eye-outline"
                                    variant="tonal"
                                    size="small"
                                    rounded="lg"
                                    @click="
                                        $emit(
                                            'preview',
                                            contract.download_url,
                                            contract.contract_number + '.pdf',
                                        )
                                    "
                                >
                                    <v-icon icon="mdi-eye-outline" />
                                    <v-tooltip activator="parent" location="top"
                                        >Xem trước</v-tooltip
                                    >
                                </v-btn>

                                <v-btn
                                    icon="mdi-download-outline"
                                    variant="tonal"
                                    size="small"
                                    rounded="lg"
                                    :loading="downloadingId === contract.id"
                                    @click="downloadContract(contract)"
                                >
                                    <v-icon icon="mdi-download-outline" />
                                    <v-tooltip activator="parent" location="top">
                                        Tải file
                                    </v-tooltip>
                                </v-btn>

                                <!-- Chỉ hiện khi còn "active" — hợp đồng đã
                                     expired/terminated thì không còn gì để
                                     chấm dứt nữa (xem EmployeeContractService::
                                     terminate() chặn ở Backend luôn, đây chỉ
                                     là ẩn ở Frontend cho gọn giao diện). -->
                                <v-btn
                                    v-if="contract.status === 'active'"
                                    icon="mdi-file-cancel-outline"
                                    variant="tonal"
                                    color="error"
                                    size="small"
                                    rounded="lg"
                                    @click="openTerminateDialog(contract)"
                                >
                                    <v-icon icon="mdi-file-cancel-outline" />
                                    <v-tooltip activator="parent" location="top">
                                        Chấm dứt hợp đồng
                                    </v-tooltip>
                                </v-btn>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </v-table>
        </v-sheet>

        <!-- Dialog CHỈ ĐỌC — xem đủ mọi field của 1 hợp đồng, kể cả Ngày ký/
             Lương đóng BH không có cột riêng trong bảng phía trên. Không có
             ô nhập nào ở đây, không gọi API nào — chỉ hiển thị lại dữ liệu
             của contract đã có sẵn trong danh sách `contracts`. -->
        <v-dialog v-model="detailDialog" max-width="480">
            <v-card rounded="xl" elevation="12" class="glass-panel">
                <v-card-title class="text-h6 font-weight-bold pt-5 px-5">
                    Chi tiết hợp đồng {{ detailContract?.contract_number }}
                </v-card-title>
                <v-card-text
                    class="px-5"
                    style="display: flex; flex-direction: column; gap: 0.5rem"
                >
                    <div class="d-flex justify-space-between">
                        <span class="text-medium-emphasis">Loại hợp đồng</span>
                        <strong>{{
                            CONTRACT_TYPE_MAP[detailContract?.contract_type] ??
                            detailContract?.contract_type
                        }}</strong>
                    </div>
                    <div class="d-flex justify-space-between">
                        <span class="text-medium-emphasis">Ngày ký</span>
                        <strong>{{
                            formatDate(detailContract?.signed_at) ?? "—"
                        }}</strong>
                    </div>
                    <div class="d-flex justify-space-between">
                        <span class="text-medium-emphasis">Ngày bắt đầu</span>
                        <strong>{{
                            formatDate(detailContract?.start_date)
                        }}</strong>
                    </div>
                    <div class="d-flex justify-space-between">
                        <span class="text-medium-emphasis">Ngày kết thúc</span>
                        <strong>{{
                            formatDate(detailContract?.end_date) ?? "—"
                        }}</strong>
                    </div>
                    <div class="d-flex justify-space-between">
                        <span class="text-medium-emphasis"
                            >Lương thỏa thuận</span
                        >
                        <strong>{{
                            formatCurrency(detailContract?.agreed_salary)
                        }}</strong>
                    </div>
                    <div class="d-flex justify-space-between">
                        <span class="text-medium-emphasis">Lương đóng BH</span>
                        <strong>{{
                            formatCurrency(detailContract?.insurance_salary)
                        }}</strong>
                    </div>
                    <div class="d-flex justify-space-between align-center">
                        <span class="text-medium-emphasis">Trạng thái</span>
                        <StatusChip
                            :status="detailContract?.status"
                            :map="CONTRACT_STATUS_MAP"
                        />
                    </div>
                </v-card-text>
                <v-card-actions class="px-5 pb-5">
                    <v-spacer />
                    <v-btn variant="text" @click="detailDialog = false">
                        Đóng
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Xác nhận trước khi chấm dứt — hành động một chiều (không có nút
             "hoàn tác" trên giao diện), nên bắt xác nhận riêng thay vì cho
             bấm chấm dứt thẳng từ nút ở bảng. -->
        <v-dialog v-model="terminateDialog" max-width="420">
            <v-card rounded="xl" elevation="12" class="glass-panel">
                <v-card-title class="text-h6 font-weight-bold pt-5 px-5">
                    Chấm dứt hợp đồng?
                </v-card-title>
                <v-card-text class="px-5">
                    Hợp đồng
                    <strong>{{ terminateContract?.contract_number }}</strong>
                    sẽ chuyển sang trạng thái "Đã chấm dứt" kể từ hôm nay.
                    Hành động này không thể hoàn tác.
                    <v-alert
                        v-if="terminateError"
                        type="error"
                        variant="tonal"
                        density="compact"
                        class="mt-3"
                    >
                        {{ terminateError }}
                    </v-alert>
                </v-card-text>
                <v-card-actions class="px-5 pb-5">
                    <v-spacer />
                    <v-btn
                        variant="text"
                        :disabled="terminating"
                        @click="terminateDialog = false"
                    >
                        Hủy
                    </v-btn>
                    <v-btn
                        color="error"
                        variant="flat"
                        :loading="terminating"
                        @click="submitTerminate"
                    >
                        Chấm dứt
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <v-dialog v-model="createDialog" max-width="520" persistent>
            <v-card rounded="xl" elevation="12" class="glass-panel">
                <v-card-title class="text-h6 font-weight-bold pt-5 px-5">
                    Thêm hợp đồng
                </v-card-title>
                <v-card-text
                    class="px-5"
                    style="display: flex; flex-direction: column; gap: 0.75rem"
                >
                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Số hợp đồng
                        </div>
                        <v-text-field
                            model-value="Tự động sau khi lưu"
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            readonly
                            class="text-medium-emphasis"
                        />
                    </div>

                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Loại hợp đồng <span class="text-error">*</span>
                        </div>
                        <v-select
                            v-model="createForm.contract_type"
                            :items="[
                                { title: 'Thử việc', value: 'thu_viec' },
                                { title: 'Chính thức', value: 'chinh_thuc' },
                            ]"
                            placeholder="Chọn loại hợp đồng"
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            :error-messages="createErrors.contract_type"
                        />
                    </div>
                    <v-row dense>
                        <v-col cols="4">
                            <div class="text-body-2 font-weight-medium mb-1">
                                Ngày ký
                            </div>
                            <v-text-field
                                v-model="createForm.signed_at"
                                type="date"
                                variant="outlined"
                                density="comfortable"
                                rounded="lg"
                                :error-messages="createErrors.signed_at"
                            />
                        </v-col>
                        <v-col cols="4">
                            <div class="text-body-2 font-weight-medium mb-1">
                                Ngày bắt đầu <span class="text-error">*</span>
                            </div>
                            <v-text-field
                                v-model="createForm.start_date"
                                type="date"
                                variant="outlined"
                                density="comfortable"
                                rounded="lg"
                                :error-messages="createErrors.start_date"
                            />
                        </v-col>
                        <v-col cols="4">
                            <div class="text-body-2 font-weight-medium mb-1">
                                Ngày kết thúc
                            </div>
                            <v-text-field
                                v-model="createForm.end_date"
                                type="date"
                                variant="outlined"
                                density="comfortable"
                                rounded="lg"
                                :error-messages="createErrors.end_date"
                            />
                        </v-col>
                    </v-row>
                    <v-row dense>
                        <v-col cols="6">
                            <div class="text-body-2 font-weight-medium mb-1">
                                Lương thỏa thuận
                                <span class="text-error">*</span>
                            </div>
                            <InputMoney
                                v-model="createForm.agreed_salary"
                                :error-messages="createErrors.agreed_salary"
                            />
                        </v-col>
                        <v-col
                            cols="6"
                            v-if="createForm.contract_type === 'chinh_thuc'"
                        >
                            <div class="text-body-2 font-weight-medium mb-1">
                                Lương đóng BH <span class="text-error">*</span>
                            </div>
                            <InputMoney
                                v-model="createForm.insurance_salary"
                                :error-messages="createErrors.insurance_salary"
                            />
                        </v-col>
                    </v-row>
                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Tệp hợp đồng (PDF) <span class="text-error">*</span>
                        </div>
                        <v-file-input
                            v-model="createForm.contract_file"
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            prepend-icon=""
                            prepend-inner-icon="mdi-paperclip"
                            accept=".pdf"
                            :error-messages="createErrors.contract_file"
                        />
                    </div>
                    <v-alert
                        v-if="createGeneralError"
                        type="error"
                        variant="tonal"
                        density="compact"
                    >
                        {{ createGeneralError }}
                    </v-alert>
                </v-card-text>
                <v-card-actions class="px-5 pb-5">
                    <v-spacer />
                    <v-btn
                        variant="text"
                        :disabled="creating"
                        @click="createDialog = false"
                        >Hủy</v-btn
                    >
                    <v-btn
                        color="primary"
                        variant="flat"
                        :loading="creating"
                        @click="submitCreate"
                    >
                        Lưu hợp đồng
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
// Tab "Hợp đồng" của EmployeeDetail.vue — tách riêng theo yêu cầu dễ bảo
// trì. Tự tải dữ liệu của chính nó ngay khi mount (component cha chỉ mount
// component này lần đầu khi người dùng thật sự mở tab, xem EmployeeDetail.vue).
import { onMounted, ref, reactive, watch } from "vue";
import employeeService from "../../services/employeeService";
import StatusChip from "../../components/common/StatusChip.vue";
import InputMoney from "../../components/common/InputMoney.vue";
import { useToastStore } from "../../stores/useToastStore";
const toast = useToastStore();
const props = defineProps({
    employeeId: {
        type: [String, Number],
        required: true,
    },
    // Trạng thái làm việc hiện tại của nhân viên (probation/active/...) — dùng
    // để tự chọn sẵn "Loại hợp đồng" lúc mở dialog Thêm, đỡ 1 bước chọn tay và
    // giảm rủi ro chọn nhầm loại không khớp trạng thái thật.
    employmentStatus: {
        type: String,
        default: null,
    },
});

defineEmits(["preview"]);

/* ---------------------------------------------------------------------------
 * Bảng ánh xạ hiển thị: đổi giá trị THÔ lưu trong DB (tiếng Anh, snake_case)
 * sang nhãn tiếng Việt cho người dùng đọc — dùng chung cho cả bảng danh sách
 * lẫn dialog "Xem chi tiết" bên dưới, tránh viết trùng 2 nơi.
 * ------------------------------------------------------------------------- */
const CONTRACT_STATUS_MAP = {
    active: { label: "Còn hiệu lực", color: "success" },
    // Đã ký nhưng chưa tới ngày bắt đầu — xem EmployeeContractService::create()
    // và app/Console/Commands/ActivatePendingContracts.php (job hằng ngày tự
    // chuyển sang "active" đúng lúc start_date tới).
    pending: { label: "Chưa hiệu lực", color: "warning" },
    expired: { label: "Hết hạn", color: "default" },
    terminated: { label: "Đã chấm dứt", color: "error" },
};

const CONTRACT_TYPE_MAP = {
    thu_viec: "Thử việc",
    chinh_thuc: "Chính thức",
};

/* ---------------------------------------------------------------------------
 * Hàm định dạng thuần (không gọi API, không đụng state) — nhận giá trị thô,
 * trả về chuỗi hiển thị. Dùng lại ở cả <template> (bảng) lẫn dialog chi tiết.
 * ------------------------------------------------------------------------- */
function formatDate(value) {
    if (!value) {
        return null;
    }
    return new Date(value).toLocaleDateString("vi-VN");
}

function formatCurrency(value) {
    if (value === null || value === undefined) {
        return "—";
    }
    return new Intl.NumberFormat("vi-VN", {
        style: "currency",
        currency: "VND",
    }).format(value);
}

/* ---------------------------------------------------------------------------
 * Tải danh sách hợp đồng + tải FILE PDF đính kèm về máy — 2 việc khác nhau:
 * loadContracts() lấy DỮ LIỆU (số hợp đồng, ngày, lương...) hiển thị trong
 * bảng; downloadContract() lấy đúng FILE nhị phân của 1 hợp đồng cụ thể.
 * ------------------------------------------------------------------------- */
const contracts = ref([]);
const contractsLoading = ref(false);
const contractsError = ref("");
const downloadingId = ref(null);

async function loadContracts() {
    contractsLoading.value = true;
    contractsError.value = "";
    try {
        const response = await employeeService.contracts(props.employeeId);
        contracts.value = response.data.data;
    } catch (e) {
        contractsError.value =
            e.response?.data?.message ?? "Không thể tải danh sách hợp đồng.";
    } finally {
        contractsLoading.value = false;
    }
}

async function downloadContract(contract) {
    downloadingId.value = contract.id;
    contractsError.value = "";
    try {
        // download_url trỏ tới route yêu cầu auth:api — dùng axios (tự gắn
        // Authorization header qua interceptor ở bootstrap.js) thay vì thẻ <a>
        // trần, vì thẻ <a> không gửi kèm header nên sẽ bị 401.
        const response = await window.axios.get(contract.download_url, {
            responseType: "blob",
        });
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement("a");
        link.href = url;
        link.download = `${contract.contract_number}.pdf`;
        link.click();
        window.URL.revokeObjectURL(url);
    } catch (e) {
        contractsError.value =
            e.response?.data?.message ?? "Không thể tải file hợp đồng.";
    } finally {
        downloadingId.value = null;
    }
}
/* ---------------------------------------------------------------------------
 * Xem chi tiết 1 hợp đồng — dialog CHỈ ĐỌC, không gọi API nào cả. `contract`
 * truyền vào là bản ghi ĐÃ CÓ SẴN trong mảng `contracts` (từ loadContracts()
 * ở trên), chỉ cần lưu lại rồi hiện dialog, không cần tải lại từ server.
 * ------------------------------------------------------------------------- */
const detailDialog = ref(false);
const detailContract = ref(null);

function openDetailDialog(contract) {
    detailContract.value = contract;
    detailDialog.value = true;
}

/* ---------------------------------------------------------------------------
 * Tạo hợp đồng mới — dialog CÓ ghi (form nhập liệu + gọi API tạo), khác hẳn
 * dialog "Xem chi tiết" phía trên. contract_number KHÔNG có trong createForm
 * vì hệ thống tự sinh (xem EmployeeContractService::generateContractNumber()),
 * client không được quyền đặt số hợp đồng.
 * ------------------------------------------------------------------------- */
const createDialog = ref(false);
const createForm = reactive({
    signed_at: "",
    contract_type: "",
    start_date: "",
    end_date: "",
    agreed_salary: null,
    insurance_salary: null,
    contract_file: null,
});
const createErrors = ref({});
const createGeneralError = ref("");
const creating = ref(false);

function openCreateDialog() {
    // Gợi ý sẵn Loại hợp đồng theo trạng thái làm việc hiện tại — người dùng
    // vẫn đổi được tay (vd chuyển từ thử việc sang chính thức thì chính lúc
    // này employment_status có thể CHƯA kịp cập nhật), chỉ là giá trị mặc định.
    createForm.contract_type =
        props.employmentStatus === "probation" ? "thu_viec" : "chinh_thuc";
    createForm.signed_at = "";
    createForm.start_date = "";
    createForm.end_date = "";
    createForm.agreed_salary = null;
    createForm.insurance_salary = null;
    createForm.contract_file = null;
    createErrors.value = {};
    createGeneralError.value = "";
    createDialog.value = true;
}

async function submitCreate() {
    createErrors.value = {};
    createGeneralError.value = "";
    creating.value = true;
    try {
        const formData = new FormData();
        formData.append("contract_type", createForm.contract_type);
        if (createForm.signed_at)
            formData.append("signed_at", createForm.signed_at);
        formData.append("start_date", createForm.start_date);
        if (createForm.end_date)
            formData.append("end_date", createForm.end_date);
        formData.append("agreed_salary", createForm.agreed_salary ?? "");
        formData.append("insurance_salary", createForm.insurance_salary ?? "");
        const file = Array.isArray(createForm.contract_file)
            ? createForm.contract_file[0]
            : createForm.contract_file;
        if (file) formData.append("contract_file", file);

        await employeeService.createContract(props.employeeId, formData);
        // Tải lại CẢ danh sách thay vì chỉ chèn thêm bản ghi mới: hợp đồng
        // active CŨ (nếu có) đã bị Backend tự chuyển sang "expired" ngay
        // trong lúc tạo (xem EmployeeContractService::create() auto-supersede)
        // — chỉ nối thêm response mới thì dòng cũ vẫn hiện "Còn hiệu lực" sai
        // trên bảng cho tới khi người dùng tự tải lại trang.
        await loadContracts();
        toast.success("Đã thêm hợp đồng.");
        createDialog.value = false;
    } catch (e) {
        if (e.response?.status === 422) {
            createErrors.value = e.response.data.errors;
        } else {
            createGeneralError.value =
                e.response?.data?.message ?? "Không thể tạo hợp đồng.";
        }
    } finally {
        creating.value = false;
    }
}
/* ---------------------------------------------------------------------------
 * Chấm dứt hợp đồng giữa chừng (HR chủ động) — khác hẳn dialog "Xem chi tiết"
 * (chỉ đọc) và dialog "Thêm hợp đồng" (tạo mới): đây là cập nhật TRẠNG THÁI
 * của 1 hợp đồng đã có sẵn, có gọi API và có thể thất bại (vd hợp đồng vừa bị
 * người khác chấm dứt/hết hạn ngay trước đó — xem EmployeeContractService::
 * terminate() chặn nếu status không còn "active").
 * ------------------------------------------------------------------------- */
const terminateDialog = ref(false);
const terminateContract = ref(null);
const terminateError = ref("");
const terminating = ref(false);

function openTerminateDialog(contract) {
    terminateContract.value = contract;
    terminateError.value = "";
    terminateDialog.value = true;
}

async function submitTerminate() {
    terminateError.value = "";
    terminating.value = true;
    try {
        const response = await employeeService.terminateContract(
            props.employeeId,
            terminateContract.value.id,
        );
        const updated = response.data.data;
        const index = contracts.value.findIndex((c) => c.id === updated.id);
        if (index !== -1) contracts.value[index] = updated;
        toast.success("Đã chấm dứt hợp đồng.");
        terminateDialog.value = false;
    } catch (e) {
        terminateError.value =
            e.response?.data?.message ?? "Không thể chấm dứt hợp đồng.";
    } finally {
        terminating.value = false;
    }
}

// Đổi Loại hợp đồng SANG "Thử việc" thì xóa Lương đóng BH đã lỡ nhập trước đó
// — field này bị ẩn khỏi form (xem v-if ở <template>), không xóa thì giá trị
// cũ vẫn nằm trong createForm và bị gửi kèm lên server dù người dùng không
// còn thấy ô đó nữa.
watch(
    () => createForm.contract_type,
    (type) => {
        if (type !== "chinh_thuc") {
            createForm.insurance_salary = null;
        }
    },
);

/* ------------------------------------------------------------------ Vòng đời */
onMounted(() => {
    loadContracts();
});
</script>
