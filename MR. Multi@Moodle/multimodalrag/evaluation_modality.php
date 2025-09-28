<?php
require_once('../../config.php');
require_once($CFG->libdir.'/filelib.php');

$courseid = required_param('courseid', PARAM_INT);
$submitted = optional_param('submit', false, PARAM_BOOL);
$k_value = optional_param('k_value', 10, PARAM_INT);

require_login();

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);
require_capability('block/multimodalrag:processfiles', $context);

$PAGE->set_url('/blocks/multimodalrag/evaluation_modality.php', ['courseid' => $courseid]);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_title('Modality Evaluation');
$PAGE->set_heading($course->fullname);
$PAGE->requires->css('/blocks/multimodalrag/styles.css');
$PAGE->requires->js_call_amd('block_multimodalrag/evaluation_charts', 'init');
$PAGE->requires->js(new moodle_url('https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js'));

echo $OUTPUT->header();

function simple_search_api($fastapi_url, $courseid, $query, $k_value) {
    $data = json_encode(['text' => $query, 'limit' => $k_value]);
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
    return ['http_code' => $http_code, 'response' => $response];
}

function determine_chunk_modality($chunk) {
    $text = $chunk['text'] ?? '';
    $metadata = $chunk['metadata'] ?? [];
    
    if (strpos($text, 'Image:') === 0 || 
        (isset($metadata['content_type']) && $metadata['content_type'] === 'image')) {
        return 'image';
    }
    
    if (preg_match('/^\[(\~?)(\d{1,2}:\d{2}(?::\d{2})?)\]/', $text) || 
        (isset($metadata['content_type']) && $metadata['content_type'] === 'transcript')) {
        return 'video';
    }
    
    return 'text';
}

function process_modality_evaluation($fastapi_url, $courseid, $csv_content, $k_value) {
    $lines = array_map('str_getcsv', explode("\n", $csv_content));
    $header = array_shift($lines);
    
    if (!in_array('question', $header) || !in_array('modality', $header)) {
        throw new Exception('CSV must contain "question" and "modality" columns');
    }
    
    $question_idx = array_search('question', $header);
    $modality_idx = array_search('modality', $header);
    
    $modality_metrics = [
        'text' => [
            'success_at_k' => [],
            'reciprocal_ranks' => [],
            'ranks' => [],
            'rank_scores' => [],
            'no_match_count' => 0,
            'total_questions' => 0
        ],
        'image' => [
            'success_at_k' => [],
            'reciprocal_ranks' => [],
            'ranks' => [],
            'rank_scores' => [],
            'no_match_count' => 0,
            'total_questions' => 0
        ],
        'video' => [
            'success_at_k' => [],
            'reciprocal_ranks' => [],
            'ranks' => [],
            'rank_scores' => [],
            'no_match_count' => 0,
            'total_questions' => 0
        ]
    ];
    
    foreach ($lines as $line) {
        if (empty($line) || count($line) < 2) continue;
        
        $question = $line[$question_idx];
        $expected_modality = strtolower($line[$modality_idx]);
        
        if (!array_key_exists($expected_modality, $modality_metrics)) {
            continue;
        }
        
        $modality_metrics[$expected_modality]['total_questions']++;
        
        $search_result = simple_search_api($fastapi_url, $courseid, $question, $k_value);
        
        if ($search_result['http_code'] == 200) {
            $response_data = json_decode($search_result['response'], true);
            if ($response_data && isset($response_data['signal']) && $response_data['signal'] === 'vectordb_search_success') {
                $chunks = $response_data['results'] ?? [];
                
                $best_rank = PHP_FLOAT_MAX;
                $found_match = false;
                
                foreach ($chunks as $rank => $chunk) {
                    $chunk_modality = determine_chunk_modality($chunk);
                    if ($chunk_modality === $expected_modality) {
                        $found_match = true;
                        $best_rank = $rank + 1;
                        break;
                    }
                }
                
                $success_at_k = $found_match ? 1 : 0;
                $reciprocal_rank = $found_match ? 1 / $best_rank : 0;
                $rank_score = $found_match ? ($k_value - $best_rank + 1) / $k_value : 0;
                
                $modality_metrics[$expected_modality]['success_at_k'][] = $success_at_k;
                $modality_metrics[$expected_modality]['reciprocal_ranks'][] = $reciprocal_rank;
                if ($found_match) {
                    $modality_metrics[$expected_modality]['ranks'][] = $best_rank;
                }
                $modality_metrics[$expected_modality]['rank_scores'][] = $rank_score;
                if (!$found_match) {
                    $modality_metrics[$expected_modality]['no_match_count']++;
                }
            }
        }
    }
    
    $aggregate_results = [];
    foreach ($modality_metrics as $modality => $metrics) {
        $total_questions = $metrics['total_questions'];
        if ($total_questions === 0) continue;
        
        $aggregate_results[$modality] = [
            'total_questions' => $total_questions,
            'modality_match_rate' => array_sum($metrics['success_at_k']) / $total_questions,
            'mean_reciprocal_rank' => array_sum($metrics['reciprocal_ranks']) / $total_questions,
            'average_rank' => !empty($metrics['ranks']) ? array_sum($metrics['ranks']) / count($metrics['ranks']) : null,
            'mean_rank_score' => array_sum($metrics['rank_scores']) / $total_questions,
            'no_match_count' => $metrics['no_match_count']
        ];
    }
    
    return $aggregate_results;
}

