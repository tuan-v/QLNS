import axios from "axios";
const API_BASE = "/api/v1/holidays";

export default {
    list(year) {
        return axios.get(API_BASE, { params: { year } });
    },
    convertLunar(params) {
        return axios.get(`${API_BASE}/convert`, { params });
    },
    create(payload) {
        return axios.post(API_BASE, payload);
    },
    generate(year) {
        return axios.post(`${API_BASE}/generate`, { year });
    },
    showGroup(groupCode) {
        return axios.get(`${API_BASE}/groups/${groupCode}`);
    },
    confirmGroup(groupCode, confirmed = true) {
        return axios.post(`${API_BASE}/groups/${groupCode}/confirm`, { confirmed });
    },
    setInternPaid(groupCode, internPaid) {
        return axios.post(`${API_BASE}/groups/${groupCode}/intern-paid`, { intern_paid: internPaid });
    },
    applyChanges(groupCode, payload) {
        return axios.post(`${API_BASE}/groups/${groupCode}/apply`, payload);
    },
    addDay(groupCode, payload) {
        return axios.post(`${API_BASE}/groups/${groupCode}/days`, payload);
    },
    addSwap(groupCode, payload) {
        return axios.post(`${API_BASE}/groups/${groupCode}/swaps`, payload);
    },
    removeDay(id) {
        return axios.delete(`${API_BASE}/days/${id}`);
    },
    removeGroup(groupCode) {
        return axios.delete(`${API_BASE}/groups/${groupCode}`);
    },
    notifyGroup(groupCode) {
        return axios.post(`${API_BASE}/groups/${groupCode}/notify`);
    },
    rules() {
        return axios.get(`${API_BASE}/rules`);
    },
    createRule(payload) {
        return axios.post(`${API_BASE}/rules`, payload);
    },
    updateRule(id, payload) {
        return axios.put(`${API_BASE}/rules/${id}`, payload);
    },
    removeRule(id) {
        return axios.delete(`${API_BASE}/rules/${id}`);
    },
};
