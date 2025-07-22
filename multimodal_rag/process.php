<?php
// This file is part of Moodle - http://moodle.org/

require_once('../../config.php');
require_once('classes/api_client.php');

$courseid = required_param('courseid', PARAM_INT);

require_login();
require_sesskey();

$context = context_course::instance($courseid);
require_capability('block/multimodal_rag:manage', $context);

header('Content-Type: application/json');

try {
    $chunk_size = get_config('block_multimodal_rag', 'chunk_size') ?: 200;
    $overlap_size = get_config('block_multimodal_rag', 'overlap_size') ?: 20;
    
    $client = new block_multimodal_rag_api_client();
    $result = $client->process_course_materials($courseid, $chunk_size, $overlap_size);
    
    echo json_encode([
        'success' => true,
        'message' => 'Course materials processed successfully',
        'data' => $result
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}