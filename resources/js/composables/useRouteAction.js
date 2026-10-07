import { onMounted, watch } from "vue";
import { useRoute, useRouter } from "vue-router";

// Mở thẳng 1 thao tác khi vào trang bằng link có `?action=...` (lệnh từ Ctrl+K, nút ở
// Dashboard...). Chạy 1 lần rồi xóa `action` khỏi URL — F5 hay bấm Quay lại không mở lại
// dialog lần nữa. Cũng bắt khi đang đứng sẵn ở trang mà chọn lệnh (chỉ đổi query).
//
//   useRouteAction({ create: () => openCreate(), export: () => (exportDialog.value = true) });
export function useRouteAction(handlers) {
    const route = useRoute();
    const router = useRouter();

    function run() {
        const action = route.query.action;
        if (typeof action !== "string" || !handlers[action]) {
            return;
        }
        const { action: _action, ...rest } = route.query;
        router.replace({ query: rest });
        handlers[action]();
    }

    onMounted(run);
    watch(() => route.query.action, run);
}
