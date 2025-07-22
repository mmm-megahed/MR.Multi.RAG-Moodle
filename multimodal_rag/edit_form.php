<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

class block_multimodal_rag_edit_form extends block_edit_form {

    protected function specific_definition($mform) {
        global $COURSE;

        // Block title
        $mform->addElement('header', 'configheader', get_string('blocksettings', 'block'));

        $mform->addElement('text', 'config_title', get_string('blocktitle', 'block_multimodal_rag'));
        $mform->setDefault('config_title', get_string('defaulttitle', 'block_multimodal_rag'));
        $mform->setType('config_title', PARAM_TEXT);
        $mform->addHelpButton('config_title', 'blocktitle', 'block_multimodal_rag');

        // Processing settings
        $mform->addElement('header', 'processingheader', get_string('process_materials', 'block_multimodal_rag'));

        $mform->addElement('text', 'config_chunk_size', get_string('chunk_size_setting', 'block_multimodal_rag'));
        $mform->setDefault('config_chunk_size', get_config('block_multimodal_rag', 'chunk_size'));
        $mform->setType('config_chunk_size', PARAM_INT);

        $mform->addElement('text', 'config_overlap_size', get_string('overlap_size_setting', 'block_multimodal_rag'));
        $mform->setDefault('config_overlap_size', get_config('block_multimodal_rag', 'overlap_size'));
        $mform->setType('config_overlap_size', PARAM_INT);

        $mform->addElement('advcheckbox', 'config_auto_process', get_string('auto_process', 'block_multimodal_rag'), 
            get_string('auto_process_desc', 'block_multimodal_rag'));
        $mform->setDefault('config_auto_process', 1);

        // Process materials button
        $processbtn = $mform->createElement('button', 'process_materials_btn', 
            get_string('process_materials', 'block_multimodal_rag'));
        $processbtn->updateAttributes(['onclick' => 'return processCourseMaterials(' . $COURSE->id . ');']);
        $mform->addElement($processbtn);

        // Add some JavaScript for the process button
        global $PAGE;
        $PAGE->requires->js_amd_inline("
            window.processCourseMaterials = function(courseId) {
                require(['jquery', 'core/notification'], function($, notification) {
                    notification.alert('Processing', 'Processing course materials. This may take a few minutes...', 'OK');
                    // Make AJAX call to process materials
                    $.post(M.cfg.wwwroot + '/blocks/multimodal_rag/process.php', {
                        courseid: courseId,
                        sesskey: M.cfg.sesskey
                    }).done(function(response) {
                        notification.alert('Success', 'Course materials processed successfully!', 'OK');
                    }).fail(function() {
                        notification.alert('Error', 'Failed to process course materials. Please try again.', 'OK');
                    });
                });
                return false;
            };
        ");
    }
}