<?php
// simple_search.php - Enhanced with better results display and chunk optimization
require_once('../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$query = optional_param('query', '', PARAM_TEXT);
$limit = optional_param('limit', 5, PARAM_INT);
$content_filter = optional_param('content_filter', 'all', PARAM_TEXT);
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

function verify_image_exists($image_name) {
    global $CFG;
    
    $possible_paths = [
        $CFG->dataroot . '/temp/extracted_images/' . $image_name,
        $CFG->dataroot . '/temp/images/' . $image_name,
        $CFG->dataroot . '/blocks/multimodalrag/images/' . $image_name
    ];
    
    foreach ($possible_paths as $path) {
        if (file_exists($path)) {
            $web_path = str_replace($CFG->dataroot, '', $path);
            return $web_path;
        }
    }
    
    return null;
}

function parse_enhanced_content($text) {
    $result = [
        'content' => $text,
        'timestamp' => null,
        'page_number' => null,
        'is_estimated' => false
    ];
    
    // Extract timestamp from content like [12:34] or [~12:34]
    if (preg_match('/^\[(\~?)(\d{1,2}:\d{2}(?::\d{2})?)\]\s*(.+)$/s', $text, $matches)) {
        $result['is_estimated'] = !empty($matches[1]);
        $result['timestamp'] = $matches[2];
        $result['content'] = $matches[3];
    }
    // Extract page number from content like [Page 5]
    elseif (preg_match('/^\[Page\s+(\d+)\]\s*(.+)$/s', $text, $matches)) {
        $result['page_number'] = intval($matches[1]);
        $result['content'] = $matches[2];
    }
    
    return $result;
}

function format_content_type_icon($content_type) {
    switch ($content_type) {
        case 'transcript':
            return '<i class="fa fa-video text-primary"></i>';
        case 'pdf':
            return '<i class="fa fa-file-pdf text-danger"></i>';
        case 'text':
            return '<i class="fa fa-file-text text-info"></i>';
        default:
            return '<i class="fa fa-file text-muted"></i>';
    }
}

function format_timestamp_link($timestamp, $source_file, $courseid) {
    if (!$timestamp) return '';
    
    // Convert timestamp to seconds for video seeking
    $parts = explode(':', $timestamp);
    if (count($parts) == 2) {
        $seconds = intval($parts[0]) * 60 + intval($parts[1]);
    } elseif (count($parts) == 3) {
        $seconds = intval($parts[0]) * 3600 + intval($parts[1]) * 60 + intval($parts[2]);
    } else {
        $seconds = 0;
    }
    
    // This would link to your video player with timestamp - adjust URL as needed
    $video_url = new moodle_url('/blocks/multimodalrag/video_player.php', [
        'courseid' => $courseid,
        'file' => $source_file,
        't' => $seconds
    ]);
    
    return $video_url->out();
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
                <p>Search through your course materials with smart chunking and contextual results</p>
            </div>
        </div>
    </div>

    <div class="interface-main">
        <div class="input-section">
            <div class="input-card">
                <div class="input-header">
                    <h3><i class="fa fa-search-plus"></i> Search Your Course Content</h3>
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
                                       placeholder="Search across all course materials — lectures, videos, PDFs, images, etc."
                                       required>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group col-md-6">
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
                            
                            <div class="form-group col-md-6">
                                <label for="content_filter" class="form-label">
                                    <i class="fa fa-filter"></i> Content Type Filter
                                </label>
                                <select id="content_filter" name="content_filter" class="form-control">
                                    <option value="all" <?php echo $content_filter == 'all' ? 'selected' : ''; ?>>All Content</option>
                                    <option value="transcript" <?php echo $content_filter == 'transcript' ? 'selected' : ''; ?>>Video</option>

                                    <option value="text" <?php echo $content_filter == 'text' ? 'selected' : ''; ?>>Text</option>
                                    <option value="image" <?php echo $content_filter == 'image' ? 'selected' : ''; ?>>Image</option>
                                </select>
                            </div>
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
                                    
                                    // Categorize results
                                    $categorized_results = [
                                        'transcript' => [],
                                        'pdf' => [],
                                        'text' => [],
                                        'image' => []
                                    ];
                                    
                                    foreach ($response_data['results'] as $idx => $item) {
                                        if (isset($item['text']) && strpos($item['text'], 'Image:') !== false) {
                                            $categorized_results['image'][] = ['index' => $idx, 'data' => $item];
                                        } else {
                                            $metadata = $item['metadata'] ?? [];
                                            $content_type = $metadata['content_type'] ?? 'text';
                                            if (isset($categorized_results[$content_type])) {
                                                $categorized_results[$content_type][] = ['index' => $idx, 'data' => $item];
                                            } else {
                                                $categorized_results['text'][] = ['index' => $idx, 'data' => $item];
                                            }
                                        }
                                    }
                                    
                                    // Apply content filter
                                    if ($content_filter !== 'all') {
                                        $filtered_results = [$content_filter => $categorized_results[$content_filter]];
                                        $categorized_results = $filtered_results;
                                    }
                                    
                                    echo "<div class='search-results enhanced'>";
                                    echo "<div class='results-summary'>";
                                    $total_results = array_sum(array_map('count', $categorized_results));
                                    echo "<div class='summary-header'>";
                                    echo "<i class='fa fa-check-circle text-success'></i>";
                                    echo "<span class='results-count'>Found {$total_results} relevant results</span>";
                                    echo "</div>";
                                    
                                    // Show result type breakdown
                                    echo "<div class='result-breakdown'>";
                                    foreach ($categorized_results as $type => $results) {
                                        if (!empty($results)) {
                                            $icon = format_content_type_icon($type);
                                            $type_name = ucfirst($type) . 's';
                                            echo "<span class='breakdown-item'>{$icon} {$type_name}: " . count($results) . "</span>";
                                        }
                                    }
                                    echo "</div>";
                                    echo "</div>";
                                    
                                    // Display results by category
                                    foreach ($categorized_results as $content_type => $results) {
                                        if (empty($results)) continue;
                                        
                                        if ($content_type === 'image') {
                                            echo "<div class='content-type-section image-section'>";
                                            echo "<h4>" . format_content_type_icon($content_type) . " Related Images</h4>";
                                            
                                            foreach ($results as $result_item) {
                                                $idx = $result_item['index'];
                                                $item = $result_item['data'];
                                                
                                                echo "<div class='result-item image-result enhanced'>";
                                                echo "<div class='result-header'>";
                                                echo "<div class='result-meta'>";
                                                echo "<span class='result-number'>#" . ($idx + 1) . "</span>";
                                                echo "<span class='result-type'>" . format_content_type_icon('image') . " Image</span>";
                                                if (isset($item['score'])) {
                                                    $score_class = $item['score'] > 0.8 ? 'high' : ($item['score'] > 0.6 ? 'medium' : 'low');
                                                    echo "<span class='result-score {$score_class}'>Match: " . round($item['score'] * 100, 1) . "%</span>";
                                                }
                                                echo "</div>";
                                                echo "</div>";
                                                
                                                // Process image content (same as before but with enhanced styling)
                                                if (isset($item['text'])) {
                                                    $lines = explode("\n", $item['text']);
                                                    $image_name = '';
                                                    $source_pdf = '';
                                                    $page_num = '';
                                                    $context = '';
                                                    $description = '';
                                                    
                                                    foreach ($lines as $line) {
                                                        if (strpos($line, 'Image:') === 0) {
                                                            $image_name = trim(str_replace('Image:', '', $line));
                                                        } elseif (strpos($line, 'Source PDF:') === 0) {
                                                            $source_pdf = trim(str_replace('Source PDF:', '', $line));
                                                        } elseif (strpos($line, 'Page:') === 0) {
                                                            $page_num = trim(str_replace('Page:', '', $line));
                                                        } elseif (strpos($line, 'Context:') === 0) {
                                                            $context = trim(str_replace('Context:', '', $line));
                                                        } elseif (strpos($line, 'Description:') === 0) {
                                                            $description = trim(str_replace('Description:', '', $line));
                                                        }
                                                    }
                                                    
                                                    echo "<div class='result-content image-content enhanced'>";
                                                    
                                                    if ($image_name) {
                                                        $image_web_path = verify_image_exists($image_name);
                                                        
                                                        if ($image_web_path) {
                                                            $image_url = new moodle_url('/blocks/multimodalrag/serve_image.php', [
                                                                'path' => $image_web_path,
                                                                'courseid' => $courseid
                                                            ]);
                                                            
                                                            echo "<div class='image-container enhanced'>";
                                                            echo "<img src='" . $image_url->out() . "' ";
                                                            echo "alt='" . htmlspecialchars($image_name) . "' ";
                                                            echo "class='search-result-image' ";
                                                            echo "onclick='openImageModal(\"" . $image_url->out() . "\", \"" . htmlspecialchars($image_name) . "\")'>";
                                                            echo "</div>";
                                                        } else {
                                                            echo "<div class='image-placeholder'>";
                                                            echo "<i class='fa fa-image fa-2x text-muted'></i>";
                                                            echo "<p class='text-muted'>Image not available</p>";
                                                            echo "</div>";
                                                        }
                                                    }
                                                    
                                                    echo "<div class='image-details enhanced'>";
                                                    if ($source_pdf && $page_num) {
                                                        echo "<div class='detail-item source'>";
                                                        echo "<i class='fa fa-file-pdf text-danger'></i>";
                                                        echo "<strong>{$source_pdf}</strong> - Page {$page_num}";
                                                        echo "</div>";
                                                    }
                                                    if ($description) {
                                                        echo "<div class='detail-item description'>";
                                                        echo "<i class='fa fa-info-circle'></i>";
                                                        echo "<span>" . htmlspecialchars(substr($description, 0, 200));
                                                        if (strlen($description) > 200) echo "...";
                                                        echo "</span></div>";
                                                    }
                                                    if ($context) {
                                                        echo "<div class='detail-item context'>";
                                                        echo "<i class='fa fa-quote-left'></i>";
                                                        echo "<em>" . htmlspecialchars(substr($context, 0, 150));
                                                        if (strlen($context) > 150) echo "...";
                                                        echo "</em></div>";
                                                    }
                                                    echo "</div>";
                                                    echo "</div>";
                                                }
                                                echo "</div>";
                                            }
                                            echo "</div>";
                                        } else {
                                            // Text-based results (transcript, pdf, text)
                                            $type_name = ucfirst($content_type);
                                            if ($content_type === 'transcript') $type_name = 'Video Transcripts';
                                            #elseif ($content_type === 'pdf') $type_name = 'PDF Documents';
                                            elseif ($content_type === 'text') $type_name = 'Text Content';
                                            
                                            echo "<div class='content-type-section {$content_type}-section'>";
                                            echo "<h4>" . format_content_type_icon($content_type) . " {$type_name}</h4>";
                                            
                                            foreach ($results as $result_item) {
                                                $idx = $result_item['index'];
                                                $item = $result_item['data'];
                                                $metadata = $item['metadata'] ?? [];
                                                
                                                echo "<div class='result-item text-result {$content_type}-result enhanced'>";
                                                
                                                // Enhanced header with context info
                                                echo "<div class='result-header enhanced'>";
                                                echo "<div class='result-meta'>";
                                                echo "<span class='result-number'>#" . ($idx + 1) . "</span>";
                                                echo "<span class='result-type'>" . format_content_type_icon($content_type) . " {$type_name}</span>";
                                                
                                                if (isset($item['score'])) {
                                                    $score_class = $item['score'] > 0.8 ? 'high' : ($item['score'] > 0.6 ? 'medium' : 'low');
                                                    echo "<span class='result-score {$score_class}'>Match: " . round($item['score'] * 100, 1) . "%</span>";
                                                }
                                                echo "</div>";
                                                
                                                // Context breadcrumb
                                                echo "<div class='result-breadcrumb'>";
                                                if (isset($metadata['source_file'])) {
                                                    echo "<i class='fa fa-file'></i> " . htmlspecialchars(basename($metadata['source_file']));
                                                }
                                                if (isset($metadata['chunk_index']) && isset($metadata['total_chunks'])) {
                                                    echo " <span class='chunk-info'>Chunk " . ($metadata['chunk_index'] + 1) . " of " . $metadata['total_chunks'] . "</span>";
                                                }
                                                echo "</div>";
                                                echo "</div>";
                                                
                                                if (isset($item['text'])) {
                                                    $parsed = parse_enhanced_content($item['text']);
                                                    
                                                    echo "<div class='result-content enhanced'>";
                                                    
                                                    // Context bar with timestamp/page info
                                                    if ($parsed['timestamp'] || $parsed['page_number']) {
                                                        echo "<div class='context-bar'>";
                                                        
                                                        if ($parsed['timestamp']) {
                                                            $timestamp_class = $parsed['is_estimated'] ? 'estimated' : 'exact';
                                                            $timestamp_icon = $parsed['is_estimated'] ? 'fa-clock-o' : 'fa-play-circle';
                                                            $timestamp_text = $parsed['is_estimated'] ? "~{$parsed['timestamp']} (estimated)" : $parsed['timestamp'];
                                                            
                                                            echo "<div class='context-item timestamp {$timestamp_class}'>";
                                                            echo "<i class='fa {$timestamp_icon}'></i>";
                                                            
                                                            if (!$parsed['is_estimated']) {
                                                                $video_link = format_timestamp_link($parsed['timestamp'], $metadata['source_file'] ?? '', $courseid);
                                                                echo "<a href='{$video_link}' class='timestamp-link' title='Jump to this time in video'>";
                                                                echo $timestamp_text;
                                                                echo "</a>";
                                                            } else {
                                                                echo "<span>{$timestamp_text}</span>";
                                                            }
                                                            echo "</div>";
                                                        }
                                                        
                                                        if ($parsed['page_number']) {
                                                            echo "<div class='context-item page'>";
                                                            echo "<i class='fa fa-file-text-o'></i>";
                                                            echo "<span>Page {$parsed['page_number']}</span>";
                                                            echo "</div>";
                                                        }
                                                        
                                                        echo "</div>";
                                                    }
                                                    
                                                    // Main content with highlighting
                                                    echo "<div class='result-text'>";
                                                    $highlighted_content = highlight_query_terms($parsed['content'], $query);
                                                    echo nl2br(htmlspecialchars_decode($highlighted_content));
                                                    echo "</div>";
                                                    
                                                    echo "</div>";
                                                }
                                                
                                                // Additional metadata
                                                if (!empty($metadata)) {
                                                    echo "<div class='result-metadata enhanced'>";
                                                    echo "<details class='metadata-details'>";
                                                    echo "<summary><i class='fa fa-info-circle'></i> Additional Information</summary>";
                                                    echo "<div class='metadata-content'>";
                                                    
                                                    foreach ($metadata as $key => $value) {
                                                        if (in_array($key, ['content_type', 'source_file', 'chunk_index', 'total_chunks'])) continue;
                                                        echo "<div class='metadata-item'>";
                                                        echo "<span class='metadata-key'>" . htmlspecialchars(ucwords(str_replace('_', ' ', $key))) . ":</span>";
                                                        echo "<span class='metadata-value'>" . htmlspecialchars($value) . "</span>";
                                                        echo "</div>";
                                                    }
                                                    echo "</div>";
                                                    echo "</details>";
                                                    echo "</div>";
                                                }
                                                
                                                echo "</div>";
                                            }
                                            echo "</div>";
                                        }
                                    }
                                    
                                    echo "</div>";
                                    
                                    echo "<div class='search-summary'>";
                                    echo "<i class='fa fa-check-circle text-success'></i>";
                                    echo "<span>Search completed successfully with enhanced chunking and context preservation!</span>";
                                    echo "</div>";
                                    
                                } else {
                                    echo "<div class='no-results enhanced'>";
                                    echo "<div class='no-results-icon'><i class='fa fa-search fa-3x text-muted'></i></div>";
                                    echo "<h4>No results found</h4>";
                                    echo "<p>Try different keywords, check your spelling, or adjust the content filter</p>";
                                    echo "<div class='search-suggestions'>";
                                    echo "<h5>Search Tips:</h5>";
                                    echo "<ul>";
                                    echo "<li>Use specific terms related to your course content</li>";
                                    echo "<li>Try searching for concepts rather than exact phrases</li>";
                                    echo "<li>Remove the content filter to search all material types</li>";
                                    echo "</ul>";
                                    echo "</div>";
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
            <a href="/puplic/blocks/multimodalrag/simple_chat.php?courseid=<?php echo $courseid; ?>" class="nav-link">
                <i class="fa fa-comments"></i> Chat with Content
            </a>
            <?php echo $OUTPUT->continue_button(new moodle_url('/course/view.php', ['id' => $courseid])); ?>
        </div>
    </div>
</div>

<?php
// Helper function for highlighting search terms
function highlight_query_terms($text, $query) {
    if (empty($query)) return htmlspecialchars($text);
    
    $terms = array_filter(explode(' ', $query));
    $highlighted = htmlspecialchars($text);
    
    foreach ($terms as $term) {
        $term = trim($term);
        if (strlen($term) > 2) { // Only highlight terms longer than 2 characters
            $pattern = '/(' . preg_quote($term, '/') . ')/i';
            $highlighted = preg_replace($pattern, '<mark class="search-highlight">$1</mark>', $highlighted);
        }
    }
    
    return $highlighted;
}
?>

<style>
/* Enhanced Search Results Styling */
.search-results.enhanced {
    margin-top: 20px;
}

.results-summary {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 25px;
    border-left: 4px solid #28a745;
}

.summary-header {
    display: flex;
    align-items: center;
    margin-bottom: 15px;
}

.summary-header i {
    font-size: 18px;
    margin-right: 10px;
}

.results-count {
    font-size: 18px;
    font-weight: 600;
    color: #495057;
}

.result-breakdown {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
}

.breakdown-item {
    background: white;
    padding: 8px 12px;
    border-radius: 6px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 5px;
}

.content-type-section {
    margin-bottom: 30px;
}

.content-type-section h4 {
    color: #495057;
    border-bottom: 2px solid #dee2e6;
    padding-bottom: 10px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.result-item.enhanced {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 20px;
    overflow: hidden;
    transition: all 0.3s ease;
}

.result-item.enhanced:hover {
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
    transform: translateY(-2px);
}

.result-header.enhanced {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    padding: 15px 20px;
    border-bottom: 1px solid #dee2e6;
}

.result-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
}

.result-number {
    background: #6c757d;
    color: white;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
}

.result-type {
    display: flex;
    align-items: center;
    gap: 5px;
    font-weight: 500;
    color: #495057;
}

.result-score {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
}

.result-score.high {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.result-score.medium {
    background: #fff3cd;
    color: #856404;
    border: 1px solid #ffeaa7;
}

.result-score.low {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.result-breadcrumb {
    color: #6c757d;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.chunk-info {
    background: #e9ecef;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 11px;
}

.result-content.enhanced {
    padding: 20px;
}

.context-bar {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 12px 15px;
    margin-bottom: 15px;
    display: flex;
    gap: 15px;
    align-items: center;
    flex-wrap: wrap;
}

.context-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 14px;
    font-weight: 500;
}

.context-item.timestamp.exact {
    color: #28a745;
}

.context-item.timestamp.estimated {
    color: #ffc107;
}

.context-item.page {
    color: #dc3545;
}

.timestamp-link {
    color: inherit;
    text-decoration: none;
    padding: 4px 8px;
    border-radius: 4px;
    transition: background-color 0.2s;
}

.timestamp-link:hover {
    background-color: rgba(40, 167, 69, 0.1);
    text-decoration: none;
    color: inherit;
}

.result-text {
    font-size: 15px;
    line-height: 1.6;
    color: #495057;
    margin-bottom: 15px;
}

.search-highlight {
    background: linear-gradient(120deg, #ffeaa7 0%, #fab1a0 100%);
    color: #2d3436;
    padding: 2px 4px;
    border-radius: 3px;
    font-weight: 600;
}

.result-metadata.enhanced {
    border-top: 1px solid #dee2e6;
    padding-top: 15px;
}

.metadata-details summary {
    cursor: pointer;
    color: #6c757d;
    font-size: 13px;
    font-weight: 500;
    padding: 5px 0;
}

.metadata-details summary:hover {
    color: #495057;
}

.metadata-content {
    margin-top: 10px;
    padding: 10px 0;
}

.metadata-item {
    display: flex;
    margin-bottom: 5px;
    font-size: 12px;
}

.metadata-key {
    font-weight: 600;
    color: #6c757d;
    min-width: 120px;
}

.metadata-value {
    color: #495057;
    flex: 1;
}

/* Image results enhancements */
.image-container.enhanced {
    text-align: center;
    margin-bottom: 15px;
}

.search-result-image {
    max-width: 300px;
    max-height: 200px;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    cursor: pointer;
    transition: all 0.3s ease;
}

.search-result-image:hover {
    box-shadow: 0 4px 16px rgba(0,0,0,0.2);
    transform: scale(1.02);
}

.image-details.enhanced {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 15px;
}

.detail-item {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    margin-bottom: 10px;
    font-size: 14px;
}

.detail-item:last-child {
    margin-bottom: 0;
}

.detail-item i {
    margin-top: 2px;
    width: 16px;
}

.detail-item.source {
    font-weight: 500;
    color: #495057;
}

.detail-item.description {
    color: #6c757d;
}

.detail-item.context {
    color: #6c757d;
    font-style: italic;
}

.no-results.enhanced {
    text-align: center;
    padding: 40px 20px;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-radius: 12px;
    margin: 20px 0;
}

.no-results-icon {
    margin-bottom: 20px;
}

.search-suggestions {
    background: white;
    border-radius: 8px;
    padding: 20px;
    margin-top: 20px;
    text-align: left;
    max-width: 500px;
    margin-left: auto;
    margin-right: auto;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.search-suggestions h5 {
    color: #495057;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.search-suggestions ul {
    color: #6c757d;
    margin: 0;
    padding-left: 20px;
}

.search-suggestions li {
    margin-bottom: 5px;
}

.search-summary {
    background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
    border-radius: 8px;
    padding: 15px;
    text-align: center;
    margin-top: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    color: #155724;
    font-weight: 500;
}

/* Form enhancements */
.form-row {
    display: flex;
    gap: 15px;
}

.form-row .form-group {
    flex: 1;
}

@media (max-width: 768px) {
    .form-row {
        flex-direction: column;
        gap: 0;
    }
    
    .result-meta {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }
    
    .context-bar {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .result-breakdown {
        flex-direction: column;
        gap: 8px;
    }
}
</style>

<script>
// Enhanced JavaScript for better interactivity

// Image Modal Functions (enhanced from original)
function openImageModal(imageUrl, imageName) {
    let modal = document.getElementById('imageModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'imageModal';
        modal.className = 'image-modal';
        modal.innerHTML = `
            <span class="image-modal-close">&times;</span>
            <div class="image-modal-content">
                <img id="modalImage" src="" alt="">
                <div class="image-modal-title" id="modalTitle"></div>
            </div>
        `;
        document.body.appendChild(modal);
        
        modal.querySelector('.image-modal-close').onclick = closeImageModal;
        modal.onclick = function(event) {
            if (event.target === modal) {
                closeImageModal();
            }
        };
        
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && modal.style.display === 'block') {
                closeImageModal();
            }
        });
    }
    
    document.getElementById('modalImage').src = imageUrl;
    document.getElementById('modalTitle').textContent = imageName;
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function closeImageModal() {
    const modal = document.getElementById('imageModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
}

// Enhanced search functionality
document.addEventListener('DOMContentLoaded', function() {
    // Smooth scrolling to results
    const submitButton = document.querySelector('.btn-search');
    if (submitButton) {
        submitButton.addEventListener('click', function(e) {
            setTimeout(() => {
                const resultsSection = document.querySelector('.response-section');
                if (resultsSection) {
                    resultsSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }, 100);
        });
    }
    
    // Handle image loading errors with enhanced feedback
    const images = document.querySelectorAll('.search-result-image');
    images.forEach(function(img) {
        img.addEventListener('error', function() {
            this.style.display = 'none';
            const container = this.closest('.image-container');
            if (container) {
                container.innerHTML = `
                    <div class="image-error">
                        <i class="fa fa-exclamation-triangle fa-2x" style="color: #e74c3c;"></i>
                        <p>Image could not be loaded</p>
                        <p class="image-name">${this.alt}</p>
                    </div>
                `;
                container.classList.add('image-not-found');
            }
        });
        
        img.addEventListener('load', function() {
            this.style.opacity = '0';
            this.style.transition = 'opacity 0.3s ease';
            setTimeout(() => {
                this.style.opacity = '1';
            }, 100);
        });
    });
    
    // Enhanced result item interactions
    const resultItems = document.querySelectorAll('.result-item.enhanced');
    resultItems.forEach(function(item) {
        item.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-2px)';
        });
        
        item.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
    });
    
    // Auto-expand first metadata details if there are few results
    const resultCount = document.querySelectorAll('.result-item').length;
    if (resultCount <= 3) {
        const firstDetails = document.querySelector('.metadata-details');
        if (firstDetails) {
            firstDetails.open = true;
        }
    }
    
    // Highlight search terms animation
    const highlights = document.querySelectorAll('.search-highlight');
    highlights.forEach(function(highlight, index) {
        setTimeout(() => {
            highlight.style.animation = 'pulse 0.5s ease-in-out';
        }, index * 100);
    });
});

// CSS animation for search highlights
const style = document.createElement('style');
style.textContent = `
    @keyframes pulse {
        0% { transform: scale(1); }
        50% { transform: scale(1.05); }
        100% { transform: scale(1); }
    }
    
    .image-modal {
        display: none;
        position: fixed;
        z-index: 9999;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.9);
    }
    
    .image-modal-content {
        position: relative;
        margin: auto;
        padding: 20px;
        width: 80%;
        max-width: 800px;
        top: 50%;
        transform: translateY(-50%);
        text-align: center;
    }
    
    .image-modal-content img {
        max-width: 100%;
        max-height: 70vh;
        border-radius: 8px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
    }
    
    .image-modal-close {
        position: absolute;
        top: 15px;
        right: 35px;
        color: #f1f1f1;
        font-size: 40px;
        font-weight: bold;
        cursor: pointer;
        transition: 0.3s;
    }
    
    .image-modal-close:hover,
    .image-modal-close:focus {
        color: #bbb;
        text-decoration: none;
    }
    
    .image-modal-title {
        color: white;
        margin-top: 15px;
        font-size: 18px;
    }
`;
document.head.appendChild(style);
</script>

<?php
echo $OUTPUT->footer();
?>