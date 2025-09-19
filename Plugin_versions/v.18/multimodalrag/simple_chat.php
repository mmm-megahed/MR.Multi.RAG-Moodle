<?php
// simple_chat.php - Enhanced multimodal version with inline chunk images and compact source attribution
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

// =================================================================
// HELPER FUNCTIONS
// =================================================================

function simple_chat_api($fastapi_url, $courseid, $query, $limit) {
    $data = json_encode(['text' => $query, 'limit' => $limit]);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "{$fastapi_url}/api/v1/nlp/index/answer/{$courseid}",
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 90,
        CURLOPT_CONNECTTIMEOUT => 15
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    return ['http_code' => $http_code, 'response' => $response, 'curl_error' => $curl_error];
}

function verify_image_exists($image_name) {
    global $CFG;
    $possible_paths = [
        $CFG->dataroot . '/temp/extracted_images/' . $image_name,
        $CFG->dataroot . '/temp/images/' . $image_name,
        $CFG->dataroot . '/blocks/multimodalrag/images/' . $image_name
    ];
    foreach ($possible_paths as $path) {
        if (file_exists($path)) return str_replace($CFG->dataroot, '', $path);
    }
    return null;
}

function parse_enhanced_content($text) {
    $result = ['content' => $text, 'timestamp' => null, 'page_number' => null];
    if (preg_match('/^\[(\~?)(\d{1,2}:\d{2}(?::\d{2})?)\]\s*(.+)$/s', $text, $matches)) {
        $result['timestamp'] = $matches[2];
        $result['content'] = $matches[3];
    } elseif (preg_match('/^\[Page\s+(\d+)\]\s*(.+)$/s', $text, $matches)) {
        $result['page_number'] = intval($matches[1]);
        $result['content'] = $matches[2];
    }
    return $result;
}

function format_content_type_icon($content_type) {
    $icons = ['transcript' => 'fa-video text-primary', 'pdf' => 'fa-file-pdf text-danger', 'text' => 'fa-file-text text-info', 'image' => 'fa-image text-success'];
    return '<i class="fa ' . ($icons[$content_type] ?? 'fa-file text-muted') . '"></i>';
}

function format_timestamp_link($timestamp, $source_file, $courseid) {
    if (!$timestamp) return '';
    $parts = explode(':', $timestamp);
    $seconds = count($parts) == 2 ? intval($parts[0]) * 60 + intval($parts[1]) : (count($parts) == 3 ? intval($parts[0]) * 3600 + intval($parts[1]) * 60 + intval($parts[2]) : 0);
    $video_url = new moodle_url('/blocks/multimodalrag/video_player.php', ['courseid' => $courseid, 'file' => $source_file, 't' => $seconds]);
    return $video_url->out();
}

/**
 * Enhanced multimodal answer rendering with inline chunk images
 */
function render_multimodal_answer($answer_text, $sources, $courseid) {
    $html = '';
    
    // First render the main answer text with any embedded images
    $html .= "<div class='ai-answer-main'>";
    $html .= "<div class='answer-content'>";
    $html .= "<i class='fa fa-quote-left quote-icon'></i>";
    $html .= "<div class='answer-text-container'>" . render_answer_with_images($answer_text, $courseid) . "</div>";
    $html .= "</div></div>";
    
    // Then add relevant chunk images from sources
    if (!empty($sources)) {
        $chunk_images = [];
        foreach ($sources as $source) {
            $text = $source['text'] ?? '';
            $metadata = $source['metadata'] ?? [];
            $content_type = $metadata['content_type'] ?? 'text';
            
            // Check if this source contains an image
            if ($content_type === 'image' || strpos($text, 'Image:') !== false) {
                $image_name = '';
                if (preg_match('/Image:\s*(.*)/', $text, $matches)) {
                    $image_name = trim($matches[1]);
                }
                
                if ($image_name && ($image_web_path = verify_image_exists($image_name))) {
                    $image_url = new moodle_url('/blocks/multimodalrag/serve_image.php', ['path' => $image_web_path, 'courseid' => $courseid]);
                    $chunk_images[] = [
                        'url' => $image_url->out(),
                        'name' => $image_name,
                        'source_file' => $metadata['source_file'] ?? '',
                        'score' => $source['score'] ?? 0
                    ];
                }
            }
        }
        
        // Display chunk images in a compact grid
        if (!empty($chunk_images)) {
            $html .= "<div class='chunk-images-section'>";
            $html .= "<h4><i class='fa fa-images'></i> Related Images from Course Material</h4>";
            $html .= "<div class='chunk-images-grid'>";
            foreach ($chunk_images as $img) {
                $html .= "<div class='chunk-image-item'>";
                $html .= "<img src='{$img['url']}' alt='{$img['name']}' class='chunk-image' onclick='openImageModal(\"{$img['url']}\", \"{$img['name']}\")'>";
                $html .= "<div class='chunk-image-caption'>";
                $html .= "<span class='chunk-image-name'>{$img['name']}</span>";
                if ($img['score'] > 0) {
                    $score_class = $img['score'] > 0.8 ? 'high' : ($img['score'] > 0.6 ? 'medium' : 'low');
                    $html .= "<span class='chunk-image-score {$score_class}'>" . round($img['score'] * 100, 1) . "%</span>";
                }
                $html .= "</div></div>";
            }
            $html .= "</div></div>";
        }
    }
    
    return $html;
}

