<template>
    <!-- Văn bản "Thông báo nghỉ lễ" theo mẫu Word của công ty (HolidayAnnouncementService).
         Văn bản chỉ có hiệu lực khi người đại diện ký + đóng dấu, nên đi 4 bước:
         Soạn -> Chốt & in trình ký (khóa nội dung) -> Tải lên bản đã ký (PDF) -> Phát hành.
         Người quản lý: soạn bên trái, bên phải là CHÍNH file Word sẽ in (server dựng, xem bằng
         docx-preview). Nhân viên: chỉ xem/tải BẢN ĐÃ KÝ đã phát hành. -->
    <div>
        <PageHeader :title="pageTitle" :subtitle="subtitle">
            <template #actions>
                <v-btn variant="text" prepend-icon="mdi-arrow-left" :to="{ name: 'holidays' }"> Lịch nghỉ lễ </v-btn>
                <template v-if="loaded && canManage">
                    <template v-if="status === 'draft'">
                        <v-btn
                            variant="tonal"
                            prepend-icon="mdi-microsoft-word"
                            :loading="downloading"
                            @click="downloadWord"
                        >
                            Tải file Word
                        </v-btn>
                        <v-btn
                            variant="tonal"
                            color="primary"
                            prepend-icon="mdi-content-save-outline"
                            :loading="saving"
                            @click="save"
                        >
                            Lưu nháp
                        </v-btn>
                        <v-btn
                            color="primary"
                            variant="flat"
                            prepend-icon="mdi-printer-outline"
                            @click="ask('prepare')"
                        >
                            Chốt & in trình ký
                        </v-btn>
                    </template>
                    <template v-else>
                        <v-btn variant="tonal" prepend-icon="mdi-lock-open-variant-outline" @click="ask('reopen')">
                            Mở lại để sửa
                        </v-btn>
                        <v-btn
                            v-if="status === 'pending_signature'"
                            variant="tonal"
                            prepend-icon="mdi-printer-outline"
                            :loading="downloading"
                            @click="downloadWord"
                        >
                            Tải file Word để in
                        </v-btn>
                    </template>
                </template>
                <template v-if="loaded && signedName">
                    <v-btn variant="tonal" prepend-icon="mdi-file-pdf-box" @click="signedPreview = true"
                        >Xem bản đã ký</v-btn
                    >
                    <v-btn
                        variant="tonal"
                        prepend-icon="mdi-download"
                        :loading="downloadingSigned"
                        @click="downloadSigned"
                        >Tải bản đã ký</v-btn
                    >
                </template>
            </template>
        </PageHeader>

        <v-alert v-if="loadError" type="error" variant="tonal" density="compact" class="mb-4">
            {{ loadError }}
        </v-alert>
        <div v-if="loading" class="d-flex justify-center py-12"><v-progress-circular indeterminate /></div>

        <template v-else-if="loaded">
            <!-- Các bước — chỉ người quản lý thấy -->
            <v-sheet v-if="canManage" class="border rounded-lg pa-3 mb-4 glass-panel" color="transparent">
                <div class="steps">
                    <div
                        v-for="(step, i) in STEPS"
                        :key="step.title"
                        class="step"
                        :class="{ 'step--done': i < stepIndex, 'step--active': i === stepIndex }"
                    >
                        <v-avatar
                            size="28"
                            :color="i < stepIndex ? 'success' : i === stepIndex ? 'primary' : undefined"
                            :variant="i <= stepIndex ? 'flat' : 'tonal'"
                        >
                            <v-icon v-if="i < stepIndex" icon="mdi-check" size="16" />
                            <span v-else class="text-caption font-weight-bold">{{ i + 1 }}</span>
                        </v-avatar>
                        <div class="min-width-0">
                            <div class="text-body-2 font-weight-bold">{{ step.title }}</div>
                            <div class="text-caption" style="opacity: 0.65">{{ step.hint }}</div>
                        </div>
                    </div>
                </div>
                <v-alert
                    :type="statusAlert.type"
                    variant="tonal"
                    density="compact"
                    class="mt-3"
                    :icon="statusAlert.icon"
                >
                    {{ statusAlert.text }}
                </v-alert>
            </v-sheet>

            <!-- Chờ ký: tải lên bản đã ký rồi phát hành -->
            <v-sheet
                v-if="canManage && status === 'pending_signature'"
                class="border rounded-lg pa-4 mb-4 glass-panel"
                color="transparent"
            >
                <div class="d-flex flex-wrap align-start ga-4">
                    <div class="flex-grow-1" style="min-width: 260px">
                        <div class="text-subtitle-2 font-weight-bold mb-1">Bản đã ký và đóng dấu</div>
                        <div v-if="signedName" class="text-body-2 mb-2">
                            <v-icon icon="mdi-file-pdf-box" color="error" size="18" />
                            <a href="#" class="text-primary" @click.prevent="signedPreview = true">{{ signedName }}</a>
                            <span style="opacity: 0.65">
                                — tải lên {{ formatDateTime(info.announcement.signed_uploaded_at) }}
                                <template v-if="info.announcement.signed_uploaded_by">
                                    bởi {{ info.announcement.signed_uploaded_by }}</template
                                >
                            </span>
                        </div>
                        <div class="d-flex align-start ga-2">
                            <InputFile
                                v-model="signedFile"
                                :limit="UPLOAD_LIMITS.holidaySigned"
                                :label="signedName ? 'Thay bằng bản scan khác' : 'Chọn bản scan đã ký (PDF)'"
                                :error-messages="err('signed_file')"
                                class="flex-grow-1"
                            />
                            <v-btn
                                color="primary"
                                variant="tonal"
                                height="48"
                                :loading="uploading"
                                :disabled="!pickedFile"
                                @click="uploadSigned"
                            >
                                Tải lên
                            </v-btn>
                        </div>
                    </div>
                    <div class="d-flex flex-column ga-1" style="min-width: 200px">
                        <v-btn
                            color="primary"
                            variant="flat"
                            size="large"
                            prepend-icon="mdi-send-outline"
                            :disabled="!signedName"
                            @click="ask('publish')"
                        >
                            Phát hành
                        </v-btn>
                        <div class="text-caption" style="opacity: 0.65; max-width: 240px">
                            {{
                                signedName
                                    ? "Gửi thông báo trong app; nhân viên xem bản đã ký."
                                    : "Cần tải lên bản đã ký trước khi phát hành."
                            }}
                        </div>
                    </div>
                </div>
            </v-sheet>

            <!-- Nhân viên: tóm tắt + bản đã ký -->
            <v-sheet v-if="!canManage" class="border rounded-lg pa-5 glass-panel" color="transparent">
                <div class="text-h6 font-weight-bold mb-1">{{ form.content.title }}</div>
                <div class="text-body-2 mb-4" style="opacity: 0.7">
                    Số {{ form.document_number }} · ban hành ngày {{ formatDate(form.issued_date) }}
                </div>
                <div class="schedule-box text-body-2 mb-4">
                    <div><strong>Thời gian nghỉ:</strong> {{ periodText }}</div>
                    <div>
                        <strong>Trở lại làm việc:</strong>
                        {{ info.schedule.return_weekday }}, ngày {{ formatDate(info.schedule.return_date) }}
                    </div>
                    <div v-if="info.schedule.makeup_days.length">
                        <strong>Lịch làm bù:</strong>
                        <span v-for="(m, i) in info.schedule.makeup_days" :key="m.date">
                            {{ i ? "; " : "" }}{{ m.weekday }}, ngày {{ formatDate(m.date) }} (làm bù cho ngày
                            {{ formatDate(m.replaces) }})
                        </span>
                    </div>
                    <div v-if="form.has_bonus">
                        <strong>Thưởng lễ:</strong>
                        <span v-for="(r, i) in form.bonus_rows" :key="i"
                            >{{ i ? "; " : "" }}{{ r.label }} {{ money(r.amount) }}</span
                        >
                        — {{ form.content.bonus_pay_time }}
                    </div>
                </div>
                <v-btn color="primary" variant="flat" prepend-icon="mdi-file-pdf-box" @click="signedPreview = true">
                    Xem văn bản đã ký
                </v-btn>
            </v-sheet>

            <v-row v-else>
                <v-col cols="12" lg="5">
                    <v-sheet class="border rounded-lg pa-4 glass-panel" color="transparent">
                        <!-- Đã chốt trình ký / phát hành: chỉ xem, muốn sửa phải "Mở lại" -->
                        <v-form :readonly="locked" @submit.prevent>
                            <FormSection title="Thông tin văn bản" icon="mdi-file-document-outline" :columns="2">
                                <FormField label="Loại hình công ty" required>
                                    <v-text-field
                                        v-model="form.content.company_type"
                                        :error-messages="err('content.company_type')"
                                    />
                                </FormField>
                                <FormField label="Tên công ty" required>
                                    <v-text-field
                                        v-model="form.content.company_name"
                                        :error-messages="err('content.company_name')"
                                    />
                                </FormField>
                                <FormField label="Số văn bản" hint="Tự gợi ý theo thứ tự trong năm, sửa được">
                                    <v-text-field
                                        v-model="form.document_number"
                                        :error-messages="err('document_number')"
                                    />
                                </FormField>
                                <FormField label="Nơi ban hành" required>
                                    <v-text-field v-model="form.content.city" :error-messages="err('content.city')" />
                                </FormField>
                                <FormField label="Ngày ban hành" required>
                                    <InputDate v-model="form.issued_date" :error-messages="err('issued_date')" />
                                </FormField>
                                <FormField label="Trích yếu" required span="full">
                                    <v-text-field v-model="form.content.title" :error-messages="err('content.title')" />
                                </FormField>
                                <FormField label="Câu mở đầu" required span="full">
                                    <v-textarea
                                        v-model="form.content.intro"
                                        rows="2"
                                        auto-grow
                                        :error-messages="err('content.intro')"
                                    />
                                </FormField>
                                <FormField label="1. Đối tượng" required span="full">
                                    <v-textarea
                                        v-model="form.content.audience"
                                        rows="2"
                                        auto-grow
                                        :error-messages="err('content.audience')"
                                    />
                                </FormField>
                            </FormSection>

                            <FormSection
                                title="2. Thời gian nghỉ"
                                icon="mdi-calendar-range"
                                description="Lấy từ lịch nghỉ — muốn đổi thì sửa ở trang Ngày nghỉ lễ (Điều chỉnh)."
                            >
                                <div class="schedule-box text-body-2">
                                    <div><strong>Thời gian nghỉ:</strong> {{ periodText }}</div>
                                    <div>
                                        <strong>Trở lại làm việc:</strong>
                                        {{ info.schedule.return_weekday }}, ngày
                                        {{ formatDate(info.schedule.return_date) }}
                                    </div>
                                    <div v-if="info.schedule.makeup_days.length">
                                        <strong>Lịch làm bù:</strong>
                                        <span v-for="(m, i) in info.schedule.makeup_days" :key="m.date">
                                            {{ i ? "; " : "" }}{{ m.weekday }}, ngày {{ formatDate(m.date) }} (làm bù
                                            cho ngày {{ formatDate(m.replaces) }})
                                        </span>
                                    </div>
                                    <div v-else style="opacity: 0.6">Không có lịch làm bù.</div>
                                </div>
                            </FormSection>

                            <FormSection title="Thưởng lễ" icon="mdi-gift-outline">
                                <template #header-actions>
                                    <v-switch
                                        v-model="form.has_bonus"
                                        color="primary"
                                        hide-details
                                        density="compact"
                                        :label="form.has_bonus ? 'Có thưởng' : 'Không thưởng'"
                                    />
                                </template>
                                <v-alert
                                    v-if="err('has_bonus')"
                                    type="error"
                                    variant="tonal"
                                    density="compact"
                                    class="mb-3"
                                >
                                    {{ err("has_bonus") }}
                                </v-alert>
                                <template v-if="form.has_bonus">
                                    <FormField label="Tiêu đề mục" required>
                                        <v-text-field
                                            v-model="form.content.bonus_heading"
                                            :error-messages="err('content.bonus_heading')"
                                        />
                                    </FormField>
                                    <div class="text-caption mb-2" style="opacity: 0.7">
                                        Mức thưởng tự cộng vào bảng lương theo loại hợp đồng vào ngày đầu kỳ nghỉ (vào
                                        làm sau ngày đó thì không được thưởng).
                                    </div>
                                    <v-alert
                                        v-if="err('bonus_rows')"
                                        type="error"
                                        variant="tonal"
                                        density="compact"
                                        class="mb-3"
                                    >
                                        {{ err("bonus_rows") }}
                                    </v-alert>
                                    <div
                                        v-for="(row, i) in form.bonus_rows"
                                        :key="i"
                                        class="bonus-row border rounded-lg pa-3 mb-2"
                                    >
                                        <div class="d-flex align-center ga-2 mb-2">
                                            <span class="text-body-2 font-weight-bold">{{ i + 1 }}.</span>
                                            <v-text-field
                                                v-model="row.label"
                                                placeholder="Vị trí"
                                                density="compact"
                                                hide-details="auto"
                                                :error-messages="err(`bonus_rows.${i}.label`)"
                                            />
                                            <v-btn
                                                v-if="!locked"
                                                icon="mdi-close"
                                                size="small"
                                                variant="text"
                                                :disabled="form.bonus_rows.length <= 1"
                                                @click="form.bonus_rows.splice(i, 1)"
                                            />
                                        </div>
                                        <div class="bonus-row__grid">
                                            <v-select
                                                v-model="row.category"
                                                :items="CATEGORY_ITEMS"
                                                label="Cộng lương cho"
                                                density="compact"
                                                hide-details="auto"
                                                :error-messages="err(`bonus_rows.${i}.category`)"
                                            />
                                            <InputMoney
                                                v-model="row.amount"
                                                label="Mức thưởng"
                                                density="compact"
                                                hide-details="auto"
                                                :error-messages="err(`bonus_rows.${i}.amount`)"
                                            />
                                            <v-text-field
                                                v-model="row.note"
                                                label="Ghi chú"
                                                density="compact"
                                                hide-details="auto"
                                                class="bonus-row__note"
                                            />
                                        </div>
                                    </div>
                                    <v-btn
                                        v-if="!locked && form.bonus_rows.length < 10"
                                        size="small"
                                        variant="text"
                                        prepend-icon="mdi-plus"
                                        class="mb-3"
                                        @click="form.bonus_rows.push({ label: '', category: '', amount: 0, note: '' })"
                                    >
                                        Thêm dòng
                                    </v-btn>
                                    <div class="form-grid form-grid--2">
                                        <FormField label="Chi cùng lương tháng" required>
                                            <v-select
                                                v-model="bonusPeriod"
                                                :items="periodItems"
                                                :error-messages="err('bonus_payroll_month')"
                                            />
                                        </FormField>
                                        <FormField label="Hình thức chi">
                                            <v-text-field
                                                v-model="form.content.bonus_method"
                                                :error-messages="err('content.bonus_method')"
                                            />
                                        </FormField>
                                        <FormField label="Thời gian chi thưởng" span="full">
                                            <v-text-field
                                                v-model="form.content.bonus_pay_time"
                                                :error-messages="err('content.bonus_pay_time')"
                                            />
                                        </FormField>
                                    </div>
                                </template>
                                <div v-else class="text-body-2" style="opacity: 0.7">
                                    Dịp này không có thưởng — văn bản không có mục chi thưởng.
                                </div>
                            </FormSection>

                            <FormSection title="Trách nhiệm các phòng ban" icon="mdi-format-list-bulleted">
                                <div
                                    v-for="(line, i) in form.content.responsibilities"
                                    :key="i"
                                    class="d-flex align-start ga-1 mb-2"
                                >
                                    <v-textarea
                                        v-model="form.content.responsibilities[i]"
                                        rows="1"
                                        auto-grow
                                        density="compact"
                                        hide-details="auto"
                                        :error-messages="err(`content.responsibilities.${i}`)"
                                    />
                                    <v-btn
                                        v-if="!locked"
                                        icon="mdi-close"
                                        size="small"
                                        variant="text"
                                        @click="form.content.responsibilities.splice(i, 1)"
                                    />
                                </div>
                                <v-btn
                                    v-if="!locked && form.content.responsibilities.length < 20"
                                    size="small"
                                    variant="text"
                                    prepend-icon="mdi-plus"
                                    @click="form.content.responsibilities.push('')"
                                >
                                    Thêm ý
                                </v-btn>
                            </FormSection>

                            <FormSection title="Kết thúc, người ký & nơi nhận" icon="mdi-draw-pen">
                                <FormField label="Lời chúc (in đậm)">
                                    <v-textarea
                                        v-model="form.content.closing"
                                        rows="2"
                                        auto-grow
                                        :error-messages="err('content.closing')"
                                    />
                                </FormField>
                                <FormField label="Câu kết">
                                    <v-text-field
                                        v-model="form.content.sign_off"
                                        :error-messages="err('content.sign_off')"
                                    />
                                </FormField>
                                <div class="form-grid form-grid--2 mb-4">
                                    <FormField label="Chức danh người ký" required>
                                        <v-text-field
                                            v-model="form.content.signer_title"
                                            placeholder="GIÁM ĐỐC"
                                            :error-messages="err('content.signer_title')"
                                        />
                                    </FormField>
                                    <FormField label="Họ tên người ký" hint="Để trống nếu người ký tự ghi tay">
                                        <v-text-field
                                            v-model="form.content.signer_name"
                                            :error-messages="err('content.signer_name')"
                                        />
                                    </FormField>
                                </div>
                                <FormField label="Nơi nhận">
                                    <div
                                        v-for="(r, i) in form.content.recipients"
                                        :key="i"
                                        class="d-flex align-center ga-2 mb-2"
                                    >
                                        <v-text-field
                                            v-model="r.text"
                                            placeholder="Ban giám đốc"
                                            density="compact"
                                            hide-details="auto"
                                            :error-messages="err(`content.recipients.${i}.text`)"
                                        />
                                        <v-text-field
                                            v-model="r.note"
                                            placeholder="để b/c"
                                            density="compact"
                                            hide-details
                                            style="max-width: 120px"
                                        />
                                        <v-btn
                                            v-if="!locked"
                                            icon="mdi-close"
                                            size="small"
                                            variant="text"
                                            @click="form.content.recipients.splice(i, 1)"
                                        />
                                    </div>
                                    <v-btn
                                        v-if="!locked && form.content.recipients.length < 10"
                                        size="small"
                                        variant="text"
                                        prepend-icon="mdi-plus"
                                        @click="form.content.recipients.push({ text: '', note: '' })"
                                    >
                                        Thêm nơi nhận
                                    </v-btn>
                                </FormField>
                            </FormSection>
                        </v-form>
                    </v-sheet>
                </v-col>

                <v-col cols="12" lg="7">
                    <div class="preview-col">
                        <div class="d-flex align-center ga-2 mb-2 text-caption" style="opacity: 0.7">
                            <v-icon icon="mdi-eye-outline" size="16" />
                            {{
                                locked
                                    ? "File Word đã chốt — đúng bản cần in để trình ký."
                                    : "Bản xem trước chính là file Word sẽ in — tự cập nhật khi bạn sửa."
                            }}
                        </div>
                        <AnnouncementDocxPreview :data="docx" :busy="previewing" />
                    </div>
                </v-col>
            </v-row>
        </template>

        <!-- Xác nhận các bước -->
        <v-dialog v-model="confirm.open" max-width="500">
            <v-card v-if="confirm.action" rounded="xl">
                <v-card-title class="pt-5 px-6">{{ CONFIRMS[confirm.action].title }}</v-card-title>
                <v-card-text class="px-6">
                    {{ CONFIRMS[confirm.action].text }}
                    <v-alert
                        v-if="confirm.action !== 'reopen' && form?.has_bonus"
                        type="info"
                        variant="tonal"
                        density="compact"
                        class="mt-3"
                    >
                        Thưởng lễ được cộng vào bảng lương tháng {{ form.bonus_payroll_month }}/{{
                            form.bonus_payroll_year
                        }}
                        (chỉ khi văn bản đã phát hành).
                    </v-alert>
                    <v-alert
                        v-if="confirm.action === 'reopen' && status === 'published'"
                        type="warning"
                        variant="tonal"
                        density="compact"
                        class="mt-3"
                    >
                        Văn bản đang phát hành — nhân viên sẽ không xem được cho tới khi ký và phát hành lại.
                    </v-alert>
                </v-card-text>
                <v-card-actions class="px-6 pb-5">
                    <v-spacer />
                    <v-btn variant="text" @click="confirm.open = false">Hủy</v-btn>
                    <v-btn
                        :color="confirm.action === 'reopen' ? 'warning' : 'primary'"
                        variant="flat"
                        :loading="busyAction"
                        @click="runConfirmed"
                    >
                        {{ CONFIRMS[confirm.action].button }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <FilePreviewDialog
            v-if="signedName"
            v-model="signedPreview"
            :file-url="holidayService.signedUrl(groupCode)"
            :file-name="signedName"
        />
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from "vue";
import { onBeforeRouteLeave, useRoute } from "vue-router";
import holidayService from "../../services/holidayService";
import PageHeader from "../../components/common/PageHeader.vue";
import FormSection from "../../components/common/FormSection.vue";
import FormField from "../../components/common/FormField.vue";
import InputDate from "../../components/common/InputDate.vue";
import InputMoney from "../../components/common/InputMoney.vue";
import InputFile, { UPLOAD_LIMITS } from "../../components/common/InputFile.vue";
import FilePreviewDialog from "../../components/common/FilePreviewDialog.vue";
import AnnouncementDocxPreview from "./AnnouncementDocxPreview.vue";
import { useAuthStore } from "../../stores/authStore";
import { useToastStore } from "../../stores/useToastStore";

const CATEGORY_ITEMS = [
    { title: "Nhân sự chính thức", value: "official" },
    { title: "Nhân sự thử việc", value: "probation" },
    { title: "Thực tập sinh / học việc", value: "intern" },
    { title: "Không tự cộng lương", value: "" },
];

const STEPS = [
    { title: "Soạn văn bản", hint: "Sửa nội dung, xem trước bản Word" },
    { title: "Chốt & in trình ký", hint: "Khóa nội dung, tải Word in ra" },
    { title: "Tải lên bản đã ký", hint: "Bản scan PDF có chữ ký, dấu" },
    { title: "Phát hành", hint: "Gửi thông báo cho nhân viên" },
];

const CONFIRMS = {
    prepare: {
        title: "Chốt văn bản để trình ký",
        text: 'Nội dung sẽ được lưu và khóa lại, file Word được tải về để in trình ký. Muốn sửa sau đó phải bấm "Mở lại để sửa" và ký lại.',
        button: "Chốt & tải file Word",
    },
    publish: {
        title: "Phát hành thông báo",
        text: "Gửi thông báo trong app tới toàn bộ nhân viên đang hoạt động; nhân viên xem và tải được bản đã ký.",
        button: "Phát hành",
    },
    reopen: {
        title: "Mở lại để sửa",
        text: "Văn bản trở về bản nháp và bản đã ký hiện tại bị bỏ — sửa xong phải in, ký và tải lên lại.",
        button: "Mở lại",
    },
};

const route = useRoute();
const auth = useAuthStore();
const toast = useToastStore();
const canManage = computed(() => auth.permissions.includes("holiday.manage"));
const groupCode = computed(() => route.params.groupCode);

const info = ref(null);
const form = ref(null);
const loading = ref(true);
const loadError = ref("");
const errors = ref({});
const docx = ref(null);
const previewing = ref(false);
const saving = ref(false);
const downloading = ref(false);
const downloadingSigned = ref(false);
const uploading = ref(false);
const busyAction = ref(false);
const signedFile = ref(null);
const signedPreview = ref(false);
const confirm = reactive({ open: false, action: null });
let savedState = "";

const loaded = computed(() => !!info.value && !!form.value);
const status = computed(() => info.value?.announcement?.status ?? "draft");
const locked = computed(() => status.value !== "draft");
const signedName = computed(() => info.value?.announcement?.signed_file_name ?? null);
const pickedFile = computed(() => (Array.isArray(signedFile.value) ? signedFile.value[0] : signedFile.value) ?? null);
const stepIndex = computed(() => {
    if (status.value === "published") return 4;
    if (status.value === "pending_signature") return signedName.value ? 3 : 2;
    return 0;
});
const statusAlert = computed(() => {
    const a = info.value?.announcement;
    if (status.value === "published") {
        return {
            type: "success",
            icon: "mdi-check-decagram-outline",
            text: `Đã phát hành ${formatDateTime(a.published_at)}${a.published_by ? ` bởi ${a.published_by}` : ""}. Nhân viên đang xem bản đã ký.`,
        };
    }
    if (status.value === "pending_signature") {
        return {
            type: "warning",
            icon: "mdi-draw-pen",
            text: "Đang chờ ký — in file Word, trình người đại diện ký và đóng dấu, rồi tải bản scan lên để phát hành. Nội dung đã khóa.",
        };
    }
    return a
        ? { type: "info", icon: "mdi-file-document-edit-outline", text: "Bản nháp — nhân viên chưa xem được." }
        : {
              type: "info",
              icon: "mdi-file-document-plus-outline",
              text: "Chưa có văn bản cho dịp này — nội dung lấy từ lịch nghỉ và văn bản gần nhất.",
          };
});
const dirty = computed(() => !!form.value && !locked.value && JSON.stringify(form.value) !== savedState);
const pageTitle = computed(() => (info.value ? `Thông báo nghỉ ${info.value.schedule.name}` : "Thông báo nghỉ lễ"));
const subtitle = computed(() =>
    canManage.value
        ? "Soạn theo mẫu Word của công ty, in trình ký, tải lên bản đã ký rồi phát hành cho nhân viên."
        : "Văn bản thông báo nghỉ lễ đã ký của công ty.",
);
const periodText = computed(() => {
    const s = info.value?.schedule;
    if (!s) return "";
    return s.start_date === s.end_date
        ? `ngày ${formatDate(s.start_date)}`
        : `Từ ngày ${formatDate(s.start_date)} đến hết ngày ${formatDate(s.end_date)}`;
});
const periodItems = computed(() =>
    (info.value?.payroll_periods ?? []).map((p) => ({
        title: `Tháng ${p.month}/${p.year}${p.has_payroll ? " (đã tạo bảng lương)" : ""}`,
        value: `${p.month}-${p.year}`,
        props: { disabled: p.has_payroll && !isCurrentPeriod(p) },
    })),
);
const bonusPeriod = computed({
    get: () =>
        form.value?.bonus_payroll_month ? `${form.value.bonus_payroll_month}-${form.value.bonus_payroll_year}` : null,
    set: (value) => {
        const [month, year] = value.split("-").map(Number);
        const oldDefault = defaultPayTime(form.value.bonus_payroll_month, form.value.bonus_payroll_year);
        form.value.bonus_payroll_month = month;
        form.value.bonus_payroll_year = year;
        // Câu "thời gian chi thưởng" còn là câu mặc định thì đổi theo tháng mới.
        if (!form.value.content.bonus_pay_time || form.value.content.bonus_pay_time === oldDefault) {
            form.value.content.bonus_pay_time = defaultPayTime(month, year);
        }
    },
});

function isCurrentPeriod(p) {
    return p.month === form.value?.bonus_payroll_month && p.year === form.value?.bonus_payroll_year;
}

function defaultPayTime(month, year) {
    return `Chi trả cùng lương tháng ${month}/${year}.`;
}

function err(key) {
    return errors.value[key]?.[0] ?? "";
}

function payload() {
    const f = form.value;
    return {
        ...f,
        bonus_rows: f.has_bonus
            ? f.bonus_rows.map((r) => ({ ...r, category: r.category || null, amount: r.amount ?? 0 }))
            : [],
    };
}

function formatDate(value) {
    if (!value) return "";
    const [y, m, d] = value.slice(0, 10).split("-");
    return `${d}/${m}/${y}`;
}

function formatDateTime(value) {
    if (!value) return "";
    return `${value.slice(11, 16)} ${formatDate(value)}`;
}

function money(value) {
    return `${Math.round(value ?? 0).toLocaleString("vi-VN")} đồng`;
}

// Lỗi 422 của request trả ArrayBuffer (file Word) phải tự giải mã JSON.
function errorsOf(error) {
    let body = error.response?.data;
    if (body instanceof ArrayBuffer) {
        try {
            body = JSON.parse(new TextDecoder().decode(body));
        } catch {
            body = null;
        }
    }
    return { errors: body?.errors ?? null, message: body?.message };
}

function handleError(error) {
    const e = errorsOf(error);
    if (e.errors) {
        errors.value = e.errors;
        toast.error(Object.values(e.errors)[0]?.[0] ?? "Dữ liệu chưa hợp lệ.");
    } else {
        toast.error(e.message ?? "Có lỗi xảy ra, vui lòng thử lại.");
    }
}

function applyInfo(data) {
    info.value = data;
    const f = structuredClone(data.form);
    f.bonus_rows = (f.bonus_rows ?? []).map((r) => ({ ...r, category: r.category ?? "", note: r.note ?? "" }));
    f.content.recipients = (f.content.recipients ?? []).map((r) => ({ text: r.text ?? "", note: r.note ?? "" }));
    f.content.responsibilities = f.content.responsibilities ?? [];
    f.content.signer_name = f.content.signer_name ?? "";
    form.value = f;
    savedState = JSON.stringify(f);
}

async function load() {
    loading.value = true;
    loadError.value = "";
    try {
        applyInfo((await holidayService.announcement(groupCode.value)).data);
        if (canManage.value) {
            await refreshPreview();
        } else if (signedName.value) {
            signedPreview.value = true; // nhân viên vào từ thông báo -> mở luôn bản đã ký
        }
    } catch (error) {
        loadError.value =
            error.response?.status === 404
                ? "Dịp nghỉ này chưa có thông báo được phát hành."
                : (error.response?.data?.message ?? "Không tải được thông báo.");
    } finally {
        loading.value = false;
    }
}

/* ---- Xem trước: bản nháp -> dựng từ nội dung đang soạn; đã chốt -> đúng file đã lưu ---- */
let previewTimer = null;
let previewSeq = 0;

async function refreshPreview() {
    const seq = ++previewSeq;
    previewing.value = true;
    try {
        const response = locked.value
            ? await holidayService.announcementDocx(groupCode.value)
            : await holidayService.previewAnnouncement(groupCode.value, payload());
        if (seq !== previewSeq) return;
        docx.value = response.data;
        errors.value = {};
    } catch (error) {
        if (seq !== previewSeq) return;
        const e = errorsOf(error);
        // Đang gõ dở (thiếu trường bắt buộc) -> giữ bản xem trước cũ, báo lỗi dưới ô.
        if (e.errors) errors.value = e.errors;
    } finally {
        if (seq === previewSeq) previewing.value = false;
    }
}

watch(
    form,
    () => {
        if (!canManage.value || loading.value || locked.value) return;
        clearTimeout(previewTimer);
        previewTimer = setTimeout(refreshPreview, 700);
    },
    { deep: true },
);

onBeforeUnmount(() => clearTimeout(previewTimer));

/* ---- Các thao tác ---- */
async function save() {
    saving.value = true;
    try {
        applyInfo((await holidayService.saveAnnouncement(groupCode.value, payload())).data);
        errors.value = {};
        toast.success("Đã lưu nháp.");
    } catch (error) {
        handleError(error);
    } finally {
        saving.value = false;
    }
}

function ask(action) {
    confirm.action = action;
    confirm.open = true;
}

async function runConfirmed() {
    busyAction.value = true;
    try {
        if (confirm.action === "prepare") {
            applyInfo((await holidayService.prepareSigning(groupCode.value, payload())).data);
            errors.value = {};
            confirm.open = false;
            await refreshPreview();
            await downloadWord();
            toast.success("Đã chốt văn bản — in file Word vừa tải về để trình ký.");
        } else if (confirm.action === "publish") {
            const response = await holidayService.publishAnnouncement(groupCode.value);
            applyInfo(response.data);
            confirm.open = false;
            toast.success(`Đã phát hành và gửi thông báo tới ${response.data.recipients} tài khoản.`);
        } else {
            applyInfo((await holidayService.reopenAnnouncement(groupCode.value)).data);
            confirm.open = false;
            await refreshPreview();
            toast.success("Đã mở lại văn bản để sửa.");
        }
    } catch (error) {
        confirm.open = false;
        handleError(error);
    } finally {
        busyAction.value = false;
    }
}

async function uploadSigned() {
    if (!pickedFile.value) return;
    uploading.value = true;
    try {
        applyInfo((await holidayService.uploadSigned(groupCode.value, pickedFile.value)).data);
        signedFile.value = null;
        errors.value = {};
        toast.success("Đã tải lên bản đã ký — kiểm tra lại rồi bấm Phát hành.");
    } catch (error) {
        handleError(error);
    } finally {
        uploading.value = false;
    }
}

function saveBlob(response, fallbackName) {
    const disposition = response.headers["content-disposition"] ?? "";
    const name = /filename="?([^";]+)"?/.exec(disposition)?.[1] ?? fallbackName;
    const url = URL.createObjectURL(new Blob([response.data], { type: response.headers["content-type"] }));
    Object.assign(document.createElement("a"), { href: url, download: name }).click();
    URL.revokeObjectURL(url);
}

