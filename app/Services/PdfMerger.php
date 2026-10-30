<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class PdfMerger
{
    /** @param list<string> $inputs */
    public function merge(array $inputs, string $output): void
    {
        $binary = (new ExecutableFinder)->find(config('pdf_merge.binary'));
        abort_unless($binary, 503, 'Chức năng ghép PDF chưa sẵn sàng trên máy chủ.');

        try {
            foreach ($inputs as $input) {
                $encrypted = new Process([$binary, '--is-encrypted', $input]);
                $encrypted->setTimeout(10)->disableOutput()->run();
                if ($encrypted->getExitCode() === 0) {
                    throw ValidationException::withMessages(['pdfs' => 'Không hỗ trợ ghép PDF được mã hóa hoặc bảo vệ bằng mật khẩu.']);
                }
            }

            // Keep the original document's metadata and append the other pages in order.
            $arguments = [$binary, '--warning-exit-0', $inputs[0], '--pages', '.', '1-z'];
            foreach (array_slice($inputs, 1) as $input) {
                $arguments[] = $input;
                $arguments[] = '1-z';
            }
            $process = new Process([...$arguments, '--', $output]);
            $process->setTimeout(config('pdf_merge.timeout'))->disableOutput()->run();
            if (! $process->isSuccessful() || ! is_file($output) || filesize($output) === 0) {
                throw ValidationException::withMessages(['pdfs' => 'Không thể ghép PDF. File có thể bị hỏng hoặc có định dạng không được hỗ trợ.']);
            }
        } catch (ProcessTimedOutException) {
            throw ValidationException::withMessages(['pdfs' => 'Ghép PDF mất quá nhiều thời gian. Vui lòng thử với ít file hơn.']);
        }
    }
}
