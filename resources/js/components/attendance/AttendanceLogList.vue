<template>
    <div>
        <div v-if="!logs.length" class="text-center py-4" style="opacity: 0.6">
            Không có nhật ký nào.
        </div>

        <!-- Mỗi lượt chấm công là 1 khối ghi đủ dữ liệu của THIẾT BỊ đã bấm (IP,
        địa chỉ, tên thiết bị). Bản ghi cũ (trước 2026-09-21) chưa có các trường
        này nên hiện "—". -->
        <div v-for="log in logs" v-else :key="log.id" class="border rounded-lg pa-3 mb-3">
            <div class="d-flex align-center ga-2 mb-2">
                <v-chip size="small" label :color="log.event_type === 'check_in' ? 'success' : 'info'">
                    {{ log.event_type === "check_in" ? "Vào" : "Ra" }}
                </v-chip>
                <span class="font-weight-medium">{{ formatDateTime(log.occurred_at) }}</span>
            </div>

            <div class="log-detail">
                <span class="log-detail__label">Thiết bị</span>
                <span>{{ log.device_name ?? "—" }}</span>

                <span class="log-detail__label">Địa chỉ IP</span>
                <span>{{ log.ip_address ?? "—" }}</span>

                <span class="log-detail__label">Vị trí</span>
                <span>
                    <template v-if="log.address">{{ log.address }}</template>
                    <template v-else-if="hasCoordinates(log)">Không tra được địa chỉ chữ</template>
                    <template v-else>Không lấy được vị trí</template>
                    <span v-if="hasCoordinates(log)" class="d-block text-caption" style="opacity: 0.6">
                        {{ formatCoordinate(log.latitude) }}, {{ formatCoordinate(log.longitude) }}
                        <template v-if="log.accuracy_meters !== null">
                            (sai số ~{{ Math.round(log.accuracy_meters) }}m)
                        </template>
                    </span>
                </span>

                <span class="log-detail__label">Điểm chấm công</span>
                <span>
                    {{ log.attendance_location?.name ?? "Không khớp điểm nào" }}
                    <span class="text-caption" style="opacity: 0.6">
                        ({{ METHOD_LABEL[log.method] ?? log.method }})
                    </span>
                </span>
            </div>
        </div>
    </div>
</template>

<script setup>
// Danh sách các lượt vào/ra (attendance_logs) của 1 bản ghi chấm công — dùng
// chung cho dialog "Chi tiết chấm công" ở AttendanceHistoryPanel.vue và màn
// "Duyệt chấm công" của HR (AttendanceApprovals.vue), để HR nhìn cùng 1 dữ liệu
// thiết bị/IP/địa chỉ khi quyết định duyệt.
defineProps({
    logs: {
        type: Array,
        default: () => [],
    },
});

// Cột attendance_logs.method là "khớp điểm chấm công bằng cách nào" (không còn
// là lựa chọn của nhân viên) — 'device' = không khớp điểm nào, chỉ có dữ liệu
// thiết bị. wifi/gps/qr cũng gặp ở bản ghi cũ do nhân viên tự chọn.
const METHOD_LABEL = {
    wifi: "khớp theo Wifi/IP",
    gps: "khớp theo GPS",
    qr: "khớp theo mã QR",
    device: "chỉ ghi nhận thiết bị",
};

function hasCoordinates(log) {
    return log.latitude !== null && log.latitude !== undefined && log.longitude !== null && log.longitude !== undefined;
}

// Tọa độ hiện 5 chữ số thập phân (~1m) — đủ để đối chiếu mà không dài dòng.
function formatCoordinate(value) {
    return Number(value).toFixed(5);
}

function formatDateTime(value) {
    if (!value) {
        return "—";
    }
    return new Date(value).toLocaleString("vi-VN");
}
</script>

<style scoped>
/* Lưới 2 cột "nhãn — giá trị" cho khối chi tiết từng lượt chấm công. */
.log-detail {
    display: grid;
    grid-template-columns: 120px 1fr;
    gap: 6px 12px;
    font-size: 0.875rem;
}

.log-detail__label {
    opacity: 0.65;
}

@media (max-width: 600px) {
    .log-detail {
        grid-template-columns: 1fr;
        gap: 0;
    }

    .log-detail__label {
        margin-top: 6px;
        font-size: 0.75rem;
    }
}
</style>
