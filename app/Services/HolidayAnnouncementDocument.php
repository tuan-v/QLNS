<?php

namespace App\Services;

use Carbon\Carbon;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\ListItem;

// Dựng file .docx "Thông báo nghỉ lễ" theo mẫu Word của công ty (Times New Roman, A4, lề
// trái 3cm): khối tên công ty/quốc hiệu, số văn bản + nơi/ngày ban hành, tiêu đề, các mục
// đánh số TỰ ĐỘNG (mục thưởng chỉ có khi bật thưởng nên các mục sau tự lùi số), nơi nhận.
class HolidayAnnouncementDocument
{
    private const FONT = 'Times New Roman';

    private const BODY_SIZE = 13;

    private const TEXT_WIDTH_CM = 16; // A4 21cm − lề trái 3cm − lề phải 2cm

    private const PARAGRAPH = ['alignment' => Jc::BOTH, 'spaceAfter' => 60, 'lineHeight' => 1.15];

    private const BULLET = ['listType' => ListItem::TYPE_BULLET_FILLED];

    /** @return string đường dẫn file tạm (controller xóa sau khi gửi) */
    public function build(array $form, array $schedule): string
    {
        Settings::setOutputEscapingEnabled(true);

        $word = new PhpWord();
        $word->setDefaultFontName(self::FONT);
        $word->setDefaultFontSize(self::BODY_SIZE);
        $word->getDocInfo()->setTitle($form['content']['title'] ?? 'Thông báo nghỉ lễ');

        $section = $word->addSection([
            'paperSize' => 'A4',
            'marginTop' => Converter::cmToTwip(2),
            'marginBottom' => Converter::cmToTwip(2),
            'marginLeft' => Converter::cmToTwip(3),
            'marginRight' => Converter::cmToTwip(2),
        ]);

        $content = $form['content'];
        $this->header($section, $form, $content);
        $this->title($section, $content);

        $section->addText($content['intro'] ?? '', [], self::PARAGRAPH);

        $number = 0;
        $this->heading($section, ++$number, 'Đối tượng');
        $section->addText($content['audience'] ?? '', [], self::PARAGRAPH);

        $this->heading($section, ++$number, 'Thời gian nghỉ');
        $this->schedule($section, $schedule);

        if (! empty($form['has_bonus'])) {
            $this->heading($section, ++$number, $content['bonus_heading'] ?? 'Nội dung chi thưởng');
            $this->bonus($section, $form, $content);
        }

        $responsibilities = array_values(array_filter($content['responsibilities'] ?? [], fn ($line) => trim((string) $line) !== ''));
        if ($responsibilities !== []) {
            $this->heading($section, ++$number, 'Trách nhiệm các phòng ban');
            foreach ($responsibilities as $line) {
                $section->addListItem($line, 0, [], self::BULLET, self::PARAGRAPH);
            }
        }

        if (! empty($content['closing'])) {
            $section->addText($content['closing'], ['bold' => true], self::PARAGRAPH);
        }
        if (! empty($content['sign_off'])) {
            $section->addText($content['sign_off'], [], self::PARAGRAPH);
        }

        $this->signatureBlock($section, $content);

        $path = tempnam(sys_get_temp_dir(), 'tbnl');
        IOFactory::createWriter($word, 'Word2007')->save($path);

        return $path;
    }

