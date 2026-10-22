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
    "leave.balance_adjusted": "leave-requests",
    "holiday.upcoming": "holidays",
    "holiday.confirm_needed": "holidays",
    "holiday.announcement": "holiday-announcement",
    "leave.pending_manager": "leave-management",
    "leave.pending_hr": "leave-management",
    "attendance.pending_approval": "attendance-overview",
    "attendance_adjustment.pending": "attendance-adjustments",
    "resignation.pending": "resignations",
    "resignation.notice": "resignations",
    "resignation.decided": "my-profile",
    "recruitment.cv_pending": "recruitment-detail",
    "recruitment.opening_created": "recruitment-detail",
    "recruitment.cv_reviewed": "recruitment-detail",
    "recruitment.interview_assigned": "recruitment-detail",
    "recruitment.offer_pending": "recruitment-detail",
    "recruitment.offer_reviewed": "recruitment-detail",
    "recruitment.offer_responded": "recruitment-detail",
    "recruitment.interview_confirmation": "interview-schedule",
    "bank_account.pending": "employee-detail",
    "bank_account.reviewed": "my-profile",
    // Đề xuất phụ cấp (HR -> Admin duyệt) — tab "Đề xuất" của trang Phụ cấp.
    "allowance.pending": "allowances",
    "allowance.decided": "allowances",
    // Dự án & công việc — mở trang dự án, kèm ?task= để mở thẳng công việc.
    "project.lead_assigned": "project-detail",
    "project.member_added": "project-detail",
    "task.assigned": "project-detail",
    "task.submitted": "project-detail",
    "task.approved": "project-detail",
    "task.returned": "project-detail",
    "task.due_soon": "project-detail",
    "task.overdue": "project-detail",
    "task.start_today": "project-detail",
    "task.due_today": "project-detail",
    "task.review_pending": "project-detail",
    "task.unassigned": "project-detail",
    "task.due_changed": "project-detail",
    "task.cancelled": "project-detail",
    "project.start_due": "project-detail",
    "project.ending_soon": "project-detail",
    "project.overdue": "project-detail",
    "project.status_changed": "project-detail",
    "task.deleted": "project-detail",
    "project.member_removed": "projects",
};

// Tham số route (trang có :id).
const recruitmentParams = (item) => ({ id: item.data?.recruitment_opening_id });
const projectParams = (item) => ({ id: item.data?.project_id });
const TYPE_PARAMS = {
    "recruitment.cv_pending": recruitmentParams,
    "recruitment.opening_created": recruitmentParams,
    "recruitment.cv_reviewed": recruitmentParams,
    "recruitment.interview_assigned": recruitmentParams,
    "recruitment.offer_pending": recruitmentParams,
    "recruitment.offer_reviewed": recruitmentParams,
    "recruitment.offer_responded": recruitmentParams,
    "bank_account.pending": (item) => ({ id: item.data?.employee_id }),
    "holiday.announcement": (item) => ({ groupCode: item.data?.group_code }),
    "project.lead_assigned": projectParams,
    "project.member_added": projectParams,
    "task.assigned": projectParams,
    "task.submitted": projectParams,
    "task.approved": projectParams,
    "task.returned": projectParams,
    "task.due_soon": projectParams,
    "task.overdue": projectParams,
    "task.start_today": projectParams,
    "task.due_today": projectParams,
    "task.review_pending": projectParams,
    "task.unassigned": projectParams,
    "task.due_changed": projectParams,
    "task.cancelled": projectParams,
    "project.start_due": projectParams,
    "project.ending_soon": projectParams,
    "project.overdue": projectParams,
    "project.status_changed": projectParams,
    "task.deleted": projectParams,
};

const TYPE_QUERY = {
    "resignation.pending": (item) => ({ id: item.data?.resignation_request_id }),
    "resignation.notice": (item) => ({ id: item.data?.resignation_request_id }),
    "recruitment.interview_confirmation": (item) => ({ month: item.data?.month, interview: item.data?.interview_id }),
    "bank_account.pending": () => ({ tab: "bank" }),
    // Mở thẳng hộp thoại xem/duyệt offer của ứng viên.
    "recruitment.offer_pending": (item) => ({ offer: item.data?.candidate_id }),
    "bank_account.reviewed": () => ({ tab: "bank" }),
    "allowance.pending": () => ({ tab: "requests", status: "pending" }),
    "allowance.decided": (item) => ({ tab: "requests", status: item.data?.status ?? "all" }),
    "task.assigned": (item) => ({ task: item.data?.task_id }),
    "task.submitted": (item) => ({ task: item.data?.task_id }),
    "task.approved": (item) => ({ task: item.data?.task_id }),
    "task.returned": (item) => ({ task: item.data?.task_id }),
    "task.due_soon": (item) => ({ task: item.data?.task_id }),
    "task.overdue": (item) => ({ task: item.data?.task_id }),
    "task.start_today": (item) => ({ task: item.data?.task_id }),
    "task.due_today": (item) => ({ task: item.data?.task_id }),
    "task.review_pending": (item) => ({ task: item.data?.task_id }),
    "task.unassigned": (item) => ({ task: item.data?.task_id }),
    "task.due_changed": (item) => ({ task: item.data?.task_id }),
    "task.cancelled": (item) => ({ task: item.data?.task_id }),
};

export function notificationTarget(item) {
    const name = TYPE_ROUTES[item?.type];
    if (!name) {
        return null;
    }
    return { name, query: TYPE_QUERY[item.type]?.(item), params: TYPE_PARAMS[item.type]?.(item) };
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
