<?php
require_once('../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$query = optional_param('query', '', PARAM_TEXT);
$limit = optional_param('limit', 5, PARAM_INT);
$submitted = optional_param('submit', false, PARAM_BOOL);

require_login();

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);
require_capability('block/multimodalrag:search', $context);

$PAGE->set_url('/blocks/multimodalrag/simple_search.php', ['courseid' => $courseid]);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_title('Content Search');
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

$fastapi_url = rtrim(get_config('block_multimodalrag', 'fastapi_url') ?: 'http://fastapi:8000', '/');
?>

<div class="multimodal-interface-container">
    <div class="interface-header">
        <div class="header-content">
            <div class="header-icon">
                <i class="fa fa-search"></i>
            </div>
            <div class="header-text">
                <h2>Content Search</h2>
                <p>Search through your course materials using semantic search</p>
            </div>
        </div>
    </div>

    <div class="interface-main">
        <div class="input-section">
            <div class="input-card">
                <div class="input-header">
                    <h3><i class="fa fa-search-plus"></i> Search Your Content</h3>
                </div>
                <div class="input-body">
                    <form method="POST" action="" class="search-form">
                        <input type="hidden" name="courseid" value="<?php echo $courseid; ?>">
                        <input type="hidden" name="submit" value="1">
                        
                        <div class="form-group">
                            <label for="query" class="form-label">
                                <i class="fa fa-search"></i> Search Query
                            </label>
                            <div class="input-wrapper">
                                <input type="text" 
                                       id="query" 
                                       name="query" 
                                       class="form-control search-input" 
                                       value="<?php echo htmlspecialchars($query); ?>" 
                                       placeholder="What would you like to find?"
                                       required>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="limit" class="form-label">
                                <i class="fa fa-list-ol"></i> Number of Results
                            </label>
                            <select id="limit" name="limit" class="form-control">
                                <option value="5" <?php echo $limit == 5 ? 'selected' : ''; ?>>5 results</option>
                                <option value="10" <?php echo $limit == 10 ? 'selected' : ''; ?>>10 results</option>
                                <option value="15" <?php echo $limit == 15 ? 'selected' : ''; ?>>15 results</option>
                                <option value="20" <?php echo $limit == 20 ? 'selected' : ''; ?>>20 results</option>
                            </select>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary btn-search">
                                <i class="fa fa-search"></i> Search Now
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
                    <h3><i class="fa fa-list"></i> Search Results</h3>
                </div>
                <div class="response-body">
                    <?php
                    $result = simple_search_api($fastapi_url, $courseid, $query, $limit);
                    
                    if (!empty($result['curl_error'])) {
                        echo "<div class='error-message'>";
                        echo "<i class='fa fa-exclamation-triangle'></i>";
                        echo "<strong>Connection Error:</strong> " . htmlspecialchars($result['curl_error']);
                        echo "</div>";
                    } else {
                        if ($result['http_code'] == 200) {
                            $response_data = json_decode($result['response'], true);
                            if ($response_data && isset($response_data['signal']) && $response_data['signal'] === 'vectordb_search_success') {
                                if (isset($response_data['results']) && is_array($response_data['results']) && count($response_data['results']) > 0) {
                                    echo "<div class='search-results'>";
                                    echo "<div class='results-header'>";
                                    echo "<i class='fa fa-check-circle text-success'></i>";
                                    echo "<span class='results-count'>Found " . count($response_data['results']) . " relevant results</span>";
                                    echo "</div>";
                                    
                                    foreach ($response_data['results'] as $idx => $item) {
                                        echo "<div class='result-item'>";
                                        echo "<div class='result-header'>";
                                        echo "<span class='result-number'>#" . ($idx + 1) . "</span>";
                                        if (isset($item['score'])) {
                                            echo "<span class='result-score'>Score: " . round($item['score'], 3) . "</span>";
                                        }
                                        echo "</div>";
                                        
                                        if (isset($item['text'])) {
                                            echo "<div class='result-content'>";
                                            echo "<div class='result-text'>" . nl2br(htmlspecialchars($item['text'])) . "</div>";
                                            echo "</div>";
                                        }
                                        
                                        if (isset($item['metadata']) && is_array($item['metadata'])) {
                                            echo "<div class='result-metadata'>";
                                            echo "<i class='fa fa-info-circle'></i> ";
                                            foreach ($item['metadata'] as $key => $value) {
                                                echo "<span class='metadata-item'><strong>" . htmlspecialchars($key) . ":</strong> " . htmlspecialchars($value) . "</span> ";
                                            }
                                            echo "</div>";
                                        }
                                        echo "</div>";
                                    }
                                    echo "</div>";
                                    
                                    echo "<div class='success-indicator'>";
                                    echo "<i class='fa fa-check-circle'></i> 🔍 Search completed successfully!";
                                    echo "</div>";
                                } else {
                                    echo "<div class='no-results'>";
                                    echo "<i class='fa fa-search'></i>";
                                    echo "<h4>No results found</h4>";
                                    echo "<p>Try different keywords or check your spelling</p>";
                                    echo "</div>";
                                }
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
                                <strong>Endpoint:</strong> POST <?php echo $fastapi_url; ?>/api/v1/nlp/index/search/<?php echo $courseid; ?><br>
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
            <a href="/blocks/multimodalrag/simple_chat.php?courseid=<?php echo $courseid; ?>" class="nav-link">
                <i class="fa fa-comments"></i> Chat with Content
            </a>
            <?php echo $OUTPUT->continue_button(new moodle_url('/course/view.php', ['id' => $courseid])); ?>
        </div>
    </div>
</div>

<?php
echo $OUTPUT->footer();
?>