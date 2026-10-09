<template>
    <!-- Xem nhanh 1 nhân viên ở ngăn bên phải — không rời trang danh sách (giữ bộ lọc,
         trang, vị trí cuộn). Dùng thẳng dữ liệu của dòng (EmployeeResource đã đủ, kể cả
         ẩn trường nhạy cảm với cấp dưới) nên không gọi thêm API. -->
    <v-navigation-drawer
        :model-value="modelValue"
        location="right"
        temporary
        width="420"
        @update:model-value="$emit('update:modelValue', $event)"
    >
        <div v-if="employee">
            <div class="pa-5 d-flex align-center ga-3 border-b">
                <v-avatar
                    size="56"
                    :color="employee.avatar_url ? undefined : 'primary'"
                    variant="tonal"
                >
                    <v-img
                        v-if="employee.avatar_url"
                        :src="employee.avatar_url"
                        cover
                    />
                    <span v-else class="text-h6 font-weight-bold">{{
                        initials
                    }}</span>
                </v-avatar>
                <div class="flex-grow-1" style="min-width: 0">
                    <div class="text-h6 font-weight-bold text-truncate">
                        {{ employee.full_name }}
                    </div>
                    <div class="text-body-2" style="opacity: 0.7">
                        {{ employee.code }}
                    </div>
                    <StatusChip
                        class="mt-1"
                        :status="employee.employment_status"
                        :map="EMPLOYMENT_STATUS_MAP"
                    />
                    <div class="d-flex flex-wrap ga-1 mt-1">
                        <v-chip
                            v-for="alert in employeeAlerts(employee)"
                            :key="alert.text"
                            :color="alert.color"
                            size="x-small"
                            variant="tonal"
                            :prepend-icon="alert.icon"
                        >
                            {{ alert.text }}
                        </v-chip>
                    </div>
                </div>
                <v-btn
                    icon="mdi-close"
                    variant="text"
                    size="small"
                    @click="$emit('update:modelValue', false)"
                />
            </div>

            <div class="pa-5 d-flex flex-column ga-4">
                <section>
                    <div class="section-title">Công việc</div>
                    <InfoRow
                        icon="mdi-office-building-outline"
                        label="Phòng ban"
                        :value="employee.department?.name"
                    />
                    <InfoRow
                        icon="mdi-badge-account-outline"
                        label="Chức vụ"
                        :value="employee.position?.name"
                    />
                    <InfoRow
                        icon="mdi-account-tie-outline"
                        label="Quản lý trực tiếp"
                        :value="employee.manager?.full_name"
                    />
                    <InfoRow
                        icon="mdi-calendar-start"
                        label="Ngày vào làm"
                        :value="formatDate(employee.hire_date)"
                    />
                    <InfoRow
                        icon="mdi-cash"
                        label="Lương hợp đồng"
                        :value="formatMoney(employee.agreed_salary)"
                    />
                    <InfoRow
                        icon="mdi-beach"
                        label="Phép năm còn lại"
                        :value="
                            employee.leave_remaining_days != null
                                ? `${Number(employee.leave_remaining_days)} ngày`
                                : null
                        "
                    />
                </section>

                <section>
                    <div class="section-title">Liên hệ</div>
                    <InfoRow
                        icon="mdi-email-outline"
                        label="Email công ty"
                        :value="employee.company_email"
                        :href="
                            employee.company_email
                                ? `mailto:${employee.company_email}`
                                : null
                        "
                    />
                    <InfoRow
                        icon="mdi-phone-outline"
                        label="Điện thoại"
                        :value="employee.phone"
                        :href="employee.phone ? `tel:${employee.phone}` : null"
                    />
                    <InfoRow
                        icon="mdi-email-variant"
                        label="Email cá nhân"
                        :value="employee.personal_email"
                    />
                </section>

                <section>
                    <div class="section-title">Tài khoản đăng nhập</div>
                    <InfoRow
                        icon="mdi-account-key-outline"
                        label="Tài khoản"
                        :value="
                            employee.user
                                ? `${employee.user.status === 'active' ? 'Đang hoạt động' : 'Đã khóa'} · ${employee.user.roles?.join(', ') || '—'}`
                                : 'Chưa có tài khoản'
                        "
                    />
                </section>
            </div>
        </div>

        <!-- Slot phải là con TRỰC TIẾP của v-navigation-drawer (không lồng trong v-if). -->
        <template #append>
            <div v-if="employee" class="pa-4 d-flex ga-2 border-t">
                <v-btn
                    color="primary"
                    variant="flat"
                    class="flex-grow-1"
                    prepend-icon="mdi-open-in-new"
                    :to="{
                        name: 'employee-detail',
                        params: { id: employee.id },
                    }"
                >
                    Mở hồ sơ đầy đủ
                </v-btn>
                <v-btn
                    v-if="canUpdate"
                    variant="tonal"
                    prepend-icon="mdi-pencil-outline"
                    @click="$emit('edit', employee)"
                >
                    Sửa
                </v-btn>
            </div>
        </template>
    </v-navigation-drawer>
</template>

<script setup>
import { computed, defineComponent, h } from "vue";
import StatusChip from "../../components/common/StatusChip.vue";
import { EMPLOYMENT_STATUS_MAP } from "../../composables/employmentStatus";
import { employeeAlerts } from "../../composables/employeeAlerts";

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    employee: { type: Object, default: null },
    canUpdate: { type: Boolean, default: false },
});
defineEmits(["update:modelValue", "edit"]);

const initials = computed(() =>
    String(props.employee?.full_name ?? "")
        .trim()
        .split(/\s+/)
        .slice(-2)
        .map((w) => w[0]?.toUpperCase() ?? "")
        .join(""),
);

function formatDate(value) {
    return value ? new Date(value).toLocaleDateString("vi-VN") : null;
}

function formatMoney(value) {
    return value != null ? `${Number(value).toLocaleString("vi-VN")} đ` : null;
}

// 1 dòng "nhãn: giá trị" — giá trị rỗng/bị ẩn (null) hiện "—".
const InfoRow = defineComponent({
    props: {
        icon: { type: String, default: "" },
        label: { type: String, default: "" },
        value: { type: [String, Number], default: null },
        href: { type: String, default: null },
    },
    setup(p) {
        return () =>
            h("div", { class: "d-flex align-start ga-3 py-1" }, [
                h("i", {
                    class: `mdi ${p.icon} text-medium-emphasis`,
                    style: "font-size: 18px; margin-top: 1px",
                }),
                h("div", { style: "min-width: 0" }, [
                    h(
                        "div",
                        { class: "text-caption", style: "opacity: 0.6" },
                        p.label,
                    ),
                    p.value && p.href
                        ? h(
                              "a",
                              {
                                  href: p.href,
                                  class: "text-body-2",
                                  style: "word-break: break-all",
                              },
                              p.value,
                          )
                        : h(
                              "div",
                              {
                                  class: "text-body-2",
                                  style: "word-break: break-word",
                              },
                              p.value ?? "—",
                          ),
                ]),
            ]);
    },
});
</script>

<style scoped>
.section-title {
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    opacity: 0.55;
    margin-bottom: 4px;
}
</style>
