import { ref, watch } from "vue";
import { useAuthStore } from "../stores/authStore";

// ref nhớ giá trị lần chọn trước (bộ lọc, loại phép hay dùng...) theo TỪNG người dùng
// trên trình duyệt này. Chỉ là tiện ích: localStorage lỗi/bị chặn (ẩn danh, đầy bộ nhớ)
// thì vẫn chạy bình thường với giá trị mặc định, không báo lỗi.
//
//   const statusFilter = useRememberedRef("leave-management.status", "pending");
export function useRememberedRef(key, defaultValue) {
    const auth = useAuthStore();
    const storageKey = () => `qlns:${auth.user?.id ?? "anon"}:${key}`;

    function read() {
        try {
            const raw = localStorage.getItem(storageKey());
            return raw === null ? defaultValue : JSON.parse(raw);
        } catch {
            return defaultValue;
        }
    }

    const value = ref(read());

    watch(value, (next) => {
        try {
            localStorage.setItem(storageKey(), JSON.stringify(next ?? null));
        } catch {
            // Không lưu được thì thôi — lần sau dùng mặc định.
        }
    });

    return value;
}
