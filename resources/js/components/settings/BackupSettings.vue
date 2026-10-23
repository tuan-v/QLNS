<template>
    <FormSection title="Sao lưu dữ liệu" icon="mdi-database-export-outline">
        <template #header-actions>
            <v-btn color="primary" variant="flat" prepend-icon="mdi-backup-restore" :loading="running" @click="runNow">
                Sao lưu ngay
            </v-btn>
        </template>

        <p class="text-body-2 mb-4" style="opacity: 0.75">
            Tự động sao lưu lúc <strong>{{ schedule.time }}</strong> mỗi ngày, gồm toàn bộ dữ liệu và các file đã tải
            lên (hợp đồng, CV, minh chứng...). Giữ <strong>{{ schedule.keep }}</strong> bản gần nhất, bản cũ hơn tự xóa.
            Nên thỉnh thoảng tải 1 bản về cất ở nơi khác để phòng hỏng máy chủ.
        </p>

        <v-alert v-if="loadError" type="error" variant="tonal" density="compact" class="mb-4">{{ loadError }}</v-alert>

        <v-table density="comfortable" class="border rounded-lg">
            <thead>
                <tr>
                    <th>Bản sao lưu</th>
                    <th>Thời gian</th>
                    <th class="text-right">Dung lượng</th>
                    <th class="text-right" style="width: 1%"></th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="loading">
                    <td colspan="4" class="text-center py-4"><v-progress-circular indeterminate size="22" /></td>
                </tr>
                <tr v-else-if="!backups.length">
                    <td colspan="4" class="text-center text-medium-emphasis py-4">
                        Chưa có bản sao lưu nào. Bấm "Sao lưu ngay" để tạo bản đầu tiên.
                    </td>
                </tr>
                <tr v-for="(backup, index) in backups" v-else :key="backup.name">
                    <td class="text-no-wrap">
                        {{ backup.name }}
                        <v-chip v-if="index === 0" size="x-small" color="success" variant="tonal" class="ml-1">
                            Mới nhất
                        </v-chip>
                    </td>
                    <td class="text-no-wrap">{{ formatDateTime(backup.created_at) }}</td>
                    <td class="text-right text-no-wrap">{{ formatSize(backup.size) }}</td>
                    <td class="text-right">
                        <v-btn
                            icon="mdi-download-outline"
                            size="small"
                            variant="text"
                            :loading="downloading === backup.name"
                            :aria-label="`Tải ${backup.name}`"
                            @click="download(backup.name)"
                        />
                    </td>
                </tr>
            </tbody>
        </v-table>

        <div class="text-caption text-medium-emphasis mt-3">
            Khôi phục dữ liệu chỉ làm bằng lệnh trên máy chủ (tránh bấm nhầm ghi đè dữ liệu đang dùng):
            <code>php artisan backup:restore &lt;tên bản sao lưu&gt;</code>
        </div>
    </FormSection>
</template>

<script setup>
import { onMounted, onUnmounted, ref } from "vue";
import FormSection from "../common/FormSection.vue";
import backupService from "../../services/backupService";
import { useToastStore } from "../../stores/useToastStore";

// Mục "Sao lưu dữ liệu" của Cài đặt hệ thống (chỉ người có system.backup) — xem App\Services\BackupService.
const POLL_MS = 4000;
const POLL_LIMIT_MS = 10 * 60 * 1000;

const toast = useToastStore();
const backups = ref([]);
const schedule = ref({ time: "02:00", keep: 14 });
const loading = ref(true);
const loadError = ref("");
const running = ref(false);
const downloading = ref("");
let pollTimer = null;

const formatDateTime = (value) => new Date(value).toLocaleString("vi-VN");

function formatSize(bytes) {
    const units = ["B", "KB", "MB", "GB"];
    let size = bytes;
    let unit = 0;
    while (size >= 1024 && unit < units.length - 1) {
        size /= 1024;
        unit++;
    }
    return `${size.toLocaleString("vi-VN", { maximumFractionDigits: unit ? 1 : 0 })} ${units[unit]}`;
}

async function load() {
    try {
        const { data } = await backupService.list();
        backups.value = data.data;
        schedule.value = data.schedule;
        loadError.value = "";
    } catch (e) {
        loadError.value = e.response?.data?.message ?? "Không tải được danh sách bản sao lưu.";
    } finally {
        loading.value = false;
    }
}

// Sao lưu chạy nền: hỏi lại danh sách vài giây một lần tới khi có bản mới (thông báo "xong" do chuông báo).
async function runNow() {
    running.value = true;
    const newestBefore = backups.value[0]?.name;
    try {
        const { data } = await backupService.create();
        toast.info(data.message);
    } catch (e) {
        toast.error(e.response?.data?.message ?? "Không bắt đầu sao lưu được.");
        running.value = false;
        return;
    }

    const startedAt = Date.now();
    clearInterval(pollTimer);
    pollTimer = setInterval(async () => {
        await load();
        if (backups.value[0]?.name !== newestBefore || Date.now() - startedAt > POLL_LIMIT_MS) {
            clearInterval(pollTimer);
            running.value = false;
        }
    }, POLL_MS);
}

async function download(name) {
    downloading.value = name;
    try {
        const response = await backupService.download(name);
        const url = URL.createObjectURL(response.data);
        const link = document.createElement("a");
        link.href = url;
        link.download = name;
        link.click();
        URL.revokeObjectURL(url);
    } catch {
        toast.error("Không tải được bản sao lưu.");
    } finally {
        downloading.value = "";
    }
}

onMounted(load);
onUnmounted(() => clearInterval(pollTimer));
</script>
