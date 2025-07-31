## simple_search.php
```php
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

## simple_chat.php
```php
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
                <p>Ask questions about your course content and get intelligent answers</p>
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
                                         placeholder="What would you like to know about the course content?"
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
                                
                                echo "<div class='success-indicator'>";
                                echo "<i class='fa fa-check-circle'></i> 🤖 AI response generated successfully!";
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
echo $OUTPUT->footer();# Moodle Block Plugin: multimodalrag

## Directory Structure
```
blocks/multimodalrag/
├── block_multimodalrag.php
├── version.php
├── db/
│   ├── access.php
│   └── install.xml
├── lang/en/
│   └── block_multimodalrag.php
├── settings.php
├── process.php
├── simple_search.php
├── simple_chat.php
├── ajax.php
├── styles.css
└── js/
    └── module.js
```

## block_multimodalrag.php
```php
<?php
defined('MOODLE_INTERNAL') || die();

class block_multimodalrag extends block_base {
    public function init() {
        $this->title = get_string('pluginname', 'block_multimodalrag');
    }

    public function get_content() {
        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass;
        $this->content->text = '';
        $this->content->footer = '';

        if (!$this->page->course || $this->page->course->id == SITEID) {
            return $this->content;
        }

        $courseid = $this->page->course->id;
        $context = context_course::instance($courseid);

        if (has_capability('block/multimodalrag:processfiles', $context)) {
            $processurl = new moodle_url('/blocks/multimodalrag/process.php', [
                'courseid' => $courseid,
                'sesskey' => sesskey()
            ]);
            $this->content->text .= html_writer::link($processurl, 
                '<i class="fa fa-cogs"></i> Process Files', 
                ['class' => 'btn btn-primary btn-sm mb-2']);
        }

        if (has_capability('block/multimodalrag:search', $context) || 
            has_capability('block/multimodalrag:chat', $context)) {
            $this->content->text .= '<div class="multimodal-nav-buttons">';
            
            if (has_capability('block/multimodalrag:search', $context)) {
                $simplesearchurl = new moodle_url('/blocks/multimodalrag/simple_search.php', [
                    'courseid' => $courseid
                ]);
                $this->content->text .= html_writer::link($simplesearchurl, 
                    '<i class="fa fa-search"></i> Search Content', 
                    ['class' => 'btn btn-primary btn-sm d-block mb-2']);
            }
            
            if (has_capability('block/multimodalrag:chat', $context)) {
                $simplechaturl = new moodle_url('/blocks/multimodalrag/simple_chat.php', [
                    'courseid' => $courseid
                ]);
                $this->content->text .= html_writer::link($simplechaturl, 
                    '<i class="fa fa-comments"></i> Chat with Content', 
                    ['class' => 'btn btn-success btn-sm d-block']);
            }
            
            $this->content->text .= '</div>';
        }

        return $this->content;
    }

    public function applicable_formats() {
        return ['course' => true, 'course-category' => false, 'site' => false];
    }

    public function has_config() {
        return true;
    }
}
?>

## simple_search.php
```php
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
$PAGE->set_title('Simple Search');
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

