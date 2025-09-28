<?php
require_once('../../config.php');

$action = required_param('action', PARAM_ALPHA);
$courseid = required_param('courseid', PARAM_INT);
$format = optional_param('format', 'json', PARAM_ALPHA);

require_login();

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);
$fastapi_url = rtrim(get_config('block_multimodalrag', 'fastapi_url') ?: 'http://fastapi:8000', '/');

// Validate courseid globally before any action
function validate_courseid($courseid, $fastapi_url) {
    global $DB;
    
    // Check if course exists in Moodle
    if (!$DB->record_exists('course', ['id' => $courseid])) {
        throw new Exception('Invalid course ID: Course does not exist');
    }
    
    // Check if course has processed files
    if (!$DB->record_exists('block_multimodalrag_processed', ['courseid' => $courseid])) {
        throw new Exception('Course has no processed files. Please process files first.');
    }
    
    // Check collection info from FastAPI
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "{$fastapi_url}/api/v1/nlp/index/info/{$courseid}",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        throw new Exception('FastAPI collection not found for course ID: ' . $courseid);
    }
    
    $result = json_decode($response, true);
    if (!$result || $result['signal'] !== 'vectordb_collection_retrieved') {
        throw new Exception('Invalid collection status for course ID: ' . $courseid);
    }
    
    if (!isset($result['collection_info']['record_count']) || $result['collection_info']['record_count'] <= 0) {
        throw new Exception('No indexed records found for course ID: ' . $courseid);
    }
    
    return true;
}

try {
    // Global courseid validation
    validate_courseid($courseid, $fastapi_url);
    
    switch ($action) {
        case 'search':
            require_capability('block/multimodalrag:search', $context);
            $text = required_param('text', PARAM_TEXT);
            $limit = optional_param('limit', 10, PARAM_INT);
            $result = search_content($fastapi_url, $courseid, $text, $limit);
            break;

        case 'chat':
            require_capability('block/multimodalrag:chat', $context);
            $text = required_param('text', PARAM_TEXT);
            $limit = optional_param('limit', get_config('block_multimodalrag', 'default_chat_limit') ?: 10, PARAM_INT);
            $result = chat_with_content($fastapi_url, $courseid, $text, $limit);
            break;

        default:
            throw new Exception('Invalid action');
    }

    header('Content-Type: application/json');
    echo json_encode($result);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}

function search_content($fastapi_url, $courseid, $text, $limit) {
    $data = json_encode(['text' => $text, 'limit' => $limit]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "{$fastapi_url}/api/v1/nlp/index/search/{$courseid}",
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        throw new Exception('Search request failed');
    }

    $result = json_decode($response, true);
    if (!$result || $result['signal'] !== 'VECTORDB_SEARCH_SUCCESS') {
        throw new Exception('Search failed');
    }

    return ['success' => true, 'results' => $result['results']];
}

function chat_with_content($fastapi_url, $courseid, $text, $limit) {
    $data = json_encode(['text' => $text, 'limit' => $limit]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "{$fastapi_url}/api/v1/nlp/index/answer/{$courseid}",
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 10
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        throw new Exception('Chat request failed');
    }

    $result = json_decode($response, true);
    if (!$result || $result['signal'] !== 'RAG_ANSWER_SUCCESS') {
        throw new Exception('Chat failed');
    }

    return [
        'success' => true,
        'answer' => $result['answer'],
        'full_prompt' => $result['full_prompt'] ?? '',
        'chat_history' => $result['chat_history'] ?? []
    ];
}
?>