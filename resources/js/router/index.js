import { createRouter, createWebHistory } from "vue-router";
import { useLoadingStore } from "../stores/useLoadingStore";
import { useAuthStore } from "../stores/authStore";

const routes = [
    {
        path: "/dang-nhap",
        name: "login",
        component: () => import("../views/Login.vue"),
        meta: { layout: "blank" },
    },
    {
        path: "/quen-mat-khau",
        name: "forgot-password",
        component: () => import("../views/ForgotPassword.vue"),
        meta: { layout: "blank" },
    },
    {
        path: "/dat-lai-mat-khau/:token",
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
        path: "/ho-so-cua-toi",
        name: "my-profile",
        meta: { title: "Hồ sơ của tôi" },
        component: () => import("../views/Me/MyProfile.vue"),
    },
    {
        path: "/thong-bao",
        name: "notifications",
        // Không cần permission riêng — ai đăng nhập cũng xem được thông báo
        // của mình. Tab "Toàn công ty" tự ẩn/hiện trong trang theo
        // notification.view_all (xem Notifications.vue).
        meta: { title: "Thông báo" },
        component: () => import("../views/Notification/Notifications.vue"),
    },
    {
        path: "/phong-ban",
        name: "departments",
        // permission: mã quyền bắt buộc để vào route này — thiếu thì router
        // guard bên dưới tự đá về Dashboard, không cần khai riêng chỗ khác.
        meta: { title: "Phòng ban", permission: "department.view" },
        component: () => import("../views/Department/Departments.vue"),
    },
    {
        path: "/chuc-vu",
        name: "positions",
        // Chức vụ dùng chung mã quyền với Phòng ban (xem CODE_MAP mục 7) —
        // không tách quyền riêng, guard route cũng theo đúng quy ước đó.
        meta: { title: "Chức vụ", permission: "department.view" },
        component: () => import("../views/Position/Positions.vue"),
    },
    {
        path: "/vai-tro-va-phan-quyen",
        name: "roles",
        meta: { title: "Vai trò & Phân quyền", permission: "rbac.manage" },
        component: () => import("../views/Role/Roles.vue"),
    },
    {
        path: "/bang-luong",
        name: "payrolls",
        meta: { title: "Bảng lương", permission: "payroll.view_all" },
        component: () => import("../views/Payroll/PayrollList.vue"),
    },
    {
        path: "/bang-luong/:id",
        name: "payroll-detail",
        meta: {
            title: "Chi tiết bảng lương",
            parent: "payrolls",
            permission: "payroll.view_all",
        },
        component: () => import("../views/Payroll/PayrollDetail.vue"),
    },

    {
        path: "/nhan-vien",
        name: "employees",
        meta: { title: "Nhân viên", permission: "employee.view" },
        component: () => import("../views/Employee/Employees.vue"),
    },
    {
        path: "/nhan-vien/:id",
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
        path: "/don-nghi-viec",
        name: "resignations",
        // HR thấy mọi đơn, Manager chỉ đơn của nhân viên mình quản lý trực tiếp
        // (Backend tự lọc — ResignationService).
        meta: { title: "Đơn nghỉ việc", permission: "resignation.approve" },
        component: () => import("../views/Resignation/Resignations.vue"),
    },
    // {
    //     path: "/ca-lam-viec",
    //     name: "work-shifts",
    //     meta: { title: "Ca làm việc", permission: "shift.view" },
    //     component: () => import("../views/WorkShift/WorkShifts.vue"),
    // },
    {
        path: "/cai-dat-he-thong",
        name: "settings",
        // shift.manage — Admin có mọi quyền, HR cũng quản lý Ca làm việc
        // (mục 12) nên hợp lý được sửa luôn ca mặc định ở đây.
        meta: { title: "Cài đặt hệ thống", permission: "shift.manage" },
        component: () => import("../views/Settings/Settings.vue"),
    },
    {
        path: "/cham-cong",
        name: "check-in",
        meta: { title: "Chấm công", permission: "attendance.check" },
        component: () => import("../views/Attendance/CheckIn.vue"),
    },
    {
        path: "/duyet-dieu-chinh-cong",
        name: "attendance-adjustments",
        meta: {
            title: "Duyệt điều chỉnh công",
            permission: "attendance.adjust",
        },
        component: () =>
            import("../views/Attendance/AttendanceAdjustments.vue"),
    },
    {
        path: "/tong-hop-cham-cong",
        name: "attendance-overview",
        // Quyền view_all (không phải approve) — Manager xem được, nút
        // Duyệt/Từ chối trong trang tự ẩn nếu không có attendance.approve
        // (xem AttendanceOverview.vue), Backend vẫn tự chặn ở API duyệt.
        meta: {
            title: "Tổng hợp chấm công",
            permission: "attendance.view_all",
        },
        component: () => import("../views/Attendance/AttendanceOverview.vue"),
    },
    {
        path: "/lich-su-cham-cong",
        name: "attendance-history",
        meta: { title: "Lịch sử chấm công", permission: "attendance.view_own" },
        component: () => import("../views/Attendance/AttendanceHistory.vue"),
    },
    {
        path: "/nghi-phep",
        name: "leave-requests",
        meta: { title: "Nghỉ phép", permission: "leave.request" },
        component: () => import("../views/Leave/LeaveRequests.vue"),
    },
    {
        path: "/duyet-nghi-phep",
        name: "leave-management",
        meta: { title: "Duyệt nghỉ phép", permission: "leave.view_all" },
        component: () => import("../views/Leave/LeaveManagement.vue"),
    },
    {
        path: "/tong-hop-nghi-phep",
        name: "leave-overview",
        meta: { title: "Tổng hợp nghỉ phép", permission: "leave.view_all" },
        component: () => import("../views/Leave/LeaveOverview.vue"),
    },
    {
        path: "/ngay-nghi-le",
        name: "holidays",
        // Ai đăng nhập cũng xem được lịch nghỉ lễ; nút quản lý trong trang theo holiday.manage.
        meta: { title: "Ngày nghỉ lễ" },
        component: () => import("../views/Holiday/Holidays.vue"),
    },
    {
        path: "/tuyen-dung",
        name: "recruitment",
        meta: { title: "Tuyển dụng", permission: "recruitment.manage" },
        component: () => import("../views/Recruitment/RecruitmentOpenings.vue"),
    },
    {
        path: "/tuyen-dung/:id",
        name: "recruitment-detail",
        meta: { title: "Chi tiết đợt tuyển", permission: "recruitment.manage" },
        component: () => import("../views/Recruitment/RecruitmentOpeningDetail.vue"),
    },
    {
        path: "/onboarding",
        name: "onboarding",
        // HR (toàn công ty) hoặc Quản lý (nhân viên mình quản lý trực tiếp).
        meta: { title: "Onboarding / Offboarding", permission: ["onboarding.manage", "onboarding.team"] },
        component: () => import("../views/Onboarding/Onboarding.vue"),
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

    // meta.permission: 1 mã, hoặc mảng = có BẤT KỲ mã nào trong mảng.
    const requiredPermission = to.meta.permission;
    if (requiredPermission && ![].concat(requiredPermission).some((code) => auth.permissions.includes(code))) {
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
