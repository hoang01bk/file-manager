<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class PdfExtractor
{
    public function pageCount(string $input): int
    {
        $binary = $this->binary();
        try {
            $encrypted = new Process([$binary, '--is-encrypted', $input]);
            $encrypted->setTimeout(10)->disableOutput()->run();
            if ($encrypted->getExitCode() === 0) {
                throw ValidationException::withMessages(['file' => 'Không hỗ trợ PDF được mã hóa hoặc bảo vệ bằng mật khẩu.']);
            }
            $process = $this->run([$binary, '--warning-exit-0', '--show-npages', $input], true);
            $count = trim($process->getOutput());
            if (! ctype_digit($count) || (int) $count < 1) {
                throw ValidationException::withMessages(['file' => 'PDF không có trang nào có thể tách.']);
            }

            return (int) $count;
        } catch (ProcessTimedOutException) {
            throw ValidationException::withMessages(['file' => 'Đọc PDF mất quá nhiều thời gian. Vui lòng thử file khác.']);
        }
    }

    /** @return list<int> */
    public function selectedPages(string $expression, int $total): array
    {
        if (strlen($expression) > 50000 || ! preg_match('/^\s*\d+\s*(?:-\s*\d+\s*)?(?:,\s*\d+\s*(?:-\s*\d+\s*)?)*$/D', $expression)) {
            throw ValidationException::withMessages(['pages' => 'Nhập trang theo dạng 1,3,5-8.']);
        }
        $selected = [];
        foreach (explode(',', preg_replace('/\s+/', '', $expression)) as $part) {
            $bounds = array_map('intval', explode('-', $part));
            $first = $bounds[0];
            $last = $bounds[1] ?? $first;
            if ($first < 1 || $last < 1 || $first > $total || $last > $total) {
                throw ValidationException::withMessages(['pages' => 'Số trang phải nằm trong khoảng 1 đến '.$total.'.']);
            }
            $step = $first <= $last ? 1 : -1;
            for ($page = $first; ; $page += $step) {
                $selected[$page] = true;
                if (count($selected) > config('pdf_merge.max_selected_pages')) {
                    throw ValidationException::withMessages(['pages' => 'Chỉ được lấy tối đa '.config('pdf_merge.max_selected_pages').' trang mỗi lần.']);
                }
                if ($page === $last) {
                    break;
                }
            }
        }

        return array_keys($selected);
    }

    public function extract(string $input, string $expression, string $output, array $rotations = []): void
    {
        $count = $this->pageCount($input);
        $pages = $this->selectedPages($expression, $count);
        foreach ($rotations as $page => $angle) {
            if (! preg_match('/^[1-9][0-9]*$/D', (string) $page) || (int) $page > $count
                || ! in_array($angle, [0, 90, 180, 270, '0', '90', '180', '270'], true)) {
                throw ValidationException::withMessages(['rotations' => 'Trang hoặc góc xoay không hợp lệ.']);
            }
        }
        $arguments = [$this->binary(), '--warning-exit-0', '--empty', '--pages', $input, implode(',', $pages), '--'];
        $groups = [];
        foreach ($pages as $index => $page) {
            $angle = (int) ($rotations[$page] ?? 0);
            if ($angle !== 0) {
                // qpdf applies rotations after extraction: use output positions, not source numbers.
                $groups[$angle][] = $index + 1;
            }
        }
        foreach ($groups as $angle => $positions) {
            $arguments[] = '--rotate=+'.$angle.':'.implode(',', $positions);
        }
        $arguments[] = $output;
        $this->run($arguments);
        if (! is_file($output) || filesize($output) === 0) {
            throw ValidationException::withMessages(['file' => 'Không tạo được PDF mới.']);
        }
    }

    private function binary(): string
    {
        $binary = (new ExecutableFinder)->find(config('pdf_merge.binary'));
        abort_unless($binary, 503, 'Chức năng xử lý PDF chưa sẵn sàng trên máy chủ.');

        return $binary;
    }

    private function run(array $arguments, bool $capture = false): Process
    {
        $process = new Process($arguments);
        $process->setTimeout(config('pdf_merge.timeout'));
        if (! $capture) {
            $process->disableOutput();
        }
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw ValidationException::withMessages(['file' => 'Xử lý PDF mất quá nhiều thời gian. Vui lòng chọn ít trang hơn.']);
        }
        if (! $process->isSuccessful()) {
            throw ValidationException::withMessages(['file' => 'Không xử lý được PDF. File có thể bị hỏng hoặc không được hỗ trợ.']);
        }

        return $process;
    }
}
