<script setup>
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from "vue";
import { useRouter } from "vue-router";
import { useAuthStore } from "../../stores/authStore";
import searchService from "../../services/searchService";

// Danh sách trang cố định (Phase 1 — CHỨC NĂNG, không gọi API, xem CODE_MAP
// mục 38). Cố tình KHÔNG tự sinh từ router.getRoutes() — router chỉ có
// title/permission, không có icon lẫn từ khóa phụ (tiếng Anh/không dấu),
// phải khai tay giống hệt cách AppSidebar.vue đang liệt kê menu (title/icon/
// permission lặp lại ở đó, đây chỉ thêm 1 nơi thứ 3 theo ĐÚNG cùng quy ước,
// không phải mẫu mới). `employee-detail`/`payroll-detail` cần id cụ
// thể — không nằm trong danh sách trang tĩnh, tìm được qua Phase 2 bên dưới
// (nhân viên) hoặc gõ đúng trang danh sách trước.
const ALL_ITEMS = [
    {
        name: "dashboard",
        title: "Tổng quan",
        icon: "mdi-view-dashboard-outline",
        permission: null,
        keywords: ["dashboard", "trang chu", "tong quan"],
    },
    {
        name: "my-profile",
        title: "Hồ sơ của tôi",
        icon: "mdi-account-circle-outline",
        permission: null,
        keywords: ["profile", "ho so"],
    },
    {
        name: "notifications",
        title: "Thông báo",
        icon: "mdi-bell-outline",
        permission: null,
        keywords: ["notification", "thong bao"],
    },
    {
        name: "employees",
        title: "Nhân viên",
        icon: "mdi-account-group-outline",
        permission: "employee.view",
        keywords: ["employee", "nhan vien", "quan ly nhan vien"],
    },
    {
        name: "departments",
        title: "Phòng ban",
        icon: "mdi-office-building-outline",
        permission: "department.view",
        keywords: ["department", "phong ban"],
    },
    {
        name: "positions",
        title: "Chức vụ",
        icon: "mdi-badge-account-outline",
        permission: "department.view",
        keywords: ["position", "chuc vu", "chuc danh"],
    },
    {
        name: "resignations",
        title: "Đơn nghỉ việc",
        icon: "mdi-account-arrow-right-outline",
        permission: "resignation.approve",
        keywords: ["resignation", "nghi viec", "don nghi viec", "thoi viec"],
    },
    {
        name: "recruitment",
        title: "Tuyển dụng",
        icon: "mdi-briefcase-search-outline",
        permission: "recruitment.manage",
        keywords: ["recruitment", "tuyen dung", "ung vien", "cv", "phong van"],
    },
    {
        name: "onboarding",
        title: "Onboarding / Offboarding",
        icon: "mdi-clipboard-check-multiple-outline",
        permission: ["onboarding.manage", "onboarding.team"],
        keywords: ["onboarding", "offboarding", "nhan viec", "ban giao", "checklist", "hoi nhap"],
    },
    {
        name: "holidays",
        title: "Ngày nghỉ lễ",
        icon: "mdi-calendar-star",
        keywords: ["holiday", "ngay le", "nghi le", "tet", "am lich", "lich nghi"],
    },
    {
        name: "work-shifts",
        title: "Ca làm việc",
        icon: "mdi-timetable",
        permission: "shift.view",
        keywords: ["shift", "ca lam viec"],
    },
    {
        name: "check-in",
        title: "Chấm công",
        icon: "mdi-calendar-check-outline",
        permission: "attendance.check",
        keywords: ["attendance", "check in", "cham cong"],
    },
    {
        name: "attendance-overview",
        title: "Tổng hợp chấm công",
        icon: "mdi-clipboard-check-outline",
        permission: "attendance.view_all",
        keywords: ["attendance", "tong hop cham cong", "duyet cham cong"],
    },
    {
        name: "attendance-history",
        title: "Lịch sử chấm công",
        icon: "mdi-history",
        permission: "attendance.view_own",
        keywords: ["attendance", "lich su cham cong"],
    },
    {
        name: "attendance-adjustments",
        title: "Duyệt điều chỉnh công",
        icon: "mdi-file-clock-outline",
        permission: "attendance.adjust",
        keywords: ["dieu chinh cong", "overtime", "ot"],
    },
    {
        name: "leave-requests",
        title: "Nghỉ phép",
        icon: "mdi-calendar-blank-outline",
        permission: "leave.request",
        keywords: ["leave", "nghi phep", "don nghi phep"],
    },
    {
        name: "leave-management",
        title: "Duyệt nghỉ phép",
        icon: "mdi-calendar-check-outline",
        permission: "leave.view_all",
        keywords: ["leave", "duyet nghi phep"],
    },
    {
        name: "leave-overview",
        title: "Tổng hợp nghỉ phép",
        icon: "mdi-calendar-month-outline",
        permission: "leave.view_all",
        keywords: ["leave", "tong hop nghi phep", "quy phep", "ngay phep con lai"],
    },
    {
        name: "payrolls",
        title: "Bảng lương",
        icon: "mdi-cash-multiple",
        permission: "payroll.view_all",
        keywords: ["payroll", "luong", "bang luong", "tinh luong"],
    },
    {
        name: "settings",
        title: "Cài đặt hệ thống",
        icon: "mdi-cog-outline",
        permission: "shift.manage",
        keywords: ["setting", "cai dat"],
    },
    {
        name: "roles",
        title: "Vai trò & Phân quyền",
        icon: "mdi-shield-account-outline",
        permission: "rbac.manage",
        keywords: ["role", "permission", "phan quyen", "vai tro"],
    },
];

