<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

// Dữ liệu Tỉnh/Xã lấy từ provinces.open-api.vn (đã cập nhật theo đợt sáp nhập
// hành chính 2025 — chỉ còn 2 cấp Tỉnh -> Xã/Phường, không còn Huyện), tải về
// 1 lần và lưu tĩnh tại database/seeders/data/*.json thay vì tự gõ tay hơn
// 3300 xã/phường. Muốn cập nhật lại khi ranh giới hành chính đổi tiếp thì tải
// lại 2 file JSON này, không cần sửa Seeder.
class ProvinceCommuneSeeder extends Seeder
{
    public function run(): void
    {
        $provinces = json_decode(
            file_get_contents(__DIR__.'/data/provinces.json'),
            true
        );
        $communes = json_decode(
            file_get_contents(__DIR__.'/data/communes.json'),
            true
        );

        $now = now();

        foreach (array_chunk($provinces, 500) as $chunk) {
            DB::table('provinces')->insertOrIgnore(array_map(
                fn (array $p) => [...$p, 'created_at' => $now, 'updated_at' => $now],
                $chunk
            ));
        }

        foreach (array_chunk($communes, 500) as $chunk) {
            DB::table('communes')->insertOrIgnore(array_map(
                fn (array $c) => [...$c, 'created_at' => $now, 'updated_at' => $now],
                $chunk
            ));
        }
    }
}
