<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Mr. Multi-RAG@Moodle';
$string['multimodalrag:addinstance'] = 'Add a new Multimodal RAG block';
$string['multimodalrag:processfiles'] = 'Process course files';
$string['multimodalrag:search'] = 'Search content';
$string['multimodalrag:chat'] = 'Chat with content';
$string['fastapi_url'] = 'FastAPI URL';
$string['fastapi_url_desc'] = 'URL of the FastAPI backend server';
$string['processing_title'] = 'Processing Files';
$string['search_title'] = 'Search & Chat';
$string['processing_videos_title'] = 'Processing Videos';

// settings.php
$string['general_settings'] = 'General Settings';
$string['processing_settings'] = 'Content Processing Settings';
$string['processing_settings_desc'] = 'Configure how different types of content are processed and chunked.';
$string['chunk_size'] = 'Chunk Size';
$string['chunk_size_desc'] = 'Default size of text chunks for processing (in characters).';
$string['overlap_size'] = 'Overlap Size';
$string['overlap_size_desc'] = 'Size of the overlap between text chunks (in characters).';
$string['enable_video_processing'] = 'Enable Video Processing';
$string['enable_video_processing_desc'] = 'Allow processing of video files to extract transcripts.';
$string['enable_image_processing'] = 'Enable Image Processing';
$string['enable_image_processing_desc'] = 'Allow processing of images to generate descriptions.';
$string['search_chat_settings'] = 'Search & Chat Settings';
$string['default_search_limit'] = 'Default Search Results';
$string['default_search_limit_desc'] = 'Default number of results to show on the search page.';
$string['default_chat_limit'] = 'Default Chat Context Depth';
$string['default_chat_limit_desc'] = 'Default number of context chunks to use in the chat.';
$string['chat_welcome_message'] = 'Chat Welcome Message';
$string['chat_welcome_message_desc'] = 'The initial message displayed in the chat interface.';
$string['default_chat_welcome'] = 'Hello! How can I help you with your course materials today?';
$string['evaluation_settings'] = 'Evaluation Settings';
$string['evaluation_settings_desc'] = 'Set the thresholds for the RAG evaluation metrics.';
$string['context_precision_good'] = 'Context Precision (Good)';
$string['context_recall_good'] = 'Context Recall (Good)';
$string['answer_relevance_good'] = 'Answer Relevance (Good)';
$string['evaluation_threshold_desc'] = 'Threshold for a "Good" rating (0.0 to 1.0).';
$string['ui_settings'] = 'UI Customization';
$string['teacher_tools_title'] = 'Teacher Tools Title';
$string['teacher_tools_title_desc'] = 'Title for the teacher tools section in the block.';
$string['default_teacher_tools_title'] = 'Teacher Tools';
$string['student_tools_title'] = 'Student Tools Title';
$string['student_tools_title_desc'] = 'Title for the student tools section in the block.';
$string['default_student_tools_title'] = 'Student Tools';

// Model selection settings
$string['model_settings'] = 'Model Settings';
$string['model_settings_desc'] = 'Configure which AI models to use for different tasks.';
$string['generation_model'] = 'Generation Model';
$string['generation_model_desc'] = 'Select the model to use for generating responses in chat.';
$string['embedding_model'] = 'Embedding Model';
$string['embedding_model_desc'] = 'Select the model to use for creating text embeddings.';