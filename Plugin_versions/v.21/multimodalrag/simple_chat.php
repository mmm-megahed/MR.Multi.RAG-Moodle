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

function simple_search_api($fastapi_url, $courseid, $query, $limit) {
    $data = json_encode([
        'text' => $query,
        'limit' => $limit
    ]);

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
    $curl_error = curl_error($ch);
    curl_close($ch);

    return [
        'http_code' => $http_code,
        'response' => $response,
        'curl_error' => $curl_error
    ];
}

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
            <div class="header-actions">
                <a href="/public/blocks/multimodalrag/evaluation.php?courseid=<?php echo $courseid; ?>" class="btn btn-outline-primary">
                    <i class="fa fa-chart-line"></i> RAG Evaluation
                </a>
            </div>
        </div>
    </div>

    <div class="interface-main">
        <div class="input-section">
            <div class="input-card">
                <div class="input-header">
                    <h3><i class="fa fa-comments"></i> Chat with Your Course Content</h3>
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
                                         placeholder="<?php echo get_config('block_multimodalrag', 'chat_welcome_message'); ?>"
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
                    $search_result = simple_search_api($fastapi_url, $courseid, $query, $limit);
                    
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
                                
                                // Add source attribution button
                                echo "<div class='source-attribution'>";
                                echo "<button onclick='toggleSources()' class='btn btn-outline-secondary btn-sm' id='sourceBtn'>";
                                echo "<i class='fa fa-list'></i> Show Source Attribution";
                                echo "</button>";
                                echo "<div id='sourceContent' class='source-content' style='display: none;'>";
                                echo "<h5><i class='fa fa-database'></i> Retrieved Chunks</h5>";
                                
                                // Get and display retrieved chunks from search API
                                if ($search_result['http_code'] == 200) {
                                    $search_data = json_decode($search_result['response'], true);
                                    if ($search_data && isset($search_data['signal']) && $search_data['signal'] === 'vectordb_search_success') {
                                        if (isset($search_data['results']) && is_array($search_data['results'])) {
                                            echo "<div class='retrieved-chunks-list'>";
                                            foreach ($search_data['results'] as $idx => $chunk) {
                                                $score = isset($chunk['score']) ? round($chunk['score'] * 100, 1) : 'N/A';
                                                $content_type = $chunk['metadata']['content_type'] ?? 'text';
                                                $source_file = isset($chunk['metadata']['source_file']) ? basename($chunk['metadata']['source_file']) : 'Unknown';
                                                
                                                echo "<div class='chunk-item'>";
                                                echo "<div class='chunk-header'>";
                                                echo "<span class='chunk-number'>#" . ($idx + 1) . "</span>";
                                                echo "<span class='chunk-type'>" . ucfirst($content_type) . "</span>";
                                                echo "<span class='chunk-score'>Score: {$score}%</span>";
                                                echo "<span class='chunk-source'>Source: {$source_file}</span>";
                                                echo "</div>";
                                                echo "<div class='chunk-content'>";
                                                $chunk_text = $chunk['text'] ?? '';
                                                if (strlen($chunk_text) > 200) {
                                                    echo "<div class='chunk-preview'>" . htmlspecialchars(substr($chunk_text, 0, 200)) . "...</div>";
                                                    echo "<div class='chunk-full' style='display: none;'>" . htmlspecialchars($chunk_text) . "</div>";
                                                    echo "<button onclick='toggleChunkFull(this)' class='btn-expand'>Show More</button>";
                                                } else {
                                                    echo htmlspecialchars($chunk_text);
                                                }
                                                echo "</div>";
                                                echo "</div>";
                                            }
                                            echo "</div>";
                                        } else {
                                            echo "<p class='text-muted'>No chunks retrieved from search API</p>";
                                        }
                                    } else {
                                        echo "<p class='text-muted'>Invalid search API response</p>";
                                    }
                                } else {
                                    echo "<p class='text-muted'>Search API call failed</p>";
                                }
                                
                                echo "<div class='raw-response-toggle'>";
                                echo "<button onclick='toggleRawResponse()' class='btn btn-outline-info btn-sm' id='rawBtn'>";
                                echo "<i class='fa fa-code'></i> Show Raw Response";
                                echo "</button>";
                                echo "<div id='rawResponseContent' style='display: none;'>";
                                echo "<h6>Chat API Raw Response:</h6>";
                                echo "<div class='code-block'>";
                                echo "<pre>" . htmlspecialchars(json_encode($response_data, JSON_PRETTY_PRINT)) . "</pre>";
                                echo "</div>";
                                echo "</div>";
                                echo "</div>";
                                
                                echo "</div>";
                                echo "</div>";
                                
                                echo "<script>";
                                echo "function toggleSources() {";
                                echo "  var content = document.getElementById('sourceContent');";
                                echo "  var btn = document.getElementById('sourceBtn');";
                                echo "  if (content.style.display === 'none') {";
                                echo "    content.style.display = 'block';";
                                echo "    btn.innerHTML = '<i class=\"fa fa-list\"></i> Hide Source Attribution';";
                                echo "  } else {";
                                echo "    content.style.display = 'none';";
                                echo "    btn.innerHTML = '<i class=\"fa fa-list\"></i> Show Source Attribution';";
                                echo "  }";
                                echo "}";
                                
                                echo "function toggleRawResponse() {";
                                echo "  var content = document.getElementById('rawResponseContent');";
                                echo "  var btn = document.getElementById('rawBtn');";
                                echo "  if (content.style.display === 'none') {";
                                echo "    content.style.display = 'block';";
                                echo "    btn.innerHTML = '<i class=\"fa fa-code\"></i> Hide Raw Response';";
                                echo "  } else {";
                                echo "    content.style.display = 'none';";
                                echo "    btn.innerHTML = '<i class=\"fa fa-code\"></i> Show Raw Response';";
                                echo "  }";
                                echo "}";
                                
                                echo "function toggleChunkFull(btn) {";
                                echo "  var preview = btn.previousElementSibling.previousElementSibling;";
                                echo "  var full = btn.previousElementSibling;";
                                echo "  if (full.style.display === 'none') {";
                                echo "    preview.style.display = 'none';";
                                echo "    full.style.display = 'block';";
                                echo "    btn.textContent = 'Show Less';";
                                echo "  } else {";
                                echo "    preview.style.display = 'block';";
                                echo "    full.style.display = 'none';";
                                echo "    btn.textContent = 'Show More';";
                                echo "  }";
                                echo "}";
                                echo "</script>";
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
            <a href="/public/blocks/multimodalrag/simple_search.php?courseid=<?php echo $courseid; ?>" class="nav-link">
                <i class="fa fa-search"></i> Search Content
            </a>
            <?php echo $OUTPUT->continue_button(new moodle_url('/course/view.php', ['id' => $courseid])); ?>
        </div>
    </div>