// Lệnh (thao tác nhanh): mở đúng trang VÀ tự mở luôn dialog/bộ lọc cần dùng qua
// `?action=` — trang đích xử lý bằng useRouteAction(). Quyền = đúng quyền của thao tác.
const COMMANDS = [
    { name: "leave-requests", action: "create", title: "Tạo đơn xin nghỉ phép", icon: "mdi-calendar-plus", permission: "leave.request", keywords: ["xin nghi", "don nghi", "nghi phep", "leave"] },
    { name: "check-in", action: "supplement", title: "Xin bổ sung chấm công", icon: "mdi-clock-plus-outline", permission: "attendance.check", keywords: ["bo sung", "quen cham cong", "supplement"] },
    { name: "check-in", action: "ot", title: "Xin OT", icon: "mdi-clock-fast", permission: "attendance.check", keywords: ["ot", "tang ca", "lam them"] },
    { name: "check-in", action: "extra-shift", title: "Xin làm ngoài lịch", icon: "mdi-calendar-clock", permission: "attendance.check", keywords: ["ngoai lich", "lam bu", "extra"] },
    { name: "attendance-overview", action: "pending", title: "Duyệt chấm công hôm nay", icon: "mdi-clipboard-check-outline", permission: "attendance.approve", keywords: ["duyet cham cong", "cho duyet", "approve"] },
    { name: "attendance-overview", action: "export", title: "Xuất bảng chấm công (Excel)", icon: "mdi-microsoft-excel", permission: "attendance.view_all", keywords: ["xuat", "excel", "bang cong", "export"] },
    { name: "employees", action: "create", title: "Thêm nhân viên", icon: "mdi-account-plus-outline", permission: "employee.create", keywords: ["them nhan vien", "tao ho so", "new employee"] },
    { name: "payrolls", action: "generate", title: "Tạo bảng lương", icon: "mdi-cash-plus", permission: "payroll.manage", keywords: ["tinh luong", "bang luong", "payroll"] },
    { name: "recruitment", action: "create", title: "Mở đợt tuyển dụng", icon: "mdi-briefcase-plus-outline", permission: "recruitment.manage", keywords: ["tuyen dung", "dot tuyen", "recruit"] },
    { name: "onboarding", action: "create", title: "Tạo checklist nhận việc / nghỉ việc", icon: "mdi-clipboard-plus-outline", permission: "onboarding.manage", keywords: ["onboarding", "offboarding", "checklist", "nhan viec", "ban giao"] },
    { name: "holidays", action: "create", title: "Thêm ngày nghỉ của công ty", icon: "mdi-calendar-star", permission: "holiday.manage", keywords: ["ngay nghi", "nghi le", "holiday"] },
];

