<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Multimodal RAG';
$string['multimodal_rag'] = 'Multimodal RAG';
$string['multimodal_rag:addinstance'] = 'Add a new Multimodal RAG block';
$string['multimodal_rag:myaddinstance'] = 'Add a new Multimodal RAG block to Dashboard';

// Block settings
$string['defaulttitle'] = 'AI Assistant';
$string['blocktitle'] = 'Block title';
$string['blocktitle_help'] = 'Custom title for this block instance';

// Admin settings
$string['api_url'] = 'FastAPI URL';
$string['api_url_desc'] = 'The base URL of your FastAPI RAG service (e.g., http://127.0.0.1:8000)';
$string['api_timeout'] = 'API Timeout';
$string['api_timeout_desc'] = 'Timeout in seconds for API requests (default: 30)';
$string['chunk_size'] = 'Default Chunk Size';
$string['chunk_size_desc'] = 'Default chunk size for document processing (default: 200)';
$string['overlap_size'] = 'Default Overlap Size';
$string['overlap_size_desc'] = 'Default overlap size for document chunking (default: 20)';
$string['answer_limit'] = 'Default Answer Limit';
$string['answer_limit_desc'] = 'Default number of relevant chunks to retrieve (default: 5)';

// Interface
$string['ask_question'] = 'Ask a question about course materials';
$string['question_placeholder'] = 'Enter your question here...';
$string['ask'] = 'Ask';
$string['loading'] = 'Processing your question...';
$string['no_answer'] = 'No answer found. Please try rephrasing your question.';
$string['api_error'] = 'Error connecting to AI service. Please try again later.';
$string['api_not_configured'] = 'AI service not configured. Please contact your administrator.';

// Status
$string['course_index_id'] = 'Course Index ID: {$a}';
$string['index_status'] = 'Index Status';
$string['processing_status'] = 'Processing course materials...';
$string['ready_status'] = 'Ready to answer questions';

// Settings form
$string['process_materials'] = 'Process Course Materials';
$string['process_materials_desc'] = 'Upload and process all course files to the RAG system';
$string['chunk_size_setting'] = 'Chunk Size';
$string['overlap_size_setting'] = 'Overlap Size';
$string['auto_process'] = 'Auto-process new materials';
$string['auto_process_desc'] = 'Automatically process new course materials when they are added';

// Privacy
$string['privacy:metadata'] = 'The Multimodal RAG block does not store any personal data.';
$string['privacy:metadata:external:fastapi'] = 'Course content is sent to the configured FastAPI service for processing and answering questions.';
$string['privacy:metadata:external:fastapi:courseid'] = 'The course ID is sent to identify which course content to use.';
$string['privacy:metadata:external:fastapi:question'] = 'User questions are sent to the AI service to generate answers.';
$string['privacy:metadata:external:fastapi:content'] = 'Course materials are sent for processing and indexing.';