</div>

<style>
.interface-header { position: relative; }
.header-content { display: flex; align-items: center; justify-content: space-between; }
.header-actions { margin-left: auto; }
.btn-outline-primary { 
    border: 1px solid #007bff; 
    color: #007bff; 
    background: transparent; 
    padding: 0.5rem 1rem; 
    border-radius: 5px; 
    text-decoration: none; 
    transition: all 0.3s ease; 
}
.btn-outline-primary:hover { 
    background: #007bff; 
    color: white; 
    text-decoration: none; 
}
.source-attribution { margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #dee2e6; }
.source-content { margin-top: 1rem; padding: 1rem; background: #f8f9fa; border-radius: 5px; }
.btn-outline-secondary { 
    border: 1px solid #6c757d; 
    color: #6c757d; 
    background: transparent; 
    padding: 0.375rem 0.75rem; 
    border-radius: 3px; 
    cursor: pointer; 
}
.btn-outline-secondary:hover { background: #6c757d; color: white; }
.retrieved-chunks-list { max-height: 400px; overflow-y: auto; margin: 1rem 0; }
.chunk-item { border: 1px solid #dee2e6; border-radius: 8px; margin-bottom: 1rem; padding: 1rem; background: white; }
.chunk-header { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.5rem; font-size: 0.9em; }
.chunk-number { background: #6c757d; color: white; padding: 2px 6px; border-radius: 3px; font-size: 0.8em; }
.chunk-type { background: #007bff; color: white; padding: 2px 6px; border-radius: 3px; font-size: 0.8em; }
.chunk-score { background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 0.8em; }
.chunk-source { background: #ffc107; color: #212529; padding: 2px 6px; border-radius: 3px; font-size: 0.8em; }
.chunk-content { font-size: 0.9em; line-height: 1.4; margin-top: 0.5rem; }
.chunk-preview, .chunk-full { margin-bottom: 0.5rem; }
.btn-expand { background: none; border: 1px solid #007bff; color: #007bff; padding: 2px 8px; border-radius: 3px; font-size: 0.8em; cursor: pointer; }
.btn-expand:hover { background: #007bff; color: white; }
.raw-response-toggle { margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #dee2e6; }
</style>

<?php
echo $OUTPUT->footer();
?>