const RECENT_KEY = "qlns_quick_search_recent";
const RECENT_LIMIT = 6;
const MIN_QUERY_LENGTH = 2;
const EMPLOYEE_SEARCH_DEBOUNCE_MS = 400;

// localStorage có thể bị chặn (chế độ ẩn danh/site data bị tắt) — bọc try/catch
// để lỗi đọc/ghi "Gần đây" không bao giờ làm hỏng chức năng tìm kiếm chính.
// Lưu NGUYÊN object nav-item (không chỉ tên route) — cho phép "Gần đây" nhớ
// được CẢ trang lẫn nhân viên vừa xem (2026-09-29, Phase 2).
function loadRecentItems() {
    try {
        const raw = localStorage.getItem(RECENT_KEY);
        const parsed = raw ? JSON.parse(raw) : [];

        // Bản cũ của tính năng này (trước Phase 2) lưu KIỂU KHÁC (vd chỉ tên route) —
        // giữ lại thì "Gần đây" hiện ra 1 dòng TRỐNG không có tiêu đề. Chỉ nhận mục
        // đủ hình dạng nav-item hiện tại; mục hỏng bị bỏ và ghi đè lại bản sạch.
        const valid = Array.isArray(parsed)
            ? parsed.filter(
                  (item) =>
                      item &&
                      typeof item === "object" &&
                      item.key &&
                      item.title &&
                      item.routeName,
              )
            : [];

        if (Array.isArray(parsed) && valid.length !== parsed.length) {
            localStorage.setItem(RECENT_KEY, JSON.stringify(valid));
        }

        return valid;
    } catch {
        return [];
    }
}

// Bỏ dấu tiếng Việt + hạ chữ thường — cho phép gõ không dấu ("cham cong")
// vẫn khớp tiêu đề có dấu ("Chấm công"), thói quen gõ phổ biến của người Việt.
function normalize(str) {
    return str
        .toLowerCase()
        .normalize("NFD")
        .replace(/\p{Diacritic}/gu, "")
        .replace(/đ/g, "d");
}

const router = useRouter();
const auth = useAuthStore();

const dialogOpen = ref(false);
const query = ref("");
const activeIndex = ref(0);
const inputRef = ref(null);
const recentItems = ref(loadRecentItems());

const canSearchEmployees = computed(() =>
    auth.permissions.includes("employee.view"),
);

// Chuẩn hóa 16 trang tĩnh về CHUNG 1 hình dạng nav-item với kết quả nhân
// viên từ API (kind/key/title/description/icon/routeName/routeParams) —
// select()/lưu "Gần đây" chỉ cần viết 1 lần, dùng chung cho cả 2 nguồn.
const availablePages = computed(() =>
    ALL_ITEMS.filter(
        (item) =>
            !item.permission || [].concat(item.permission).some((code) => auth.permissions.includes(code)),
    ).map((item) => ({
        kind: "page",
        key: `page:${item.name}`,
        title: item.title,
        description: "",
        icon: item.icon,
        keywords: item.keywords,
        routeName: item.name,
        routeParams: {},
    })),
);

const availableCommands = computed(() =>
    COMMANDS.filter((c) => !c.permission || auth.permissions.includes(c.permission)).map((c) => ({
        kind: "command",
        key: `cmd:${c.name}:${c.action}`,
        title: c.title,
        description: "",
        icon: c.icon,
        keywords: c.keywords,
        routeName: c.name,
        routeParams: {},
        routeQuery: { action: c.action },
    })),
);

// Khớp theo từng từ: "xuat excel" khớp khi MỌI từ đều có trong tiêu đề hoặc từ khóa
// (không bắt buộc cả cụm phải đứng liền nhau).
function matchesQuery(item, q) {
    const haystack = [item.title, ...item.keywords].map(normalize).join(" ");
    return q.split(/\s+/).every((word) => haystack.includes(word));
}

const commandMatches = computed(() => {
    const q = normalize(query.value.trim());
    if (!q) {
        return [];
    }
    return availableCommands.value.filter((item) => matchesQuery(item, q));
});

const pageMatches = computed(() => {
    const q = normalize(query.value.trim());
    if (!q) {
        return [];
    }
    return availablePages.value.filter((item) => matchesQuery(item, q));
});