/**
 * Render compact source attribution box
 */
function render_compact_sources($sources, $courseid) {
    if (empty($sources)) return '';
    
    $html = "<div class='compact-sources'>";
    $html .= "<div class='compact-sources-header'>";
    $html .= "<h4><i class='fa fa-book'></i> Source References (" . count($sources) . ")</h4>";
    $html .= "<button class='toggle-sources-btn' onclick='toggleSources()'><i class='fa fa-chevron-down'></i></button>";
    $html .= "</div>";
    
    $html .= "<div class='compact-sources-content' id='sourcesContent'>";
    $html .= "<div class='sources-grid'>";
    
    foreach ($sources as $idx => $item) {
        $text = $item['text'] ?? '';
        $metadata = $item['metadata'] ?? [];
        $content_type = $metadata['content_type'] ?? 'text';
        $is_image = (strpos($text, 'Image:') !== false) || $content_type === 'image';
        
        $html .= "<div class='compact-source-item " . ($is_image ? 'image' : 'text') . "-source'>";
        
        // Source header with type and score
        $html .= "<div class='compact-source-header'>";
        $html .= "<span class='source-number'>#" . ($idx + 1) . "</span>";
        $html .= "<span class='source-type'>" . format_content_type_icon($is_image ? 'image' : $content_type) . " " . ($is_image ? 'Image' : ucfirst($content_type)) . "</span>";
        if (isset($item['score'])) {
            $score_class = $item['score'] > 0.8 ? 'high' : ($item['score'] > 0.6 ? 'medium' : 'low');
            $html .= "<span class='source-score {$score_class}'>" . round($item['score'] * 100, 1) . "%</span>";
        }
        $html .= "</div>";
        
        // Source content preview
        $html .= "<div class='compact-source-content'>";
        if ($is_image) {
            $image_name = '';
            if (preg_match('/Image:\s*(.*)/', $text, $matches)) {
                $image_name = trim($matches[1]);
            }
            $html .= "<div class='source-image-preview'>";
            if ($image_name && ($image_web_path = verify_image_exists($image_name))) {
                $image_url = new moodle_url('/blocks/multimodalrag/serve_image.php', ['path' => $image_web_path, 'courseid' => $courseid]);
                $html .= "<img src='" . $image_url->out() . "' alt='" . htmlspecialchars($image_name) . "' class='preview-image'>";
            }
            $html .= "<span class='image-name'>" . htmlspecialchars($image_name) . "</span>";
            $html .= "</div>";
        } else {
            $parsed = parse_enhanced_content($text);
            $preview_text = mb_substr($parsed['content'], 0, 150) . (mb_strlen($parsed['content']) > 150 ? '...' : '');
            $html .= "<div class='source-text-preview'>" . htmlspecialchars($preview_text) . "</div>";
            
            if ($parsed['timestamp'] || $parsed['page_number']) {
                $html .= "<div class='source-context'>";
                if ($parsed['timestamp']) {
                    $link = format_timestamp_link($parsed['timestamp'], $metadata['source_file'] ?? '', $courseid);
                    $html .= "<span class='context-timestamp'><i class='fa fa-play'></i> <a href='{$link}' target='_blank'>{$parsed['timestamp']}</a></span>";
                }
                if ($parsed['page_number']) {
                    $html .= "<span class='context-page'><i class='fa fa-file-text-o'></i> Page {$parsed['page_number']}</span>";
                }
                $html .= "</div>";
            }
        }
        
        // Source file info
        if (isset($metadata['source_file'])) {
            $html .= "<div class='source-file'>" . htmlspecialchars(basename($metadata['source_file'])) . "</div>";
        }
        
        $html .= "</div></div>";
    }
    
    $html .= "</div></div></div>";
    return $html;
}

