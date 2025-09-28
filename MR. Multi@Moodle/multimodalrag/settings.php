<?php
defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    // General Settings
    $settings->add(new admin_setting_heading(
        'block_multimodalrag/general_heading',
        get_string('general_settings', 'block_multimodalrag'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'block_multimodalrag/fastapi_url',
        get_string('fastapi_url', 'block_multimodalrag'),
        get_string('fastapi_url_desc', 'block_multimodalrag'),
        'http://fastapi:8000',
        PARAM_URL
    ));

    // Content Processing Settings
    $settings->add(new admin_setting_heading(
        'block_multimodalrag/processing_heading',
        get_string('processing_settings', 'block_multimodalrag'),
        get_string('processing_settings_desc', 'block_multimodalrag')
    ));

    $settings->add(new admin_setting_configtext(
        'block_multimodalrag/chunk_size',
        get_string('chunk_size', 'block_multimodalrag'),
        get_string('chunk_size_desc', 'block_multimodalrag'),
        500,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'block_multimodalrag/overlap_size',
        get_string('overlap_size', 'block_multimodalrag'),
        get_string('overlap_size_desc', 'block_multimodalrag'),
        100,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'block_multimodalrag/enable_video_processing',
        get_string('enable_video_processing', 'block_multimodalrag'),
        get_string('enable_video_processing_desc', 'block_multimodalrag'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'block_multimodalrag/enable_image_processing',
        get_string('enable_image_processing', 'block_multimodalrag'),
        get_string('enable_image_processing_desc', 'block_multimodalrag'),
        1
    ));

    // Search & Chat Settings
    $settings->add(new admin_setting_heading(
        'block_multimodalrag/search_chat_heading',
        get_string('search_chat_settings', 'block_multimodalrag'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'block_multimodalrag/default_search_limit',
        get_string('default_search_limit', 'block_multimodalrag'),
        get_string('default_search_limit_desc', 'block_multimodalrag'),
        10,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'block_multimodalrag/default_chat_limit',
        get_string('default_chat_limit', 'block_multimodalrag'),
        get_string('default_chat_limit_desc', 'block_multimodalrag'),
        10,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtextarea(
        'block_multimodalrag/chat_welcome_message',
        get_string('chat_welcome_message', 'block_multimodalrag'),
        get_string('chat_welcome_message_desc', 'block_multimodalrag'),
        get_string('default_chat_welcome', 'block_multimodalrag')
    ));

    // Evaluation Settings
    $settings->add(new admin_setting_heading(
        'block_multimodalrag/evaluation_heading',
        get_string('evaluation_settings', 'block_multimodalrag'),
        get_string('evaluation_settings_desc', 'block_multimodalrag')
    ));

    $settings->add(new admin_setting_configtext(
        'block_multimodalrag/context_precision_good',
        get_string('context_precision_good', 'block_multimodalrag'),
        get_string('evaluation_threshold_desc', 'block_multimodalrag'),
        0.8,
        PARAM_FLOAT
    ));

    $settings->add(new admin_setting_configtext(
        'block_multimodalrag/context_recall_good',
        get_string('context_recall_good', 'block_multimodalrag'),
        get_string('evaluation_threshold_desc', 'block_multimodalrag'),
        0.8,
        PARAM_FLOAT
    ));

    $settings->add(new admin_setting_configtext(
        'block_multimodalrag/answer_relevance_good',
        get_string('answer_relevance_good', 'block_multimodalrag'),
        get_string('evaluation_threshold_desc', 'block_multimodalrag'),
        0.8,
        PARAM_FLOAT
    ));

    // Model Selection Settings
    $settings->add(new admin_setting_heading(
        'block_multimodalrag/model_heading',
        get_string('model_settings', 'block_multimodalrag'),
        get_string('model_settings_desc', 'block_multimodalrag')
    ));

    // Generation Model Selection
    $generation_models = array(
        'gemma3:1b' => 'Gemma 3 (1B)',
        'phi3:3.8b-mini-4k-instruct-q4_K_S' => 'Phi-3 Mini (3.8B Instruct Q4_K_S)'
    );
    
    $settings->add(new admin_setting_configselect(
        'block_multimodalrag/generation_model',
        get_string('generation_model', 'block_multimodalrag'),
        get_string('generation_model_desc', 'block_multimodalrag'),
        'gemma3:1b',
        $generation_models
    ));

    // Embedding Model Selection
    $embedding_models = array(
        'nomic-embed-text' => 'Nomic Embed Text',
        'mxbai-embed-large' => 'MXBai Embed Large'
    );
    
    $settings->add(new admin_setting_configselect(
        'block_multimodalrag/embedding_model',
        get_string('embedding_model', 'block_multimodalrag'),
        get_string('embedding_model_desc', 'block_multimodalrag'),
        'nomic-embed-text',
        $embedding_models
    ));

    // UI Customization Settings
    $settings->add(new admin_setting_heading(
        'block_multimodalrag/ui_heading',
        get_string('ui_settings', 'block_multimodalrag'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'block_multimodalrag/teacher_tools_title',
        get_string('teacher_tools_title', 'block_multimodalrag'),
        get_string('teacher_tools_title_desc', 'block_multimodalrag'),
        get_string('default_teacher_tools_title', 'block_multimodalrag')
    ));

    $settings->add(new admin_setting_configtext(
        'block_multimodalrag/student_tools_title',
        get_string('student_tools_title', 'block_multimodalrag'),
        get_string('student_tools_title_desc', 'block_multimodalrag'),
        get_string('default_student_tools_title', 'block_multimodalrag')
    ));
}
