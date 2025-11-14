<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Configuration service for the SmartSPE activity module.
 *
 * @package    mod_smartspe
 * @copyright  2025 Jeremy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_smartspe\local\config;

use core\output\renderer_base;
use mod_smartspe\local\report\analysis_service;

/**
 * Configuration service class.
 */
final class configuration_service {
    /** Draft form status */
    public const STATUS_DRAFT = 0;

    /** Published form status */
    public const STATUS_PUBLISHED = 1;

    /** Archived form status */
    public const STATUS_ARCHIVED = 2;

    /**
     * Return forms for an activity, enriched with status labels and HTML action buttons.
     *
     * @param \stdClass $cm Course module
     * @param \context_module $context Context (reserved for future capability-aware variations)
     * @param renderer_base $output Renderer for action_link()
     * @return array Enriched form records ready for mustache
     */
    public static function build_forms_for_display(\stdClass $cm, \context_module $context, $output): array {
        global $DB;

        $forms = $DB->get_records('smartspe_form', ['smartspeid' => $cm->instance]) ?: [];

        foreach ($forms as $form) {
            // Status label.
            $form->statuslabel = self::status_label((int)($form->status ?? 0));

            // Edit button (no sesskey / state change).
            $editurl = new \moodle_url('/mod/smartspe/config/form_edit.php', [
                'id' => $cm->id,
                'formid' => $form->id,
            ]);
            $form->editbutton = \html_writer::link(
                $editurl,
                get_string('edit'),
                ['class' => 'btn btn-info']
            );

            // Delete (with confirmation + sesskey).
            $deleteurl = new \moodle_url('/mod/smartspe/config/form_delete.php', [
                'id' => $cm->id,
                'formid' => $form->id,
                'sesskey' => sesskey(),
            ]);
            $form->deletebutton = $output->action_link(
                $deleteurl,
                get_string('delete'),
                new \confirm_action(get_string('confirmdeleteform', 'mod_smartspe')),
                ['class' => 'btn btn-danger']
            );

            // Publish / Unpublish (with confirmation + sesskey).
            if ((int)$form->status === self::STATUS_PUBLISHED) {
                $publishurl = new \moodle_url('/mod/smartspe/config/form_publish.php', [
                    'id' => $cm->id,
                    'formid' => $form->id,
                    'action' => 'unpublish',
                    'sesskey' => sesskey(),
                ]);
                $form->publishbutton = $output->action_link(
                    $publishurl,
                    get_string('unpublish', 'mod_smartspe'),
                    new \confirm_action(get_string('confirmunpublishform', 'mod_smartspe')),
                    ['class' => 'btn btn-primary']
                );
            } else {
                $publishurl = new \moodle_url('/mod/smartspe/config/form_publish.php', [
                    'id' => $cm->id,
                    'formid' => $form->id,
                    'action' => 'publish',
                    'sesskey' => sesskey(),
                ]);
                $form->publishbutton = $output->action_link(
                    $publishurl,
                    get_string('publish', 'mod_smartspe'),
                    new \confirm_action(get_string('confirmpublishform', 'mod_smartspe')),
                    ['class' => 'btn btn-primary']
                );
            }

            // Archive (with confirmation + sesskey).
            $archiveurl = new \moodle_url('/mod/smartspe/config/form_archive.php', [
                'id' => $cm->id,
                'formid' => $form->id,
                'action' => 'archive',
                'sesskey' => sesskey(),
            ]);
            $form->archivebutton = $output->action_link(
                $archiveurl,
                get_string('archive', 'mod_smartspe'),
                new \confirm_action(get_string('confirmarchiveform', 'mod_smartspe')),
                ['class' => 'btn btn-warning']
            );
        }

        return $forms;
    }

    /**
     * Build archived forms dropdown options.
     *
     * @param int $smartspeid Activity instance id
     * @param int $selectedformid Currently selected form id (optional)
     * @return array
     */
    public static function archived_forms_dropdown(int $smartspeid, int $selectedformid = 0): array {
        global $DB;

        $archivedforms = $DB->get_records('smartspe_form', [
            'smartspeid' => $smartspeid,
            'status' => self::STATUS_ARCHIVED,
        ], 'timemodified DESC');

        $options = [];
        foreach ($archivedforms as $form) {
            $options[] = [
                'id' => (int)$form->id,
                'name' => format_string($form->title),
                'selected' => ((int)$form->id === (int)$selectedformid),
            ];
        }

        return $options;
    }

    /**
     * Return table header data (question labels) for a given form.
     * Delegates to report service if available, otherwise provides a minimal fallback.
     *
     * @param int $formid Form id
     * @return array Table header data for the form
     * @throws \dml_exception If database access fails
     */
    public static function table_headers_for_form(int $formid): array {
        if (
            class_exists('\\mod_smartspe\\local\\report\\analysis_service') &&
            method_exists('\\mod_smartspe\\local\\report\\analysis_service', 'table_headers_for_form')
        ) {
            return analysis_service::table_headers_for_form($formid);
        }

        // Load questions for the form.
        global $DB;
        $questions = array_values($DB->get_records('smartspe_question', ['formid' => $formid], 'id ASC', 'id'));
        $headers = [];
        $i = 1;
        foreach ($questions as $unused) {
            $headers[] = ['label' => 'Q' . $i];
            $i++;
        }

        return ['questions' => $headers];
    }

    /**
     * Return status label for a given form status.
     *
     * @param int $status Form status
     * @return string Status label
     * @throws \coding_exception If an unknown status is provided
     */
    private static function status_label(int $status): string {
        return match ($status) {
            self::STATUS_DRAFT => get_string('formstatusdraft', 'mod_smartspe'),
            self::STATUS_PUBLISHED => get_string('formstatuspublished', 'mod_smartspe'),
            self::STATUS_ARCHIVED => get_string('formstatusarchived', 'mod_smartspe'),
            default => get_string('unknownstatus', 'mod_smartspe'),
        };
    }
}