    private function header(Section $section, array $form, array $content): void
    {
        $center = ['alignment' => Jc::CENTER, 'spaceAfter' => 0];
        $table = $section->addTable(['layout' => 'fixed', 'cellMargin' => 0]);

        $table->addRow();
        $left = $table->addCell(Converter::cmToTwip(6.5));
        $left->addText(mb_strtoupper($content['company_type'] ?? ''), ['bold' => true, 'size' => 13], $center);
        $left->addText(mb_strtoupper($content['company_name'] ?? ''), ['bold' => true, 'size' => 13, 'underline' => 'single'], $center);

        $right = $table->addCell(Converter::cmToTwip(self::TEXT_WIDTH_CM - 6.5));
        $right->addText('CỘNG HÒA XÃ HỘI CHỦ NGHĨA VIỆT NAM', ['bold' => true, 'size' => 12], $center);
        $right->addText('Độc lập – Tự do – Hạnh phúc', ['bold' => true, 'size' => 13, 'underline' => 'single'], $center);

        $table->addRow();
        $spaced = $center + ['spaceBefore' => 160];
        $table->addCell(Converter::cmToTwip(6.5))->addText('Số: '.($form['document_number'] ?: '…../TB'), ['size' => 13], $spaced);

        $issued = ! empty($form['issued_date']) ? Carbon::parse($form['issued_date']) : now();
        $table->addCell(Converter::cmToTwip(self::TEXT_WIDTH_CM - 6.5))->addText(
            sprintf('%s, ngày %s tháng %s năm %d', $content['city'] ?? '', $issued->format('d'), $issued->format('m'), $issued->year),
            ['italic' => true, 'size' => 13],
            $spaced,
        );
    }

    private function title(Section $section, array $content): void
    {
        $section->addText('THÔNG BÁO', ['bold' => true, 'size' => 14], ['alignment' => Jc::CENTER, 'spaceBefore' => 360, 'spaceAfter' => 0]);
        $section->addText($content['title'] ?? '', ['bold' => true, 'size' => 14], ['alignment' => Jc::CENTER, 'spaceAfter' => 240]);
    }

    private function heading(Section $section, int $number, string $text): void
    {
        $section->addText($number.'. '.rtrim($text, ': ').':', ['bold' => true], ['spaceBefore' => 60, 'spaceAfter' => 60, 'keepNext' => true]);
    }

    private function schedule(Section $section, array $schedule): void
    {
        $start = Carbon::parse($schedule['start_date']);
        $end = Carbon::parse($schedule['end_date']);

        $run = $section->addListItemRun(0, self::BULLET, self::PARAGRAPH);
        $run->addText('Thời gian nghỉ', ['bold' => true]);
        if ($start->equalTo($end)) {
            $run->addText(': '.$this->weekday($start).', ngày ');
            $run->addText($start->format('d/m/Y'), ['bold' => true]);
        } else {
            $run->addText(': Từ ngày ');
            $run->addText($start->format('d/m/Y'), ['bold' => true]);
            $run->addText(' đến hết ngày ');
            $run->addText($end->format('d/m/Y'), ['bold' => true]);
        }
        $run->addText('.');

        $return = Carbon::parse($schedule['return_date']);
        $run = $section->addListItemRun(0, self::BULLET, self::PARAGRAPH);
        $run->addText('Thời gian trở lại làm việc', ['bold' => true]);
        $run->addText(': '.$this->weekday($return).', ngày ');
        $run->addText($return->format('d/m/Y'), ['bold' => true]);
        $run->addText('.');

        $makeups = $schedule['makeup_days'] ?? [];
        if ($makeups !== []) {
            $run = $section->addListItemRun(0, self::BULLET, self::PARAGRAPH);
            $run->addText('Lịch làm bù', ['bold' => true]);
            $run->addText(': ');
            foreach (array_values($makeups) as $i => $makeup) {
                $date = Carbon::parse($makeup['date']);
                $run->addText(($i > 0 ? '; ' : '').$this->weekday($date).', ngày ');
                $run->addText($date->format('d/m/Y'), ['bold' => true]);
                $run->addText(' (làm bù cho ngày ');
                $run->addText(Carbon::parse($makeup['replaces'])->format('d/m/Y'), ['bold' => true]);
                $run->addText(')');
            }
            $run->addText('.');
        }
    }

