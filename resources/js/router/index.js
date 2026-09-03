import { createRouter, createWebHistory } from 'vue-router';

const routes = [
    {
        path: '/login',
        name: 'login',
        component: ()=> import('../views/Login.vue'),
    },

];

export default createRouter({
    history: createWebHistory(),
    routes,
});