// --- Phase 2 (2026-09-29, theo yêu cầu người dùng): tìm NHÂN VIÊN theo dữ
// liệu thật qua GET /api/v1/search — debounce 400ms (không gọi API theo
// từng lần gõ phím), Backend tự lọc theo quyền employee.view (xem
// app/Services/SearchService.php) nên KHÔNG cần kiểm tra quyền gì thêm với
// dữ liệu trả về, chỉ cần tự ẩn hẳn Ô GÕ/tiêu đề nhóm khi user chắc chắn
// không có quyền (đỡ gọi API vô ích).
const employeeResults = ref([]);
const searchingEmployees = ref(false);
let debounceTimer = null;
let searchToken = 0;

async function runEmployeeSearch(q) {
    const token = ++searchToken;
    searchingEmployees.value = true;
    try {
        const response = await searchService.search(q);
        // Bỏ qua nếu người dùng đã gõ tiếp/đổi query trong lúc chờ — tránh
        // kết quả CŨ trả về SAU đè lên kết quả MỚI hơn (race condition mạng,
        // request gửi trước có thể phản hồi SAU request gửi sau).
        if (token !== searchToken) {
            return;
        }
        employeeResults.value = response.data.data.map((r) => ({
            kind: "employee",
            key: `employee:${r.id}`,
            title: r.title,
            description: r.description,
            icon: "mdi-account-outline",
            routeName: r.route,
            routeParams: r.route_params,
        }));
    } catch {
        if (token === searchToken) {
            employeeResults.value = [];
        }
    } finally {
        if (token === searchToken) {
            searchingEmployees.value = false;
        }
    }
}

watch(query, (value) => {
    activeIndex.value = 0;
    clearTimeout(debounceTimer);
    const q = value.trim();
    if (!canSearchEmployees.value || q.length < MIN_QUERY_LENGTH) {
        searchToken++; // Vô hiệu hóa request đang bay — tránh áp nhầm vào query rỗng/quá ngắn.
        employeeResults.value = [];
        searchingEmployees.value = false;
        return;
    }
    debounceTimer = setTimeout(
        () => runEmployeeSearch(q),
        EMPLOYEE_SEARCH_DEBOUNCE_MS,
    );
});

watch(dialogOpen, async (value) => {
    if (value) {
        query.value = "";
        activeIndex.value = 0;
        employeeResults.value = [];
        await nextTick();
        inputRef.value?.focus?.();
    } else {
        clearTimeout(debounceTimer);
    }
});

const showEmployeeLoading = computed(
    () =>
        canSearchEmployees.value &&
        searchingEmployees.value &&
        query.value.trim().length >= MIN_QUERY_LENGTH,
);

// Danh sách phẳng dùng CHUNG cho cả hiển thị lẫn điều hướng bàn phím (Lên/
// Xuống/Enter) — mỗi phần tử tự mang `sectionLabel` để template tự chèn tiêu
// đề nhóm khi nhóm đổi, không cần dựng cấu trúc lồng nhau phức tạp.
const flatItems = computed(() => {
    if (query.value.trim() === "") {
        return [
            ...recentItems.value.map((item) => ({
                ...item,
                sectionLabel: "Gần đây",
            })),
            ...availableCommands.value.map((item) => ({
                ...item,
                sectionLabel: "Thao tác nhanh",
            })),
            ...availablePages.value.map((item) => ({
                ...item,
                sectionLabel: "Truy cập nhanh",
            })),
        ];
    }

    const items = [
        ...commandMatches.value.map((item) => ({ ...item, sectionLabel: "Thao tác" })),
        ...pageMatches.value.map((item) => ({ ...item, sectionLabel: "Chức năng" })),
    ];

    if (
        canSearchEmployees.value &&
        query.value.trim().length >= MIN_QUERY_LENGTH
    ) {
        items.push(
            ...employeeResults.value.map((item) => ({
                ...item,
                sectionLabel: "Nhân viên",
            })),
        );
    }

    return items;
});

function moveActive(delta) {
    if (flatItems.value.length === 0) {
        return;
    }
    activeIndex.value =
        (activeIndex.value + delta + flatItems.value.length) %
        flatItems.value.length;
}

