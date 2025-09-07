<?php
defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_configtext(
        'block_multimodalrag/fastapi_url',
        get_string('fastapi_url', 'block_multimodalrag'),
        get_string('fastapi_url_desc', 'block_multimodalrag'),
        'http://fastapi:8000',
        PARAM_URL
    ));
}
?>