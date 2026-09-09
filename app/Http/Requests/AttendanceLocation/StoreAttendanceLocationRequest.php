<?php

namespace App\Http\Requests\AttendanceLocation;

use Illuminate\Foundation\Http\FormRequest;

class StoreAttendanceLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // "code" và "qr_secret" không nhận từ client — AttendanceLocationService
            // tự sinh (code kiểu DD001..., qr_secret là chuỗi bí mật ngẫu nhiên).
            'name' => ['required', 'string', 'max:150'],
            'method' => ['required', 'in:wifi,gps,qr'],
            'wifi_ssid' => ['required_if:method,wifi', 'nullable', 'string', 'max:150'],
            'allowed_ip_cidr' => ['nullable', 'string', 'max:50'],
            'latitude' => ['required_if:method,gps', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['required_if:method,gps', 'nullable', 'numeric', 'between:-180,180'],
            'radius_meters' => ['required_if:method,gps', 'nullable', 'integer', 'min:1'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Tên điểm chấm công không được để trống',
            'name.max' => 'Tên điểm chấm công không được vượt quá 150 ký tự',
            'method.required' => 'Vui lòng chọn phương thức chấm công',
            'method.in' => 'Phương thức chấm công không hợp lệ',
            'wifi_ssid.required_if' => 'Vui lòng nhập tên Wifi (SSID) cho phương thức Wifi',
            'wifi_ssid.max' => 'Tên Wifi không được vượt quá 150 ký tự',
            'allowed_ip_cidr.max' => 'Dải IP không được vượt quá 50 ký tự',
            'latitude.required_if' => 'Vui lòng nhập vĩ độ cho phương thức GPS',
            'latitude.between' => 'Vĩ độ phải trong khoảng -90 đến 90',
            'longitude.required_if' => 'Vui lòng nhập kinh độ cho phương thức GPS',
            'longitude.between' => 'Kinh độ phải trong khoảng -180 đến 180',
            'radius_meters.required_if' => 'Vui lòng nhập bán kính cho phương thức GPS',
            'radius_meters.min' => 'Bán kính phải lớn hơn 0',
            'is_active.boolean' => 'Trạng thái phải là 1 hoặc 0',
        ];
    }
}