function pushRecent(item) {
    try {
        // Bỏ `sectionLabel` (chỉ để hiển thị theo NGỮ CẢNH lúc chọn, vô nghĩa
        // khi lưu lại — lần mở sau flatItems tự gắn lại đúng nhóm "Gần đây").
        const { sectionLabel: _sectionLabel, ...clean } = item;
        const items = [
            clean,
            ...loadRecentItems().filter((i) => i.key !== clean.key),
        ].slice(0, RECENT_LIMIT);
        localStorage.setItem(RECENT_KEY, JSON.stringify(items));
        recentItems.value = items;
    } catch {
        // Bỏ qua — xem chú thích ở loadRecentItems().
    }
}

function select(item) {
    pushRecent(item);
    dialogOpen.value = false;
    router.push({ name: item.routeName, params: item.routeParams ?? {}, query: item.routeQuery ?? {} });
}

function selectActive() {
    const item = flatItems.value[activeIndex.value];
    if (item) {
        select(item);
    }
}

// Ctrl+K (Windows/Linux) hoặc Cmd+K (Mac) mở tìm kiếm từ BẤT KỲ đâu trong
// app, giống VSCode/Slack/Linear — đăng ký ở window vì header luôn hiện
// suốt vòng đời layout đã đăng nhập (AppLayout.vue).
function handleGlobalKeydown(e) {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "k") {
        e.preventDefault();
        dialogOpen.value = true;
    }
}

onMounted(() => window.addEventListener("keydown", handleGlobalKeydown));
onBeforeUnmount(() => {
    window.removeEventListener("keydown", handleGlobalKeydown);
    clearTimeout(debounceTimer);
});

defineExpose({
    open: () => {
        dialogOpen.value = true;
    },
});
</script>

<template>
    <v-dialog v-model="dialogOpen" max-width="640" scrollable>
        <v-card rounded="lg">
            <v-text-field
                ref="inputRef"
                v-model="query"
                hide-details
                variant="plain"
                density="comfortable"
                placeholder="Tìm nhân viên, chức năng hoặc thao tác (vd: xin nghỉ, xuất excel)..."
                prepend-inner-icon="mdi-magnify"
                class="px-4 pt-3"
                @keydown.down.prevent="moveActive(1)"
                @keydown.up.prevent="moveActive(-1)"
                @keydown.enter.prevent="selectActive"
                @keydown.esc="dialogOpen = false"
            />
            <v-divider />

            <div style="max-height: 360px; overflow-y: auto">
                <v-list nav density="compact" class="px-2 py-2">
                    <template
                        v-for="(item, index) in flatItems"
                        :key="`${item.sectionLabel}-${item.key}`"
                    >
                        <v-list-subheader
                            v-if="
                                index === 0 ||
                                flatItems[index - 1].sectionLabel !==
                                    item.sectionLabel
                            "
                        >
                            {{ item.sectionLabel }}
                        </v-list-subheader>
                        <v-list-item
                            :active="index === activeIndex"
                            rounded="lg"
                            @click="select(item)"
                            @mouseenter="activeIndex = index"
                        >
                            <template #prepend>
                                <v-icon :icon="item.icon" size="20" />
                            </template>
                            <v-list-item-title>{{
                                item.title
                            }}</v-list-item-title>
                            <v-list-item-subtitle v-if="item.description">{{
                                item.description
                            }}</v-list-item-subtitle>
                        </v-list-item>
                    </template>
                </v-list>

                <div
                    v-if="showEmployeeLoading && employeeResults.length === 0"
                    class="px-4 py-3 text-caption text-medium-emphasis d-flex align-center ga-2"
                >
                    <v-progress-circular indeterminate size="14" width="2" />
                    Đang tìm nhân viên...
                </div>

                <div
                    v-if="flatItems.length === 0 && !showEmployeeLoading"
                    class="text-center py-8 text-medium-emphasis"
                >
                    Không tìm thấy kết quả phù hợp.
                </div>
            </div>

            <v-divider />
            <div
                class="d-flex ga-4 px-4 py-2 text-caption text-medium-emphasis"
            >
                <span>↑↓ Di chuyển</span>
                <span>Enter Mở</span>
                <span>Esc Đóng</span>
            </div>
        </v-card>
    </v-dialog>
</template>
