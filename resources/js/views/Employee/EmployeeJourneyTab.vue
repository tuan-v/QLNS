<template>
    <div class="d-flex flex-column ga-4">
        <div v-if="loading" class="d-flex justify-center py-8"><v-progress-circular indeterminate /></div>
        <v-alert v-else-if="loadError" type="error" variant="tonal" density="compact">{{ loadError }}</v-alert>

        <template v-else-if="journey">
            <!-- Tóm tắt: 6 ô, mỗi ô 1 con số chính + nhãn màu kết luận + 1 dòng chi tiết (2026-10-22) -->
            <div class="highlights">
                <v-sheet v-for="tile in highlights" :key="tile.key" class="border rounded-lg glass-panel pa-4 tile" color="transparent">
                    <div class="d-flex align-center ga-2 mb-2">
                        <span class="tile__icon" :class="`bg-${TONE_COLOR[tile.badge?.tone] ?? 'primary'}`">
                            <v-icon :icon="tile.icon" size="18" />
                        </span>
                        <span class="text-caption text-uppercase font-weight-bold tile__label">{{ tile.label }}</span>
                    </div>
                    <div class="d-flex flex-wrap align-center ga-2">
                        <span class="text-h6 font-weight-bold">{{ tile.value }}</span>
                        <v-chip v-if="tile.badge" size="small" variant="tonal" label :color="TONE_COLOR[tile.badge.tone]" class="font-weight-bold">
                            {{ tile.badge.text }}
                        </v-chip>
                    </div>
                    <div class="text-body-2 mt-1 tile__detail">{{ tile.detail }}</div>
                </v-sheet>
            </div>

            <!-- Diễn biến lương (2026-10-22): bậc lương theo hợp đồng + lương thực nhận từng tháng.
            Ẩn với người không có quyền xem lương (Trưởng phòng) — API cũng không trả số tiền. -->
            <v-sheet v-if="journey.can_view_salary" class="border rounded-lg glass-panel pa-4" color="transparent">
                <div class="d-flex flex-wrap align-baseline ga-2 mb-3">
                    <div class="text-subtitle-1 font-weight-bold">Diễn biến lương</div>
                    <div class="text-caption" style="opacity: 0.65">Mỗi bậc là 1 hợp đồng — cao hơn là lương cao hơn</div>
                </div>
                <SalaryLadder :salary-history="journey.salary_history" />

                <v-divider class="my-4" />
                <div class="d-flex flex-wrap align-baseline ga-2 mb-1">
                    <div class="text-subtitle-1 font-weight-bold">Lương thực nhận từng tháng</div>
                    <div class="text-caption" style="opacity: 0.65">Rê chuột vào cột để xem công, phụ cấp, khấu trừ</div>
                </div>
                <SalaryJourneyChart :salary-history="journey.salary_history" :payslips="journey.payslips" />
            </v-sheet>

            <!-- Phỏng vấn: điểm từng phần + nhận xét / lý do đạt, không đạt -->
            <v-sheet v-if="journey.interviews?.length" class="border rounded-lg glass-panel pa-4" color="transparent">
                <div class="text-subtitle-1 font-weight-bold mb-3">Phỏng vấn</div>
                <div v-for="(iv, index) in journey.interviews" :key="index" class="interview">
                    <div class="d-flex flex-wrap align-center ga-2">
                        <span class="font-weight-medium">{{ formatDate(iv.scheduled_at) }}</span>
                        <span v-if="iv.interviewer" class="text-caption" style="opacity: 0.7">· {{ iv.interviewer }}</span>
                        <v-chip v-if="iv.result" size="x-small" variant="tonal" :color="iv.result === 'passed' ? 'success' : 'error'">
                            {{ iv.result === "passed" ? "Đạt" : "Không đạt" }}
                        </v-chip>
                        <span v-if="iv.score_percent != null" class="text-body-2">
                            <strong>{{ iv.score_percent }}%</strong>
                            <span style="opacity: 0.7"> (cần {{ iv.pass_percent }}%)</span>
                        </span>
                    </div>
                    <div v-if="iv.sections.length > 1 || iv.sections[0]?.name" class="sections mt-2">
                        <div v-for="section in iv.sections" :key="section.name" class="sections__row">
                            <span class="text-caption text-truncate">{{ section.name }}<template v-if="iv.sections.length > 1"> · {{ section.weight }}%</template></span>
                            <v-progress-linear
                                :model-value="section.percent"
                                :color="section.pass_percent != null && section.percent < section.pass_percent ? 'error' : 'success'"
                                height="6"
                                rounded
                            />
                            <span class="text-caption text-right">{{ section.percent }}%</span>
                        </div>
                    </div>
                    <div v-if="iv.result_note" class="text-body-2 mt-2"><span style="opacity: 0.7">Lý do kết quả:</span> {{ iv.result_note }}</div>
                    <div v-if="iv.overall_note" class="text-body-2 mt-1"><span style="opacity: 0.7">Nhận xét phiếu chấm:</span> {{ iv.overall_note }}</div>
                </div>
            </v-sheet>

            <!-- Dự án & KPI (đạt = xếp loại A/B/C) -->
            <v-sheet class="border rounded-lg glass-panel" color="transparent">
                <div class="text-subtitle-1 font-weight-bold pa-4 pb-0">Dự án &amp; KPI</div>
                <v-table density="comfortable">
                    <thead>
                        <tr>
                            <th>Dự án</th>
                            <th>Vai trò</th>
                            <th class="text-center">Việc xong</th>
                            <th class="text-center">Đúng hạn</th>
                            <th class="text-center">Chất lượng</th>
                            <th class="text-right">KPI</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!journey.kpi.projects.length"><td colspan="6" class="text-center py-4" style="opacity: 0.6">Chưa tham gia dự án nào.</td></tr>
                        <tr v-for="item in journey.kpi.projects" :key="item.project.id">
                            <td>
                                <router-link :to="{ name: 'project-detail', params: { id: item.project.id } }" class="font-weight-medium">{{ item.project.name }}</router-link>
                                <div class="text-caption" style="opacity: 0.6">
                                    {{ item.project.code }} · {{ PROJECT_STATUS[item.project.status] ?? item.project.status }}
                                </div>
                            </td>
                            <td>{{ item.role === "lead" ? "Lead" : item.role ? "Thành viên" : "—" }}</td>
                            <template v-if="item.metrics">
                                <td class="text-center">{{ item.metrics.done_count }}/{{ item.metrics.task_count }}</td>
                                <td class="text-center">{{ item.metrics.on_time_rate }}%</td>
                                <td class="text-center">{{ item.metrics.quality_avg != null ? `${item.metrics.quality_avg}/5` : "—" }}</td>
                                <td class="text-right text-no-wrap">
                                    <strong>{{ item.metrics.score }}</strong>
                                    <v-chip size="x-small" variant="tonal" class="ml-1" :color="['A', 'B', 'C'].includes(item.metrics.grade.key) ? 'success' : 'error'">
                                        {{ item.metrics.grade.key }} · {{ ["A", "B", "C"].includes(item.metrics.grade.key) ? "Đạt" : "Chưa đạt" }}
                                    </v-chip>
                                </td>
                            </template>
                            <td v-else colspan="4" class="text-center text-caption" style="opacity: 0.6">Chưa có việc tới hạn để tính KPI</td>
                        </tr>
                    </tbody>
                </v-table>
                <div v-if="journey.kpi.months.length" class="d-flex flex-wrap align-center ga-2 pa-4 pt-2">
                    <span class="text-caption" style="opacity: 0.7">KPI từng tháng:</span>
                    <v-chip
                        v-for="m in journey.kpi.months"
                        :key="`${m.year}-${m.month}`"
                        size="small"
                        variant="outlined"
                        :color="['A', 'B', 'C'].includes(m.grade.key) ? 'success' : 'error'"
                        :title="m.status === 'locked' ? 'Đã chốt' : 'Tạm tính'"
                    >
                        {{ String(m.month).padStart(2, "0") }}/{{ m.year }}: {{ m.score }} ({{ m.grade.key }})<template v-if="m.status !== 'locked'">*</template>
                    </v-chip>
                    <span class="text-caption" style="opacity: 0.6">* tạm tính, chưa chốt</span>
                </div>
            </v-sheet>

            <v-row>
                <!-- Chuyên cần theo tháng -->
                <v-col cols="12" md="8">
                    <v-sheet class="border rounded-lg glass-panel h-100" color="transparent">
                        <div class="text-subtitle-1 font-weight-bold pa-4 pb-0">
                            Chuyên cần theo tháng
                            <span class="text-caption font-weight-regular" style="opacity: 0.65">(12 tháng gần nhất)</span>
                        </div>
                        <v-table density="comfortable">
                            <thead>
                                <tr>
                                    <th>Tháng</th>
                                    <th class="text-center">Công</th>
                                    <th class="text-center">Đi muộn</th>
                                    <th class="text-center">Về sớm</th>
                                    <th class="text-center">Nghỉ phép</th>
                                    <th class="text-center">Vắng</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!journey.attendance.length"><td colspan="6" class="text-center py-4" style="opacity: 0.6">Chưa có dữ liệu chấm công.</td></tr>
                                <tr v-for="m in [...journey.attendance].reverse()" :key="`${m.year}-${m.month}`">
                                    <td class="text-no-wrap">{{ String(m.month).padStart(2, "0") }}/{{ m.year }}</td>
                                    <td class="text-center text-no-wrap">
                                        {{ m.work_days }}/{{ m.scheduled_days }}
                                        <span class="text-caption" :class="m.attendance_rate < 90 ? 'text-error' : ''">({{ m.attendance_rate }}%)</span>
                                    </td>
                                    <td class="text-center" :class="m.late_count ? 'text-warning' : ''">{{ m.late_count }}</td>
                                    <td class="text-center">{{ m.early_leave_count }}</td>
                                    <td class="text-center">{{ m.leave_days }}</td>
                                    <td class="text-center" :class="m.absent_days ? 'text-error' : ''">{{ m.absent_days }}</td>
                                </tr>
                            </tbody>
                        </v-table>
                    </v-sheet>
                </v-col>
                <!-- Phụ cấp đang hưởng -->
                <v-col cols="12" md="4">
                    <v-sheet class="border rounded-lg glass-panel pa-4 h-100" color="transparent">
                        <div class="text-subtitle-1 font-weight-bold mb-2">Phụ cấp đang hưởng</div>
                        <div v-if="!journey.allowances.length" class="text-body-2" style="opacity: 0.6">Không có.</div>
                        <div v-for="a in journey.allowances" :key="a.name" class="d-flex justify-space-between ga-2 py-1">
                            <div>
                                <div class="text-body-2">{{ a.name }}</div>
                                <div v-if="a.note" class="text-caption" style="opacity: 0.7">{{ a.note }}</div>
                            </div>
                            <div class="text-body-2 font-weight-medium text-no-wrap">{{ money(a.amount) }}{{ a.unit === "day" ? "/ngày" : "/tháng" }}</div>
                        </div>
                        <div class="text-caption mt-2" style="opacity: 0.6">Lịch sử thay đổi xem ở dòng thời gian (nhóm Phụ cấp).</div>
                    </v-sheet>
                </v-col>
            </v-row>

            <!-- Dòng thời gian -->
            <v-sheet class="border rounded-lg glass-panel pa-4" color="transparent">
                <div class="d-flex flex-wrap align-center ga-2 mb-3">
                    <div class="text-subtitle-1 font-weight-bold flex-grow-1">Dòng thời gian</div>
                    <v-chip-group v-model="groupFilter" selected-class="text-primary" mandatory>
                        <v-chip value="all" size="small" variant="outlined">Tất cả</v-chip>
                        <v-chip v-for="(meta, key) in GROUPS" :key="key" :value="key" size="small" variant="outlined" :prepend-icon="meta.icon">
                            {{ meta.label }}
                        </v-chip>
                    </v-chip-group>
                </div>
                <div v-if="!filteredEvents.length" class="text-center py-4" style="opacity: 0.6">Chưa có mốc nào.</div>
                <v-timeline v-else side="end" align="start" density="compact" truncate-line="both">
                    <v-timeline-item
                        v-for="(event, index) in filteredEvents"
                        :key="index"
                        :dot-color="GROUPS[event.group]?.color"
                        :icon="ICONS[event.type] ?? GROUPS[event.group]?.icon"
                        size="small"
                    >
                        <template #opposite>
                            <span class="text-caption" style="opacity: 0.7">{{ formatDate(event.date) }}</span>
                        </template>
                        <div class="text-body-2 font-weight-bold">{{ event.title }}</div>
                        <div v-if="event.description" class="text-caption" style="opacity: 0.8">{{ event.description }}</div>
                    </v-timeline-item>
                </v-timeline>
            </v-sheet>
        </template>
    </div>
