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

function verify_image_exists($image_name) {
    global $CFG;
    
    // Check multiple possible locations for the image
    $possible_paths = [
        $CFG->dataroot . '/temp/extracted_images/' . $image_name,
        $CFG->dataroot . '/temp/images/' . $image_name,
        $CFG->dataroot . '/blocks/multimodalrag/images/' . $image_name
    ];
    
    foreach ($possible_paths as $path) {
        if (file_exists($path)) {
            // Return the web-accessible path
            $web_path = str_replace($CFG->dataroot, '', $path);
            return $web_path;
        }
    }
    
    return null;
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
                                                
                                                // Display actual image - Updated URL construction
                                                if ($image_name) {
                                                    // Verify the image exists and get the correct path
                                                    $image_web_path = verify_image_exists($image_name);
                                                    
                                                    if ($image_web_path) {
                                                        $image_url = new moodle_url('/blocks/multimodalrag/serve_image.php', [
                                                            'path' => $image_web_path,
                                                            'courseid' => $courseid
                                                        ]);
                                                        
                                                        echo "<div class='image-container'>";
                                                        echo "<img src='" . $image_url->out() . "' ";
                                                        echo "alt='" . htmlspecialchars($image_name) . "' ";
                                                        echo "class='search-result-image' ";
                                                        echo "style='max-width: 300px; max-height: 200px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); cursor: pointer;' ";
                                                        echo "onclick='openImageModal(\"" . $image_url->out() . "\", \"" . htmlspecialchars($image_name) . "\")'>";
                                                        echo "<p class='image-name'>" . htmlspecialchars($image_name) . "</p>";
                                                        echo "</div>";
                                                    } else {
                                                        // Image not found, show placeholder
                                                        echo "<div class='image-container image-not-found'>";
                                                        echo "<div class='image-placeholder'>";
                                                        echo "<i class='fa fa-image fa-3x' style='color: #ccc;'></i>";
                                                        echo "<p>Image not available</p>";
                                                        echo "<p class='image-name'>" . htmlspecialchars($image_name) . "</p>";
                                                        echo "</div>";
                                                        echo "</div>";
                                                    }
                                                }
                                                
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

<style>
/* Modal styles for image viewing */
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

/* Placeholder for missing images */
.image-not-found .image-placeholder {
    border: 2px dashed #ddd;
    padding: 40px 20px;
    text-align: center;
    border-radius: 8px;
    background: #f9f9f9;
}

.image-not-found .image-placeholder p {
    margin: 10px 0 5px 0;
    color: #666;
}

.image-not-found .image-placeholder .image-name {
    font-weight: bold;
    color: #333;
}
</style>

<script>
// Image Modal JavaScript Functions
function openImageModal(imageUrl, imageName) {
    // Create modal if it doesn't exist
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
        
        // Add event listeners
        modal.querySelector('.image-modal-close').onclick = closeImageModal;
        modal.onclick = function(event) {
            if (event.target === modal) {
                closeImageModal();
            }
        };
        
        // Close on Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && modal.style.display === 'block') {
                closeImageModal();
            }
        });
    }
    
    // Set image and title
    document.getElementById('modalImage').src = imageUrl;
    document.getElementById('modalTitle').textContent = imageName;
    
    // Show modal
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden'; // Prevent background scrolling
}

function closeImageModal() {
    const modal = document.getElementById('imageModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = ''; // Restore scrolling
    }
}

// Add error handling for images
document.addEventListener('DOMContentLoaded', function() {
    // Handle image loading errors
    const images = document.querySelectorAll('.search-result-image');
    images.forEach(function(img) {
        img.addEventListener('error', function() {
            this.style.display = 'none';
            const container = this.closest('.image-container');
            if (container) {
                container.innerHTML = `
                    <div class="image-error">
                        <i class="fa fa-exclamation-triangle fa-2x" style="color: #e74c3c;"></i>
                        <p>Image not found</p>
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
});
</script>

<?php
echo $OUTPUT->footer();
?>