<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

defined('MOODLE_INTERNAL') || die();

class block_multimodal_rag extends block_base {
    
    public function init() {
        $this->title = get_string('pluginname', 'block_multimodal_rag');
    }

    public function get_content() {
        global $CFG, $COURSE, $PAGE;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass;
        $this->content->text = '';
        $this->content->footer = '';

        // Check if API is configured
        $apiurl = get_config('block_multimodal_rag', 'api_url');
        if (empty($apiurl)) {
            $this->content->text = html_writer::div(
                get_string('api_not_configured', 'block_multimodal_rag'),
                'alert alert-warning'
            );
            return $this->content;
        }

        // Add CSS and JavaScript
        $PAGE->requires->css('/blocks/multimodal_rag/styles.css');
        $PAGE->requires->js_call_amd('block_multimodal_rag/main', 'init', [$COURSE->id]);

        // Build the interface
        $this->content->text .= $this->build_search_interface();
        
        return $this->content;
    }

    private function build_search_interface() {
        global $COURSE;
        
        $html = '';
        
        // Search form
        $html .= html_writer::start_div('multimodal-rag-container');
        
        // Search input
        $html .= html_writer::start_div('search-section');
        $html .= html_writer::tag('label', get_string('ask_question', 'block_multimodal_rag'), 
            ['for' => 'rag-question', 'class' => 'form-label']);
        $html .= html_writer::tag('textarea', '', [
            'id' => 'rag-question',
            'class' => 'form-control',
            'placeholder' => get_string('question_placeholder', 'block_multimodal_rag'),
            'rows' => 3
        ]);
        $html .= html_writer::tag('button', get_string('ask', 'block_multimodal_rag'), [
            'id' => 'rag-ask-btn',
            'class' => 'btn btn-primary mt-2',
            'type' => 'button'
        ]);
        $html .= html_writer::end_div(); // search-section
        
        // Loading indicator
        $html .= html_writer::div('', 'loading-spinner d-none', ['id' => 'rag-loading']);
        
        // Results area
        $html .= html_writer::start_div('results-section mt-3', ['id' => 'rag-results']);
        $html .= html_writer::end_div(); // results-section
        
        // Status info
        $html .= html_writer::start_div('status-section mt-2', ['id' => 'rag-status']);
        $html .= $this->get_index_status($COURSE->id);
        $html .= html_writer::end_div(); // status-section
        
        $html .= html_writer::end_div(); // multimodal-rag-container
        
        return $html;
    }

    private function get_index_status($courseid) {
        $apiurl = get_config('block_multimodal_rag', 'api_url');
        if (empty($apiurl)) {
            return '';
        }

        // This would typically make an API call to check index status
        // For now, we'll show a basic status
        return html_writer::div(
            html_writer::tag('small', 
                get_string('course_index_id', 'block_multimodal_rag', $courseid),
                ['class' => 'text-muted']
            ),
            'index-status'
        );
    }

    public function applicable_formats() {
        return [
            'course-view' => true,
            'site' => false,
            'mod' => false,
            'my' => false
        ];
    }

    public function has_config() {
        return true;
    }

    public function instance_allow_multiple() {
        return false;
    }

    public function hide_header() {
        return false;
    }

    public function instance_allow_config() {
        return true;
    }

    public function specialization() {
        if (isset($this->config)) {
            if (empty($this->config->title)) {
                $this->title = get_string('defaulttitle', 'block_multimodal_rag');
            } else {
                $this->title = $this->config->title;
            }
        }
    }
}