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

        if (has_capability('block/multimodalrag:processfiles', $context)) {
            // Button to process standard files (PDF, TXT)
            $processurl = new moodle_url('/blocks/multimodalrag/process.php', [
                'courseid' => $courseid,
                'sesskey' => sesskey()
            ]);
            $this->content->text .= html_writer::link($processurl,
                '<i class="fa fa-cogs"></i> Process Files',
                ['class' => 'btn btn-primary btn-sm mb-2']);

            // Button to process video files
            $processvideosurl = new moodle_url('/blocks/multimodalrag/process_videos.php', [
                'courseid' => $courseid,
                'sesskey' => sesskey()
            ]);
            $this->content->text .= html_writer::link($processvideosurl,
                '<i class="fa fa-film"></i> Process Videos',
                ['class' => 'btn btn-info btn-sm mb-2']);
        }

        if (has_capability('block/multimodalrag:search', $context) ||
            has_capability('block/multimodalrag:chat', $context)) {
            $this->content->text .= '<div class="multimodal-nav-buttons">';

            if (has_capability('block/multimodalrag:search', $context)) {
                $simplesearchurl = new moodle_url('/blocks/multimodalrag/simple_search.php', [
                    'courseid' => $courseid
                ]);
                $this->content->text .= html_writer::link($simplesearchurl,
                    '<i class="fa fa-search"></i> Search Content',
                    ['class' => 'btn btn-primary btn-sm d-block mb-2']);
            }

            if (has_capability('block/multimodalrag:chat', $context)) {
                $simplechaturl = new moodle_url('/blocks/multimodalrag/simple_chat.php', [
                    'courseid' => $courseid
                ]);
                $this->content->text .= html_writer::link($simplechaturl,
                    '<i class="fa fa-comments"></i> Chat with Content',
                    ['class' => 'btn btn-success btn-sm d-block']);
            }

            $this->content->text .= '</div>';
        }

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
