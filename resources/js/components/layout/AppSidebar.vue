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
                <div class="text-subtitle-1 font-weight-bold text-no-wrap" style="color: rgb(var(--v-theme-ink))">
                    QLNS
                </div>
                <div class="text-caption text-no-wrap text-medium-emphasis" style="line-height: 1.2">
                    Quản lý Nhân sự
                </div>
            </div>
        </div>

        <!-- 2026-10-13: menu gom NHÓM đóng/mở (dropdown) cho gọn — xem `menu` bên dưới.
             Thu gọn (rail): mỗi nhóm còn 1 icon, rê chuột/bấm hiện menu con bên phải. -->
        <v-list
            :opened="opened"
            open-strategy="multiple"
            nav
            density="compact"
            class="px-4 pt-2"
            @update:opened="onOpened"
        >
            <template v-for="entry in visibleMenu" :key="entry.key">
                <v-list-item v-if="!entry.children" v-bind="itemProps(entry)" />

                <v-menu v-else-if="!showLabels" location="end" open-on-hover :open-delay="80" offset="8">
                    <template #activator="{ props: menuProps }">
                        <v-list-item
                            v-bind="menuProps"
                            :prepend-icon="entry.icon"
                            :active="groupActive(entry)"
                            color="primary"
                            rounded="lg"
                        />
                    </template>
                    <v-list nav density="compact" min-width="230">
                        <v-list-subheader>{{ entry.title }}</v-list-subheader>
                        <v-list-item
                            v-for="child in entry.children"
                            :key="child.key"
                            v-bind="itemProps(child)"
                            :title="child.title"
                        />
                    </v-list>
                </v-menu>

                <v-list-group v-else :value="entry.key">
                    <template #activator="{ props: groupProps }">
                        <v-list-item
                            v-bind="groupProps"
                            :prepend-icon="entry.icon"
                            :title="entry.title"
                            :class="{
                                'qlns-group--active': groupActive(entry),
                            }"
                            rounded="lg"
                        />
                    </template>
                    <v-list-item v-for="child in entry.children" :key="child.key" v-bind="itemProps(child)" />
                </v-list-group>
            </template>
        </v-list>

        <template #append>
            <v-divider class="mb-1" />
            <v-list nav density="compact" class="px-4 pb-2">
                <v-list-item prepend-icon="mdi-help-circle-outline" title="Hướng dẫn sử dụng" rounded="lg" disabled />
            </v-list>
        </template>
    </v-navigation-drawer>
</template>

<script setup>
import { computed, watch } from "vue";
import { useDisplay } from "vuetify";
import { useRoute } from "vue-router";
import { useAuthStore } from "../../stores/authStore";
import { useRememberedRef } from "../../composables/useRememberedRef";

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
function can(...codes) {
    return codes.some((code) => auth.permissions.includes(code));
}

