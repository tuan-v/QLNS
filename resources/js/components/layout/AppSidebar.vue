<template>
    <v-navigation-drawer
        v-model="open"
        :rail="!mobile && rail"
        rail-width="80"
        width="280"
        :permanent="!mobile"
        :temporary="mobile"
        class="border-e qlns-sidebar"
    >
        <div class="d-flex align-center ga-3 px-4" style="min-height: 64px">
            <div
                style="
                    width: 36px;
                    height: 36px;
                    border-radius: 8px;
                    background: rgb(var(--v-theme-primary));
                    color: rgb(var(--v-theme-on-primary));
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    flex-shrink: 0;
                "
            >
                <svg
                    width="18"
                    height="18"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.75"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <circle cx="12" cy="5" r="2.5"></circle>
                    <circle cx="5" cy="18" r="2.5"></circle>
                    <circle cx="19" cy="18" r="2.5"></circle>
                    <path d="M12 7.5v4M12 11.5 6.5 16M12 11.5l5.5 4.5"></path>
                </svg>
            </div>
            <div v-if="showLabels" style="overflow: hidden">
                <div
                    class="text-subtitle-1 font-weight-bold text-no-wrap"
                    style="color: rgb(var(--v-theme-ink))"
                >
                    QLNS
                </div>
                <div
                    class="text-caption text-no-wrap text-medium-emphasis"
                    style="line-height: 1.2"
                >
                    Quản lý Nhân sự
                </div>
            </div>
        </div>

        <v-list nav density="compact" class="px-4 pt-2">
            <v-list-item
                prepend-icon="mdi-view-dashboard-outline"
                title="Tổng quan"
                to="/"
                rounded="lg"
            />
            <!-- Luôn hiện với mọi tài khoản đã đăng nhập — xem hồ sơ CHÍNH
                 MÌNH không phụ thuộc mã quyền nào, xem MyProfile.vue. -->
            <v-list-item
                prepend-icon="mdi-account-circle-outline"
                title="Hồ sơ của tôi"
                to="/my-profile"
                rounded="lg"
            />
            <v-list-item
                prepend-icon="mdi-bell-outline"
                title="Thông báo"
                to="/notifications"
                rounded="lg"
            />

            <v-list-subheader
                v-if="
                    showLabels &&
                    (can('employee.view') || can('department.view') || can('resignation.approve'))
                "
                >Nhân sự</v-list-subheader
            >
            <v-list-item
                v-if="can('employee.view')"
                prepend-icon="mdi-account-group-outline"
                title="Nhân viên"
                rounded="lg"
                to="/employees"
            />
            <v-list-item
                v-if="can('department.view')"
                prepend-icon="mdi-office-building-outline"
                title="Phòng ban"
                to="/departments"
                rounded="lg"
            />
            <v-list-item
                v-if="can('department.view')"
                prepend-icon="mdi-badge-account-outline"
                title="Chức vụ"
                to="/positions"
                rounded="lg"
            />
            <v-list-item
                v-if="can('resignation.approve')"
                prepend-icon="mdi-account-arrow-right-outline"
                title="Đơn nghỉ việc"
                to="/resignations"
                rounded="lg"
            />

            <v-list-subheader v-if="showLabels"
                >Chấm công &amp; Nghỉ phép</v-list-subheader
            >
            <!-- <v-list-item
                v-if="can('shift.view')"
                prepend-icon="mdi-timetable"
                title="Ca làm việc"
                to="/work-shifts"
                rounded="lg"
            /> -->
            <v-list-item
                v-if="can('attendance.check')"
                prepend-icon="mdi-calendar-check-outline"
                title="Chấm công"
                to="/check-in"
                rounded="lg"
            />
            <v-list-item
                v-if="can('attendance.view_all')"
                prepend-icon="mdi-clipboard-check-outline"
                title="Tổng hợp chấm công"
                to="/attendance-overview"
                rounded="lg"
            />
            <v-list-item
                v-if="can('attendance.adjust')"
                prepend-icon="mdi-file-clock-outline"
                title="Duyệt điều chỉnh công"
                to="/attendance-adjustments"
                rounded="lg"
            />
            <v-list-item
                v-if="can('leave.request')"
                prepend-icon="mdi-calendar-blank-outline"
                title="Nghỉ phép"
                to="/leave-requests"
                rounded="lg"
            />
            <v-list-item
                v-if="can('leave.view_all')"
                prepend-icon="mdi-calendar-check-outline"
                title="Duyệt nghỉ phép"
                to="/leave-management"
                rounded="lg"
            />
            <v-list-item
                v-if="can('leave.view_all')"
                prepend-icon="mdi-calendar-month-outline"
                title="Tổng hợp nghỉ phép"
                to="/leave-overview"
                rounded="lg"
            />

            <v-list-subheader v-if="showLabels"
                >Lương &amp; Báo cáo</v-list-subheader
            >
            <v-list-item
                v-if="can('payroll.view_all')"
                prepend-icon="mdi-cash-multiple"
                title="Bảng lương"
                to="/payrolls"
                rounded="lg"
            />

            <v-list-item
                prepend-icon="mdi-chart-bar"
                title="Báo cáo"
                rounded="lg"
                disabled
            />

            <v-list-subheader v-if="showLabels">Hệ thống</v-list-subheader>
            <v-list-item
                v-if="can('shift.manage')"
                prepend-icon="mdi-cog-outline"
                title="Cài đặt"
                to="/settings"
                rounded="lg"
            />
            <v-list-item
                v-if="can('rbac.manage')"
                prepend-icon="mdi-shield-account-outline"
                title="Vai trò &amp; Phân quyền"
                to="/roles"
                rounded="lg"
            />
            <v-list-item
                prepend-icon="mdi-history"
                title="Nhật ký hoạt động"
                rounded="lg"
                disabled
            />
        </v-list>

        <template #append>
            <v-divider class="mb-1" />
            <v-list nav density="compact" class="px-4 pb-2">
                <v-list-item
                    prepend-icon="mdi-help-circle-outline"
                    title="Hướng dẫn sử dụng"
                    rounded="lg"
                    disabled
                />
            </v-list>
        </template>
    </v-navigation-drawer>
