<?php

namespace App\Support;

// Ngân hàng tại Việt Nam để nhân viên CHỌN (không gõ tay -> không sai tên khi chuyển
// lương). Mã = tên viết tắt thông dụng. Thêm ngân hàng: thêm 1 dòng ở đây.
final class VietnamBanks
{
    public const ALL = [
        'VCB' => 'Vietcombank — Ngân hàng TMCP Ngoại thương Việt Nam',
        'BIDV' => 'BIDV — Ngân hàng TMCP Đầu tư và Phát triển Việt Nam',
        'CTG' => 'VietinBank — Ngân hàng TMCP Công thương Việt Nam',
        'AGR' => 'Agribank — Ngân hàng Nông nghiệp và Phát triển Nông thôn',
        'TCB' => 'Techcombank — Ngân hàng TMCP Kỹ thương Việt Nam',
        'MB' => 'MB — Ngân hàng TMCP Quân đội',
        'ACB' => 'ACB — Ngân hàng TMCP Á Châu',
        'VPB' => 'VPBank — Ngân hàng TMCP Việt Nam Thịnh Vượng',
        'TPB' => 'TPBank — Ngân hàng TMCP Tiên Phong',
        'STB' => 'Sacombank — Ngân hàng TMCP Sài Gòn Thương Tín',
        'VIB' => 'VIB — Ngân hàng TMCP Quốc tế Việt Nam',
        'HDB' => 'HDBank — Ngân hàng TMCP Phát triển TP.HCM',
        'SHB' => 'SHB — Ngân hàng TMCP Sài Gòn - Hà Nội',
        'MSB' => 'MSB — Ngân hàng TMCP Hàng Hải Việt Nam',
        'OCB' => 'OCB — Ngân hàng TMCP Phương Đông',
        'EIB' => 'Eximbank — Ngân hàng TMCP Xuất Nhập khẩu Việt Nam',
        'SCB' => 'SCB — Ngân hàng TMCP Sài Gòn',
        'SEAB' => 'SeABank — Ngân hàng TMCP Đông Nam Á',
        'LPB' => 'LPBank — Ngân hàng TMCP Lộc Phát Việt Nam',
        'NAB' => 'Nam A Bank — Ngân hàng TMCP Nam Á',
        'BAB' => 'Bac A Bank — Ngân hàng TMCP Bắc Á',
        'ABB' => 'ABBANK — Ngân hàng TMCP An Bình',
        'VAB' => 'VietABank — Ngân hàng TMCP Việt Á',
        'PVCB' => 'PVcomBank — Ngân hàng TMCP Đại Chúng Việt Nam',
        'KLB' => 'KienlongBank — Ngân hàng TMCP Kiên Long',
        'BVB' => 'BVBank — Ngân hàng TMCP Bản Việt',
        'SGB' => 'Saigonbank — Ngân hàng TMCP Sài Gòn Công Thương',
        'CAKE' => 'CAKE by VPBank',
        'TIMO' => 'Timo by Bản Việt',
        'SHBVN' => 'Shinhan Bank Việt Nam',
        'WOO' => 'Woori Bank Việt Nam',
        'HSBC' => 'HSBC Việt Nam',
        'SCVN' => 'Standard Chartered Việt Nam',
        'UOB' => 'UOB Việt Nam',
        'CIMB' => 'CIMB Việt Nam',
    ];

    public static function name(?string $code): ?string
    {
        return $code ? (self::ALL[$code] ?? null) : null;
    }
}
