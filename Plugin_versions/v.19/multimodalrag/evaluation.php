<?php
require_once('../../config.php');
require_once($CFG->libdir.'/filelib.php');

$courseid = required_param('courseid', PARAM_INT);
$submitted = optional_param('submit', false, PARAM_BOOL);
$k_value = optional_param('k_value', 5, PARAM_INT);

require_login();

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);
require_capability('block/multimodalrag:processfiles', $context);

$PAGE->set_url('/public/blocks/multimodalrag/evaluation.php', ['courseid' => $courseid]);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_title('RAG Evaluation');
$PAGE->set_heading($course->fullname);
$PAGE->requires->css('/blocks/multimodalrag/styles.css');
$PAGE->requires->js_call_amd('block_multimodalrag/evaluation_charts', 'init');

echo $OUTPUT->header();

function process_csv_evaluation($fastapi_url, $courseid, $csv_content, $k_value) {
    $lines = array_map('str_getcsv', explode("\n", $csv_content));
    $header = array_shift($lines);
    
    if (!in_array('question', $header) || !in_array('ground_truth', $header)) {
        throw new Exception('CSV must contain "question" and "ground_truth" columns');
    }
    
    $question_idx = array_search('question', $header);
    $ground_truth_idx = array_search('ground_truth', $header);
    
    $results = [];
    $results[] = ['question', 'ground_truth', 'generated_answer', 'retrieved_chunks'];
    
    foreach ($lines as $line) {
        if (empty($line) || count($line) < 2) continue;
        
        $question = $line[$question_idx];
        $ground_truth = $line[$ground_truth_idx];
        
        $chat_result = simple_chat_api($fastapi_url, $courseid, $question, $k_value);
        $search_result = simple_search_api($fastapi_url, $courseid, $question, $k_value);
        
        $retrieved_chunks = '';
        $generated_answer = '';
        
        if ($chat_result['http_code'] == 200) {
            $response_data = json_decode($chat_result['response'], true);
            if ($response_data && isset($response_data['signal']) && $response_data['signal'] === 'rag_answer_success') {
                $generated_answer = $response_data['answer'];
            }
        }
        
        if ($search_result['http_code'] == 200) {
            $search_response = json_decode($search_result['response'], true);
            if ($search_response && isset($search_response['signal']) && $search_response['signal'] === 'vectordb_search_success') {
                $chunks_text = [];
                if (isset($search_response['results']) && is_array($search_response['results'])) {
                    foreach ($search_response['results'] as $idx => $chunk) {
                        $chunks_text[] = [
                            'chunk_id' => $idx + 1,
                            'text' => $chunk['text'] ?? '',
                            'score' => $chunk['score'] ?? 0,
                            'metadata' => $chunk['metadata'] ?? []
                        ];
                    }
                }
                $retrieved_chunks = json_encode($chunks_text);
            }
        }
        
        $results[] = [$question, $ground_truth, $generated_answer, $retrieved_chunks];
    }
    
    return $results;
}

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

function simple_chat_api($fastapi_url, $courseid, $query, $limit) {
    $data = json_encode(['text' => $query, 'limit' => $limit]);
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
    return ['http_code' => $http_code, 'response' => $response];
}

function calculate_enhanced_ragas_metrics($results) {
    $metrics = [
        'total_questions' => count($results) - 1,
        'successful_responses' => 0,
        'avg_answer_length' => 0,
        'response_rate' => 0,
        'context_precision' => 0,
        'context_recall' => 0,
        'answer_correctness' => 0,
        'faithfulness' => 0,
        'answer_relevance' => 0,
        'semantic_similarity' => 0,
        'factual_accuracy' => 0,
        'completeness' => 0,
        'bleu_1' => 0,
        'bleu_4' => 0,
        'rouge_l' => 0,
        'rouge_1' => 0,
        'rouge_2' => 0,
        'meteor' => 0,
        'bertscore_precision' => 0,
        'bertscore_recall' => 0,
        'bertscore_f1' => 0
    ];
    
    if (count($results) < 2) return $metrics;
    
    $total_length = 0;
    $successful = 0;
    $precision_scores = [];
    $recall_scores = [];
    $correctness_scores = [];
    $faithfulness_scores = [];
    $relevance_scores = [];
    $semantic_scores = [];
    $factual_scores = [];
    $completeness_scores = [];
    $bleu_1_scores = [];
    $bleu_4_scores = [];
    $rouge_l_scores = [];
    $rouge_1_scores = [];
    $rouge_2_scores = [];
    $meteor_scores = [];
    $bert_precision_scores = [];
    $bert_recall_scores = [];
    $bert_f1_scores = [];
    
    for ($i = 1; $i < count($results); $i++) {
        $row = $results[$i];
        $question = $row[0] ?? '';
        $ground_truth = $row[1] ?? '';
        $generated_answer = $row[2] ?? '';
        $retrieved_chunks = $row[3] ?? '';
        
        if (!empty($generated_answer) && strlen(trim($generated_answer)) > 0) {
            $successful++;
            $total_length += strlen($generated_answer);
            
            // Enhanced Context Precision - considers relevance weighting
            $chunks_data = json_decode($retrieved_chunks, true);
            $precision_score = calculate_enhanced_context_precision($chunks_data, $ground_truth, $question);
            $precision_scores[] = $precision_score;
            
            // Enhanced Context Recall - semantic coverage
            $recall_score = calculate_enhanced_context_recall($chunks_data, $ground_truth, $question);
            $recall_scores[] = $recall_score;
            
            // Enhanced Answer Correctness - multi-faceted evaluation
            $correctness_score = calculate_enhanced_answer_correctness($generated_answer, $ground_truth);
            $correctness_scores[] = $correctness_score;
            
            // Enhanced Faithfulness - fine-grained grounding analysis
            $faithfulness_score = calculate_enhanced_faithfulness($generated_answer, $chunks_data);
            $faithfulness_scores[] = $faithfulness_score;
            
            // Enhanced Answer Relevance - contextual understanding
            $relevance_score = calculate_enhanced_answer_relevance($generated_answer, $question);
            $relevance_scores[] = $relevance_score;
            
            // New enhanced metrics
            $semantic_scores[] = calculate_semantic_similarity($generated_answer, $ground_truth);
            $factual_scores[] = calculate_factual_accuracy($generated_answer, $ground_truth);
            $completeness_scores[] = calculate_completeness($generated_answer, $ground_truth);
            
            // Enhanced NLP metrics
            $bleu_1_scores[] = calculate_bleu_score($generated_answer, $ground_truth, 1);
            $bleu_4_scores[] = calculate_bleu_score($generated_answer, $ground_truth, 4);
            $rouge_scores = calculate_rouge_scores($generated_answer, $ground_truth);
            $rouge_l_scores[] = $rouge_scores['rouge_l'];
            $rouge_1_scores[] = $rouge_scores['rouge_1'];
            $rouge_2_scores[] = $rouge_scores['rouge_2'];
            $meteor_scores[] = calculate_meteor_score($generated_answer, $ground_truth);
            
            $bert_scores = calculate_enhanced_bert_score($generated_answer, $ground_truth);
            $bert_precision_scores[] = $bert_scores['precision'];
            $bert_recall_scores[] = $bert_scores['recall'];
            $bert_f1_scores[] = $bert_scores['f1'];
        }
    }
    
    $metrics['successful_responses'] = $successful;
    $metrics['response_rate'] = ($successful / $metrics['total_questions']) * 100;
    $metrics['avg_answer_length'] = $successful > 0 ? $total_length / $successful : 0;
    
    $metrics['context_precision'] = !empty($precision_scores) ? array_sum($precision_scores) / count($precision_scores) : 0;
    $metrics['context_recall'] = !empty($recall_scores) ? array_sum($recall_scores) / count($recall_scores) : 0;
    $metrics['answer_correctness'] = !empty($correctness_scores) ? array_sum($correctness_scores) / count($correctness_scores) : 0;
    $metrics['faithfulness'] = !empty($faithfulness_scores) ? array_sum($faithfulness_scores) / count($faithfulness_scores) : 0;
    $metrics['answer_relevance'] = !empty($relevance_scores) ? array_sum($relevance_scores) / count($relevance_scores) : 0;
    $metrics['semantic_similarity'] = !empty($semantic_scores) ? array_sum($semantic_scores) / count($semantic_scores) : 0;
    $metrics['factual_accuracy'] = !empty($factual_scores) ? array_sum($factual_scores) / count($factual_scores) : 0;
    $metrics['completeness'] = !empty($completeness_scores) ? array_sum($completeness_scores) / count($completeness_scores) : 0;
    
    $metrics['bleu_1'] = !empty($bleu_1_scores) ? array_sum($bleu_1_scores) / count($bleu_1_scores) : 0;
    $metrics['bleu_4'] = !empty($bleu_4_scores) ? array_sum($bleu_4_scores) / count($bleu_4_scores) : 0;
    $metrics['rouge_l'] = !empty($rouge_l_scores) ? array_sum($rouge_l_scores) / count($rouge_l_scores) : 0;
    $metrics['rouge_1'] = !empty($rouge_1_scores) ? array_sum($rouge_1_scores) / count($rouge_1_scores) : 0;
    $metrics['rouge_2'] = !empty($rouge_2_scores) ? array_sum($rouge_2_scores) / count($rouge_2_scores) : 0;
    $metrics['meteor'] = !empty($meteor_scores) ? array_sum($meteor_scores) / count($meteor_scores) : 0;
    $metrics['bertscore_precision'] = !empty($bert_precision_scores) ? array_sum($bert_precision_scores) / count($bert_precision_scores) : 0;
    $metrics['bertscore_recall'] = !empty($bert_recall_scores) ? array_sum($bert_recall_scores) / count($bert_recall_scores) : 0;
    $metrics['bertscore_f1'] = !empty($bert_f1_scores) ? array_sum($bert_f1_scores) / count($bert_f1_scores) : 0;
    
    return $metrics;
}

