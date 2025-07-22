<?php
// This file is part of Moodle - http://moodle.org/

namespace block_multimodal_rag\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;

class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {

    /**
     * Return the fields which contain personal data.
     *
     * @param collection $collection a reference to the collection to use to store the metadata.
     * @return collection the updated collection of metadata items.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_external_location_link(
            'fastapi',
            [
                'courseid' => 'privacy:metadata:external:fastapi:courseid',
                'question' => 'privacy:metadata:external:fastapi:question',
                'content' => 'privacy:metadata:external:fastapi:content'
            ],
            'privacy:metadata:external:fastapi'
        );

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid the userid.
     * @return contextlist the list of contexts containing user info for the user.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        // This block doesn't store personal data in Moodle database
        $contextlist = new contextlist();
        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist the userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist) {
        // This block doesn't store personal data in Moodle database
    }

    /**
     * Export personal data for the given approved_contextlist.
     *
     * @param approved_contextlist $contextlist a list of contexts approved for export.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        // This block doesn't store personal data in Moodle database
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context the specific context to delete data for.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        // This block doesn't store personal data in Moodle database
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist a list of contexts approved for deletion.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        // This block doesn't store personal data in Moodle database
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        // This block doesn't store personal data in Moodle database
    }
}