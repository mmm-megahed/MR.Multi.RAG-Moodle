<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

class block_multimodal_rag_api_client {
    
    private $api_url;
    private $timeout;

    public function __construct() {
        $this->api_url = rtrim(get_config('block_multimodal_rag', 'api_url'), '/');
        $this->timeout = get_config('block_multimodal_rag', 'api_timeout') ?: 30;
        
        if (empty($this->api_url)) {
            throw new moodle_exception('api_not_configured', 'block_multimodal_rag');
        }
    }

    /**
     * Ask a question to the RAG system
     */
    public function ask_question($courseid, $question) {
        $url = $this->api_url . "/api/v1/nlp/index/answer/" . $courseid;
        
        $data = [
            'text' => $question,
            'limit' => get_config('block_multimodal_rag', 'answer_limit') ?: 5
        ];
        
        $response = $this->make_request('POST', $url, $data);
        
        return [
            'answer' => $response['answer'] ?? '',
            'sources' => $this->format_sources($response['sources'] ?? [])
        ];
    }

    /**
     * Process course materials
     */
    public function process_course_materials($courseid, $chunk_size = 200, $overlap_size = 20) {
        global $DB;
        
        // Step 1: Get course files and upload them
        $files_processed = $this->upload_course_files($courseid);
        
        // Step 2: Process the uploaded files
        $process_response = $this->process_files($courseid, $chunk_size, $overlap_size);
        
        // Step 3: Push to NLP index
        $index_response = $this->push_to_index($courseid);
        
        return [
            'files_processed' => $files_processed,
            'chunks_created' => $process_response['chunks_created'] ?? 0,
            'indexed' => $index_response['success'] ?? false
        ];
    }

    /**
     * Upload course files to FastAPI
     */
    private function upload_course_files($courseid) {
        $context = context_course::instance($courseid);
        $fs = get_file_storage();
        
        // Get all files from course context
        $files = $fs->get_area_files($context->id, false, false, false, 'filename');
        
        $uploaded_count = 0;
        
        foreach ($files as $file) {
            if ($file->is_directory()) {
                continue;
            }
            
            // Skip system files and check file types
            if ($this->should_process_file($file)) {
                try {
                    $this->upload_file($courseid, $file);
                    $uploaded_count++;
                } catch (Exception $e) {
                    // Log error but continue with other files
                    debugging('Failed to upload file: ' . $file->get_filename() . ' - ' . $e->getMessage());
                }
            }
        }
        
        return $uploaded_count;
    }

    /**
     * Check if file should be processed
     */
    private function should_process_file($file) {
        $allowed_types = ['pdf', 'doc', 'docx', 'txt', 'ppt', 'pptx'];
        $filename = $file->get_filename();
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        return in_array($extension, $allowed_types) && $file->get_filesize() > 0;
    }

    /**
     * Upload a single file to FastAPI
     */
    private function upload_file($courseid, $file) {
        $url = $this->api_url . "/api/v1/data/upload/" . $courseid;
        
        $postfields = [
            'file' => new CURLFile(
                'data://application/octet-stream;base64,' . base64_encode($file->get_content()),
                $file->get_mimetype(),
                $file->get_filename()
            )
        ];
        
        return $this->make_multipart_request($url, $postfields);
    }

    /**
     * Process uploaded files
     */
    private function process_files($courseid, $chunk_size, $overlap_size) {
        $url = $this->api_url . "/api/v1/data/process/" . $courseid;
        
        $data = [
            'chunk_size' => $chunk_size,
            'overlap_size' => $overlap_size,
            'do_reset' => 0
        ];
        
        return $this->make_request('POST', $url, $data);
    }

    /**
     * Push chunks to NLP index
     */
    private function push_to_index($courseid) {
        $url = $this->api_url . "/api/v1/nlp/index/push/" . $courseid;
        
        $data = [
            'do_reset' => 0
        ];
        
        return $this->make_request('POST', $url, $data);
    }

    /**
     * Get index information
     */
    public function get_index_info($courseid) {
        $url = $this->api_url . "/api/v1/nlp/index/info/" . $courseid;
        return $this->make_request('GET', $url);
    }

    /**
     * Make HTTP request
     */
    private function make_request($method, $url, $data = null) {
        $curl = curl_init();
        
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json'
            ]
        ];
        
        if ($data && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $options[CURLOPT_POSTFIELDS] = json_encode($data);
        }
        
        curl_setopt_array($curl, $options);
        
        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        
        if ($error) {
            throw new moodle_exception('curl_error', 'block_multimodal_rag', '', $error);
        }
        
        if ($http_code >= 400) {
            throw new moodle_exception('api_error', 'block_multimodal_rag', '', 'HTTP ' . $http_code);
        }
        
        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new moodle_exception('json_decode_error', 'block_multimodal_rag', '', json_last_error_msg());
        }
        
        return $decoded;
    }

    /**
     * Make multipart request for file uploads
     */
    private function make_multipart_request($url, $postfields) {
        $curl = curl_init();
        
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postfields,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json'
            ]
        ]);
        
        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        
        if ($error) {
            throw new moodle_exception('curl_error', 'block_multimodal_rag', '', $error);
        }
        
        if ($http_code >= 400) {
            throw new moodle_exception('api_error', 'block_multimodal_rag', '', 'HTTP ' . $http_code);
        }
        
        return json_decode($response, true);
    }

    /**
     * Format sources from API response
     */
    private function format_sources($sources) {
        $formatted = [];
        
        foreach ($sources as $source) {
            $formatted[] = [
                'filename' => $source['metadata']['filename'] ?? 'Unknown',
                'content' => $source['content'] ?? '',
                'score' => $source['score'] ?? 0.0
            ];
        }
        
        return $formatted;
    }
}