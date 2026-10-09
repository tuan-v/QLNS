// Loại sự kiện trong lịch sử luân chuyển (mirror EmployeeTransfer::TYPE_* ở backend) —
// dùng chung cho tab Luân chuyển của HR (EmployeeTransfersTab.vue) và của nhân viên
// (MyProfileTransfersTab.vue).
export const TRANSFER_TYPE_MAP = {
    onboard: { label: "Tiếp nhận", color: "success" },
    transfer: { label: "Điều chuyển", color: "primary" },
    adjustment: { label: "Cập nhật", color: "warning" },
};
