import { createRouter, createWebHistory } from "vue-router";

const routes = [
    {
        path: "/login",
        name: "login",
        component: () => import("../views/Login.vue"),
        meta: { layout: "blank" },
    },
    {
        path: "/",
        name: "dashboard",
        component: () => import("../views/Dashboard.vue"),
    },
    {
        path: "/departments",
        name: "departments",
        component: () => import("../views/Department/Departments.vue"),
    },
];

export default createRouter({
    history: createWebHistory(),
    routes,
});