</template>

<script setup>
import { computed, defineAsyncComponent, onMounted, ref } from "vue";
import employeeService from "../../services/employeeService";
import { useRealtimeRefresh } from "../../composables/useRealtimeRefresh";
import SalaryLadder from "../../components/employee/SalaryLadder.vue";

// ApexCharts nặng — tải riêng, không chặn phần còn lại của tab.
const SalaryJourneyChart = defineAsyncComponent(() => import("../../components/employee/SalaryJourneyChart.vue"));

const props = defineProps({
    employeeId: { type: [Number, String], required: true },
});

const GROUPS = {
    recruitment: { label: "Tuyển dụng", icon: "mdi-briefcase-search-outline", color: "indigo" },
    contract: { label: "Hợp đồng & lương", icon: "mdi-file-document-outline", color: "primary" },
    position: { label: "Phòng ban & chức vụ", icon: "mdi-swap-horizontal", color: "teal" },
    resignation: { label: "Nghỉ việc", icon: "mdi-exit-run", color: "error" },
    project: { label: "Dự án", icon: "mdi-folder-account-outline", color: "deep-purple" },
    allowance: { label: "Phụ cấp", icon: "mdi-hand-coin-outline", color: "orange" },
};

const PROJECT_STATUS = {
    planning: "Lên kế hoạch",
    active: "Đang chạy",
    on_hold: "Tạm dừng",
    completed: "Hoàn thành",
    cancelled: "Đã hủy",
};

