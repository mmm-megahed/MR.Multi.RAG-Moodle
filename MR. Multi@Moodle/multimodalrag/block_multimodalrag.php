<?php
defined('MOODLE_INTERNAL') || die();

class block_multimodalrag extends block_base {
    public function init() {
        $this->title = get_string('pluginname', 'block_multimodalrag');
    }

    public function get_content() {
        if ($this->content !== null) {
            return $this->content;
        }
     
        $this->content = new stdClass;
        $this->content->text = '';
        $this->content->footer = '';
     
        if (!$this->page->course || $this->page->course->id == SITEID) {
            return $this->content;
        }
     
        $courseid = $this->page->course->id;
        $context = context_course::instance($courseid);

        $this->content->text .= '<div class="multimodal-block-container">';

        // Teacher/Manager section
        if (has_capability('block/multimodalrag:processfiles', $context)) {
            $this->content->text .= '<div class="card mb-3">';
            $this->content->text .= '<div class="card-header"><strong><i class="fa fa-graduation-cap"></i> Teacher Tools</strong></div>';
            $this->content->text .= '<div class="card-body">';

            // Process Files
            $processurl = new moodle_url('/blocks/multimodalrag/process.php', ['courseid' => $courseid, 'sesskey' => sesskey()]);
            $this->content->text .= html_writer::link($processurl, '<i class="fa fa-cogs"></i> Process Files', ['class' => 'btn btn-primary btn-block mb-2']);

            // Process Videos
            $processvideosurl = new moodle_url('/blocks/multimodalrag/process_videos.php', ['courseid' => $courseid, 'sesskey' => sesskey()]);
            $this->content->text .= html_writer::link($processvideosurl, '<i class="fa fa-film"></i> Process Videos', ['class' => 'btn btn-info btn-block mb-2']);

            // Process Images
            $processimagesurl = new moodle_url('/blocks/multimodalrag/process_images.php', ['courseid' => $courseid, 'sesskey' => sesskey()]);
            $this->content->text .= html_writer::link($processimagesurl, '<i class="fa fa-image"></i> Process Images', ['class' => 'btn btn-secondary btn-block mb-2']);
            
            // Evaluation
            $evaluationurl = new moodle_url('/blocks/multimodalrag/evaluation.php', ['courseid' => $courseid]);
            $this->content->text .= html_writer::link($evaluationurl, '<i class="fa fa-chart-line"></i> Evaluation', ['class' => 'btn btn-warning btn-block']);

            $this->content->text .= '</div>';
            $this->content->text .= '</div>';
        }
     
        // Student section
        if (has_capability('block/multimodalrag:search', $context) || has_capability('block/multimodalrag:chat', $context)) {
            $this->content->text .= '<div class="card">';
            $this->content->text .= '<div class="card-header"><strong><i class="fa fa-user"></i> Student Tools</strong></div>';
            $this->content->text .= '<div class="card-body">';

            // Search
            if (has_capability('block/multimodalrag:search', $context)) {
                $simplesearchurl = new moodle_url('/blocks/multimodalrag/simple_search.php', ['courseid' => $courseid]);
                $this->content->text .= html_writer::link($simplesearchurl, '<i class="fa fa-search"></i> Search Content', ['class' => 'btn btn-primary btn-block mb-2']);
            }
     
            // Chat
            if (has_capability('block/multimodalrag:chat', $context)) {
                $simplechaturl = new moodle_url('/blocks/multimodalrag/simple_chat.php', ['courseid' => $courseid]);
                $this->content->text .= html_writer::link($simplechaturl, '<i class="fa fa-comments"></i> Chat with Content', ['class' => 'btn btn-success btn-block']);
            }

            $this->content->text .= '</div>';
            $this->content->text .= '</div>';
        }

        $this->content->text .= '</div>';

        // Custom CSS for the block
        $this->content->text .= '
        <style>
            .multimodal-block-container .card {
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            .multimodal-block-container .card-header {
                background-color: #f8f9fa;
                border-bottom: 1px solid #dee2e6;
                font-size: 1.1em;
            }
            .multimodal-block-container .btn-block {
                display: block;
                width: 100%;
                text-align: left;
            }
            .multimodal-block-container .btn .fa {
                margin-right: 8px;
            }
        </style>';
     
        return $this->content;
     }

    public function applicable_formats() {
        return ['course' => true, 'course-category' => false, 'site' => false];
    }

    public function has_config() {
        return true;
    }
}
?>