function calculate_enhanced_context_precision($chunks_data, $ground_truth, $question) {
    if (!is_array($chunks_data) || empty($chunks_data)) return 0;
    
    $relevant_chunks = 0;
    $total_chunks = count($chunks_data);
    
    foreach ($chunks_data as $chunk) {
        $chunk_text = $chunk['text'] ?? '';
        $chunk_score = $chunk['score'] ?? 0;
        
        // Multi-criteria relevance assessment
        $semantic_relevance = calculate_semantic_similarity($chunk_text, $ground_truth);
        $question_relevance = calculate_semantic_similarity($chunk_text, $question);
        $keyword_overlap = calculate_keyword_overlap($chunk_text, $ground_truth);
        
        // Weighted relevance score
        $relevance_score = (0.4 * $semantic_relevance) + (0.1 * $question_relevance) + (0.1 * $keyword_overlap) + (0.4 * $chunk_score); #to focus more on chunk score
        
        if ($relevance_score > 0.35) {
            $relevant_chunks++;
        }
    }
    
    return $total_chunks > 0 ? $relevant_chunks / $total_chunks : 0;
}

function calculate_enhanced_context_recall($chunks_data, $ground_truth, $question) {
    if (!is_array($chunks_data) || empty($ground_truth)) return 0;
    
    $ground_truth_entities = extract_entities($ground_truth);
    $ground_truth_concepts = extract_key_concepts($ground_truth);
    
    $covered_entities = 0;
    $covered_concepts = 0;
    
    $all_chunks_text = '';
    foreach ($chunks_data as $chunk) {
        $all_chunks_text .= ' ' . ($chunk['text'] ?? '');
    }
    
    // Entity coverage
    foreach ($ground_truth_entities as $entity) {
        if (stripos($all_chunks_text, $entity) !== false) {
            $covered_entities++;
        }
    }
    
    // Concept coverage
    foreach ($ground_truth_concepts as $concept) {
        if (calculate_semantic_similarity($all_chunks_text, $concept) > 0.3) {
            $covered_concepts++;
        }
    }
    
    $entity_recall = count($ground_truth_entities) > 0 ? $covered_entities / count($ground_truth_entities) : 0;
    $concept_recall = count($ground_truth_concepts) > 0 ? $covered_concepts / count($ground_truth_concepts) : 0;
    
    return ($entity_recall + $concept_recall) / 2;
}

function calculate_enhanced_answer_correctness($generated_answer, $ground_truth) {
    if (empty($generated_answer) || empty($ground_truth)) return 0;
    
    // Multiple correctness dimensions
    $semantic_similarity = calculate_semantic_similarity($generated_answer, $ground_truth);
    $factual_alignment = calculate_factual_alignment($generated_answer, $ground_truth);
    $information_completeness = calculate_information_completeness($generated_answer, $ground_truth);
    $structural_similarity = calculate_structural_similarity($generated_answer, $ground_truth);
    
    // Weighted combination
    return (0.35 * $semantic_similarity) + (0.25 * $factual_alignment) + 
           (0.25 * $information_completeness) + (0.15 * $structural_similarity);
}

function calculate_enhanced_faithfulness($generated_answer, $chunks_data) {
    if (empty($generated_answer) || !is_array($chunks_data)) return 0;
    
    $context_text = '';
    foreach ($chunks_data as $chunk) {
        $context_text .= ' ' . ($chunk['text'] ?? '');
    }
    
    if (empty($context_text)) return 0;
    
    // Extract claims from generated answer
    $answer_claims = extract_claims($generated_answer);
    $supported_claims = 0;
    
    foreach ($answer_claims as $claim) {
        $support_score = calculate_claim_support($claim, $context_text);
        if ($support_score > 0.05) {
            $supported_claims++;
        }
    }
    
    return count($answer_claims) > 0 ? $supported_claims / count($answer_claims) : 0;
}

function calculate_enhanced_answer_relevance($generated_answer, $question) {
    if (empty($generated_answer) || empty($question)) return 0;
    
    $question_intent = analyze_question_intent($question);
    $answer_focus = analyze_answer_focus($generated_answer);
    
    $intent_alignment = calculate_intent_alignment($question_intent, $answer_focus);
    $keyword_relevance = calculate_keyword_overlap($generated_answer, $question);
    $semantic_relevance = calculate_semantic_similarity($generated_answer, $question);
    
    return (0.4 * $intent_alignment) + (0.3 * $semantic_relevance) + (0.3 * $keyword_relevance);
}

// Helper functions for enhanced metrics
function calculate_semantic_similarity($text1, $text2) {
    if (empty($text1) || empty($text2)) return 0;
    
    $words1 = array_filter(explode(' ', strtolower(preg_replace('/[^\w\s]/', '', $text1))));
    $words2 = array_filter(explode(' ', strtolower(preg_replace('/[^\w\s]/', '', $text2))));
    
    if (empty($words1) || empty($words2)) return 0;
    
    // Jaccard similarity with word frequency weighting
    $freq1 = array_count_values($words1);
    $freq2 = array_count_values($words2);
    
    $intersection = array_intersect_key($freq1, $freq2);
    $intersection_sum = 0;
    foreach ($intersection as $word => $count) {
        $intersection_sum += min($freq1[$word], $freq2[$word]);
    }
    
    $union_sum = array_sum($freq1) + array_sum($freq2) - $intersection_sum;
    
    return $union_sum > 0 ? $intersection_sum / $union_sum : 0;
}

