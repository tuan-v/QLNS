import { createRouter, createWebHistory } from "vue-router";
import { useLoadingStore } from "../stores/useLoadingStore";
import { useAuthStore } from "../stores/authStore";

const routes = [
    {
        path: "/login",
        name: "login",
        component: () => import("../views/Login.vue"),
        meta: { layout: "blank" },
    },
    {
        path: "/forgot-password",
        name: "forgot-password",
        component: () => import("../views/ForgotPassword.vue"),
        meta: { layout: "blank" },
    },
    {
        path: "/reset-password",
        name: "reset-password",
        component: () => import("../views/ResetPassword.vue"),
        meta: { layout: "blank" },
    },
    {
        path: "/",
        name: "dashboard",
        meta: { title: "Tổng quan" },
        component: () => import("../views/Dashboard.vue"),
    },
    {
        path: "/my-profile",
        name: "my-profile",
        meta: { title: "Hồ sơ của tôi" },
        component: () => import("../views/Me/MyProfile.vue"),
    },
    {
        path: "/departments",
        name: "departments",
        // permission: mã quyền bắt buộc để vào route này — thiếu thì router
        // guard bên dưới tự đá về Dashboard, không cần khai riêng chỗ khác.
        meta: { title: "Phòng ban", permission: "department.view" },
        component: () => import("../views/Department/Departments.vue"),
    },
    {
        path: "/positions",
        name: "positions",
        // Chức vụ dùng chung mã quyền với Phòng ban (xem CODE_MAP mục 7) —
        // không tách quyền riêng, guard route cũng theo đúng quy ước đó.
        meta: { title: "Chức vụ", permission: "department.view" },
        component: () => import("../views/Position/Positions.vue"),
    },
    {
        path: "/employees",
        name: "employees",
        meta: { title: "Nhân viên", permission: "employee.view" },
        component: () => import("../views/Employee/Employees.vue"),
    },
    {
        path: "/employees/:id",
        name: "employee-detail",
        meta: {
            title: "Chi tiết nhân viên",
            parent: "employees",
            permission: "employee.view",
        },
        component: () => import("../views/Employee/EmployeeDetail.vue"),
        props: true,
    },
    {
        path: "/work-shifts",
        name: "work-shifts",
        meta: { title: "Ca làm việc", permission: "shift.view" },
        component: () => import("../views/WorkShift/WorkShifts.vue"),
    },
    {
        path: "/attendance-locations",
        name: "attendance-locations",
        meta: { title: "Điểm chấm công", permission: "location.view" },
        component: () =>
            import("../views/AttendanceLocation/AttendanceLocations.vue"),
    },
    {
        path: "/check-in",
        name: "check-in",
        meta: { title: "Chấm công", permission: "attendance.check" },
        component: () => import("../views/Attendance/CheckIn.vue"),
    },
    {
        path: "/attendance-adjustments",
        name: "attendance-adjustments",
        meta: {
            title: "Duyệt điều chỉnh công",
            permission: "attendance.adjust",
        },
        component: () =>
            import("../views/Attendance/AttendanceAdjustments.vue"),
    },
    {
        path: "/attendance-history",
        name: "attendance-history",
        meta: { title: "Lịch sử chấm công", permission: "attendance.view_own" },
        component: () => import("../views/Attendance/AttendanceHistory.vue"),
    },
    {
        path: "/leave-requests",
        name: "leave-requests",
        meta: { title: "Nghỉ phép", permission: "leave.request" },
        component: () => import("../views/Leave/LeaveRequests.vue"),
    },
    {
        path: "/leave-management",
        name: "leave-management",
        meta: { title: "Duyệt nghỉ phép", permission: "leave.view_all" },
        component: () => import("../views/Leave/LeaveManagement.vue"),
    },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

// Trang được lazy-load nên lần đầu vào một route phải tải chunk JS về —
// thanh loading cho người dùng biết app đang chạy chứ không phải bị treo.
router.beforeEach(() => {
    useLoadingStore().start();
});

// Chặn vào thẳng URL của trang không có quyền (menu ở AppSidebar.vue đã tự ẩn
// mục đó, nhưng gõ tay URL vẫn phải chặn) — chỉ là lớp UX phụ, quyền thật vẫn
// do backend enforce qua middleware permission:xxx, route guard này không
// thay được lớp đó. Phải đợi auth.user nạp xong (F5 trang chỉ có access_token
// trong localStorage, chưa có permissions) rồi mới kiểm tra, nếu không sẽ đá
// nhầm người dùng hợp lệ vì permissions đang rỗng lúc mới tải trang.
router.beforeEach(async (to) => {
    const auth = useAuthStore();

    if (auth.accessToken && !auth.user) {
        try {
            await auth.fetchMe();
        } catch {
            // Token hết hạn/không hợp lệ — để nguyên, interceptor của
            // bootstrap.js sẽ tự xử lý 401 ở lần gọi API thật tiếp theo.
        }
    }

    const requiredPermission = to.meta.permission;
    if (requiredPermission && !auth.permissions.includes(requiredPermission)) {
        return { name: "dashboard" };
    }
});

router.afterEach(() => {
    useLoadingStore().stop();
});

// Tải chunk lỗi (mất mạng, vừa deploy bản mới) thì afterEach KHÔNG chạy, phải
// tự tắt ở đây nếu không thanh loading sẽ quay mãi.
router.onError(() => {
    useLoadingStore().stop();
});

export default router;
