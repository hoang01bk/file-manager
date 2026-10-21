<?php

return [
    'max_file_size' => 100 * 1024 * 1024,
    'user_quota' => 1024 * 1024 * 1024,
    // Anonymous uploads share one pool because they have no persistent owner.
    'guest_quota' => 1024 * 1024 * 1024,
];