/**
 * ## REVISED AND MORE ROBUST RENDER FUNCTION ##
 */
function render_answer_with_images($answer_text, $courseid) {
    $html = '';
    // Split the text by the image tag, keeping the image filename as a captured delimiter.
    $parts = preg_split('/\[IMAGE:\s*([^\]]+)\]/i', $answer_text, -1, PREG_SPLIT_DELIM_CAPTURE);

    for ($i = 0; $i < count($parts); $i++) {
        if ($i % 2 == 0) {
            // This is a regular text segment.
            if (!empty($parts[$i])) {
                $html .= "<div class='answer-text-segment'>" . nl2br(htmlspecialchars($parts[$i])) . "</div>";
            }
        } else {
            // This is a captured image filename.
            $image_name = trim($parts[$i]);
            if ($image_web_path = verify_image_exists($image_name)) {
                $image_url = new moodle_url('/blocks/multimodalrag/serve_image.php', ['path' => $image_web_path, 'courseid' => $courseid]);
                $modal_url_out = $image_url->out();
                $image_name_out = htmlspecialchars($image_name);
                $html .= "
                <div class='in-answer-image-container'>
                    <img src='{$modal_url_out}' alt='{$image_name_out}' class='in-answer-image' onclick='openImageModal(\"{$modal_url_out}\", \"{$image_name_out}\")'>
                    <div class='in-answer-image-caption'><i class='fa fa-image'></i> {$image_name_out}</div>
                </div>";
            } else {
                // Display a clear error if the file is not found on the server.
                $html .= "<div class='image-not-found'><i class='fa fa-exclamation-triangle'></i> Image not found on server: <strong>" . htmlspecialchars($image_name) . "</strong></div>";
            }
        }
    }
    return $html;
}

$fastapi_url = rtrim(get_config('block_multimodalrag', 'fastapi_url') ?: 'http://fastapi:8000', '/');
?>