</template>

<script setup>
import { computed, watch } from "vue";
import { useDisplay } from "vuetify";
import { useRoute } from "vue-router";
import { useAuthStore } from "../../stores/authStore";

// Cả 2 đều đến từ AppLayout.vue (component cha chung với AppHeader) — không
// tự giữ state riêng, để nút hamburger ở AppHeader điều khiển được sidebar.
// v-model mặc định: ẩn/hiện HẲN sidebar — chỉ có ý nghĩa thật khi mobile
// (temporary overlay); desktop permanent thì luôn hiện, bỏ qua giá trị này.
const open = defineModel({ type: Boolean, default: true });
// v-model:rail — thu gọn còn icon, chỉ áp dụng khi desktop (xem AppLayout.vue).
const rail = defineModel("rail", { type: Boolean, default: true });

const { mobile } = useDisplay();
// Mobile: overlay đang mở thì luôn hiện đủ nhãn (không có khái niệm "rail"
// cho temporary drawer). Desktop: ẩn nhãn khi đang thu gọn (rail=true).
const showLabels = computed(() => mobile.value || !rail.value);

// Ngày 44: bấm 1 mục menu trên mobile thì tự đóng overlay lại — giống mọi
// app di động khác, tránh phải bấm ra ngoài (scrim) hoặc bấm lại hamburger.
const route = useRoute();
watch(
    () => route.fullPath,
    () => {
        if (mobile.value) {
            open.value = false;
        }
    },
);

const auth = useAuthStore();
// Chỉ ẩn/hiện mục menu — lớp UX phụ. Quyền thật vẫn do backend enforce qua
// middleware permission:xxx (mỗi route xem CODE_MAP), route guard ở
// router/index.js mới là lớp chặn thật khi gõ tay URL.
function can(code) {
    return auth.permissions.includes(code);
}
</script>
