<?php

namespace App\Console\Commands;

use App\Jobs\DeleteExpiredFile;
use App\Models\Upload;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanExpiredFiles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:clean-expired-files';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up expired uploaded files from storage and database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $expiredUploads = Upload::where('expired_at', '<=', Carbon::now())->get();

        if ($expiredUploads->isEmpty()) {
            logger()->info('No expired files to clean.');
            return;
        }

        $count = 0;
        foreach ($expiredUploads as $upload) {
            DeleteExpiredFile::dispatch($upload);
            $count++;
        }

        logger()->info("Successfully cleaned up $count expired files.");
    }
}