// Bản nháp: đúng nội dung đang thấy (kể cả chưa lưu); đã chốt: file đã lưu để in.
async function downloadWord() {
    downloading.value = true;
    try {
        const response = locked.value
            ? await holidayService.announcementDocx(groupCode.value)
            : await holidayService.previewAnnouncement(groupCode.value, payload());
        saveBlob(response, "Thong-bao-nghi-le.docx");
    } catch (error) {
        handleError(error);
    } finally {
        downloading.value = false;
    }
}

async function downloadSigned() {
    downloadingSigned.value = true;
    try {
        saveBlob(
            await window.axios.get(holidayService.signedUrl(groupCode.value), { responseType: "blob" }),
            signedName.value,
        );
    } catch (error) {
        handleError(error);
    } finally {
        downloadingSigned.value = false;
    }
}

onBeforeRouteLeave(() => {
    if (canManage.value && dirty.value) {
        return window.confirm("Văn bản có thay đổi chưa lưu. Rời trang và bỏ các thay đổi?");
    }
    return true;
});

watch(groupCode, load, { immediate: true });
</script>

<style scoped>
.steps {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
}
@media (max-width: 960px) {
    .steps {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
.step {
    display: flex;
    align-items: center;
    gap: 10px;
    opacity: 0.55;
}
.step--done,
.step--active {
    opacity: 1;
}
.schedule-box {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 12px;
    border-radius: 10px;
    background: rgba(var(--v-theme-primary), 0.06);
}
.bonus-row__grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
}
.bonus-row__note {
    grid-column: 1 / -1;
}
.preview-col {
    position: sticky;
    top: 80px;
}
</style>