function calculate_factual_accuracy($generated_answer, $ground_truth) {
    $answer_facts = extract_facts($generated_answer);
    $ground_facts = extract_facts($ground_truth);
    
    $correct_facts = 0;
    foreach ($answer_facts as $fact) {
        foreach ($ground_facts as $ground_fact) {
            if (calculate_semantic_similarity($fact, $ground_fact) > 0.7) {
                $correct_facts++;
                break;
            }
        }
    }
    
    return count($answer_facts) > 0 ? $correct_facts / count($answer_facts) : 0;
}

function calculate_completeness($generated_answer, $ground_truth) {
    $ground_key_points = extract_key_points($ground_truth);
    $covered_points = 0;
    
    foreach ($ground_key_points as $point) {
        if (calculate_semantic_similarity($generated_answer, $point) > 0.4) {
            $covered_points++;
        }
    }
    
    return count($ground_key_points) > 0 ? $covered_points / count($ground_key_points) : 0;
}

function calculate_bleu_score($candidate, $reference, $n = 1) {
    $candidate_words = explode(' ', strtolower($candidate));
    $reference_words = explode(' ', strtolower($reference));
    
    if (count($candidate_words) < $n || count($reference_words) < $n) return 0;
    
    $candidate_ngrams = [];
    $reference_ngrams = [];
    
    for ($i = 0; $i <= count($candidate_words) - $n; $i++) {
        $ngram = implode(' ', array_slice($candidate_words, $i, $n));
        $candidate_ngrams[] = $ngram;
    }
    
    for ($i = 0; $i <= count($reference_words) - $n; $i++) {
        $ngram = implode(' ', array_slice($reference_words, $i, $n));
        $reference_ngrams[] = $ngram;
    }
    
    $matches = 0;
    foreach ($candidate_ngrams as $ngram) {
        if (in_array($ngram, $reference_ngrams)) {
            $matches++;
        }
    }
    
    return count($candidate_ngrams) > 0 ? $matches / count($candidate_ngrams) : 0;
}

function calculate_rouge_scores($candidate, $reference) {
    $candidate_words = explode(' ', strtolower($candidate));
    $reference_words = explode(' ', strtolower($reference));
    
    // ROUGE-1
    $rouge_1 = calculate_rouge_n($candidate_words, $reference_words, 1);
    
    // ROUGE-2
    $rouge_2 = calculate_rouge_n($candidate_words, $reference_words, 2);
    
    // ROUGE-L
    $lcs_length = longest_common_subsequence($candidate_words, $reference_words);
    $rouge_l_precision = count($candidate_words) > 0 ? $lcs_length / count($candidate_words) : 0;
    $rouge_l_recall = count($reference_words) > 0 ? $lcs_length / count($reference_words) : 0;
    $rouge_l = ($rouge_l_precision + $rouge_l_recall) > 0 ? 2 * $rouge_l_precision * $rouge_l_recall / ($rouge_l_precision + $rouge_l_recall) : 0;
    
    return [
        'rouge_1' => $rouge_1,
        'rouge_2' => $rouge_2,
        'rouge_l' => $rouge_l
    ];
}

function calculate_rouge_n($candidate_words, $reference_words, $n) {
    if (count($candidate_words) < $n || count($reference_words) < $n) return 0;
    
    $candidate_ngrams = [];
    $reference_ngrams = [];
    
    for ($i = 0; $i <= count($candidate_words) - $n; $i++) {
        $ngram = implode(' ', array_slice($candidate_words, $i, $n));
        $candidate_ngrams[] = $ngram;
    }
    
    for ($i = 0; $i <= count($reference_words) - $n; $i++) {
        $ngram = implode(' ', array_slice($reference_words, $i, $n));
        $reference_ngrams[] = $ngram;
    }
    
    $matches = count(array_intersect($candidate_ngrams, $reference_ngrams));
    return count($reference_ngrams) > 0 ? $matches / count($reference_ngrams) : 0;
}

function calculate_meteor_score($candidate, $reference) {
    $candidate_words = explode(' ', strtolower($candidate));
    $reference_words = explode(' ', strtolower($reference));
    
    $matches = count(array_intersect($candidate_words, $reference_words));
    $precision = count($candidate_words) > 0 ? $matches / count($candidate_words) : 0;
    $recall = count($reference_words) > 0 ? $matches / count($reference_words) : 0;
    
    $f_mean = ($precision + $recall) > 0 ? 2 * $precision * $recall / ($precision + $recall) : 0;
    
    // Simplified penalty (without chunk analysis)
    $penalty = 0.5;
    
    return $f_mean * (1 - $penalty);
}

function calculate_enhanced_bert_score($candidate, $reference) {
    $candidate_words = explode(' ', strtolower($candidate));
    $reference_words = explode(' ', strtolower($reference));
    
    $matches = count(array_intersect($candidate_words, $reference_words));
    $precision = count($candidate_words) > 0 ? $matches / count($candidate_words) : 0;
    $recall = count($reference_words) > 0 ? $matches / count($reference_words) : 0;
    $f1 = ($precision + $recall) > 0 ? 2 * $precision * $recall / ($precision + $recall) : 0;
    
    return [
        'precision' => $precision,
        'recall' => $recall,
        'f1' => $f1
    ];
}

// Additional helper functions
function extract_entities($text) {
    // Simplified entity extraction - dates, numbers, proper nouns
    $entities = [];
    
    // Extract dates
    preg_match_all('/\b\d{1,2}[\s\-\/]\w+[\s\-\/]\d{4}\b|\b\d{4}\b/', $text, $dates);
    $entities = array_merge($entities, $dates[0]);
    
    // Extract numbers
    preg_match_all('/\b\d+(?:\.\d+)?\b/', $text, $numbers);
    $entities = array_merge($entities, $numbers[0]);
    
    // Extract capitalized words (potential proper nouns)
    preg_match_all('/\b[A-Z][a-z]+\b/', $text, $proper_nouns);
    $entities = array_merge($entities, $proper_nouns[0]);
    
    return array_unique($entities);
}