// Cấu hình menu: mục lẻ (to) hoặc nhóm (children). `show` = điều kiện quyền (bỏ trống = ai
// cũng thấy); `also` = đường dẫn khác cũng tính là thuộc mục này (để tự mở đúng nhóm).
// Nhóm không còn mục con nào thì ẩn; còn đúng 1 mục thì hiện thẳng mục đó, khỏi phải mở nhóm.
const menu = [
    { key: "dashboard", icon: "mdi-view-dashboard-outline", title: "Tổng quan", to: "/" },
    // Luôn hiện — xem hồ sơ CHÍNH MÌNH không phụ thuộc mã quyền nào, xem MyProfile.vue.
    { key: "profile", icon: "mdi-account-circle-outline", title: "Hồ sơ của tôi", to: "/ho-so-cua-toi" },
    { key: "notifications", icon: "mdi-bell-outline", title: "Thông báo", to: "/thong-bao" },
    {
        key: "hr",
        icon: "mdi-account-group-outline",
        title: "Nhân sự",
        children: [
            {
                key: "employees",
                icon: "mdi-account-multiple-outline",
                title: "Nhân viên",
                to: "/nhan-vien",
                show: () => can("employee.view"),
            },
            {
                key: "departments",
                icon: "mdi-office-building-outline",
                title: "Phòng ban",
                to: "/phong-ban",
                show: () => can("department.view"),
            },
            {
                key: "positions",
                icon: "mdi-badge-account-outline",
                title: "Chức vụ",
                to: "/chuc-vu",
                show: () => can("department.view"),
            },
            {
                key: "resignations",
                icon: "mdi-account-arrow-right-outline",
                title: "Đơn nghỉ việc",
                to: "/don-nghi-viec",
                show: () => can("resignation.approve"),
            },
        ],
    },
    {
        key: "recruitment",
        icon: "mdi-briefcase-search-outline",
        title: "Tuyển dụng",
        children: [
            {
                key: "openings",
                icon: "mdi-briefcase-outline",
                title: "Đợt tuyển dụng",
                to: "/tuyen-dung",
                show: () => can("recruitment.manage"),
            },
            {
                key: "interviews",
                icon: "mdi-calendar-account-outline",
                title: "Lịch phỏng vấn",
                to: "/lich-phong-van",
                show: () => can("recruitment.manage", "recruitment.approve"),
            },
            {
                key: "question-sets",
                icon: "mdi-clipboard-text-outline",
                title: "Bộ câu hỏi phỏng vấn",
                to: "/bo-cau-hoi-phong-van",
                show: () => can("recruitment.manage", "recruitment.approve"),
            },
            {
                key: "recruitment-report",
                icon: "mdi-chart-box-outline",
                title: "Báo cáo tuyển dụng",
                to: "/bao-cao-tuyen-dung",
                show: () => can("recruitment.manage", "recruitment.approve"),
            },
        ],
    },
    {
        key: "attendance",
        icon: "mdi-calendar-check-outline",
        title: "Chấm công",
        children: [
            {
                key: "check",
                icon: "mdi-fingerprint",
                title: "Chấm công",
                to: "/cham-cong",
                also: ["/lich-su-cham-cong"],
                show: () => can("attendance.check"),
            },
            {
                key: "attendance-overview",
                icon: "mdi-clipboard-check-outline",
                title: "Tổng hợp chấm công",
                to: "/tong-hop-cham-cong",
                show: () => can("attendance.view_all"),
            },
            {
                key: "adjustments",
                icon: "mdi-file-clock-outline",
                title: "Duyệt điều chỉnh công",
                to: "/duyet-dieu-chinh-cong",
                show: () => can("attendance.adjust"),
            },
        ],
    },
    {
        key: "leave",
        icon: "mdi-calendar-blank-outline",
        title: "Nghỉ phép",
        children: [
            {
                key: "my-leave",
                icon: "mdi-calendar-edit-outline",
                title: "Đơn nghỉ phép",
                to: "/nghi-phep",
                show: () => can("leave.request"),
            },
            {
                key: "leave-approval",
                icon: "mdi-calendar-check-outline",
                title: "Duyệt nghỉ phép",
                to: "/duyet-nghi-phep",
                show: () => can("leave.view_all"),
            },
            {
                key: "leave-overview",
                icon: "mdi-calendar-month-outline",
                title: "Tổng hợp nghỉ phép",
                to: "/tong-hop-nghi-phep",
                show: () => can("leave.view_all"),
            },
            { key: "holidays", icon: "mdi-calendar-star", title: "Ngày nghỉ lễ", to: "/ngay-nghi-le" },
        ],
    },
    {
        key: "payroll",
        icon: "mdi-cash-multiple",
        title: "Lương & Báo cáo",
        children: [
            {
                key: "payrolls",
                icon: "mdi-cash-multiple",
                title: "Bảng lương",
                to: "/bang-luong",
                show: () => can("payroll.view_all"),
            },
            { key: "reports", icon: "mdi-chart-bar", title: "Báo cáo", disabled: true },
        ],
    },
    {
        key: "system",
        icon: "mdi-cog-outline",
        title: "Hệ thống",
        children: [
            {
                key: "settings",
                icon: "mdi-tune-variant",
                title: "Cài đặt",
                to: "/cai-dat-he-thong",
                show: () => can("shift.manage"),
            },
            {
                key: "rbac",
                icon: "mdi-shield-account-outline",
                title: "Vai trò & Phân quyền",
                to: "/vai-tro-va-phan-quyen",
                show: () => can("rbac.manage"),
            },
            { key: "activity-log", icon: "mdi-history", title: "Nhật ký hoạt động", disabled: true },
        ],
    },
];

const visibleMenu = computed(() =>
    menu
        .map((entry) => {
            if (!entry.children) return entry;
            const children = entry.children.filter((c) => !c.show || c.show());
            // Mục "đang phát triển" (disabled) không đủ để giữ 1 nhóm.
            if (!children.some((c) => !c.disabled)) return null;
            return children.length === 1 ? children[0] : { ...entry, children };
        })
        .filter(Boolean),
);

function itemProps(item) {
    return {
        prependIcon: item.icon,
        // Thu gọn (rail) chỉ còn icon — bỏ nhãn kẻo hiện chữ bị cắt "T…".
        title: showLabels.value ? item.title : undefined,
        to: item.to,
        disabled: item.disabled,
        rounded: "lg",
    };
}

function matches(item, path) {
    return [item.to, ...(item.also ?? [])].some((to) => to && to !== "/" && (path === to || path.startsWith(to + "/")));
}

function groupActive(entry) {
    return entry.children.some((c) => matches(c, route.path));
}

// Nhóm đang mở — nhớ theo từng người dùng; mặc định đóng hết.
const opened = useRememberedRef("sidebar.opened", []);

// Vuetify tự bỏ khỏi "opened" các nhóm CHƯA hiển thị (vd lúc F5, quyền chưa tải xong) —
// chỉ nhận thay đổi của nhóm đang hiện, giữ nguyên trạng thái đã nhớ của nhóm còn lại.
function onOpened(next) {
    const shown = visibleMenu.value.filter((e) => e.children).map((e) => e.key);
    opened.value = [...new Set([...opened.value.filter((k) => !shown.includes(k)), ...next])];
}

// Vào trang thuộc nhóm nào (kể cả từ thông báo, gõ URL) thì tự mở nhóm đó.
// Theo dõi cả người dùng/quyền: lúc F5, tài khoản + quyền tải xong SAU khi sidebar dựng.
watch(
    [() => route.path, () => auth.user?.id, visibleMenu],
    () => {
        const group = visibleMenu.value.find((e) => e.children && groupActive(e));
        if (group && !opened.value.includes(group.key)) {
            opened.value = [...opened.value, group.key];
        }
    },
    { immediate: true },
);
</script>

<style scoped>
/* Mục con chỉ thụt nhẹ so với tiêu đề nhóm (mặc định Vuetify thụt quá sâu). */
.qlns-sidebar :deep(.v-list-group__items .v-list-item) {
    padding-inline-start: 24px !important;
}
.qlns-group--active :deep(.v-list-item__prepend .v-icon),
.qlns-group--active :deep(.v-list-item-title) {
    color: rgb(var(--v-theme-primary));
}
</style>
