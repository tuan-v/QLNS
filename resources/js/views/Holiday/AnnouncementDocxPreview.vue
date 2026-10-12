<template>
    <!-- Xem trước file .docx (docx-preview vẽ DOM, không dùng v-html), tự thu nhỏ trang A4
         cho vừa khung. Truyền ArrayBuffer của file; null = chưa có. -->
    <div ref="frame" class="docx-frame">
        <div v-if="error" class="pa-6 text-center text-error text-body-2">{{ error }}</div>
        <div v-show="!error" ref="container" class="docx-stage" :style="{ zoom: scale }"></div>
        <v-fade-transition>
            <div v-if="busy" class="docx-busy">
                <v-progress-circular indeterminate size="28" color="primary" />
            </div>
        </v-fade-transition>
    </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from "vue";

const props = defineProps({
    data: { type: [ArrayBuffer, Object], default: null },
    busy: { type: Boolean, default: false },
});

// Bề ngang trang A4 docx-preview dựng ở 96dpi (21cm) + lề khung.
const PAGE_WIDTH = 794 + 32;

const frame = ref(null);
const container = ref(null);
const scale = ref(1);
const error = ref("");
let observer = null;

async function render() {
    if (!props.data || !container.value) return;
    error.value = "";
    try {
        const { renderAsync } = await import("docx-preview");
        const target = document.createElement("div");
        await renderAsync(props.data, target, null, { inWrapper: true, ignoreLastRenderedPageBreak: true });
        // Thay nội dung 1 lần sau khi dựng xong -> không nháy trắng khi đang gõ.
        container.value.replaceChildren(...target.childNodes);
    } catch {
        error.value = "Không dựng được bản xem trước file Word.";
    }
}

function fit() {
    if (!frame.value) return;
    scale.value = Math.min(1, frame.value.clientWidth / PAGE_WIDTH);
}

watch(() => props.data, render);

onMounted(() => {
    fit();
    observer = new ResizeObserver(fit);
    observer.observe(frame.value);
    render();
});

onBeforeUnmount(() => observer?.disconnect());
</script>

<style scoped>
.docx-frame {
    position: relative;
    min-height: 420px;
    background: rgba(var(--v-theme-on-surface), 0.06);
    border-radius: 12px;
    overflow: hidden;
}
.docx-stage :deep(.docx-wrapper) {
    background: transparent;
    padding: 16px;
}
.docx-stage :deep(.docx-wrapper > section.docx) {
    margin: 0 auto 16px;
    box-shadow: 0 6px 24px rgba(0, 0, 0, 0.18);
}
.docx-busy {
    position: absolute;
    top: 12px;
    right: 12px;
    background: rgb(var(--v-theme-surface));
    border-radius: 50%;
    padding: 6px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}
</style>
