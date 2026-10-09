import { getCurrentInstance, onBeforeUnmount, onMounted, watch } from "vue";
import { reconnectSignal } from "../echo";
import { useAuthStore } from "../stores/authStore";
import { useMySyncStore } from "../stores/useMySyncStore";
import { useResourceSyncStore } from "../stores/useResourceSyncStore";

// 1 dòng để trang tự tải lại khi dữ liệu liên quan đổi — không F5 (mục 37
// CODE_MAP).
//
//   useRealtimeRefresh(loadData, {
//       mine: ["attendance", "leave_requests"],   // dữ liệu CỦA TÔI (kênh user-sync, nối sẵn ở App.vue)
//       shared: [                                  // trang quản lý (kênh resource-sync, tự join/leave theo trang)
//           { resource: "employees", permission: "employee.view" },  // chỉ join khi có quyền (mảng = có 1 trong các quyền)
//           "work_shifts_public",                                    // chuỗi trơn = không cần quyền
//       ],
//   });
//
// - `mine`/`shared` nhận mảng hoặc hàm trả mảng (vd tùy props). Kênh shared
//   thiếu quyền sẽ bị 403 vô ích nên khai `permission` để tự bỏ qua.
// - Nhiều tín hiệu dồn dập (thao tác hàng loạt) được gộp thành 1 lần tải lại
//   sau `debounceMs`, và không chồng lệnh khi lần tải trước chưa xong.
// - Gọi trong setup() của component; tự dọn khi component unmount.
export function useRealtimeRefresh(reload, { mine = [], shared = [], debounceMs = 300 } = {}) {
    const mySync = useMySyncStore();
    const resourceSync = useResourceSyncStore();
    const auth = useAuthStore();
    const resolve = (value) => (typeof value === "function" ? value() : value) ?? [];
    const allowed = (permission) => {
        if (!permission) {
            return true;
        }
        return [].concat(permission).some((code) => auth.permissions.includes(code));
    };
    // Chuẩn hóa `shared` thành danh sách tên resource được phép nghe.
    const sharedResources = () =>
        resolve(shared)
            .filter((entry) => typeof entry === "string" || allowed(entry.permission))
            .map((entry) => (typeof entry === "string" ? entry : entry.resource));

    let joinedShared = [];
    let timer = null;
    let running = false;
    let again = false;

    async function run() {
        if (running) {
            again = true;
            return;
        }
        running = true;
        try {
            await reload();
        } finally {
            running = false;
            if (again) {
                again = false;
                schedule();
            }
        }
    }

    function schedule() {
        clearTimeout(timer);
        timer = setTimeout(run, debounceMs);
    }

    // So sánh theo GIÁ TRỊ (chuỗi), không theo mảng: kênh vừa kết nối thêm khóa mới
    // vào signals (giá trị vẫn 0) làm getter chạy lại -> mảng mới luôn "khác" mảng cũ
    // -> mọi trang tự tải lại thừa 1 lần ngay khi mở.
    const stopWatch = watch(
        () => [
            reconnectSignal.value,
            ...resolve(mine).map((resource) => mySync.signals[resource] ?? 0),
            ...sharedResources().map((resource) => resourceSync.signals[resource] ?? 0),
        ].join("|"),
        schedule,
    );

    // Danh sách kênh chung có thể đổi sau khi mount (vd quyền tải xong muộn
    // sau F5) — join thêm/rời bớt theo đúng danh sách hiện tại.
    function syncJoined() {
        const wanted = [...new Set(sharedResources())];
        joinedShared.filter((r) => !wanted.includes(r)).forEach((r) => resourceSync.disconnect(r));
        wanted.filter((r) => !joinedShared.includes(r)).forEach((r) => resourceSync.connect(r));
        joinedShared = wanted;
    }

    if (getCurrentInstance()) {
        const stopJoinWatch = watch(() => sharedResources().join(","), syncJoined);
        onMounted(syncJoined);
        onBeforeUnmount(() => {
            stopWatch();
            stopJoinWatch();
            clearTimeout(timer);
            joinedShared.forEach((resource) => resourceSync.disconnect(resource));
            joinedShared = [];
        });
    }
}
