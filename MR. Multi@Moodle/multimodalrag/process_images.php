<?php
require_once('../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$sesskey = required_param('sesskey', PARAM_ALPHANUM);

require_login();
confirm_sesskey($sesskey);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);
require_capability('block/multimodalrag:processfiles', $context);

$PAGE->set_url('/blocks/multimodalrag/process_images.php');
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_title('Image Processing');
$PAGE->set_heading($course->fullname);
$PAGE->requires->css('/blocks/multimodalrag/styles.css');

echo $OUTPUT->header();

$fastapi_url = rtrim(get_config('block_multimodalrag', 'fastapi_url') ?: 'http://fastapi:8000', '/');

try {
    $pdf_files = get_pdf_files($courseid);
    
    if (empty($pdf_files)) {
        echo $OUTPUT->notification('No PDF files found to process for image extraction.', 'info');
        echo $OUTPUT->continue_button(new moodle_url('/course/view.php', ['id' => $courseid]));
        echo $OUTPUT->footer();
        exit;
    }

    echo "<div class='processing-container'>";
    echo "<h3><i class='fa fa-image fa-spin'></i> Processing " . count($pdf_files) . " PDF file(s) for image extraction...</h3>";
    echo "<div class='progress-steps'>";

    // Show PDF files that will be processed
    echo "<div class='step'><h4><i class='fa fa-file-pdf-o'></i> PDF Files Found</h4><div class='step-content'>";
    foreach ($pdf_files as $file) {
        echo "<div class='info-item'><i class='fa fa-file-pdf-o text-info'></i> {$file->filename}</div>";
        flush();
    }
    echo "</div></div>";

    // Start asynchronous processing
    echo "<div class='step'><h4><i class='fa fa-cogs'></i> Starting Image Extraction</h4><div class='step-content'>";
    
    $job_result = start_image_processing($fastapi_url, $courseid);
    
    if ($job_result && isset($job_result['job_id'])) {
        echo "<div class='success-item'><i class='fa fa-check text-success'></i> Image processing job started</div>";
        echo "<div class='info-item'><i class='fa fa-info-circle text-info'></i> Job ID: {$job_result['job_id']}</div>";
        echo "<div class='info-item'><i class='fa fa-clock-o text-muted'></i> Processing is running in the background...</div>";
    } else {
        throw new Exception('Failed to start image processing job');
    }
    echo "</div></div>";

    echo "</div></div>";
    
    echo $OUTPUT->notification('Image processing has been started! The system is extracting images from your PDF files and creating searchable descriptions. This process may take several minutes depending on the number and size of your PDF files. You can continue using other features while processing continues in the background.', 'success');

} catch (Exception $e) {
    echo $OUTPUT->notification('Error: ' . $e->getMessage(), 'error');
}

echo $OUTPUT->continue_button(new moodle_url('/course/view.php', ['id' => $courseid]));
echo $OUTPUT->footer();

function get_pdf_files($courseid) {
    global $DB, $CFG;
    
    $sql = "SELECT f.filename, f.mimetype,
                   CONCAT(SUBSTRING(f.contenthash, 1, 2), '/', 
                          SUBSTRING(f.contenthash, 3, 2), '/', 
                          f.contenthash) AS relpath
            FROM {course_modules} cm
            JOIN {context} ctx ON ctx.contextlevel = 70 AND ctx.instanceid = cm.id
            JOIN {modules} m ON cm.module = m.id
            JOIN {files} f ON f.contextid = ctx.id
            WHERE cm.course = ? 
              AND m.name = 'resource'
              AND f.mimetype = 'application/pdf'
              AND f.filename != '.'
              AND f.filesize > 0";
    
    $files = $DB->get_records_sql($sql, [$courseid]);
    
    foreach ($files as $file) {
        $file->fullpath = $CFG->dataroot . '/filedir/' . $file->relpath;
    }
    
    return $files;
}

function start_image_processing($fastapi_url, $courseid) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "{$fastapi_url}/api/v1/images/process/{$courseid}",
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200) {
        return json_decode($response, true);
    }
    
    return false;
}
?>