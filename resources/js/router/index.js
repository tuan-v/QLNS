import { createRouter, createWebHistory } from "vue-router";
import { useLoadingStore } from "../stores/useLoadingStore";

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
        path: "/departments",
        name: "departments",
        meta: { title: "Phòng ban" },
        component: () => import("../views/Department/Departments.vue"),
    },
    {
        path: "/employees",
        name: "employees",
        meta: { title: "Nhân viên" },
        component: () => import("../views/Employee/Employees.vue"),
    },
    {
        path: "/employees/:id",
        name: "employee-detail",
        meta: { title: "Chi tiết nhân viên", parent: "employees" },
        component: () => import("../views/Employee/EmployeeDetail.vue"),
        props: true,
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

router.afterEach(() => {
    useLoadingStore().stop();
});

// Tải chunk lỗi (mất mạng, vừa deploy bản mới) thì afterEach KHÔNG chạy, phải
// tự tắt ở đây nếu không thanh loading sẽ quay mãi.
router.onError(() => {
    useLoadingStore().stop();
});

export default router;
