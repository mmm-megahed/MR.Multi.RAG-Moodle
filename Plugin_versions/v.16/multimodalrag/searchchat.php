<?php
require_once('../../config.php');

$courseid = required_param('courseid', PARAM_INT);

require_login();

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

$PAGE->set_url('/blocks/multimodalrag/searchchat.php', ['courseid' => $courseid]);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_title(get_string('search_title', 'block_multimodalrag'));
$PAGE->set_heading($course->fullname);
$PAGE->requires->css('/blocks/multimodalrag/styles.css');
$PAGE->requires->js('/blocks/multimodalrag/js/module.js');

echo $OUTPUT->header();

// Check if courseid is valid and processing has been done
function check_course_processing($courseid) {
    global $DB;
    
    // Check if course exists
    if (!$DB->record_exists('course', ['id' => $courseid])) {
        throw new Exception('Invalid course ID');
    }
    
    // Check if any files have been processed for this course
    $processed = $DB->record_exists('block_multimodalrag_processed', ['courseid' => $courseid]);
    
    return $processed;
}

// Check collection info from FastAPI
function check_collection_info($fastapi_url, $courseid) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "{$fastapi_url}/api/v1/nlp/index/info/{$courseid}",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        return false;
    }
    
    $result = json_decode($response, true);
    return $result && $result['signal'] === 'vectordb_collection_retrieved' && 
           isset($result['collection_info']) && $result['collection_info']['record_count'] > 0;
}

$fastapi_url = rtrim(get_config('block_multimodalrag', 'fastapi_url') ?: 'http://fastapi:8000', '/');

try {
    $is_processed = check_course_processing($courseid);
    $has_collection = check_collection_info($fastapi_url, $courseid);
    
    if (!$is_processed || !$has_collection) {
        echo $OUTPUT->notification('No processed files found for this course. Please process files first before using search and chat features.', 'warning');
        $processurl = new moodle_url('/blocks/multimodalrag/process.php', [
            'courseid' => $courseid,
            'sesskey' => sesskey()
        ]);
        echo html_writer::link($processurl, 'Process Files Now', ['class' => 'btn btn-primary']);
        echo $OUTPUT->continue_button(new moodle_url('/course/view.php', ['id' => $courseid]));
        echo $OUTPUT->footer();
        exit;
    }
} catch (Exception $e) {
    echo $OUTPUT->notification('Error: ' . $e->getMessage(), 'error');
    echo $OUTPUT->continue_button(new moodle_url('/course/view.php', ['id' => $courseid]));
    echo $OUTPUT->footer();
    exit;
}
?>

<div class="multimodal-rag-interface">
    <div class="row">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h4><i class="fa fa-search"></i> Search Content</h4>
                </div>
                <div class="card-body">
                    <?php if (has_capability('block/multimodalrag:search', $context)): ?>
                    <form id="search-form">
                        <div class="form-group">
                            <input type="text" class="form-control" id="search-text" placeholder="Enter search query..." required>
                            <input type="hidden" id="search-courseid" value="<?php echo $courseid; ?>">
                        </div>
                        <button type="submit" class="btn btn-primary">Search</button>
                    </form>
                    <div id="search-results" class="mt-3"></div>
                    <?php else: ?>
                    <p>You don't have permission to search.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h4><i class="fa fa-comments"></i> Chat with Content</h4>
                </div>
                <div class="card-body">
                    <?php if (has_capability('block/multimodalrag:chat', $context)): ?>
                    <form id="chat-form">
                        <div class="form-group">
                            <input type="text" class="form-control" id="chat-text" placeholder="Ask a question..." required>
                            <input type="hidden" id="chat-courseid" value="<?php echo $courseid; ?>">
                        </div>
                        <button type="submit" class="btn btn-success">Ask</button>
                    </form>
                    <div id="chat-response" class="mt-3"></div>
                    <?php else: ?>
                    <p>You don't have permission to chat.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
echo $OUTPUT->continue_button(new moodle_url('/course/view.php', ['id' => $courseid]));
echo $OUTPUT->footer();
?>