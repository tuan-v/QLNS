import { useRouter } from "vue-router";
import { useNotificationStore } from "../stores/useNotificationStore";

// 1 nơi DUY NHẤT quyết định "bấm vào thông báo thì đi đâu" — dùng chung cho
// chuông (NotificationCenter), khối "Thông báo gần đây" ở Dashboard và tab
// "Thông báo của tôi" (trang Thông báo), để 3 chỗ luôn dẫn tới đúng cùng 1 trang.
//
// leave.decided -> nhân viên xem đơn CỦA MÌNH; leave.pending_manager/
// leave.pending_hr -> người NHẬN là người phải DUYỆT, đưa thẳng sang trang duyệt
// đơn (2 trang khác nhau, gate quyền khác nhau). attendance.pending_approval ->
// người có quyền duyệt chấm công, sang "Tổng hợp chấm công". Đơn nghỉ việc:
// người duyệt -> mở thẳng đúng đơn (query id); người nộp -> "Hồ sơ của tôi".
// Thêm loại thông báo mới thì thêm 1 dòng ở đây (loại không khai báo = không
// điều hướng, chỉ đánh dấu đã đọc).
const TYPE_ROUTES = {
    "leave.decided": "leave-requests",
    "holiday.upcoming": "holidays",
    "holiday.confirm_needed": "holidays",
    "leave.pending_manager": "leave-management",
    "leave.pending_hr": "leave-management",
    "attendance.pending_approval": "attendance-overview",
    "attendance_adjustment.pending": "attendance-adjustments",
    "resignation.pending": "resignations",
    "resignation.notice": "resignations",
    "resignation.decided": "my-profile",
};

const TYPE_QUERY = {
    "resignation.pending": (item) => ({ id: item.data?.resignation_request_id }),
    "resignation.notice": (item) => ({ id: item.data?.resignation_request_id }),
};

export function notificationTarget(item) {
    const name = TYPE_ROUTES[item?.type];
    if (!name) {
        return null;
    }
    return { name, query: TYPE_QUERY[item.type]?.(item) };
}

export function useNotificationNavigation() {
    const router = useRouter();
    const store = useNotificationStore();

    // Trả true nếu đã điều hướng (để nơi gọi đóng menu/dialog nếu cần).
    function openNotification(item) {
        if (!item.read_at) {
            store.markRead(item.id);
        }
        const target = notificationTarget(item);
        if (!target) {
            return false;
        }
        router.push(target);
        return true;
    }

    return { openNotification, canOpen: (item) => notificationTarget(item) !== null };
}
