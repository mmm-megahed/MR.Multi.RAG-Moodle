<?php
require_once('../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$query = optional_param('query', '', PARAM_TEXT);
$limit = optional_param('limit', 10, PARAM_INT);
$submitted = optional_param('submit', false, PARAM_BOOL);

require_login();

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);
require_capability('block/multimodalrag:chat', $context);

$PAGE->set_url('/blocks/multimodalrag/simple_chat.php', ['courseid' => $courseid]);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_title('AI Chat Interface');
$PAGE->set_heading($course->fullname);
$PAGE->requires->css('/blocks/multimodalrag/styles.css');

echo $OUTPUT->header();

function simple_chat_api($fastapi_url, $courseid, $query, $limit) {
    $data = json_encode([
        'text' => $query,
        'limit' => $limit
    ]);

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
    $curl_error = curl_error($ch);
    curl_close($ch);

    return [
        'http_code' => $http_code,
        'response' => $response,
        'curl_error' => $curl_error
    ];
}

function extract_image_references_from_answer($answer_text) {
    $image_refs = [];
    
    // Look for image references in the answer
    if (preg_match_all('/\b(\w+\.png|\w+\.jpg|\w+\.jpeg)\b/i', $answer_text, $matches)) {
        $image_refs = array_unique($matches[1]);
    }
    
    return $image_refs;
}

$fastapi_url = rtrim(get_config('block_multimodalrag', 'fastapi_url') ?: 'http://fastapi:8000', '/');
?>

<div class="multimodal-interface-container">
    <div class="interface-header">
        <div class="header-content">
            <div class="header-icon">
                <i class="fa fa-robot"></i>
            </div>
            <div class="header-text">
                <h2>AI Chat Interface</h2>
                <p>Ask questions about your course content including text and images</p>
            </div>
        </div>
    </div>

    <div class="interface-main">
        <div class="input-section">
            <div class="input-card">
                <div class="input-header">
                    <h3><i class="fa fa-comments"></i> Chat with Your Content</h3>
                </div>
                <div class="input-body">
                    <form method="POST" action="" class="chat-form">
                        <input type="hidden" name="courseid" value="<?php echo $courseid; ?>">
                        <input type="hidden" name="submit" value="1">
                        
                        <div class="form-group">
                            <label for="query" class="form-label">
                                <i class="fa fa-question-circle"></i> Your Question
                            </label>
                            <div class="input-wrapper">
                                <textarea id="query" 
                                         name="query" 
                                         class="form-control chat-input" 
                                         rows="3"
                                         placeholder="What would you like to know about the course content? (including images)"
                                         required><?php echo htmlspecialchars($query); ?></textarea>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="limit" class="form-label">
                                <i class="fa fa-cog"></i> Context Depth
                            </label>
                            <select id="limit" name="limit" class="form-control">
                                <option value="5" <?php echo $limit == 5 ? 'selected' : ''; ?>>Quick (5 references)</option>
                                <option value="10" <?php echo $limit == 10 ? 'selected' : ''; ?>>Standard (10 references)</option>
                                <option value="15" <?php echo $limit == 15 ? 'selected' : ''; ?>>Deep (15 references)</option>
                            </select>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary btn-chat">
                                <i class="fa fa-paper-plane"></i> Ask AI
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php if ($submitted && !empty($query)): ?>
        <div class="response-section">
            <div class="response-card">
                <div class="response-header">
                    <h3><i class="fa fa-brain"></i> AI Response</h3>
                </div>
                <div class="response-body">
                    <?php
                    $result = simple_chat_api($fastapi_url, $courseid, $query, $limit);
                    
                    if (!empty($result['curl_error'])) {
                        echo "<div class='error-message'>";
                        echo "<i class='fa fa-exclamation-triangle'></i>";
                        echo "<strong>Connection Error:</strong> " . htmlspecialchars($result['curl_error']);
                        echo "</div>";
                    } else {
                        if ($result['http_code'] == 200) {
                            $response_data = json_decode($result['response'], true);
                            if ($response_data && isset($response_data['signal']) && $response_data['signal'] === 'rag_answer_success') {
                                echo "<div class='ai-answer'>";
                                echo "<div class='answer-content'>";
                                echo "<i class='fa fa-quote-left quote-icon'></i>";
                                echo "<div class='answer-text'>" . nl2br(htmlspecialchars($response_data['answer'])) . "</div>";
                                echo "</div>";
                                echo "</div>";
                                
                                // Check if the response references any images and show them
                                $image_refs = extract_image_references_from_answer($response_data['answer']);
                                if (!empty($image_refs)) {
                                    echo "<div class='referenced-images'>";
                                    echo "<h4><i class='fa fa-images'></i> Referenced Images</h4>";
                                    echo "<div class='image-gallery'>";
                                    foreach ($image_refs as $image_ref) {
                                        echo "<div class='referenced-image'>";
                                        echo "<div class='image-placeholder'>";
                                        echo "<i class='fa fa-image fa-2x'></i>";
                                        echo "<p class='image-name'>" . htmlspecialchars($image_ref) . "</p>";
                                        echo "</div>";
                                        echo "</div>";
                                    }
                                    echo "</div>";
                                    echo "</div>";
                                }
                                
                                echo "<div class='success-indicator'>";
                                echo "<i class='fa fa-check-circle'></i> AI response generated successfully!";
                                echo "</div>";
                            } else {
                                echo "<div class='error-message'>";
                                echo "<i class='fa fa-exclamation-circle'></i>";
                                echo "<strong>API Error:</strong> Invalid response format";
                                echo "</div>";
                            }
                        } else {
                            echo "<div class='error-message'>";
                            echo "<i class='fa fa-times-circle'></i>";
                            echo "<strong>HTTP {$result['http_code']}:</strong> Request failed";
                            echo "</div>";
                        }
                    }
                    ?>
                </div>
            </div>
            
            <div class="tech-details">
                <details class="api-details">
                    <summary><i class="fa fa-code"></i> Technical Details</summary>
                    <div class="details-content">
                        <div class="request-info">
                            <h4>API Request</h4>
                            <div class="code-block">
                                <strong>Endpoint:</strong> POST <?php echo $fastapi_url; ?>/api/v1/nlp/index/answer/<?php echo $courseid; ?><br>
                                <strong>Headers:</strong> Content-Type: application/json<br>
                                <strong>Payload:</strong> <?php echo json_encode(['text' => $query, 'limit' => $limit], JSON_PRETTY_PRINT); ?>
                            </div>
                        </div>
                        <div class="response-info">
                            <h4>Raw Response</h4>
                            <div class="code-block">
                                <strong>Status:</strong> <?php echo $result['http_code']; ?><br>
                                <strong>Response:</strong><br>
                                <pre><?php echo htmlspecialchars($result['response']); ?></pre>
                            </div>
                        </div>
                    </div>
                </details>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="interface-footer">
        <div class="navigation-links">
            <a href="/blocks/multimodalrag/simple_search.php?courseid=<?php echo $courseid; ?>" class="nav-link">
                <i class="fa fa-search"></i> Search Content
            </a>
            <?php echo $OUTPUT->continue_button(new moodle_url('/course/view.php', ['id' => $courseid])); ?>
        </div>
    </div>
</div>

<?php
echo $OUTPUT->footer();
?>