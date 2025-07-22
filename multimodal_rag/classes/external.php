<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/blocks/multimodal_rag/classes/api_client.php');

class block_multimodal_rag_external extends external_api {

    /**
     * Returns description of method parameters
     */
    public static function ask_question_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'question' => new external_value(PARAM_TEXT, 'Question to ask'),
        ]);
    }

    /**
     * Ask a question to the RAG system
     */
    public static function ask_question($courseid, $question) {
        global $USER;

        // Validate parameters
        $params = self::validate_parameters(self::ask_question_parameters(), [
            'courseid' => $courseid,
            'question' => $question,
        ]);

        // Check permissions
        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('moodle/course:view', $context);

        try {
            $client = new block_multimodal_rag_api_client();
            $response = $client->ask_question($params['courseid'], $params['question']);
            
            return [
                'answer' => $response['answer'] ?? '',
                'sources' => $response['sources'] ?? [],
                'success' => true,
                'message' => ''
            ];
        } catch (Exception $e) {
            return [
                'answer' => '',
                'sources' => [],
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Returns description of method result value
     */
    public static function ask_question_returns() {
        return new external_single_structure([
            'answer' => new external_value(PARAM_RAW, 'The answer from RAG system'),
            'sources' => new external_multiple_structure(
                new external_single_structure([
                    'filename' => new external_value(PARAM_TEXT, 'Source filename', VALUE_OPTIONAL),
                    'content' => new external_value(PARAM_RAW, 'Source content excerpt', VALUE_OPTIONAL),
                    'score' => new external_value(PARAM_FLOAT, 'Relevance score', VALUE_OPTIONAL),
                ])
            ),
            'success' => new external_value(PARAM_BOOL, 'Whether the request was successful'),
            'message' => new external_value(PARAM_TEXT, 'Error message if any'),
        ]);
    }

    /**
     * Returns description of method parameters
     */
    public static function process_course_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'chunk_size' => new external_value(PARAM_INT, 'Chunk size', VALUE_DEFAULT, 200),
            'overlap_size' => new external_value(PARAM_INT, 'Overlap size', VALUE_DEFAULT, 20),
        ]);
    }

    /**
     * Process course materials
     */
    public static function process_course($courseid, $chunk_size = 200, $overlap_size = 20) {
        global $USER;

        // Validate parameters
        $params = self::validate_parameters(self::process_course_parameters(), [
            'courseid' => $courseid,
            'chunk_size' => $chunk_size,
            'overlap_size' => $overlap_size,
        ]);

        // Check permissions
        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('moodle/course:update', $context);

        try {
            $client = new block_multimodal_rag_api_client();
            $result = $client->process_course_materials($params['courseid'], $params['chunk_size'], $params['overlap_size']);
            
            return [
                'success' => true,
                'message' => 'Course materials processed successfully',
                'files_processed' => $result['files_processed'] ?? 0,
                'chunks_created' => $result['chunks_created'] ?? 0,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'files_processed' => 0,
                'chunks_created' => 0,
            ];
        }
    }

    /**
     * Returns description of method result value
     */
    public static function process_course_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the processing was successful'),
            'message' => new external_value(PARAM_TEXT, 'Status message'),
            'files_processed' => new external_value(PARAM_INT, 'Number of files processed'),
            'chunks_created' => new external_value(PARAM_INT, 'Number of chunks created'),
        ]);
    }
}