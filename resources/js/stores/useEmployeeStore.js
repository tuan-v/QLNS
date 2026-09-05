import { ref } from "vue";
import { defineStore } from "pinia";
import employeeService from "../services/employeeService";

export const useEmployeeStore = defineStore("employee", () => {
    const employees = ref([]);
    const pagination = ref(null);
    const loading = ref(false);
    const errors = ref({});
    const loadError = ref("");

    function resetErrors() {
        errors.value = {};
        loadError.value = "";
    }

    function handleError(error) {
        if (error.response?.status === 422) {
            errors.value = error.response.data.errors;
        } else {
            loadError.value =
                error.response?.data?.message ??
                "Không thể kết nối máy chủ, vui lòng thử lại.";
        }
    }

    async function fetchList(filters = {}) {
        resetErrors();
        loading.value = true;
        try {
            const response = await employeeService.list(filters);
            const { data, meta } = response.data;
            employees.value = data;
            pagination.value = meta;
        } catch (e) {
            employees.value = [];
            pagination.value = null;
            handleError(e);
        } finally {
            loading.value = false;
        }
    }

    return { employees, pagination, loading, errors, loadError, fetchList };
});