<div class="simple-search-container">
    <div class="card">
        <div class="card-header">
            <h3><i class="fa fa-search-plus"></i> Simple FastAPI Search</h3>
            <p class="text-muted">Direct search interface to test FastAPI connection</p>
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <input type="hidden" name="courseid" value="<?php echo $courseid; ?>">
                <input type="hidden" name="submit" value="1">
                
                <div class="form-group mb-3">
                    <label for="query"><strong>Search Query:</strong></label>
                    <input type="text" 
                           id="query" 
                           name="query" 
                           class="form-control" 
                           value="<?php echo htmlspecialchars($query); ?>" 
                           placeholder="What is attention?" 
                           required>
                </div>
                
                <div class="form-group mb-3">
                    <label for="limit"><strong>Result Limit:</strong></label>
                    <select id="limit" name="limit" class="form-control">
                        <option value="5" <?php echo $limit == 5 ? 'selected' : ''; ?>>5 results</option>
                        <option value="10" <?php echo $limit == 10 ? 'selected' : ''; ?>>10 results</option>
                        <option value="15" <?php echo $limit == 15 ? 'selected' : ''; ?>>15 results</option>
                    </select>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-search"></i> Search Now
                </button>
            </form>
        </div>
    </div>

    <?php if ($submitted && !empty($query)): ?>
    <div class="card mt-4">
        <div class="card-header">
            <h4><i class="fa fa-terminal"></i> API Request & Response</h4>
        </div>
        <div class="card-body">
            <?php
            echo "<div class='api-request mb-3'>";
            echo "<h5>Request Details:</h5>";
            echo "<div class='code-block'>";
            echo "<strong>URL:</strong> POST {$fastapi_url}/api/v1/nlp/index/search/{$courseid}<br>";
            echo "<strong>Headers:</strong> Content-Type: application/json<br>";
            echo "<strong>Body:</strong> " . json_encode(['text' => $query, 'limit' => $limit], JSON_PRETTY_PRINT);
            echo "</div>";
            echo "</div>";

            $result = simple_search_api($fastapi_url, $courseid, $query, $limit);
            
            echo "<div class='api-response'>";
            echo "<h5>Response:</h5>";
            
            if (!empty($result['curl_error'])) {
                echo "<div class='alert alert-danger'>";
                echo "<strong>cURL Error:</strong> " . htmlspecialchars($result['curl_error']);
                echo "</div>";
            } else {
                echo "<div class='response-status mb-2'>";
                echo "<strong>HTTP Status:</strong> <span class='badge " . 
                     ($result['http_code'] == 200 ? 'badge-success' : 'badge-danger') . "'>" . 
                     $result['http_code'] . "</span>";
                echo "</div>";
                
                echo "<div class='response-body'>";
                echo "<strong>Response Body:</strong>";
                echo "<div class='code-block'>";
                
                if ($result['http_code'] == 200) {
                    $response_data = json_decode($result['response'], true);
                    if ($response_data) {
                        echo "<pre>" . json_encode($response_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
                        
                        if (isset($response_data['signal']) && $response_data['signal'] === 'vectordb_search_success') {
                            echo "<div class='success-indicator mt-2'>";
                            echo "<i class='fa fa-check-circle text-success'></i> 🔍 Search completed successfully!";
                            echo "</div>";
                            
                            if (isset($response_data['results']) && is_array($response_data['results'])) {
                                echo "<div class='search-results mt-3'>";
                                echo "<h6>Found " . count($response_data['results']) . " results:</h6>";
                                echo "<ol>";
                                foreach ($response_data['results'] as $idx => $item) {
                                    echo "<li class='result-item'>";
                                    if (isset($item['text'])) {
                                        echo "<div class='result-text'>" . htmlspecialchars(substr($item['text'], 0, 200)) . "...</div>";
                                    }
                                    if (isset($item['score'])) {
                                        echo "<div class='result-score'>Score: " . round($item['score'], 4) . "</div>";
                                    }
                                    echo "</li>";
                                }
                                echo "</ol>";
                                echo "</div>";
                            }
                        }
                    } else {
                        echo htmlspecialchars($result['response']);
                    }
                } else {
                    echo htmlspecialchars($result['response']);
                }
                echo "</div>";
                echo "</div>";
            }
            echo "</div>";
            ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="mt-4">
    <?php echo $OUTPUT->continue_button(new moodle_url('/course/view.php', ['id' => $courseid])); ?>
</div>

<?php
echo $OUTPUT->footer();

## version.php
```php
<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'block_multimodalrag';
$plugin->version = 2024072900;
$plugin->requires = 2020061500;
$plugin->maturity = MATURITY_STABLE;
$plugin->release = 'v1.0';
?>
```

## db/access.php
```php
<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'block/multimodalrag:addinstance' => [
        'riskbitmask' => RISK_SPAM | RISK_XSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_BLOCK,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW
        ],
        'clonepermissionsfrom' => 'moodle/site:manageblocks'
    ],
    'block/multimodalrag:processfiles' => [
        'riskbitmask' => RISK_CONFIG,
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW
        ]
    ],
    'block/multimodalrag:search' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'student' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW
        ]
    ],
    'block/multimodalrag:chat' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'student' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW
        ]
    ]
];
?>
```

## db/install.xml
```xml
<?xml version="1.0" encoding="UTF-8" ?>
<XMLDB PATH="blocks/multimodalrag/db" VERSION="20240729" COMMENT="XMLDB file for Moodle blocks/multimodalrag">
  <TABLES>
    <TABLE NAME="block_multimodalrag_processed" COMMENT="Track processed files">
      <FIELDS>
        <FIELD NAME="id" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
        <FIELD NAME="courseid" TYPE="int" LENGTH="10" NOTNULL="true"/>
        <FIELD NAME="filename" TYPE="char" LENGTH="255" NOTNULL="true"/>
        <FIELD NAME="contenthash" TYPE="char" LENGTH="40" NOTNULL="true"/>
        <FIELD NAME="processed_time" TYPE="int" LENGTH="10" NOTNULL="true"/>
        <FIELD NAME="status" TYPE="char" LENGTH="20" NOTNULL="true" DEFAULT="processed"/>
      </FIELDS>
      <KEYS>
        <KEY NAME="primary" TYPE="primary" FIELDS="id"/>
      </KEYS>
      <INDEXES>
        <INDEX NAME="courseid" UNIQUE="false" FIELDS="courseid"/>
        <INDEX NAME="contenthash" UNIQUE="false" FIELDS="contenthash"/>
      </INDEXES>
    </TABLE>
  </TABLES>
</XMLDB>
```

## lang/en/block_multimodalrag.php
```php
<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Multimodal RAG';
$string['multimodalrag:addinstance'] = 'Add a new Multimodal RAG block';
$string['multimodalrag:processfiles'] = 'Process course files';
$string['multimodalrag:search'] = 'Search content';
$string['multimodalrag:chat'] = 'Chat with content';
$string['fastapi_url'] = 'FastAPI URL';
$string['fastapi_url_desc'] = 'URL of the FastAPI backend server';
$string['processing_title'] = 'Processing Files';
$string['search_title'] = 'Search & Chat';
?>
```

## settings.php
```php
<?php
defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_configtext(
        'block_multimodalrag/fastapi_url',
        get_string('fastapi_url', 'block_multimodalrag'),
        get_string('fastapi_url_desc', 'block_multimodalrag'),
        'http://fastapi:8000',
        PARAM_URL
    ));
}
?>
```

## process.php
```php
<?php
require_once('../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$sesskey = required_param('sesskey', PARAM_ALPHANUM);
$chunk_size = optional_param('chunk_size', 200, PARAM_INT);
$overlap_size = optional_param('overlap_size', 20, PARAM_INT);
$do_reset = optional_param('do_reset', 0, PARAM_INT);

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
```

## searchchat.php
```php
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
```

## ajax.php
```php
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
            $limit = optional_param('limit', 10, PARAM_INT);
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
```

## styles.css
```css
/* Base Styles */
.multimodal-rag-interface .card {
    margin-bottom: 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.processing-container {
    max-width: 800px;
    margin: 0 auto;
}

.progress-steps .step {
    margin-bottom: 20px;
    padding: 15px;
    border: 1px solid #ddd;
    border-radius: 5px;
}

.step-content {
    margin-top: 10px;
}

.success-item {
    color: #28a745;
    margin: 5px 0;
}

.error-item {
    color: #dc3545;
    margin: 5px 0;
}

#search-results, #chat-response {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 5px;
    min-height: 100px;
}

.loading {
    text-align: center;
    color: #6c757d;
}

/* Block Navigation */
.multimodal-nav-buttons {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.multimodal-nav-buttons .btn {
    text-decoration: none;
    border-radius: 6px;
    font-weight: 500;
    transition: all 0.3s ease;
}

.multimodal-nav-buttons .btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}

/* Enhanced Interface Container */
.multimodal-interface-container {
    max-width: 1000px;
    margin: 0 auto;
    background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
    min-height: 80vh;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}

/* Interface Header */
.interface-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 30px;
    text-align: center;
    position: relative;
    overflow: hidden;
}

.interface-header::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="50" cy="50" r="1" fill="white" opacity="0.1"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
    pointer-events: none;
}

.header-content {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 20px;
    position: relative;
    z-index: 1;
}

.header-icon {
    font-size: 48px;
    opacity: 0.9;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.05); }
}

.header-text h2 {
    margin: 0;
    font-size: 28px;
    font-weight: 700;
    text-shadow: 0 2px 4px rgba(0,0,0,0.3);
}

.header-text p {
    margin: 8px 0 0 0;
    font-size: 16px;
    opacity: 0.9;
}

/* Interface Main */
.interface-main {
    padding: 40px;
    background: white;
}

/* Input Section */
.input-section {
    margin-bottom: 30px;
}

.input-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 8px 25px rgba(0,0,0,0.1);
    overflow: hidden;
    border: 1px solid #e1e8ed;
}

.input-header {
    background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    color: white;
    padding: 20px 30px;
    border-bottom: none;
}

.input-header h3 {
    margin: 0;
    font-size: 20px;
    font-weight: 600;
}

.input-body {
    padding: 30px;
}

/* Form Styles */
.search-form, .chat-form {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.form-label {
    font-weight: 600;
    color: #2c3e50;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.form-label i {
    color: #3498db;
}

.input-wrapper {
    position: relative;
}

.form-control {
    border: 2px solid #e1e8ed;
    border-radius: 8px;
    padding: 12px 16px;
    font-size: 16px;
    transition: all 0.3s ease;
    background: #fafbfc;
}

.form-control:focus {
    border-color: #3498db;
    box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
    outline: none;
    background: white;
}

.search-input, .chat-input {
    font-size: 16px;
    resize: vertical;
}

.chat-input {
    min-height: 80px;
    font-family: inherit;
}

/* Buttons */
.form-actions {
    display: flex;
    justify-content: flex-end;
}

.btn {
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
}

.btn-primary {
    background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
    color: white;
}

.btn-primary:hover {
    background: linear-gradient(135deg, #2980b9 0%, #1f618d 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(52, 152, 219, 0.3);
}

.btn-search, .btn-chat {
    min-width: 140px;
    justify-content: center;
}

/* Response Section */
.response-section {
    margin-top: 30px;
}

.response-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 8px 25px rgba(0,0,0,0.1);
    overflow: hidden;
    border: 1px solid #e1e8ed;
}

.response-header {
    background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
    color: white;
    padding: 20px 30px;
}

.response-header h3 {
    margin: 0;
    font-size: 20px;
    font-weight: 600;
}

.response-body {
    padding: 30px;
}

/* Search Results */
.search-results {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}

.results-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #e9ecef;
}

.results-count {
    font-weight: 600;
    color: #2c3e50;
    font-size: 16px;
}

.result-item {
    background: white;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 15px;
    border: 1px solid #e1e8ed;
    transition: all 0.3s ease;
}

.result-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0,0,0,0.1);
}

.result-item:last-child {
    margin-bottom: 0;
}

.result-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 1px solid #f1f3f4;
}

.result-number {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}

.result-score {
    background: #e8f5e8;
    color: #27ae60;
    padding: 4px 10px;
    border-radius: 15px;
    font-size: 12px;
    font-weight: 600;
}

.result-content {
    margin-bottom: 12px;
}

.result-text {
    font-size: 14px;
    line-height: 1.6;
    color: #2c3e50;
}

.result-metadata {
    font-size: 12px;
    color: #7f8c8d;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
}

.metadata-item {
    background: #f8f9fa;
    padding: 2px 8px;
    border-radius: 4px;
    border: 1px solid #e9ecef;
}

/* AI Answer */
.ai-answer {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 20px;
    color: white;
    position: relative;
    overflow: hidden;
}

.ai-answer::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="dots" width="20" height="20" patternUnits="userSpaceOnUse"><circle cx="10" cy="10" r="1" fill="white" opacity="0.1"/></pattern></defs><rect width="100" height="100" fill="url(%23dots)"/></svg>');
    pointer-events: none;
}

.answer-content {
    position: relative;
    z-index: 1;
}

.quote-icon {
    font-size: 24px;
    opacity: 0.7;
    margin-bottom: 15px;
    display: block;
}

.answer-text {
    font-size: 16px;
    line-height: 1.7;
    margin-left: 20px;
}

/* Success Indicators */
.success-indicator {
    background: linear-gradient(135deg, #56ab2f 0%, #a8e6cf 100%);
    border: none;
    color: #1e7e34;
    padding: 15px 20px;
    border-radius: 8px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 20px;
}

/* Error Messages */
.error-message {
    background: linear-gradient(135deg, #ff6b6b 0%, #feca57 100%);
    color: white;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 500;
}

.error-message i {
    font-size: 20px;
}

/* No Results */
.no-results {
    text-align: center;
    padding: 40px;
    color: #7f8c8d;
}

.no-results i {
    font-size: 48px;
    margin-bottom: 20px;
    opacity: 0.5;
}

.no-results h4 {
    margin-bottom: 10px;
    color: #5a6c7d;
}

/* Technical Details */
.tech-details {
    margin-top: 30px;
}

.api-details {
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    overflow: hidden;
}

.api-details summary {
    background: #e9ecef;
    padding: 15px 20px;
    cursor: pointer;
    font-weight: 600;
    color: #495057;
    display: flex;
    align-items: center;
    gap: 10px;
    transition: background-color 0.3s ease;
}

.api-details summary:hover {
    background: #dee2e6;
}

.details-content {
    padding: 20px;
}

.request-info, .response-info {
    margin-bottom: 20px;
}

.request-info h4, .response-info h4 {
    color: #343a40;
    margin-bottom: 10px;
    font-size: 16px;
}

.code-block {
    background: #2d3748;
    color: #e2e8f0;
    border-radius: 6px;
    padding: 15px;
    font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
    font-size: 13px;
    margin: 10px 0;
    overflow-x: auto;
    border: 1px solid #4a5568;
}

.code-block pre {
    margin: 0;
    white-space: pre-wrap;
    word-wrap: break-word;
    color: #a0aec0;
}

.code-block strong {
    color: #68d391;
}

/* Interface Footer */
.interface-footer {
    background: #f8f9fa;
    padding: 25px 40px;
    border-top: 1px solid #e9ecef;
}

.navigation-links {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
}

.nav-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    background: linear-gradient(135deg, #74b9ff 0%, #0984e3 100%);
    color: white;
    text-decoration: none;
    border-radius: 6px;
    font-weight: 500;
    transition: all 0.3s ease;
}

.nav-link:hover {
    background: linear-gradient(135deg, #0984e3 0%, #2d3436 100%);
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(116, 185, 255, 0.3);
    color: white;
    text-decoration: none;
}

/* Responsive Design */
@media (max-width: 768px) {
    .multimodal-interface-container {
        margin: 10px;
        border-radius: 8px;
    }
    
    .interface-header {
        padding: 20px;
    }
    
    .header-content {
        flex-direction: column;
        gap: 15px;
    }
    
    .header-icon {
        font-size: 36px;
    }
    
    .header-text h2 {
        font-size: 24px;
    }
    
    .interface-main {
        padding: 20px;
    }
    
    .input-body {
        padding: 20px;
    }
    
    .navigation-links {
        flex-direction: column;
        align-items: stretch;
    }
    
    .nav-link {
        justify-content: center;
    }
}
```

## js/module.js
```javascript
document.addEventListener('DOMContentLoaded', function() {
    const searchForm = document.getElementById('search-form');
    const chatForm = document.getElementById('chat-form');
    
    if (searchForm) {
        searchForm.addEventListener('submit', function(e) {
            e.preventDefault();
            performSearch();
        });
    }
    
    if (chatForm) {
        chatForm.addEventListener('submit', function(e) {
            e.preventDefault();
            performChat();
        });
    }
});

function performSearch() {
    const text = document.getElementById('search-text').value;
    const courseid = document.getElementById('search-courseid').value;
    const resultsDiv = document.getElementById('search-results');
    
    resultsDiv.innerHTML = '<div class="loading"><i class="fa fa-spinner fa-spin"></i> Searching...</div>';
    
    fetch('/blocks/multimodalrag/ajax.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=search&courseid=${courseid}&text=${encodeURIComponent(text)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            let html = '<h5>Search Results:</h5>';
            if (data.results && data.results.length > 0) {
                html += '<ul>';
                data.results.forEach(result => {
                    html += `<li>${result}</li>`;
                });
                html += '</ul>';
            } else {
                html += '<p>No results found.</p>';
            }
            resultsDiv.innerHTML = html;
        } else {
            resultsDiv.innerHTML = `<div class="alert alert-danger">Error: ${data.error}</div>`;
        }
    })
    .catch(error => {
        resultsDiv.innerHTML = `<div class="alert alert-danger">Request failed: ${error.message}</div>`;
    });
}

function performChat() {
    const text = document.getElementById('chat-text').value;
    const courseid = document.getElementById('chat-courseid').value;
    const responseDiv = document.getElementById('chat-response');
    
    responseDiv.innerHTML = '<div class="loading"><i class="fa fa-spinner fa-spin"></i> Thinking...</div>';
    
    fetch('/blocks/multimodalrag/ajax.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=chat&courseid=${courseid}&text=${encodeURIComponent(text)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            responseDiv.innerHTML = `<h5>Answer:</h5><div class="chat-answer">${data.answer}</div>`;
        } else {
            responseDiv.innerHTML = `<div class="alert alert-danger">Error: ${data.error}</div>`;
        }
    })
    .catch(error => {
        responseDiv.innerHTML = `<div class="alert alert-danger">Request failed: ${error.message}</div>`;
    });
}
```