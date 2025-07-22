<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    // API Configuration
    $settings->add(new admin_setting_heading(
        'block_multimodal_rag/apiheading',
        get_string('api_url', 'block_multimodal_rag'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'block_multimodal_rag/api_url',
        get_string('api_url', 'block_multimodal_rag'),
        get_string('api_url_desc', 'block_multimodal_rag'),
        'http://127.0.0.1:8000',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'block_multimodal_rag/api_timeout',
        get_string('api_timeout', 'block_multimodal_rag'),
        get_string('api_timeout_desc', 'block_multimodal_rag'),
        '30',
        PARAM_INT
    ));

    // Processing Configuration
    $settings->add(new admin_setting_heading(
        'block_multimodal_rag/processingheading',
        get_string('process_materials', 'block_multimodal_rag'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'block_multimodal_rag/chunk_size',
        get_string('chunk_size', 'block_multimodal_rag'),
        get_string('chunk_size_desc', 'block_multimodal_rag'),
        '200',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'block_multimodal_rag/overlap_size',
        get_string('overlap_size', 'block_multimodal_rag'),
        get_string('overlap_size_desc', 'block_multimodal_rag'),
        '20',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'block_multimodal_rag/answer_limit',
        get_string('answer_limit', 'block_multimodal_rag'),
        get_string('answer_limit_desc', 'block_multimodal_rag'),
        '5',
        PARAM_INT
    ));
}