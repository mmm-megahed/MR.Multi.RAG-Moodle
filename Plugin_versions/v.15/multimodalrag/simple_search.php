<?php
// simple_search.php - Enhanced with image results
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

function get_image_files_for_descriptions($courseid, $results) {
    global $DB, $CFG;
    
    if (!$results || !is_array($results)) {
        return [];
    }
    
    $image_references = [];
    
    // Extract image filenames from search results
    foreach ($results as $result) {
        if (isset($result['text']) && strpos($result['text'], 'Image:') !== false) {
            // Parse the description text to extract image filename
            $lines = explode("\n", $result['text']);
            foreach ($lines as $line) {
                if (strpos($line, 'Image:') === 0) {
                    $image_name = trim(str_replace('Image:', '', $line));
                    if ($image_name) {
                        $image_references[] = $image_name;
                    }
                    break;
                }
            }
        }
    }
    
    if (empty($image_references)) {
        return [];
    }
    
    // Find corresponding image files in moodledata
    $moodledata_path = $CFG->dataroot;
    $found_images = [];
    
    foreach ($image_references as $image_name) {
        // Search for the image file in the moodledata structure
        $image_path = find_image_file($moodledata_path, $image_name);
        if ($image_path) {
            $found_images[] = [
                'name' => $image_name,
                'path' => $image_path,
                'web_path' => get_image_web_path($image_path, $moodledata_path)
            ];
        }
    }
    
    return $found_images;
}

function find_image_file($moodledata_path, $image_name) {
    $filedir = $moodledata_path . '/temp/images';
    
    // Create a simple approach - in a real implementation, 
    // you'd want to store image paths in database when processing
    $search_dirs = [
        $moodledata_path . '/temp/images',
        $moodledata_path . '/temp/extracted_images'
    ];
    
    foreach ($search_dirs as $dir) {
        if (is_dir($dir)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getFilename() === $image_name) {
                    return $file->getPathname();
                }
            }
        }
    }
    
    return null;
}

function get_image_web_path($image_path, $moodledata_path) {
    // Convert filesystem path to web-accessible path
    // This is a simplified approach - in production, you'd serve images through a proper endpoint
    $relative_path = str_replace($moodledata_path, '', $image_path);
    return '/blocks/multimodalrag/serve_image.php?path=' . urlencode($relative_path);
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
                <p>Search through your course materials including text content and images</p>
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
                                       placeholder="What would you like to find? (text or images)"
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
                                    
                                    // Separate text and image results
                                    $text_results = [];
                                    $image_results = [];
                                    
                                    foreach ($response_data['results'] as $idx => $item) {
                                        if (isset($item['text']) && strpos($item['text'], 'Image:') !== false) {
                                            $image_results[] = ['index' => $idx, 'data' => $item];
                                        } else {
                                            $text_results[] = ['index' => $idx, 'data' => $item];
                                        }
                                    }
                                    
                                    // Display text results first
                                    if (!empty($text_results)) {
                                        echo "<div class='text-results-section'>";
                                        echo "<h4><i class='fa fa-file-text'></i> Text Content</h4>";
                                        foreach ($text_results as $result_item) {
                                            $idx = $result_item['index'];
                                            $item = $result_item['data'];
                                            echo "<div class='result-item text-result'>";
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
                                    }
                                    
                                    // Display image results
                                    if (!empty($image_results)) {
                                        echo "<div class='image-results-section'>";
                                        echo "<h4><i class='fa fa-image'></i> Related Images</h4>";
                                        foreach ($image_results as $result_item) {
                                            $idx = $result_item['index'];
                                            $item = $result_item['data'];
                                            echo "<div class='result-item image-result'>";
                                            echo "<div class='result-header'>";
                                            echo "<span class='result-number'>#" . ($idx + 1) . "</span>";
                                            echo "<span class='result-type'><i class='fa fa-image'></i> Image</span>";
                                            if (isset($item['score'])) {
                                                echo "<span class='result-score'>Score: " . round($item['score'], 3) . "</span>";
                                            }
                                            echo "</div>";
                                            
                                            if (isset($item['text'])) {
                                                // Parse image information
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
                                                
                                                echo "<div class='result-content image-content'>";
                                                
                                                // Show image placeholder (in real implementation, serve actual image)
                                                echo "<div class='image-placeholder'>";
                                                echo "<i class='fa fa-image fa-3x'></i>";
                                                echo "<p class='image-name'>" . htmlspecialchars($image_name) . "</p>";
                                                echo "</div>";
                                                
                                                echo "<div class='image-details'>";
                                                if ($source_pdf) {
                                                    echo "<p><strong>Source:</strong> " . htmlspecialchars($source_pdf) . "</p>";
                                                }
                                                if ($page_num) {
                                                    echo "<p><strong>Page:</strong> " . htmlspecialchars($page_num) . "</p>";
                                                }
                                                if ($description) {
                                                    echo "<p><strong>Description:</strong> " . htmlspecialchars($description) . "</p>";
                                                }
                                                if ($context) {
                                                    echo "<p><strong>Context:</strong> " . htmlspecialchars(substr($context, 0, 200)) . "...</p>";
                                                }
                                                echo "</div>";
                                                echo "</div>";
                                            }
                                            echo "</div>";
                                        }
                                        echo "</div>";
                                    }
                                    
                                    echo "</div>";
                                    
                                    echo "<div class='success-indicator'>";
                                    echo "<i class='fa fa-check-circle'></i> Search completed successfully!";
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