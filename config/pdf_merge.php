<?php

return [
    'binary' => env('QPDF_BINARY', 'qpdf'),
    'timeout' => 60,
    'max_files' => 5,
    'max_selected_pages' => 5000,
];