    private function bonus(Section $section, array $form, array $content): void
    {
        $widths = [1.3, 6.5, 4.5, 3.7];
        $border = ['borderSize' => 6, 'borderColor' => '000000'];
        $center = ['alignment' => Jc::CENTER, 'spaceAfter' => 0];
        $cell = ['valign' => 'center'];

        $table = $section->addTable($border + ['layout' => 'fixed', 'cellMarginLeft' => 80, 'cellMarginRight' => 80, 'cellMarginTop' => 40, 'cellMarginBottom' => 40]);
        $table->addRow(null, ['tblHeader' => true]);
        foreach (['STT', 'Vị trí', 'Mức chi thưởng', 'Ghi chú'] as $i => $label) {
            $table->addCell(Converter::cmToTwip($widths[$i]), $cell)->addText($label, ['bold' => true], $center);
        }

        foreach (array_values($form['bonus_rows'] ?? []) as $i => $row) {
            $table->addRow();
            $table->addCell(Converter::cmToTwip($widths[0]), $cell)->addText((string) ($i + 1), [], $center);
            $table->addCell(Converter::cmToTwip($widths[1]), $cell)->addText($row['label'] ?? '', [], ['spaceAfter' => 0]);
            $table->addCell(Converter::cmToTwip($widths[2]), $cell)->addText($this->money($row['amount'] ?? 0), [], ['spaceAfter' => 0]);
            $table->addCell(Converter::cmToTwip($widths[3]), $cell)->addText($row['note'] ?? '', [], ['spaceAfter' => 0]);
        }

        $section->addTextBreak(0);
        if (! empty($content['bonus_pay_time'])) {
            $section->addListItem('Thời gian chi thưởng: '.$content['bonus_pay_time'], 0, [], self::BULLET, self::PARAGRAPH);
        }
        if (! empty($content['bonus_method'])) {
            $section->addListItem('Hình thức chi thưởng: '.rtrim($content['bonus_method'], '.').'.', 0, [], self::BULLET, self::PARAGRAPH);
        }
    }

    // Bảng 2 cột không viền như văn bản hành chính: trái "Nơi nhận", phải chức danh người ký,
    // khoảng trống để ký + đóng dấu, rồi họ tên (để trống thì người ký tự ghi tay).
    private function signatureBlock(Section $section, array $content): void
    {
        $recipients = array_values(array_filter($content['recipients'] ?? [], fn ($r) => trim((string) ($r['text'] ?? '')) !== ''));
        $leftWidth = Converter::cmToTwip(8);
        $rightWidth = Converter::cmToTwip(self::TEXT_WIDTH_CM - 8);

        $section->addTextBreak(1);
        $table = $section->addTable(['layout' => 'fixed', 'cellMargin' => 0]);
        $table->addRow(null, ['cantSplit' => true]);

        $left = $table->addCell($leftWidth);
        if ($recipients !== []) {
            $left->addText('Nơi nhận:', ['bold' => true, 'italic' => true, 'size' => 12], ['spaceAfter' => 0]);
            $last = count($recipients) - 1;
            foreach ($recipients as $i => $recipient) {
                $run = $left->addTextRun(['spaceAfter' => 0, 'indentation' => ['left' => 120]]);
                $run->addText('-   '.rtrim($recipient['text'], ';.'), ['size' => 11]);
                if (! empty($recipient['note'])) {
                    $run->addText(' ('.trim($recipient['note'], '() ').')', ['size' => 11, 'italic' => true]);
                }
                $run->addText($i === $last ? '.' : ';', ['size' => 11]);
            }
        }

        $center = ['alignment' => Jc::CENTER, 'spaceAfter' => 0];
        $right = $table->addCell($rightWidth);
        $right->addText(mb_strtoupper(trim((string) ($content['signer_title'] ?? ''))), ['bold' => true], $center);
        for ($i = 0; $i < 5; $i++) {
            $right->addText('', [], $center);
        }
        if (! empty($content['signer_name'])) {
            $right->addText(trim($content['signer_name']), ['bold' => true], $center);
        }
    }

    private function weekday(Carbon $date): string
    {
        return ['Chủ nhật', 'Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy'][$date->dayOfWeek];
    }

    private function money($amount): string
    {
        return number_format((float) $amount, 0, ',', '.').' đồng';
    }
}