function extract_key_concepts($text) {
    $stopwords = ['the', 'is', 'are', 'was', 'were', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by'];
    $words = array_filter(explode(' ', strtolower(preg_replace('/[^\w\s]/', '', $text))));
    $concepts = array_filter($words, function($word) use ($stopwords) {
        return strlen($word) > 4 && !in_array($word, $stopwords);
    });
    
    return array_unique($concepts);
}

function extract_claims($text) {
    // Simple sentence-based claim extraction
    $sentences = preg_split('/[.!?]+/', $text);
    return array_filter(array_map('trim', $sentences));
}

function extract_facts($text) {
    // Extract factual statements (simplified)
    $facts = [];
    $sentences = preg_split('/[.!?]+/', $text);
    
    foreach ($sentences as $sentence) {
        $sentence = trim($sentence);
        if (preg_match('/\b\d{4}\b|\b\d+\b|occurred|happened|was|is|are/', $sentence)) {
            $facts[] = $sentence;
        }
    }
    
    return $facts;
}

function extract_key_points($text) {
    // Extract key informational points
    $sentences = preg_split('/[.!?]+/', $text);
    return array_filter(array_map('trim', $sentences), function($s) {
        return strlen($s) > 10;
    });
}

function calculate_keyword_overlap($text1, $text2) {
    $words1 = extract_keywords($text1);
    $words2 = extract_keywords($text2);
    
    if (empty($words1) || empty($words2)) return 0;
    
    $overlap = array_intersect($words1, $words2);
    return count($overlap) / max(count($words1), count($words2));
}

function calculate_factual_alignment($generated_answer, $ground_truth) {
    return calculate_semantic_similarity($generated_answer, $ground_truth);
}

function calculate_information_completeness($generated_answer, $ground_truth) {
    return calculate_completeness($generated_answer, $ground_truth);
}

function calculate_structural_similarity($generated_answer, $ground_truth) {
    $answer_structure = analyze_text_structure($generated_answer);
    $ground_structure = analyze_text_structure($ground_truth);
    
    return calculate_semantic_similarity(implode(' ', $answer_structure), implode(' ', $ground_structure));
}

function analyze_text_structure($text) {
    $sentences = preg_split('/[.!?]+/', $text);
    return array_map(function($s) {
        return substr(trim($s), 0, 20);
    }, $sentences);
}

function calculate_claim_support($claim, $context) {
    return calculate_semantic_similarity($claim, $context);
}

function analyze_question_intent($question) {
    $question_words = ['what', 'when', 'where', 'who', 'why', 'how'];
    foreach ($question_words as $word) {
        if (stripos($question, $word) !== false) {
            return $word;
        }
    }
    return 'general';
}

function analyze_answer_focus($answer) {
    return substr($answer, 0, 50);
}

function calculate_intent_alignment($question_intent, $answer_focus) {
    return calculate_semantic_similarity($question_intent, $answer_focus);
}

function extract_keywords($text) {
    $stopwords = ['the', 'is', 'are', 'was', 'were', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'what', 'how', 'when', 'where', 'why', 'who'];
    $words = array_filter(explode(' ', strtolower(preg_replace('/[^\w\s]/', '', $text))));
    return array_filter($words, function($word) use ($stopwords) {
        return strlen($word) > 2 && !in_array($word, $stopwords);
    });
}

function longest_common_subsequence($arr1, $arr2) {
    $m = count($arr1);
    $n = count($arr2);
    $lcs = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));
    
    for ($i = 1; $i <= $m; $i++) {
        for ($j = 1; $j <= $n; $j++) {
            if ($arr1[$i-1] === $arr2[$j-1]) {
                $lcs[$i][$j] = $lcs[$i-1][$j-1] + 1;
            } else {
                $lcs[$i][$j] = max($lcs[$i-1][$j], $lcs[$i][$j-1]);
            }
        }
    }
    
    return $lcs[$m][$n];
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
                <h2>RAG Evaluation</h2>
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
                                <i class="fa fa-file-csv"></i> CSV File (question, ground_truth columns)
                            </label>
                            <input type="file" id="csvfile" name="csvfile" accept=".csv" class="form-control" required>
                            <small class="form-text text-muted">Upload CSV with 'question' and 'ground_truth' columns</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="k_value" class="form-label">
                                <i class="fa fa-list-ol"></i> Retrieval @k (Number of chunks to retrieve)
                            </label>
                            <select id="k_value" name="k_value" class="form-control">
                                <option value="3" <?php echo $k_value == 3 ? 'selected' : ''; ?>>@3 - Top 3 chunks</option>
                                <option value="5" <?php echo $k_value == 5 ? 'selected' : ''; ?>>@5 - Top 5 chunks</option>
                                <option value="10" <?php echo $k_value == 10 ? 'selected' : ''; ?>>@10 - Top 10 chunks</option>
                                <option value="15" <?php echo $k_value == 15 ? 'selected' : ''; ?>>@15 - Top 15 chunks</option>
                                <option value="20" <?php echo $k_value == 20 ? 'selected' : ''; ?>>@20 - Top 20 chunks</option>
                            </select>
                            <small class="form-text text-muted">Controls how many chunks the RAG system retrieves for each question</small>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fa fa-play"></i> Run Evaluation
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
                $results = process_csv_evaluation($fastapi_url, $courseid, $csv_content, $k_value);
                $metrics = calculate_enhanced_ragas_metrics($results);
            ?>
                <div class="response-card">
                    <div class="response-header">
                        <h3><i class="fa fa-chart-bar"></i> Evaluation Results</h3>
                    </div>
                    <div class="response-body">
                        <div class="metrics-summary">
                            <h4><i class="fa fa-tachometer-alt"></i> Comprehensive Performance Metrics</h4>
                            
                            <!-- Basic Performance -->
                            <div class="metrics-section">
                                <h5>Basic Performance (Retrieval @<?php echo $k_value; ?>)</h5>
                                <div class="metrics-grid">
                                    <div class="metric-item">
                                        <span class="metric-label">Total Questions:</span>
                                        <span class="metric-value"><?php echo $metrics['total_questions']; ?></span>
                                    </div>
                                    <div class="metric-item">
                                        <span class="metric-label">Successful Responses:</span>
                                        <span class="metric-value"><?php echo $metrics['successful_responses']; ?></span>
                                    </div>
                                    <div class="metric-item">
                                        <span class="metric-label">Response Rate:</span>
                                        <span class="metric-value <?php echo $metrics['response_rate'] > 90 ? 'excellent' : ($metrics['response_rate'] > 80 ? 'good' : ($metrics['response_rate'] > 60 ? 'medium' : 'poor')); ?>"><?php echo round($metrics['response_rate'], 2); ?>%</span>
                                    </div>
                                    <div class="metric-item">
                                        <span class="metric-label">Avg Answer Length:</span>
                                        <span class="metric-value"><?php echo round($metrics['avg_answer_length']); ?> chars</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Enhanced Retrieval Evaluation -->
                            <div class="metrics-section">
                                <h5>Retrieval Evaluation</h5>
                                <div class="metrics-grid">
                                    <div class="metric-item">
                                        <span class="metric-label">Context Precision:</span>
                                        <span class="metric-value <?php echo $metrics['context_precision'] > 0.8 ? 'excellent' : ($metrics['context_precision'] > 0.7 ? 'good' : ($metrics['context_precision'] > 0.5 ? 'medium' : 'poor')); ?>"><?php echo round($metrics['context_precision'], 3); ?></span>
                                        <small class="metric-desc">Relevance of retrieved chunks</small>
                                    </div>
                                    <div class="metric-item">
                                        <span class="metric-label">Context Recall:</span>
                                        <span class="metric-value <?php echo $metrics['context_recall'] > 0.8 ? 'excellent' : ($metrics['context_recall'] > 0.7 ? 'good' : ($metrics['context_recall'] > 0.5 ? 'medium' : 'poor')); ?>"><?php echo round($metrics['context_recall'], 3); ?></span>
                                        <small class="metric-desc">Coverage of ground truth information</small>
                                    </div>
                                </div>
                            </div>

                            <!-- Enhanced Generation Evaluation -->
                            <div class="metrics-section">
                                <h5>Generation Evaluation</h5>
                                <div class="metrics-grid">
                                    <div class="metric-item">
                                        <span class="metric-label">Answer Correctness:</span>
                                        <span class="metric-value <?php echo $metrics['answer_correctness'] > 0.8 ? 'excellent' : ($metrics['answer_correctness'] > 0.7 ? 'good' : ($metrics['answer_correctness'] > 0.5 ? 'medium' : 'poor')); ?>"><?php echo round($metrics['answer_correctness'], 3); ?></span>
                                        <small class="metric-desc">Multi-dimensional correctness assessment</small>
                                    </div>
                                    <div class="metric-item">
                                        <span class="metric-label">Faithfulness:</span>
                                        <span class="metric-value <?php echo $metrics['faithfulness'] > 0.8 ? 'excellent' : ($metrics['faithfulness'] > 0.7 ? 'good' : ($metrics['faithfulness'] > 0.5 ? 'medium' : 'poor')); ?>"><?php echo round($metrics['faithfulness'], 3); ?></span>
                                        <small class="metric-desc">Answer grounding in context</small>
                                    </div>
                                    <div class="metric-item">
                                        <span class="metric-label">Answer Relevance:</span>
                                        <span class="metric-value <?php echo $metrics['answer_relevance'] > 0.8 ? 'excellent' : ($metrics['answer_relevance'] > 0.7 ? 'good' : ($metrics['answer_relevance'] > 0.5 ? 'medium' : 'poor')); ?>"><?php echo round($metrics['answer_relevance'], 3); ?></span>
                                        <small class="metric-desc">Question-answer alignment</small>
                                    </div>
                                    <div class="metric-item">
                                        <span class="metric-label">Semantic Similarity:</span>
                                        <span class="metric-value <?php echo $metrics['semantic_similarity'] > 0.7 ? 'excellent' : ($metrics['semantic_similarity'] > 0.6 ? 'good' : ($metrics['semantic_similarity'] > 0.4 ? 'medium' : 'poor')); ?>"><?php echo round($metrics['semantic_similarity'], 3); ?></span>
                                        <small class="metric-desc">Meaning preservation</small>
                                    </div>
                                    <div class="metric-item">
                                        <span class="metric-label">Factual Accuracy:</span>
                                        <span class="metric-value <?php echo $metrics['factual_accuracy'] > 0.8 ? 'excellent' : ($metrics['factual_accuracy'] > 0.7 ? 'good' : ($metrics['factual_accuracy'] > 0.5 ? 'medium' : 'poor')); ?>"><?php echo round($metrics['factual_accuracy'], 3); ?></span>
                                        <small class="metric-desc">Factual information correctness</small>
                                    </div>
                                    <div class="metric-item">
                                        <span class="metric-label">Completeness:</span>
                                        <span class="metric-value <?php echo $metrics['completeness'] > 0.8 ? 'excellent' : ($metrics['completeness'] > 0.7 ? 'good' : ($metrics['completeness'] > 0.5 ? 'medium' : 'poor')); ?>"><?php echo round($metrics['completeness'], 3); ?></span>
                                        <small class="metric-desc">Information coverage</small>
                                    </div>
                                </div>
                            </div>

                            <!-- Advanced NLP Metrics -->
                            <div class="metrics-section">
                                <h5>Advanced NLP Evaluation Metrics</h5>
                                <div class="metrics-grid">
                                    <div class="metric-item">
                                        <span class="metric-label">BLEU-1:</span>
                                        <span class="metric-value <?php echo $metrics['bleu_1'] > 0.7 ? 'excellent' : ($metrics['bleu_1'] > 0.6 ? 'good' : ($metrics['bleu_1'] > 0.4 ? 'medium' : 'poor')); ?>"><?php echo round($metrics['bleu_1'], 3); ?></span>
                                        <small class="metric-desc">Unigram precision</small>
                                    </div>
                                    <div class="metric-item">
                                        <span class="metric-label">BLEU-4:</span>
                                        <span class="metric-value <?php echo $metrics['bleu_4'] > 0.5 ? 'excellent' : ($metrics['bleu_4'] > 0.4 ? 'good' : ($metrics['bleu_4'] > 0.25 ? 'medium' : 'poor')); ?>"><?php echo round($metrics['bleu_4'], 3); ?></span>
                                        <small class="metric-desc">4-gram precision</small>
                                    </div>
                                    <div class="metric-item">
                                        <span class="metric-label">ROUGE-1:</span>
                                        <span class="metric-value <?php echo $metrics['rouge_1'] > 0.7 ? 'excellent' : ($metrics['rouge_1'] > 0.6 ? 'good' : ($metrics['rouge_1'] > 0.4 ? 'medium' : 'poor')); ?>"><?php echo round($metrics['rouge_1'], 3); ?></span>
                                        <small class="metric-desc">Unigram recall</small>
                                    </div>
                                    <div class="metric-item">
                                        <span class="metric-label">ROUGE-2:</span>
                                        <span class="metric-value <?php echo $metrics['rouge_2'] > 0.5 ? 'excellent' : ($metrics['rouge_2'] > 0.4 ? 'good' : ($metrics['rouge_2'] > 0.25 ? 'medium' : 'poor')); ?>"><?php echo round($metrics['rouge_2'], 3); ?></span>
                                        <small class="metric-desc">Bigram recall</small>
                                    </div>
                                    <div class="metric-item">
                                        <span class="metric-label">ROUGE-L:</span>
                                        <span class="metric-value <?php echo $metrics['rouge_l'] > 0.7 ? 'excellent' : ($metrics['rouge_l'] > 0.6 ? 'good' : ($metrics['rouge_l'] > 0.4 ? 'medium' : 'poor')); ?>"><?php echo round($metrics['rouge_l'], 3); ?></span>
                                        <small class="metric-desc">Longest common subsequence</small>
                                    </div>
                                    <div class="metric-item">
                                        <span class="metric-label">METEOR:</span>
                                        <span class="metric-value <?php echo $metrics['meteor'] > 0.6 ? 'excellent' : ($metrics['meteor'] > 0.5 ? 'good' : ($metrics['meteor'] > 0.35 ? 'medium' : 'poor')); ?>"><?php echo round($metrics['meteor'], 3); ?></span>
                                        <small class="metric-desc">Harmonic mean with penalty</small>
                                    </div>
                                </div>
                            </div>

                            <!-- BERTScore Metrics -->
                            <div class="metrics-section">
                                <h5>BERTScore Evaluation</h5>
                                <div class="metrics-grid">
                                    <div class="metric-item">
                                        <span class="metric-label">BERT Precision:</span>
                                        <span class="metric-value <?php echo $metrics['bertscore_precision'] > 0.8 ? 'excellent' : ($metrics['bertscore_precision'] > 0.7 ? 'good' : ($metrics['bertscore_precision'] > 0.6 ? 'medium' : 'poor')); ?>"><?php echo round($metrics['bertscore_precision'], 3); ?></span>
                                        <small class="metric-desc">Contextual token precision</small>
                                    </div>
                                    <div class="metric-item">
                                        <span class="metric-label">BERT Recall:</span>
                                        <span class="metric-value <?php echo $metrics['bertscore_recall'] > 0.8 ? 'excellent' : ($metrics['bertscore_recall'] > 0.7 ? 'good' : ($metrics['bertscore_recall'] > 0.6 ? 'medium' : 'poor')); ?>"><?php echo round($metrics['bertscore_recall'], 3); ?></span>
                                        <small class="metric-desc">Contextual token recall</small>
                                    </div>
                                    <div class="metric-item">
                                        <span class="metric-label">BERT F1:</span>
                                        <span class="metric-value <?php echo $metrics['bertscore_f1'] > 0.8 ? 'excellent' : ($metrics['bertscore_f1'] > 0.7 ? 'good' : ($metrics['bertscore_f1'] > 0.6 ? 'medium' : 'poor')); ?>"><?php echo round($metrics['bertscore_f1'], 3); ?></span>
                                        <small class="metric-desc">Contextual F1 score</small>
                                    </div>
                                </div>
                            </div>

                            <!-- Enhanced Visual Analysis -->
                            <div class="charts-section">
                                <h5><i class="fa fa-chart-pie"></i> Visual Analysis</h5>
                                <div class="charts-container">
                                    <div class="chart-item">
                                        <h6>Core RAG Metrics Radar</h6>
                                        <canvas id="coreRadar" width="350" height="350"></canvas>
                                    </div>
                                    <div class="chart-item">
                                        <h6>NLP Metrics Comparison</h6>
                                        <canvas id="nlpMetrics" width="400" height="350"></canvas>
                                    </div>
                                    <div class="chart-item">
                                        <h6>Performance Heatmap</h6>
                                        <canvas id="performanceHeatmap" width="400" height="300"></canvas>
                                    </div>
                                    <div class="chart-item">
                                        <h6>Metric Distribution</h6>
                                        <canvas id="metricDistribution" width="400" height="300"></canvas>
                                    </div>
                                </div>
                            </div>

                            <!-- Performance Analysis -->
                            <div class="analysis-section">
                                <h5><i class="fa fa-brain"></i> AI-Powered Performance Analysis</h5>
                                <div class="analysis-grid">
                                    <?php
                                    // Enhanced analysis with scoring
                                    $overall_score = ($metrics['context_precision'] + $metrics['context_recall'] + 
                                                    $metrics['answer_correctness'] + $metrics['faithfulness'] + 
                                                    $metrics['answer_relevance'] + $metrics['semantic_similarity']) / 6;
                                    
                                    $retrieval_score = ($metrics['context_precision'] + $metrics['context_recall']) / 2;
                                    $generation_score = ($metrics['answer_correctness'] + $metrics['faithfulness'] + $metrics['answer_relevance']) / 3;
                                    $nlp_score = ($metrics['bleu_1'] + $metrics['rouge_1'] + $metrics['bertscore_f1']) / 3;
                                    ?>
                                    
                                    <div class="analysis-card">
                                        <h6><i class="fa fa-trophy"></i> Overall Performance</h6>
                                        <div class="score-display">
                                            <span class="score-value <?php echo $overall_score > 0.7 ? 'excellent' : ($overall_score > 0.5 ? 'good' : ($overall_score > 0.3 ? 'medium' : 'poor')); ?>"><?php echo round($overall_score * 100, 1); ?>%</span>
                                            <span class="score-label">Overall RAG Quality</span>
                                        </div>
                                        <div class="analysis-insights">
                                            <?php if ($overall_score > 0.7): ?>
                                                <p><i class="fa fa-check-circle text-success"></i> Excellent RAG performance across all dimensions</p>
                                            <?php elseif ($overall_score > 0.6): ?>
                                                <p><i class="fa fa-thumbs-up text-info"></i> Good RAG performance with minor optimization opportunities</p>
                                            <?php elseif ($overall_score > 0.3): ?>
                                                <p><i class="fa fa-exclamation-triangle text-warning"></i> Moderate performance - significant improvement potential</p>
                                            <?php else: ?>
                                                <p><i class="fa fa-times-circle text-danger"></i> Poor performance - requires comprehensive optimization</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="analysis-card">
                                        <h6><i class="fa fa-search"></i> Retrieval Analysis</h6>
                                        <div class="score-display">
                                            <span class="score-value <?php echo $retrieval_score > 0.8 ? 'excellent' : ($retrieval_score > 0.7 ? 'good' : ($retrieval_score > 0.5 ? 'medium' : 'poor')); ?>"><?php echo round($retrieval_score * 100, 1); ?>%</span>
                                            <span class="score-label">Retrieval Quality</span>
                                        </div>
                                        <div class="analysis-insights">
                                            <?php if ($metrics['context_precision'] > $metrics['context_recall']): ?>
                                                <p><i class="fa fa-info-circle"></i> High precision, lower recall - consider increasing k-value</p>
                                            <?php elseif ($metrics['context_recall'] > $metrics['context_precision']): ?>
                                                <p><i class="fa fa-info-circle"></i> High recall, lower precision - refine retrieval scoring</p>
                                            <?php else: ?>
                                                <p><i class="fa fa-balance-scale"></i> Balanced precision-recall performance</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="analysis-card">
                                        <h6><i class="fa fa-robot"></i> Generation Analysis</h6>
                                        <div class="score-display">
                                            <span class="score-value <?php echo $generation_score > 0.8 ? 'excellent' : ($generation_score > 0.7 ? 'good' : ($generation_score > 0.5 ? 'medium' : 'poor')); ?>"><?php echo round($generation_score * 100, 1); ?>%</span>
                                            <span class="score-label">Generation Quality</span>
                                        </div>
                                        <div class="analysis-insights">
                                            <?php if ($metrics['faithfulness'] < 0.6): ?>
                                                <p><i class="fa fa-exclamation-triangle text-warning"></i> Low faithfulness detected - potential hallucinations</p>
                                            <?php endif; ?>
                                            <?php if ($metrics['answer_relevance'] > 0.8): ?>
                                                <p><i class="fa fa-check-circle text-success"></i> Excellent question-answer alignment</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="analysis-card">
                                        <h6><i class="fa fa-language"></i> NLP Metrics Analysis</h6>
                                        <div class="score-display">
                                            <span class="score-value <?php echo $nlp_score > 0.7 ? 'excellent' : ($nlp_score > 0.6 ? 'good' : ($nlp_score > 0.4 ? 'medium' : 'poor')); ?>"><?php echo round($nlp_score * 100, 1); ?>%</span>
                                            <span class="score-label">Linguistic Quality</span>
                                        </div>
                                        <div class="analysis-insights">
                                            <?php if ($metrics['bertscore_f1'] > $metrics['bleu_1']): ?>
                                                <p><i class="fa fa-info-circle"></i> Strong semantic similarity despite lexical differences</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Recommendations -->
                                <div class="recommendations-section">
                                    <h6><i class="fa fa-lightbulb"></i> Optimization Recommendations</h6>
                                    <div class="recommendations-list">
                                        <?php
                                        $recommendations = [];
                                        
                                        if ($metrics['context_precision'] < 0.7) {
                                            $recommendations[] = "Improve retrieval scoring to increase context precision";
                                        }
                                        if ($metrics['context_recall'] < 0.7) {
                                            $recommendations[] = "Consider increasing k-value or improving embedding quality for better recall";
                                        }
                                        if ($metrics['faithfulness'] < 0.7) {
                                            $recommendations[] = "Enhance prompt engineering to reduce hallucinations";
                                        }
                                        if ($metrics['answer_correctness'] < 0.7) {
                                            $recommendations[] = "Review training data quality and model fine-tuning";
                                        }
                                        if ($metrics['response_rate'] < 90) {
                                            $recommendations[] = "Investigate system reliability and error handling";
                                        }
                                        if (empty($recommendations)) {
                                            $recommendations[] = "System performing well - consider A/B testing for further optimization";
                                        }
                                        
                                        foreach ($recommendations as $rec):
                                        ?>
                                            <div class="recommendation-item">
                                                <i class="fa fa-arrow-right"></i>
                                                <span><?php echo $rec; ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Detailed Results Table -->
                        <div class="results-table">
                            <h4><i class="fa fa-table"></i> Detailed Evaluation Results</h4>
                            <div class="table-responsive">
                                <table class="table table-striped evaluation-table">
                                    <thead>
                                        <tr>
                                            <th>Question</th>
                                            <th>Ground Truth</th>
                                            <th>Generated Answer</th>
                                            <th>Retrieved Chunks</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php for ($i = 1; $i < count($results); $i++): ?>
                                            <tr>
                                                <td class="question-cell">
                                                    <div class="cell-content" title="<?php echo htmlspecialchars($results[$i][0]); ?>">
                                                        <?php echo htmlspecialchars(substr($results[$i][0], 0, 150)) . (strlen($results[$i][0]) > 150 ? '...' : ''); ?>
                                                    </div>
                                                </td>
                                                <td class="ground-truth-cell">
                                                    <div class="cell-content" title="<?php echo htmlspecialchars($results[$i][1]); ?>">
                                                        <?php echo htmlspecialchars(substr($results[$i][1], 0, 150)) . (strlen($results[$i][1]) > 150 ? '...' : ''); ?>
                                                    </div>
                                                </td>
                                                <td class="answer-cell">
                                                    <div class="cell-content" title="<?php echo htmlspecialchars($results[$i][2]); ?>">
                                                        <?php echo htmlspecialchars(substr($results[$i][2], 0, 150)) . (strlen($results[$i][2]) > 150 ? '...' : ''); ?>
                                                    </div>
                                                </td>
                                                <td class="chunks-cell">
                                                    <div class="cell-content">
                                                        <?php 
                                                        $chunks = json_decode($results[$i][3], true);
                                                        if (is_array($chunks)) {
                                                            echo count($chunks) . ' chunks retrieved';
                                                        } else {
                                                            echo 'No chunks';
                                                        }
                                                        ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endfor; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Export Section -->
                        <div class="download-section">
                            <h4><i class="fa fa-download"></i> Export Evaluation Results</h4>
                            <div class="export-options">
                                <button onclick="downloadCSV()" class="btn btn-secondary">
                                    <i class="fa fa-file-csv"></i> Download CSV
                                </button>
                                <button onclick="downloadPDF()" class="btn btn-primary">
                                    <i class="fa fa-file-pdf"></i> Download PDF Report
                                </button>
                                <button onclick="downloadJSON()" class="btn btn-info">
                                    <i class="fa fa-file-code"></i> Download JSON
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
                <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
                <script>
                const metricsData = <?php echo json_encode($metrics); ?>;
                const resultsData = <?php echo json_encode($results); ?>;

                // Core RAG Metrics Radar Chart
                const coreRadarCtx = document.getElementById('coreRadar').getContext('2d');
                new Chart(coreRadarCtx, {
                    type: 'radar',
                    data: {
                        labels: [
                            'Context Precision',
                            'Context Recall', 
                            'Answer Correctness',
                            'Faithfulness',
                            'Answer Relevance',
                            'Semantic Similarity'
                        ],
                        datasets: [{
                            label: 'RAG Performance',
                            data: [
                                metricsData.context_precision * 100,
                                metricsData.context_recall * 100,
                                metricsData.answer_correctness * 100,
                                metricsData.faithfulness * 100,
                                metricsData.answer_relevance * 100,
                                metricsData.semantic_similarity * 100
                            ],
                            backgroundColor: 'rgba(54, 162, 235, 0.2)',
                            borderColor: 'rgba(54, 162, 235, 1)',
                            borderWidth: 2,
                            pointBackgroundColor: 'rgba(54, 162, 235, 1)'
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            r: {
                                beginAtZero: true,
                                max: 100,
                                ticks: {
                                    stepSize: 20
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: false
                            }
                        }
                    }
                });

                // NLP Metrics Bar Chart
                const nlpMetricsCtx = document.getElementById('nlpMetrics').getContext('2d');
                new Chart(nlpMetricsCtx, {
                    type: 'bar',
                    data: {
                        labels: ['BLEU-1', 'BLEU-4', 'ROUGE-1', 'ROUGE-2', 'ROUGE-L', 'METEOR', 'BERT F1'],
                        datasets: [{
                            label: 'NLP Metrics',
                            data: [
                                metricsData.bleu_1 * 100,
                                metricsData.bleu_4 * 100,
                                metricsData.rouge_1 * 100,
                                metricsData.rouge_2 * 100,
                                metricsData.rouge_l * 100,
                                metricsData.meteor * 100,
                                metricsData.bertscore_f1 * 100
                            ],
                            backgroundColor: [
                                '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0',
                                '#9966FF', '#FF9F40', '#FF6384'
                            ],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
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
                            }
                        }
                    }
                });

                // Performance Heatmap (simplified with bars)
                const heatmapCtx = document.getElementById('performanceHeatmap').getContext('2d');
                new Chart(heatmapCtx, {
                    type: 'bar',
                    data: {
                        labels: [
                            'Response Rate',
                            'Retrieval Quality',
                            'Generation Quality', 
                            'Linguistic Quality'
                        ],
                        datasets: [{
                            label: 'Performance Score',
                            data: [
                                metricsData.response_rate,
                                ((metricsData.context_precision + metricsData.context_recall) / 2) * 100,
                                ((metricsData.answer_correctness + metricsData.faithfulness + metricsData.answer_relevance) / 3) * 100,
                                ((metricsData.bleu_1 + metricsData.rouge_1 + metricsData.bertscore_f1) / 3) * 100
                            ],
                            backgroundColor: function(context) {
                                const value = context.parsed.y;
                                if (value >= 80) return '#28a745';
                                if (value >= 70) return '#17a2b8';
                                if (value >= 60) return '#ffc107';
                                return '#dc3545';
                            },
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        indexAxis: 'y',
                        scales: {
                            x: {
                                beginAtZero: true,
                                max: 100
                            }
                        },
                        plugins: {
                            legend: {
                                display: false
                            }
                        }
                    }
                });

                // Metric Distribution Doughnut Chart
                const distributionCtx = document.getElementById('metricDistribution').getContext('2d');
                const avgScore = ((metricsData.context_precision + metricsData.context_recall + 
                                 metricsData.answer_correctness + metricsData.faithfulness + 
                                 metricsData.answer_relevance + metricsData.semantic_similarity) / 6) * 100;
                
                new Chart(distributionCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Excellent (>80%)', 'Good (50-70%)', 'Medium (20-50%)', 'Poor (<20%)'],
                        datasets: [{
                            data: [
                                avgScore > 70 ? 1 : 0,
                                (avgScore >= 50 && avgScore <= 70) ? 1 : 0,
                                (avgScore >= 20 && avgScore < 50) ? 1 : 0,
                                avgScore < 20 ? 1 : 0
                            ],
                            backgroundColor: ['#28a745', '#17a2b8', '#ffc107', '#dc3545'],
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                position: 'bottom'
                            },
                            title: {
                                display: true,
                                text: `Overall Score: ${avgScore.toFixed(1)}%`
                            }
                        }
                    }
                });

                // Export functions
                function downloadCSV() {
                    let csvContent = '';
                    resultsData.forEach(row => {
                        const escapedRow = row.map(cell => `"${cell.toString().replace(/"/g, '""')}"`);
                        csvContent += escapedRow.join(',') + '\n';
                    });
                    
                    const blob = new Blob([csvContent], { type: 'text/csv' });
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'enhanced_evaluation_results.csv';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    window.URL.revokeObjectURL(url);
                }

                function downloadJSON() {
                    const jsonData = {
                        metadata: {
                            timestamp: new Date().toISOString(),
                            k_value: <?php echo $k_value; ?>,
                            total_questions: metricsData.total_questions
                        },
                        metrics: metricsData,
                        results: resultsData
                    };
                    
                    const blob = new Blob([JSON.stringify(jsonData, null, 2)], { type: 'application/json' });
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'enhanced_evaluation_results.json';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    window.URL.revokeObjectURL(url);
                }

                function downloadPDF() {
                    const { jsPDF } = window.jspdf;
                    const doc = new jsPDF();
                    
                    // Title
                    doc.setFontSize(20);
                    doc.text('Enhanced RAG Evaluation Report', 20, 20);
                    
                    // Timestamp
                    doc.setFontSize(10);
                    doc.text(`Generated: ${new Date().toLocaleString()}`, 20, 30);
                    doc.text(`Course ID: <?php echo $courseid; ?>`, 20, 35);
                    doc.text(`Retrieval @k: <?php echo $k_value; ?>`, 20, 40);
                    
                    // Basic Performance
                    doc.setFontSize(16);
                    doc.text('Basic Performance', 20, 55);
                    doc.setFontSize(10);
                    doc.text(`Total Questions: ${metricsData.total_questions}`, 25, 65);
                    doc.text(`Successful Responses: ${metricsData.successful_responses}`, 25, 70);
                    doc.text(`Response Rate: ${metricsData.response_rate.toFixed(2)}%`, 25, 75);
                    doc.text(`Average Answer Length: ${metricsData.avg_answer_length.toFixed(0)} chars`, 25, 80);
                    
                    // Core RAG Metrics
                    doc.setFontSize(16);
                    doc.text('Core RAG Metrics', 20, 95);
                    doc.setFontSize(10);
                    doc.text(`Context Precision: ${(metricsData.context_precision * 100).toFixed(1)}%`, 25, 105);
                    doc.text(`Context Recall: ${(metricsData.context_recall * 100).toFixed(1)}%`, 25, 110);
                    doc.text(`Answer Correctness: ${(metricsData.answer_correctness * 100).toFixed(1)}%`, 25, 115);
                    doc.text(`Faithfulness: ${(metricsData.faithfulness * 100).toFixed(1)}%`, 25, 120);
                    doc.text(`Answer Relevance: ${(metricsData.answer_relevance * 100).toFixed(1)}%`, 25, 125);
                    doc.text(`Semantic Similarity: ${(metricsData.semantic_similarity * 100).toFixed(1)}%`, 25, 130);
                    
                    // Enhanced Metrics
                    doc.setFontSize(16);
                    doc.text('Enhanced Quality Metrics', 20, 145);
                    doc.setFontSize(10);
                    doc.text(`Factual Accuracy: ${(metricsData.factual_accuracy * 100).toFixed(1)}%`, 25, 155);
                    doc.text(`Completeness: ${(metricsData.completeness * 100).toFixed(1)}%`, 25, 160);
                    
                    // NLP Metrics
                    doc.setFontSize(16);
                    doc.text('NLP Evaluation Metrics', 20, 175);
                    doc.setFontSize(10);
                    doc.text(`BLEU-1: ${(metricsData.bleu_1 * 100).toFixed(1)}%`, 25, 185);
                    doc.text(`BLEU-4: ${(metricsData.bleu_4 * 100).toFixed(1)}%`, 25, 190);
                    doc.text(`ROUGE-1: ${(metricsData.rouge_1 * 100).toFixed(1)}%`, 25, 195);
                    doc.text(`ROUGE-2: ${(metricsData.rouge_2 * 100).toFixed(1)}%`, 25, 200);
                    doc.text(`ROUGE-L: ${(metricsData.rouge_l * 100).toFixed(1)}%`, 25, 205);
                    doc.text(`METEOR: ${(metricsData.meteor * 100).toFixed(1)}%`, 25, 210);
                    
                    // BERTScore
                    doc.text(`BERT Precision: ${(metricsData.bertscore_precision * 100).toFixed(1)}%`, 25, 220);
                    doc.text(`BERT Recall: ${(metricsData.bertscore_recall * 100).toFixed(1)}%`, 25, 225);
                    doc.text(`BERT F1: ${(metricsData.bertscore_f1 * 100).toFixed(1)}%`, 25, 230);
                    
                    // Overall Score
                    const overallScore = ((metricsData.context_precision + metricsData.context_recall + 
                                         metricsData.answer_correctness + metricsData.faithfulness + 
                                         metricsData.answer_relevance + metricsData.semantic_similarity) / 6) * 100;
                    
                    doc.setFontSize(16);
                    doc.text('Overall Assessment', 20, 250);
                    doc.setFontSize(12);
                    doc.text(`Overall RAG Quality Score: ${overallScore.toFixed(1)}%`, 25, 260);
                    
                    // Performance Grade
                    let grade = 'Poor';
                    if (overallScore > 80) grade = 'Excellent';
                    else if (overallScore > 70) grade = 'Good';
                    else if (overallScore > 50) grade = 'Medium';
                    
                    doc.text(`Performance Grade: ${grade}`, 25, 270);
                    
                    // Save the PDF
                    doc.save('enhanced_rag_evaluation_report.pdf');
                }
                </script>

            <?php
            } catch (Exception $e) {
                echo "<div class='error-message'>";
                echo "<i class='fa fa-exclamation-triangle'></i>";
                echo "<strong>Error:</strong> " . htmlspecialchars($e->getMessage());
                echo "</div>";
            }
            ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="interface-footer">
        <div class="navigation-links">
            <a href="/public/blocks/multimodalrag/simple_chat.php?courseid=<?php echo $courseid; ?>" class="nav-link">
                <i class="fa fa-comments"></i> Back to Chat
            </a>
            <?php echo $OUTPUT->continue_button(new moodle_url('/course/view.php', ['id' => $courseid])); ?>
        </div>
    </div>
</div>

<style>
.evaluation-form { margin-bottom: 2rem; }
.metrics-section { margin-bottom: 2rem; padding: 1.5rem; background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); border-radius: 12px; border-left: 4px solid #007bff; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
.metrics-section h5 { margin-bottom: 1.5rem; color: #495057; font-weight: 700; font-size: 1.1em; }
.metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem; margin: 1.5rem 0; }
.metric-item { background: white; padding: 1.5rem; border-radius: 10px; border-left: 4px solid #007bff; box-shadow: 0 3px 8px rgba(0,0,0,0.12); transition: transform 0.2s ease, box-shadow 0.2s ease; }
.metric-item:hover { transform: translateY(-2px); box-shadow: 0 6px 12px rgba(0,0,0,0.15); }
.metric-label { display: block; font-weight: 600; color: #495057; margin-bottom: 0.5rem; font-size: 0.95em; }
.metric-value { display: block; font-size: 1.4em; font-weight: 700; color: #007bff; margin-bottom: 0.25rem; }
.metric-desc { display: block; font-size: 0.85em; color: #6c757d; font-style: italic; }
.metric-value.excellent { color: #28a745; }
.metric-value.good { color: #17a2b8; }
.metric-value.medium { color: #ffc107; }
.metric-value.poor { color: #dc3545; }
.evaluation-table { font-size: 0.9em; }
.question-cell, .ground-truth-cell, .answer-cell { max-width: 200px; }
.chunks-cell { max-width: 120px; text-align: center; }
.cell-content { cursor: pointer; word-wrap: break-word; }
.table-responsive { max-height: 600px; overflow-y: auto; border-radius: 8px; border: 1px solid #dee2e6; }
.charts-section { margin-top: 2rem; padding: 1.5rem; background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%); border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
.charts-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 2rem; margin: 2rem 0; }
.chart-item { background: white; padding: 1.5rem; border-radius: 10px; box-shadow: 0 3px 8px rgba(0,0,0,0.12); border: 1px solid #e9ecef; }
.chart-item h6 { margin-bottom: 1rem; color: #495057; font-weight: 600; text-align: center; }
.analysis-section { margin-top: 2rem; padding: 2rem; background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%); border-radius: 12px; border-left: 4px solid #2196f3; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
.analysis-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin: 1.5rem 0; }
.analysis-card { background: white; padding: 1.5rem; border-radius: 10px; box-shadow: 0 3px 8px rgba(0,0,0,0.12); border-left: 4px solid #2196f3; }
.analysis-card h6 { margin-bottom: 1rem; color: #1976d2; font-weight: 600; }
.score-display { text-align: center; margin-bottom: 1rem; }
.score-value { display: block; font-size: 2em; font-weight: 700; margin-bottom: 0.25rem; }
.score-label { display: block; font-size: 0.9em; color: #6c757d; font-weight: 500; }
.analysis-insights p { margin-bottom: 0.5rem; font-size: 0.9em; }
.recommendations-section { margin-top: 2rem; padding: 1.5rem; background: white; border-radius: 10px; box-shadow: 0 3px 8px rgba(0,0,0,0.12); }
.recommendations-section h6 { margin-bottom: 1rem; color: #495057; font-weight: 600; }
.recommendations-list { margin-top: 1rem; }
.recommendation-item { display: flex; align-items: flex-start; gap: 0.5rem; margin-bottom: 0.75rem; padding: 0.75rem; background: #f8f9fa; border-radius: 6px; border-left: 3px solid #28a745; }
.recommendation-item i { color: #28a745; margin-top: 0.25rem; }
.download-section { margin-top: 2rem; padding: 1.5rem; background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 100%); border-radius: 12px; border-left: 4px solid #ff9800; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
.export-options { display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 1rem; }
.export-options .btn { min-width: 140px; }
.error-message { background: #f8d7da; color: #721c24; padding: 1rem; border-radius: 8px; margin: 1rem 0; border-left: 4px solid #dc3545; }
.text-success { color: #28a745 !important; }
.text-info { color: #17a2b8 !important; }
.text-warning { color: #ffc107 !important; }
.text-danger { color: #dc3545 !important; }
@media (max-width: 768px) {
    .charts-container { grid-template-columns: 1fr; }
    .metrics-grid { grid-template-columns: 1fr; }
    .analysis-grid { grid-template-columns: 1fr; }
    .export-options { flex-direction: column; }
    .export-options .btn { width: 100%; }
}
</style>

<?php
echo $OUTPUT->footer();
?>