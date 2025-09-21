<?php
require_once('../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$sesskey = required_param('sesskey', PARAM_ALPHANUM);
$chunk_size = optional_param('chunk_size', get_config('block_multimodalrag', 'chunk_size') ?: 500, PARAM_INT);
$overlap_size = optional_param('overlap_size', get_config('block_multimodalrag', 'overlap_size') ?: 100, PARAM_INT);
$do_reset = optional_param('do_reset', 1, PARAM_INT);

require_login();
confirm_sesskey($sesskey);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);
require_capability('block/multimodalrag:processfiles', $context);

$PAGE->set_url('/blocks/multimodalrag/process.php');
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_title(get_string('processing_title', 'block_multimodalrag'));
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();

$fastapi_url = rtrim(get_config('block_multimodalrag', 'fastapi_url') ?: 'http://fastapi:8000', '/');

try {
    $files = get_course_files($courseid);
    
    if (empty($files)) {
        echo $OUTPUT->notification('No files found to process.', 'info');
        echo $OUTPUT->continue_button(new moodle_url('/course/view.php', ['id' => $courseid]));
        echo $OUTPUT->footer();
        exit;
    }

    echo "<div class='processing-container'>";
    echo "<h3><i class='fa fa-cog fa-spin'></i> Processing " . count($files) . " file(s)...</h3>";
    echo "<div class='progress-steps'>";

    $uploaded = 0;
    
    echo "<div class='step'><h4><i class='fa fa-upload'></i> Step 1: Uploading files</h4><div class='step-content'>";
    
    foreach ($files as $file) {
        if (upload_file($fastapi_url, $courseid, $file)) {
            $uploaded++;
            echo "<div class='success-item'><i class='fa fa-check text-success'></i> Uploaded: {$file->filename}</div>";
        } else {
            echo "<div class='error-item'><i class='fa fa-times text-danger'></i> Failed: {$file->filename}</div>";
        }
        flush();
    }
    echo "</div></div>";
    
    if ($uploaded === 0) {
        throw new Exception('No files were uploaded successfully');
    }

    echo "<div class='step'><h4><i class='fa fa-cogs'></i> Step 2: Processing files</h4><div class='step-content'>";
    
    if (process_files($fastapi_url, $courseid, $chunk_size, $overlap_size, $do_reset)) {
        echo "<div class='success-item'><i class='fa fa-check text-success'></i> Files processed successfully</div>";
    } else {
        throw new Exception('File processing failed');
    }
    echo "</div></div>";

    echo "<div class='step'><h4><i class='fa fa-database'></i> Step 3: Building search index</h4><div class='step-content'>";
    
    if (push_to_index($fastapi_url, $courseid, $do_reset)) {
        echo "<div class='success-item'><i class='fa fa-check text-success'></i> Search index created successfully</div>";
    } else {
        throw new Exception('Search index creation failed');
    }
    echo "</div></div>";

    echo "</div></div>";
    
    echo $OUTPUT->notification('All files processed successfully! You can now search and chat with your course content.', 'success');

} catch (Exception $e) {
    echo $OUTPUT->notification('Error: ' . $e->getMessage(), 'error');
}

echo $OUTPUT->continue_button(new moodle_url('/course/view.php', ['id' => $courseid]));
echo $OUTPUT->footer();

function get_course_files($courseid) {
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
              AND f.mimetype IN ('text/plain', 'application/pdf')
              AND f.filename != '.'
              AND f.filesize > 0";
    
    $files = $DB->get_records_sql($sql, [$courseid]);
    
    foreach ($files as $file) {
        $file->fullpath = $CFG->dataroot . '/filedir/' . $file->relpath;
    }
    
    return $files;
}

function upload_file($fastapi_url, $courseid, $file) {
    if (!file_exists($file->fullpath)) {
        return false;
    }
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "{$fastapi_url}/api/v1/data/upload/{$courseid}",
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'file' => new CURLFile($file->fullpath, $file->mimetype, $file->filename)
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $http_code === 200;
}

function process_files($fastapi_url, $courseid, $chunk_size, $overlap_size, $do_reset) {
    $data = json_encode([
        'chunk_size' => $chunk_size,
        'overlap_size' => $overlap_size,
        'do_reset' => $do_reset
    ]);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "{$fastapi_url}/api/v1/data/process/{$courseid}",
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
    
    return $http_code === 200;
}

function push_to_index($fastapi_url, $courseid, $do_reset) {
    $data = json_encode(['do_reset' => $do_reset]);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "{$fastapi_url}/api/v1/nlp/index/push/{$courseid}",
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 90,
        CURLOPT_CONNECTTIMEOUT => 10
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $http_code === 200;
}
?>