<div class="multimodal-interface-container">
    <div class="interface-header">
        <div class="header-content">
            <div class="header-icon"><i class="fa fa-robot"></i></div>
            <div class="header-text">
                <h2>AI Chat Interface</h2>
                <p>Ask questions and get answers with direct references to your course materials.</p>
            </div>
        </div>
    </div>

    <div class="interface-main">
        <div class="input-section">
            <div class="input-card">
                <div class="input-header"><h3><i class="fa fa-comments"></i> Chat with Your Course Content</h3></div>
                <div class="input-body">
                    <form method="POST" action="" class="chat-form">
                        <input type="hidden" name="courseid" value="<?php echo $courseid; ?>">
                        <input type="hidden" name="submit" value="1">
                        <div class="form-group">
                            <label for="query" class="form-label"><i class="fa fa-question-circle"></i> Your Question</label>
                            <textarea id="query" name="query" class="form-control chat-input" rows="3" placeholder="Ask about concepts, summaries, or find specific information..." required><?php echo htmlspecialchars($query); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label for="limit" class="form-label"><i class="fa fa-book-reader"></i> Number of References</label>
                            <select id="limit" name="limit" class="form-control">
                                <option value="5" <?php echo $limit == 5 ? 'selected' : ''; ?>>5 References</option>
                                <option value="10" <?php echo $limit == 10 ? 'selected' : ''; ?>>10 References</option>
                                <option value="15" <?php echo $limit == 15 ? 'selected' : ''; ?>>15 References</option>
                            </select>
                        </div>
                        <div class="form-actions"><button type="submit" class="btn btn-primary btn-chat"><i class="fa fa-paper-plane"></i> Ask AI</button></div>
                    </form>
                </div>
            </div>
        </div>

        <?php if ($submitted && !empty($query)): ?>
        <div class="response-section">
            <div class="response-card">
                <div class="response-header"><h3><i class="fa fa-brain"></i> AI Response</h3></div>
                <div class="response-body">
                    <?php
                    $result = simple_chat_api($fastapi_url, $courseid, $query, $limit);
                    
                    if (!empty($result['curl_error'])) {
                        echo "<div class='error-message'><i class='fa fa-exclamation-triangle'></i><strong>Connection Error:</strong> " . htmlspecialchars($result['curl_error']) . "</div>";
                    } elseif ($result['http_code'] == 200) {
                        $response_data = json_decode($result['response'], true);
                        if ($response_data && isset($response_data['signal']) && $response_data['signal'] === 'rag_answer_success') {
                            
                            // Enhanced multimodal answer rendering
                            echo render_multimodal_answer($response_data['answer'], $response_data['sources'] ?? [], $courseid);

                            // Compact source attribution
                            if (isset($response_data['sources']) && is_array($response_data['sources']) && !empty($response_data['sources'])) {
                                echo render_compact_sources($response_data['sources'], $courseid);
                            }
                            
                        } else {
                            echo "<div class='error-message'><i class='fa fa-exclamation-circle'></i><strong>API Error:</strong> Invalid response format.</div>";
                        }
                    } else {
                        $response_body = json_decode($result['response'], true);
                        $detail = isset($response_body['detail']) ? htmlspecialchars($response_body['detail']) : 'No details available.';
                        echo "<div class='error-message'><i class='fa fa-times-circle'></i><strong>HTTP {$result['http_code']}:</strong> Request failed. <br><small>{$detail}</small></div>";
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
                                <strong>Payload:</strong> <pre><?php echo json_encode(['text' => $query, 'limit' => $limit], JSON_PRETTY_PRINT); ?></pre>
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
            <a href="/blocks/multimodalrag/simple_search.php?courseid=<?php echo $courseid; ?>" class="nav-link"><i class="fa fa-search"></i> Search Content</a>
            <?php echo $OUTPUT->continue_button(new moodle_url('/course/view.php', ['id' => $courseid])); ?>
        </div>
    </div>
</div>

<style>
/* Main AI Answer Styles */
.ai-answer-main { background: #f8f9fa; border-left: 4px solid #007bff; border-radius: 12px; padding: 25px; margin-bottom: 20px; }
.answer-content { display: flex; gap: 15px; }
.quote-icon { font-size: 20px; color: #007bff; margin-top: 5px; flex-shrink: 0; }
.answer-text-container { font-size: 16px; line-height: 1.7; color: #343a40; width: 100%; }
.answer-text-segment { margin-bottom: 1em; }
.in-answer-image-container { margin: 20px 0; }
.in-answer-image { max-width: 100%; max-height: 400px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); cursor: pointer; border: 1px solid #dee2e6; display: block; margin: 0 auto; }
.in-answer-image-caption { text-align: center; font-size: 0.9em; color: #6c757d; margin-top: 10px; }
.image-not-found { font-style: italic; color: #dc3545; background-color: #f8d7da; border: 1px solid #f5c6cb; padding: 10px; border-radius: 6px; margin: 15px 0; }

/* Chunk Images Section */
.chunk-images-section { margin: 20px 0; padding: 20px; background: #f8f9fa; border-radius: 12px; border: 1px solid #dee2e6; }
.chunk-images-section h4 { color: #495057; margin-bottom: 15px; display: flex; align-items: center; gap: 8px; }
.chunk-images-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; }
.chunk-image-item { background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
.chunk-image { width: 100%; height: 120px; object-fit: cover; cursor: pointer; transition: transform 0.2s; }
.chunk-image:hover { transform: scale(1.05); }
.chunk-image-caption { padding: 10px; display: flex; justify-content: space-between; align-items: center; }
.chunk-image-name { font-size: 12px; color: #6c757d; font-weight: 500; }
.chunk-image-score { font-size: 11px; padding: 2px 6px; border-radius: 3px; font-weight: 600; }
.chunk-image-score.high { background: #d4edda; color: #155724; }
.chunk-image-score.medium { background: #fff3cd; color: #856404; }
.chunk-image-score.low { background: #f8d7da; color: #721c24; }

/* Compact Sources Section */
.compact-sources { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-top: 20px; overflow: hidden; }
.compact-sources-header { background: #f8f9fa; padding: 15px 20px; border-bottom: 1px solid #dee2e6; display: flex; justify-content: space-between; align-items: center; }
.compact-sources-header h4 { margin: 0; color: #495057; display: flex; align-items: center; gap: 8px; }
.toggle-sources-btn { background: none; border: none; color: #6c757d; font-size: 16px; cursor: pointer; padding: 5px; border-radius: 4px; transition: background-color 0.2s; }
.toggle-sources-btn:hover { background-color: #e9ecef; }
.compact-sources-content { max-height: 400px; overflow-y: auto; }
.sources-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 15px; padding: 20px; }

/* Compact Source Items */
.compact-source-item { background: #f8f9fa; border-radius: 8px; padding: 12px; border: 1px solid #dee2e6; }
.compact-source-header { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; }
.source-number { background: #6c757d; color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px; font-weight: 600; }
.source-type { display: flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 500; color: #495057; }
.source-score { font-size: 11px; padding: 2px 6px; border-radius: 3px; font-weight: 600; }
.source-score.high { background: #d4edda; color: #155724; }
.source-score.medium { background: #fff3cd; color: #856404; }
.source-score.low { background: #f8d7da; color: #721c24; }

.compact-source-content { font-size: 13px; }
.source-image-preview { text-align: center; }
.preview-image { max-width: 100%; max-height: 80px; border-radius: 4px; margin-bottom: 5px; }
.image-name { font-size: 11px; color: #6c757d; font-weight: 500; }
.source-text-preview { color: #495057; line-height: 1.4; margin-bottom: 8px; }
.source-context { display: flex; gap: 10px; margin-bottom: 8px; }
.context-timestamp, .context-page { font-size: 11px; color: #6c757d; display: flex; align-items: center; gap: 3px; }
.context-timestamp a { color: #007bff; text-decoration: none; }
.context-timestamp a:hover { text-decoration: underline; }
.source-file { font-size: 11px; color: #6c757d; font-style: italic; }

/* Image Modal */
.image-modal { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.9); }
.image-modal-content { position: relative; margin: auto; padding: 20px; width: 80%; max-width: 800px; top: 50%; transform: translateY(-50%); text-align: center; }
.image-modal-content img { max-width: 100%; max-height: 80vh; border-radius: 8px; }
.image-modal-close { position: absolute; top: 15px; right: 35px; color: #f1f1f1; font-size: 40px; font-weight: bold; cursor: pointer; }
.image-modal-title { color: white; margin-top: 15px; font-size: 18px; }

/* Responsive Design */
@media (max-width: 768px) {
    .chunk-images-grid { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); }
    .sources-grid { grid-template-columns: 1fr; }
    .compact-sources-content { max-height: 300px; }
}
</style>

<script>
function openImageModal(imageUrl, imageName) {
    let modal = document.getElementById('imageModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'imageModal';
        modal.className = 'image-modal';
        modal.innerHTML = `<span class="image-modal-close">&times;</span><div class="image-modal-content"><img id="modalImage" src="" alt=""><div id="modalTitle" class="image-modal-title"></div></div>`;
        document.body.appendChild(modal);
        modal.querySelector('.image-modal-close').onclick = closeImageModal;
        modal.onclick = (event) => { if (event.target === modal) closeImageModal(); };
        document.addEventListener('keydown', (event) => { if (event.key === 'Escape') closeImageModal(); });
    }
    document.getElementById('modalImage').src = imageUrl;
    document.getElementById('modalTitle').textContent = imageName;
    modal.style.display = 'block';
}

function closeImageModal() {
    const modal = document.getElementById('imageModal');
    if (modal) modal.style.display = 'none';
}

function toggleSources() {
    const content = document.getElementById('sourcesContent');
    const btn = document.querySelector('.toggle-sources-btn i');
    
    if (content.style.display === 'none') {
        content.style.display = 'block';
        btn.className = 'fa fa-chevron-down';
    } else {
        content.style.display = 'none';
        btn.className = 'fa fa-chevron-up';
    }
}
</script>

<?php
echo $OUTPUT->footer();
?>
