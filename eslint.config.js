// ESLint cho frontend (Kế hoạch Ngày 72). Chạy: npm run lint / npm run lint:fix.
// Quy tắc định dạng (khoảng trắng, dấu nháy...) giao cho Prettier — eslint-config-prettier
// tắt các rule ESLint trùng việc để 2 công cụ không cãi nhau.
import js from "@eslint/js";
import pluginVue from "eslint-plugin-vue";
import prettier from "eslint-config-prettier";
import globals from "globals";

export default [
    {
        ignores: ["public/**", "vendor/**", "node_modules/**", "storage/**", "bootstrap/**"],
    },
    js.configs.recommended,
    ...pluginVue.configs["flat/recommended"],
    prettier,
    {
        files: ["resources/js/**/*.{js,vue}"],
        languageOptions: {
            ecmaVersion: "latest",
            sourceType: "module",
            globals: { ...globals.browser },
        },
        rules: {
            // Biến/tham số không dùng: báo lỗi, trừ tên bắt đầu bằng "_" (cố ý bỏ qua).
            "no-unused-vars": ["error", { argsIgnorePattern: "^_", varsIgnorePattern: "^_", caughtErrors: "none" }],
            // Tên component 1 từ (Dashboard.vue, Employees.vue...) là quy ước sẵn của dự án.
            "vue/multi-word-component-names": "off",
            // Slot dạng #item.title của Vuetify DataTable là hợp lệ.
            "vue/valid-v-slot": ["error", { allowModifiers: true }],
            // Dự án dùng v-html có kiểm soát ở vài chỗ — để cảnh báo, không chặn build.
            "vue/no-v-html": "warn",
        },
    },
];
