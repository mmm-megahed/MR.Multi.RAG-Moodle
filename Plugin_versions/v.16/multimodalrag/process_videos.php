<?php
require_once('../../config.php');

// Required parameters for security and context
$courseid = required_param('courseid', PARAM_INT);
$sesskey = required_param('sesskey', PARAM_ALPHANUM);

require_login();
confirm_sesskey($sesskey);

// Fetch course record and context
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

// Ensure the user has the capability to process files
require_capability('block/multimodalrag:processfiles', $context);

// Set up the Moodle page
$PAGE->set_url('/blocks/multimodalrag/process_videos.php');
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_title(get_string('processing_videos_title', 'block_multimodalrag'));
$PAGE->set_heading($course->fullname);
$PAGE->requires->css('/blocks/multimodalrag/styles.css');

echo $OUTPUT->header();

// Get FastAPI URL from plugin settings
$fastapi_url = rtrim(get_config('block_multimodalrag', 'fastapi_url') ?: 'http://fastapi:8000', '/');

/**
 * Triggers the video processing job on the FastAPI backend.
 *
 * @param string $fastapi_url The base URL of the FastAPI service.
 * @param int $courseid The ID of the course to process.
 * @return array An array containing the success status and a message.
 */
function trigger_video_processing($fastapi_url, $courseid) {
    $url = "{$fastapi_url}/api/v1/video/process/{$courseid}";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    // Handle cURL connection errors
    if ($curl_error) {
        return ['success' => false, 'message' => "Connection Error: " . htmlspecialchars($curl_error)];
    }

    // Handle successful API responses
    if ($http_code === 200) {
        $data = json_decode($response, true);
        if (isset($data['job_id'])) {
            return [
                'success' => true,
                'message' => "Video processing job started successfully. Job ID: " . htmlspecialchars($data['job_id'])
            ];
        }
    }

    // Handle failed API responses
    return ['success' => false, 'message' => "Failed to start video processing. HTTP Status: {$http_code}"];
}

// Display the processing status to the user
echo "<div class='processing-container'>";
echo "<h3><i class='fa fa-film'></i> Initiating Video Processing</h3>";

$result = trigger_video_processing($fastapi_url, $courseid);

if ($result['success']) {
    echo $OUTPUT->notification($result['message'], 'success');
} else {
    echo $OUTPUT->notification($result['message'], 'error');
}

echo "<div class='alert alert-info'>The system is now processing the videos for this course in the background. This may take some time depending on the number and length of the videos. Once complete, transcripts will be available for search and chat.</div>";
echo "</div>";

// Provide a button to return to the course page
echo $OUTPUT->continue_button(new moodle_url('/course/view.php', ['id' => $courseid]));
echo $OUTPUT->footer();
?>
