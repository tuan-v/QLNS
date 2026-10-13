<?php

return [
    // Email liên hệ ghi trong thư mời nhận việc (offer) + Reply-To — LUÔN là email hệ thống
    // của công ty, không dùng email cá nhân của HR (người dùng yêu cầu 2026-10-13). Đặt
    // RECRUITMENT_CONTACT_EMAIL = hộp thư tuyển dụng của công ty (vd tuyendung@congty.vn);
    // không đặt thì dùng địa chỉ gửi email của hệ thống (MAIL_FROM_ADDRESS).
    'offer_contact_email' => env('RECRUITMENT_CONTACT_EMAIL') ?: env('MAIL_FROM_ADDRESS'),
];