const ICONS = {
    cv_submitted: "mdi-file-upload-outline",
    cv_approved: "mdi-check",
    interview: "mdi-account-voice",
    offer_sent: "mdi-email-outline",
    offer_accepted: "mdi-handshake-outline",
    offer_declined: "mdi-close",
    hired: "mdi-account-plus-outline",
    contract_started: "mdi-cash",
    contract_terminated: "mdi-file-cancel-outline",
    onboard: "mdi-door-open",
};

const journey = ref(null);
const loading = ref(true);
const loadError = ref("");
const groupFilter = ref("all");

const summary = computed(() => journey.value?.summary ?? {});

// good = xanh, warn = cam, bad = đỏ, neutral = xám.
const TONE_COLOR = { good: "success", warn: "warning", bad: "error", neutral: "grey" };
const isPassed = (grade) => ["A", "B", "C"].includes(grade?.key);

// 6 ô tóm tắt đầu trang — dựng từ dữ liệu sẵn có của API, mỗi ô trả lời 1 câu hỏi của quản lý.
const highlights = computed(() => {
    const j = journey.value;
    if (!j) return [];
    const s = j.summary;
    const tiles = [];

    tiles.push({
        key: "tenure",
        icon: "mdi-calendar-account-outline",
        label: "Thâm niên",
        value: tenureLabel.value,
        badge: s.termination_date ? { text: "Đã nghỉ việc", tone: "bad" } : null,
        detail: [
            `Vào làm ${formatDate(s.hire_date)}`,
            s.termination_date ? `nghỉ ${formatDate(s.termination_date)}` : null,
            [s.position, s.department].filter(Boolean).join(", ") || null,
        ]
            .filter(Boolean)
            .join(" · "),
    });

    const lastInterview = [...(j.interviews ?? [])].reverse().find((iv) => iv.result);
    tiles.push(
        s.recruitment
            ? {
                  key: "recruitment",
                  icon: "mdi-account-search-outline",
                  label: "Tuyển dụng",
                  value:
                      s.recruitment.score_percent != null ? `Phỏng vấn ${s.recruitment.score_percent}%` : "Qua tuyển dụng",
                  badge: lastInterview
                      ? lastInterview.result === "passed"
                          ? { text: "Đạt", tone: "good" }
                          : { text: "Không đạt", tone: "bad" }
                      : null,
                  detail: `Đợt "${s.recruitment.opening}"${s.recruitment.offer_salary != null ? ` · offer ${money(s.recruitment.offer_salary)}` : ""}`,
              }
            : {
                  key: "recruitment",
                  icon: "mdi-account-search-outline",
                  label: "Tuyển dụng",
                  value: "Nhập trực tiếp",
                  badge: null,
                  detail: "Không qua module Tuyển dụng",
              },
    );

    const change = s.total_change_amount;
    if (j.can_view_salary) {
        tiles.push({
            key: "salary",
            icon: "mdi-cash-multiple",
            label: "Lương hiện tại",
            value: s.current_salary != null ? money(s.current_salary) : "—",
            badge:
                s.starting_salary == null
                    ? null
                    : !change
                      ? { text: "Chưa thay đổi", tone: "neutral" }
                      : { text: `${change > 0 ? "+" : "−"}${Math.abs(s.total_change_percent)}%`, tone: change > 0 ? "good" : "bad" },
            detail:
                s.starting_salary != null
                    ? `Khởi điểm ${money(s.starting_salary)} · ${s.contracts_count} hợp đồng`
                    : "Chưa có hợp đồng",
        });
    }

    const overall = j.kpi.overall;
    const scored = j.kpi.projects.filter((p) => p.metrics);
    const passedProjects = scored.filter((p) => isPassed(p.metrics.grade)).length;
    tiles.push({
        key: "kpi",
        icon: "mdi-chart-box-outline",
        label: "KPI dự án",
        value: overall ? `${overall.score} điểm` : j.kpi.projects.length ? "Chưa có KPI" : "Chưa có dự án",
        badge: overall
            ? { text: `${overall.grade.key} · ${isPassed(overall.grade) ? "Đạt" : "Chưa đạt"}`, tone: isPassed(overall.grade) ? "good" : "bad" }
            : null,
        detail: j.kpi.projects.length
            ? `${passedProjects}/${scored.length} dự án đạt KPI · tham gia ${j.kpi.projects.length} dự án`
            : "Chưa tham gia dự án nào",
    });

    const recent = j.attendance.slice(-6);
    const scheduled = recent.reduce((sum, m) => sum + m.scheduled_days, 0);
    const rate = scheduled ? Math.round((recent.reduce((sum, m) => sum + m.work_days, 0) * 1000) / scheduled) / 10 : null;
    const late = recent.reduce((sum, m) => sum + m.late_count, 0);
    const absent = recent.reduce((sum, m) => sum + m.absent_days, 0);
    tiles.push({
        key: "attendance",
        icon: "mdi-calendar-check-outline",
        label: recent.length ? `Chuyên cần ${recent.length} tháng gần nhất` : "Chuyên cần",
        value: rate != null ? `${rate.toLocaleString("vi-VN")}% ngày công` : "Chưa có dữ liệu",
        badge:
            rate == null
                ? null
                : rate >= 95 && !absent
                  ? { text: "Tốt", tone: "good" }
                  : rate >= 85
                    ? { text: "Cần chú ý", tone: "warn" }
                    : { text: "Kém", tone: "bad" },
        detail: rate != null
            ? `Đi muộn ${late} lần · vắng ${absent} ngày · nghỉ phép ${recent.reduce((sum, m) => sum + m.leave_days, 0)} ngày`
            : "Chưa có lượt chấm công nào",
    });

    const allowances = j.allowances ?? [];
    const allowanceText = (a) => `${money(a.amount)}${a.unit === "day" ? "/ngày" : "/tháng"}`;
    tiles.push({
        key: "allowance",
        icon: "mdi-hand-coin-outline",
        label: "Phụ cấp đang hưởng",
        value: allowances.length === 1 ? allowanceText(allowances[0]) : allowances.length ? `${allowances.length} khoản` : "Không có",
        badge: null,
        detail: allowances.length
            ? allowances.map((a) => (allowances.length === 1 ? a.name : `${a.name} ${allowanceText(a)}`)).join(" · ")
            : "Chưa được hưởng khoản nào",
    });

    return tiles;
});
const filteredEvents = computed(() =>
    (journey.value?.events ?? []).filter((event) => groupFilter.value === "all" || event.group === groupFilter.value),
);

