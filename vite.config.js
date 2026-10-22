import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vuetify from 'vite-plugin-vuetify';
import { createRequire } from 'node:module';

// Vuetify autoImport chèn import component lúc biên dịch nên Vite không quét thấy trước:
// mỗi lần gặp component mới (lần đầu chạy dev, sau npm install...) Vite phải gom lại thư viện
// và bắt trình duyệt tải lại trang ("optimized dependencies changed. reloading", lỗi 504).
// Gom sẵn TOÀN BỘ component ngay khi khởi động. Chỉ ảnh hưởng `npm run dev`, không ảnh hưởng bản build.
const vuetifyImportMap = createRequire(import.meta.url)('vuetify/dist/json/importMap.json');
const vuetifyDeps = [
    ...new Set(Object.values(vuetifyImportMap.components).map((c) => `vuetify/${c.from}`)),
    'vuetify/directives',
];

export default defineConfig({
    optimizeDeps: {
        include: vuetifyDeps,
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        vue(),
        tailwindcss(),
        vuetify({
            autoImport: true,
            styles: {
                configFile: 'resources/css/settings.scss',
            },
        })
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
        // Biên dịch sẵn file khởi đầu ngay khi `npm run dev` chạy, không đợi lần mở trang đầu tiên —
        // mở trang ngay sau khi bật máy không còn đứng chờ biên dịch (đo: lần đầu 2,5s → 1,6s).
        warmup: {
            clientFiles: ['./resources/js/app.js', './resources/js/views/Dashboard.vue', './resources/js/views/Login.vue'],
        },
    },
});
