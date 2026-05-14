<?php

/*
 * ==========================================================
 * ASYNC.PHP
 * ==========================================================
 *
 * Execute PHP code asynchronosuly. © 2017-2026 board.support. All rights reserved.
 *
 */

require(__DIR__ . '/functions.php');
$data = json_decode(base64_decode($argv[1]), true);

switch ($data['action']) {
    case 'typing':
        $setting_name = 'stop-worker-' . $data['conversation_id'];
        sb_delete_external_setting($setting_name);
        for ($i = 0; $i < 15; $i++) {
            sleep(3);
            if (sb_get_external_setting($setting_name)) {
                sb_delete_external_setting($setting_name);
                break;
            } else {
                sb_set_typing($data['user_id'], $data['conversation_id'], [$data['source'], $data['platform_value'], $data['page_id']]);
            }
        }
        break;
}

?>