const tenureLabel = computed(() => {
    const t = summary.value.tenure;
    if (!t) return "—";
    const parts = [];
    if (t.years) parts.push(`${t.years} năm`);
    if (t.months) parts.push(`${t.months} tháng`);
    if (!t.years && t.days) parts.push(`${t.days} ngày`);
    return parts.join(" ") || "Hôm nay";
});

function money(value) {
    return value == null ? "—" : `${Math.round(value).toLocaleString("vi-VN")} ₫`;
}

function formatDate(value) {
    if (!value) return "";
    const d = new Date(String(value).length === 10 ? `${value}T00:00:00` : value);
    const pad = (n) => String(n).padStart(2, "0");
    return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}`;
}

async function load() {
    loadError.value = "";
    try {
        journey.value = (await employeeService.journey(props.employeeId)).data.data;
    } catch (e) {
        loadError.value = e.response?.data?.message ?? "Không tải được hành trình nhân viên.";
    } finally {
        loading.value = false;
    }
}

useRealtimeRefresh(load, {
    shared: [
        { resource: "employees", permission: "employee.view" },
        { resource: "employee_contracts", permission: "employee.view" },
        { resource: "employee_transfers", permission: "employee.view" },
        { resource: "payrolls", permission: "payroll.view_all" },
        { resource: "employee_allowances", permission: "payroll.view_all" },
        "projects",
    ],
});

onMounted(load);
</script>

<style scoped>
.highlights {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
}
.tile__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    border-radius: 8px;
}
.tile__label {
    letter-spacing: 0.04em;
    opacity: 0.65;
}
.tile__detail {
    opacity: 0.75;
}
@media (max-width: 960px) {
    .highlights {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
@media (max-width: 520px) {
    .highlights {
        grid-template-columns: 1fr;
    }
}
.interview + .interview {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}
.sections {
    display: grid;
    gap: 6px;
    max-width: 520px;
}
.sections__row {
    display: grid;
    grid-template-columns: minmax(0, 200px) 1fr 48px;
    align-items: center;
    gap: 10px;
}
</style>
