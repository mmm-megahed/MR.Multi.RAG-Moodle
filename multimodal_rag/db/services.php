<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

$functions = [
    'block_multimodal_rag_ask_question' => [
        'classname' => 'block_multimodal_rag_external',
        'methodname' => 'ask_question',
        'classpath' => 'blocks/multimodal_rag/classes/external.php',
        'description' => 'Ask a question to the RAG system',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'block_multimodal_rag_process_course' => [
        'classname' => 'block_multimodal_rag_external',
        'methodname' => 'process_course',
        'classpath' => 'blocks/multimodal_rag/classes/external.php',
        'description' => 'Process course materials in the RAG system',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
];

$services = [
    'Multimodal RAG Services' => [
        'functions' => ['block_multimodal_rag_ask_question', 'block_multimodal_rag_process_course'],
        'restrictedusers' => 0,
        'enabled' => 1,
    ],
];