$fastapi_url = rtrim(get_config('block_multimodalrag', 'fastapi_url') ?: 'http://fastapi:8000', '/');
?>

<div class="multimodal-interface-container">
    <div class="interface-header">
        <div class="header-content">
            <div class="header-icon">
                <i class="fa fa-chart-line"></i>
            </div>
            <div class="header-text">
                <h2>Multimodality - RAG Evaluation</h2>
                <p>Advanced evaluation with comprehensive metrics and visual analytics</p>
            </div>
        </div>
    </div>

    <div class="interface-main">
        <div class="input-section">
            <div class="input-card">
                <div class="input-header">
                    <h3><i class="fa fa-upload"></i> Upload Evaluation CSV</h3>
                </div>
                <div class="input-body">
                    <form method="POST" action="" enctype="multipart/form-data" class="evaluation-form">
                        <input type="hidden" name="courseid" value="<?php echo $courseid; ?>">
                        <input type="hidden" name="submit" value="1">
                        
                        <div class="form-group">
                            <label for="csvfile" class="form-label">
                                <i class="fa fa-file-csv"></i> Evaluation CSV File
                            </label>
                            <input type="file" id="csvfile" name="csvfile" accept=".csv" class="form-control" required>
                            <small class="form-text text-muted">Upload CSV with 'question' and 'modality' columns</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="k_value" class="form-label">
                                <i class="fa fa-list-ol"></i> Retrieval @k Value
                            </label>
                            <select id="k_value" name="k_value" class="form-control">
                                <option value="5" <?php echo $k_value == 5 ? 'selected' : ''; ?>>@5</option>
                                <option value="10" <?php echo $k_value == 10 ? 'selected' : ''; ?>>@10</option>
                                <option value="15" <?php echo $k_value == 15 ? 'selected' : ''; ?>>@15</option>
                                <option value="20" <?php echo $k_value == 20 ? 'selected' : ''; ?>>@20</option>
                                <option value="25" <?php echo $k_value == 25 ? 'selected' : ''; ?>>@25</option>
                                <option value="30" <?php echo $k_value == 30 ? 'selected' : ''; ?>>@30</option>
                            </select>
                            <small class="form-text text-muted">Number of chunks to retrieve for each question</small>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fa fa-chart-pie"></i> Run Evaluation
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php if ($submitted && isset($_FILES['csvfile'])): ?>
            <div class="response-section">
                <?php
                try {
                    $csv_content = file_get_contents($_FILES['csvfile']['tmp_name']);
                    $modality_results = process_modality_evaluation($fastapi_url, $courseid, $csv_content, $k_value);
                ?>
                    <div class="response-card">
                        <div class="response-header">
                            <h3><i class="fa fa-chart-bar"></i> Modality Evaluation Results</h3>
                        </div>
                        <div class="response-body">
                            <div class="metrics-summary">
                                <?php foreach ($modality_results as $modality => $metrics): ?>
                                    <div class="modality-metrics">
                                        <h4><?php echo ucfirst($modality); ?> Modality Results</h4>
                                        <ul>
                                            <li>Total Questions: <?php echo $metrics['total_questions']; ?></li>
                                            <li>Success@<?php echo $k_value; ?>: <?php echo number_format($metrics['modality_match_rate'] * 100, 2); ?>%</li>
                                            <li>Mean Reciprocal Rank: <?php echo number_format($metrics['mean_reciprocal_rank'], 4); ?></li>
                                            <li>Average Rank: <?php echo $metrics['average_rank'] ? number_format($metrics['average_rank'], 2) : 'N/A'; ?></li>
                                            <li>Mean Rank Score: <?php echo number_format($metrics['mean_rank_score'], 4); ?></li>
                                            <li>No Matches: <?php echo $metrics['no_match_count']; ?></li>
                                        </ul>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="charts-section">
                                <h5>Success Rate Analysis</h5>
                                <div style="height: 400px; margin: 20px 0;">
                                    <canvas id="successRateChart"></canvas>
                                </div>
                            </div>

                            <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
                            <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
                            <script>
                                const modalityResults = <?php echo json_encode($modality_results); ?>;
                                window.addEventListener('load', function() {
                                    const ctx = document.getElementById('successRateChart');
                                    if (!ctx) return;

                                    const successRateData = modalityResults ? {
                                        text: (modalityResults.text?.modality_match_rate || 0) * 100,
                                        image: (modalityResults.image?.modality_match_rate || 0) * 100,
                                        video: (modalityResults.video?.modality_match_rate || 0) * 100
                                    } : { text: 0, image: 0, video: 0 };
                                    
                                    new Chart(ctx, {
                                        type: 'bar',
                                        data: {
                                            labels: ['Text', 'Image', 'Video'],
                                            datasets: [{
                                                label: 'Success Rate',
                                                data: [
                                                    successRateData.text,
                                                    successRateData.image,
                                                    successRateData.video
                                                ],
                                                backgroundColor: [
                                                    'rgba(54, 162, 235, 0.8)',
                                                    'rgba(75, 192, 192, 0.8)',
                                                    'rgba(153, 102, 255, 0.8)'
                                                ],
                                                borderColor: [
                                                    'rgba(54, 162, 235, 1)',
                                                    'rgba(75, 192, 192, 1)',
                                                    'rgba(153, 102, 255, 1)'
                                                ],
                                                borderWidth: 1
                                            }]
                                        },
                                        options: {
                                            responsive: true,
                                            maintainAspectRatio: false,
                                            scales: {
                                                y: {
                                                    beginAtZero: true,
                                                    max: 100,
                                                    ticks: {
                                                        callback: function(value) {
                                                            return value + '%';
                                                        }
                                                    }
                                                }
                                            },
                                            plugins: {
                                                legend: {
                                                    display: false
                                                },
                                                tooltip: {
                                                    callbacks: {
                                                        label: function(context) {
                                                            return context.parsed.y.toFixed(1) + '%';
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    });
                                });
                            </script>

                            <!-- Detailed Stats Table -->
                            <div class="metrics-table">
                                <h5><i class="fa fa-table"></i> Detailed Statistics</h5>
                                <table class="table table-bordered table-hover">
                                    <thead>
                                        <tr>
                                            <th>Metric</th>
                                            <th>Text</th>
                                            <th>Image</th>
                                            <th>Video</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>Total Questions</td>
                                            <td><?php echo $modality_results['text']['total_questions'] ?? 0; ?></td>
                                            <td><?php echo $modality_results['image']['total_questions'] ?? 0; ?></td>
                                            <td><?php echo $modality_results['video']['total_questions'] ?? 0; ?></td>
                                        </tr>
                                        <tr>
                                            <td>Success@<?php echo $k_value; ?></td>
                                            <td><?php echo number_format(($modality_results['text']['modality_match_rate'] ?? 0) * 100, 2); ?>%</td>
                                            <td><?php echo number_format(($modality_results['image']['modality_match_rate'] ?? 0) * 100, 2); ?>%</td>
                                            <td><?php echo number_format(($modality_results['video']['modality_match_rate'] ?? 0) * 100, 2); ?>%</td>
                                        </tr>
                                        <tr>
                                            <td>Mean Reciprocal Rank</td>
                                            <td><?php echo number_format($modality_results['text']['mean_reciprocal_rank'] ?? 0, 4); ?></td>
                                            <td><?php echo number_format($modality_results['image']['mean_reciprocal_rank'] ?? 0, 4); ?></td>
                                            <td><?php echo number_format($modality_results['video']['mean_reciprocal_rank'] ?? 0, 4); ?></td>
                                        </tr>
                                        <tr>
                                            <td>Average Rank</td>
                                            <td><?php echo $modality_results['text']['average_rank'] ? number_format($modality_results['text']['average_rank'], 2) : 'N/A'; ?></td>
                                            <td><?php echo $modality_results['image']['average_rank'] ? number_format($modality_results['image']['average_rank'], 2) : 'N/A'; ?></td>
                                            <td><?php echo $modality_results['video']['average_rank'] ? number_format($modality_results['video']['average_rank'], 2) : 'N/A'; ?></td>
                                        </tr>
                                        <tr>
                                            <td>No Matches</td>
                                            <td><?php echo $modality_results['text']['no_match_count'] ?? 0; ?></td>
                                            <td><?php echo $modality_results['image']['no_match_count'] ?? 0; ?></td>
                                            <td><?php echo $modality_results['video']['no_match_count'] ?? 0; ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            const ctx = document.getElementById('successRateChart');
                            new Chart(ctx, {
                                type: 'bar',
                                data: {
                                    labels: ['Text', 'Image', 'Video'],
                                    datasets: [{
                                        label: 'Success Rate',
                                        data: [
                                            <?php echo ($modality_results['text']['modality_match_rate'] ?? 0) * 100; ?>,
                                            <?php echo ($modality_results['image']['modality_match_rate'] ?? 0) * 100; ?>,
                                            <?php echo ($modality_results['video']['modality_match_rate'] ?? 0) * 100; ?>
                                        ],
                                        backgroundColor: [
                                            'rgba(54, 162, 235, 0.8)',
                                            'rgba(75, 192, 192, 0.8)',
                                            'rgba(153, 102, 255, 0.8)'
                                        ],
                                        borderColor: [
                                            'rgba(54, 162, 235, 1)',
                                            'rgba(75, 192, 192, 1)',
                                            'rgba(153, 102, 255, 1)'
                                        ],
                                        borderWidth: 1,
                                        maxBarThickness: 50
                                    }]
                                },
                                options: {
                                    indexAxis: 'y',
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    plugins: {
                                        legend: {
                                            display: false
                                        },
                                        tooltip: {
                                            callbacks: {
                                                label: function(context) {
                                                    return context.parsed.x.toFixed(1) + '%';
                                                }
                                            }
                                        }
                                    },
                                    scales: {
                                        x: {
                                            beginAtZero: true,
                                            max: 100,
                                            title: {
                                                display: true,
                                                text: 'Success Rate (%)'
                                            },
                                            grid: {
                                                display: true,
                                                color: 'rgba(0, 0, 0, 0.1)'
                                            }
                                        },
                                        y: {
                                            grid: {
                                                display: false
                                            }
                                        }
                                    },
                                    animation: {
                                        duration: 1000
                                    }
                                }
                            });
                        });
                    </script>
                <?php
                } catch (Exception $e) {
                    echo '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
                }
                ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
/* Core Interface Styles */
.multimodal-interface-container {
    max-width: 1200px;
    margin: 20px auto;
    padding: 20px;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

.interface-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 2rem;
    border-radius: 12px;
    margin-bottom: 2rem;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.header-content {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.header-icon {
    font-size: 2.5rem;
    opacity: 0.9;
}

.header-text h2 {
    margin: 0 0 0.5rem 0;
    font-size: 1.8rem;
    font-weight: 600;
}

.header-text p {
    margin: 0;
    opacity: 0.9;
    font-size: 1rem;
}

/* Input Section */
.input-section {
    margin-bottom: 2rem;
}

.input-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    overflow: hidden;
}

.input-header {
    background: #f8f9fa;
    padding: 1.5rem;
    border-bottom: 1px solid #e9ecef;
}

.input-header h3 {
    margin: 0;
    color: #495057;
    font-size: 1.2rem;
    font-weight: 600;
}

.input-body {
    padding: 2rem;
}

.evaluation-form {
    margin-bottom: 0;
}

.form-group {
    margin-bottom: 1.5rem;
}

.form-label {
    display: block;
    font-weight: 600;
    color: #495057;
    margin-bottom: 0.5rem;
    font-size: 0.95rem;
}

.form-control {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid #ced4da;
    border-radius: 6px;
    font-size: 0.9rem;
    transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
}

.form-control:focus {
    border-color: #80bdff;
    outline: 0;
    box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25);
}

.form-text {
    font-size: 0.8rem;
    color: #6c757d;
    margin-top: 0.25rem;
}

.btn {
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: 6px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s ease-in-out;
    text-decoration: none;
    display: inline-block;
}

.btn-primary {
    background: linear-gradient(135deg, #007bff, #0056b3);
    color: white;
}

.btn-primary:hover {
    background: linear-gradient(135deg, #0056b3, #004085);
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0,123,255,0.3);
}

/* Response Section */
.response-section {
    margin-top: 2rem;
}

.response-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    overflow: hidden;
}

.response-header {
    background: #f8f9fa;
    padding: 1.5rem;
    border-bottom: 1px solid #e9ecef;
}

.response-header h3 {
    margin: 0;
    color: #495057;
    font-size: 1.2rem;
    font-weight: 600;
}

.response-body {
    padding: 2rem;
}

/* Metrics Summary */
.metrics-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.modality-metrics {
    background: #f8f9fa;
    padding: 1.5rem;
    border-radius: 8px;
    border-left: 4px solid #007bff;
}

.modality-metrics h4 {
    margin: 0 0 1rem 0;
    color: #495057;
    font-size: 1.1rem;
    font-weight: 600;
}

.modality-metrics ul {
    margin: 0;
    padding: 0;
    list-style: none;
}

.modality-metrics li {
    padding: 0.25rem 0;
    color: #6c757d;
    font-size: 0.9rem;
}

/* Charts Section */
.charts-section {
    margin-top: 2rem;
    padding: 2rem;
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.chart-container {
    position: relative;
    height: 300px;
    margin-bottom: 2rem;
    padding: 1rem;
    background: white;
    border-radius: 8px;
}

.chart-container h5 {
    margin: 0 0 1.5rem 0;
    color: #2c3e50;
    font-size: 1.2rem;
    font-weight: 700;
    text-align: center;
    letter-spacing: 0.5px;
}

.chart-wrapper {
    position: relative;
    height: calc(100% - 120px);
    min-height: 400px;
    margin-bottom: 1.5rem;
}

.chart-legend {
    display: flex;
    justify-content: center;
    gap: 2rem;
    padding: 1rem;
    background: #f8f9fa;
    border-radius: 8px;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 600;
    color: #2c3e50;
}

.legend-item .color-box {
    width: 16px;
    height: 16px;
    border-radius: 4px;
}

/* Table Styles */
.metrics-table {
    margin: 2rem 0;
    padding: 1.5rem;
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.metrics-table h5 {
    margin: 0 0 1rem 0;
    color: #495057;
    font-size: 1rem;
    font-weight: 600;
}

.table {
    width: 100%;
    margin: 0;
    border-collapse: collapse;
}

.table th,
.table td {
    padding: 0.75rem;
    text-align: left;
    border-top: 1px solid #dee2e6;
    font-size: 0.9rem;
}

.table thead th {
    background: #f8f9fa;
    border-top: 0;
    font-weight: 600;
    color: #495057;
}

.table-bordered {
    border: 1px solid #dee2e6;
}

.table-bordered th,
.table-bordered td {
    border: 1px solid #dee2e6;
}

.table-hover tbody tr:hover {
    background-color: rgba(0,0,0,.075);
}

/* Alert Styles */
.alert {
    padding: 1rem;
    margin: 1rem 0;
    border-radius: 6px;
    border-left: 4px solid;
}

.alert-danger {
    background-color: #f8d7da;
    color: #721c24;
    border-left-color: #dc3545;
}

/* Responsive Design */
@media (max-width: 768px) {
    .multimodal-interface-container {
        padding: 1rem;
    }
    
    .header-content {
        flex-direction: column;
        text-align: center;
        gap: 0.5rem;
    }
    
    .header-icon {
        font-size: 2rem;
    }
    
    .metrics-summary {
        grid-template-columns: 1fr;
    }
    
    .chart-container.enhanced-chart {
        height: 500px;
        padding: 1.5rem;
    }
    
    .chart-wrapper {
        min-height: 350px;
    }
    
    .input-body,
    .response-body {
        padding: 1.5rem;
    }
    
    .btn {
        width: 100%;
        text-align: center;
    }
}

@media (max-width: 480px) {
    .chart-container.enhanced-chart {
        height: 400px;
        padding: 1rem;
    }
    
    .chart-wrapper {
        min-height: 300px;
    }
    
    .table {
        font-size: 0.8rem;
    }
    
    .table th,
    .table td {
        padding: 0.5rem;
    }
}
</style>

<?php echo $OUTPUT->footer(); ?>