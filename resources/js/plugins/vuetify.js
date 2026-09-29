import "@mdi/font/css/materialdesignicons.css";
import "vuetify/styles";
import { createVuetify } from "vuetify";
import { vi } from "vuetify/locale";

// Design system: demo.tailadmin.com-DESIGN.md (TailAdmin).
// - Nền sáng, card trắng viền hairline #E4E7EC, bóng micro.
// - Màu nhấn brand #465FFF cho nút chính, menu đang chọn, biểu đồ.
// - Màu trạng thái success/warning/error CHỈ dùng cho trạng thái.
// - Theme tối: design.md chỉ có bảng màu "derived" (chưa đo) — dùng đúng bộ
//   trung tính đó, giữ brand #465FFF cho nền nút (chữ trắng 4.6:1) và dùng
//   brand-400 #7592FF cho CHỮ màu brand trên nền tối.
// Tên theme giữ nguyên qlnsDark/qlnsLight để không vỡ chỗ đang đổi theme.
// Màu tự đặt (sidebar, hairline, muted, brand-text, ink-muted, faint) tự sinh
// biến --v-theme-<tên> + class bg-<tên>/text-<tên>; KHÔNG đặt màu tên "muted"
// làm class chữ phụ — dùng text-medium-emphasis của Vuetify.
export default createVuetify({
    locale: {
        locale: "vi",
        fallback: "vi",
        messages: { vi },
    },
    // Mặc định dùng chung cho toàn app: đổi ở đây thay vì lặp prop trên từng component.
    defaults: {
        VBtn: {
            rounded: "lg",
            variant: "flat",
            // Vuetify mặc định VIẾT HOA + giãn chữ -> tắt (typography đã chỉnh ở settings.scss)
            class: "text-none font-weight-medium qlns-btn",
        },
        VTextField: { variant: "outlined", density: "compact", rounded: "lg" },
        VSelect: { variant: "outlined", density: "compact", rounded: "lg" },
        VAutocomplete: {
            variant: "outlined",
            density: "compact",
            rounded: "lg",
        },
        VCombobox: { variant: "outlined", density: "compact", rounded: "lg" },
        VTextarea: { variant: "outlined", density: "compact", rounded: "lg" },
        VFileInput: { variant: "outlined", density: "compact", rounded: "lg" },
        VCard: { rounded: "xl", variant: "flat", class: "border" },
        VChip: { rounded: "pill", size: "small", variant: "tonal" },
        VTabs: { color: "primary", height: 50, sliderColor: "primary" },
        VBtnToggle: { color: "primary", rounded: "lg" },
        VList: { density: "compact" },
        VListItem: { rounded: "lg" },
        VAppBar: { flat: true, height: 64 },
        VAlert: { rounded: "lg" },
        VDataTable: { density: "comfortable", hover: true },
        VDataTableServer: { density: "comfortable", hover: true },
        VTable: { density: "comfortable", hover: true },
        VProgressLinear: { color: "primary" },
        VCheckbox: { color: "primary" },
        VCheckboxBtn: { color: "primary" },
        VSwitch: { color: "primary", inset: true },
        VRadioGroup: { color: "primary" },
    },
    theme: {
        defaultTheme: "qlnsLight",
        themes: {
            qlnsLight: {
                dark: false,
                colors: {
                    background: "#F9FAFB",
                    surface: "#FFFFFF",
                    "surface-variant": "#F2F4F7",
                    "on-surface-variant": "#344054",
                    "on-background": "#101828",
                    "on-surface": "#1D2939",
                    primary: "#465FFF",
                    "on-primary": "#FFFFFF",
                    secondary: "#667085",
                    "on-secondary": "#FFFFFF",
                    success: "#039855",
                    warning: "#DC6803",
                    error: "#D92D20",
                    info: "#0086C9",
                    // tuỳ biến
                    sidebar: "#FFFFFF",
                    hairline: "#E4E7EC",
                    muted: "#F2F4F7",
                    ink: "#101828",
                    "ink-muted": "#667085",
                    faint: "#98A2B3",
                    link: "#344054",
                    "brand-text": "#465FFF",
                },
                variables: {
                    "border-color": "#101828",
                    "border-opacity": 0.1,
                    "high-emphasis-opacity": 1,
                    "medium-emphasis-opacity": 0.72,
                    "disabled-opacity": 0.5,
                    // 0.05 trước đây gần như không thấy khi rê chuột (2026-09-29,
                    // người dùng báo không biết cái nào bấm được) — 0.08 đúng mức
                    // Material Design khuyến nghị cho trạng thái hover.
                    "hover-opacity": 0.08,
                },
            },
            qlnsDark: {
                dark: true,
                colors: {
                    background: "#0E0F11",
                    surface: "#1C1D1F",
                    "surface-variant": "#29292B",
                    "on-surface-variant": "#E4E4E7",
                    "on-background": "#F7F7F8",
                    "on-surface": "#F7F7F8",
                    primary: "#465FFF",
                    "on-primary": "#FFFFFF",
                    secondary: "#9E9FA0",
                    "on-secondary": "#0E0F11",
                    success: "#32D583",
                    warning: "#FDB022",
                    error: "#F97066",
                    info: "#36BFFA",
                    sidebar: "#161719",
                    hairline: "#353537",
                    muted: "#29292B",
                    ink: "#F7F7F8",
                    "ink-muted": "#9E9FA0",
                    faint: "#76777A",
                    link: "#D0D5DD",
                    "brand-text": "#7592FF",
                },
                variables: {
                    "border-color": "#FFFFFF",
                    "border-opacity": 0.14,
                    "high-emphasis-opacity": 1,
                    "medium-emphasis-opacity": 0.66,
                    "disabled-opacity": 0.45,
                    "hover-opacity": 0.1,
                },
            },
        },
    },
});
