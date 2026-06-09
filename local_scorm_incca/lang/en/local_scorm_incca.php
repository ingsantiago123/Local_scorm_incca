<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'SCORM INCCA - Download control';

// Capabilities.
$string['scorm_incca:upload']         = 'Upload SCORM packages as protected';
$string['scorm_incca:download']       = 'Download protected SCORM packages';
$string['scorm_incca:backup']         = 'Create backups of courses with protected SCORMs';
$string['scorm_incca:viewadminpanel'] = 'View plugin admin panel';

$string['cap_cargar']    = 'Users authorized to UPLOAD protected SCORMs';
$string['cap_descargar'] = 'Users authorized to DOWNLOAD protected SCORMs';
$string['cap_backup']    = 'Users authorized to create BACKUPS of courses with protected SCORMs';

// Access denied messages.
$string['backup_denied_activity'] = 'You do not have permission to create backups of protected SCORM packages.';
$string['backup_denied_course']   = 'This course contains protected SCORM packages. The backup permission is required to create backups of this course.';
$string['downloaddenied'] = 'Access denied. You do not have permission to download this protected SCORM package.';
$string['deletedenied']   = 'You do not have permission to delete this protected SCORM package. Only administrators or users with upload/download permissions can delete it.';
$string['unzipdenied']    = 'You do not have permission to unzip this protected SCORM package.';

// Navigation.
$string['protected_list']       = 'Registered SCORM packages';
$string['logs']                 = 'Debug logs';
$string['users_with_caps']      = 'Users with custom capabilities';
$string['users_with_caps_help'] = 'List of users assigned to roles holding the plugin custom capabilities.';

// Items table headers.
$string['th_status']  = 'Status';
$string['th_scorm']   = 'SCORM';
$string['th_course']  = 'Course';
$string['th_creator'] = 'Creator';
$string['th_created'] = 'Created';
$string['th_actions'] = 'Actions';

$string['protected_badge'] = 'Protected';
$string['public_badge']    = 'Public';

$string['filter_protected'] = 'Protected only';
$string['filter_public']    = 'Public only';
$string['filter_all']       = 'All';
$string['filter']           = 'Filter';
$string['filter_by_cmid']   = 'Filter by cmid';

$string['no_records'] = 'No SCORM packages registered for the selected filter.';
$string['view_logs']  = 'View logs';

// Logs table headers.
$string['th_when']      = 'When';
$string['th_eventtype'] = 'Event';
$string['th_user']      = 'User';
$string['th_cmid']      = 'cmid';
$string['th_ip']        = 'IP';
$string['th_message']   = 'Message';
$string['th_email']     = 'Email';
$string['th_role']      = 'Role';

$string['no_logs']           = 'No log entries match the selected filters.';
$string['no_users_with_cap'] = 'No users assigned with this capability.';

// Log event type labels.
$string['log_upload_protected']  = 'Upload (protected)';
$string['log_upload_public']     = 'Upload (public)';
$string['log_download_allowed']  = 'Download allowed';
$string['log_download_blocked']  = 'Download blocked';
$string['log_deleted']           = 'Deleted';
$string['log_error']             = 'Error';
$string['log_delete_blocked']    = 'Deletion blocked';
$string['log_unzip_blocked']     = 'Unzip blocked';
$string['log_import_registered'] = 'Imported';

// Search.
$string['search_placeholder'] = 'Search by SCORM name or course...';
$string['search']             = 'Search';
$string['showing_x_of_y']    = 'Showing {$a->from}–{$a->to} of {$a->total} packages';

// Admin panel UI strings.
$string['make_public']             = 'Make Public';
$string['make_protected']          = 'Protect';
$string['make_public_confirm']     = 'Make this SCORM PUBLIC? Any user will be able to download it.';
$string['make_protected_confirm']  = 'Mark this SCORM as PROTECTED? Only users with permission will be able to download it.';
$string['cleanup_orphans']         = 'Clean up orphans';
$string['cleanup_orphans_title']   = 'Removes records for SCORMs that have been deleted from Moodle';
$string['cleanup_orphans_confirm'] = 'Remove records for SCORMs that no longer exist in Moodle?';
$string['clear_search']            = 'Clear search';
$string['clear_filters']           = 'Clear';
$string['changed_to_protected']    = 'SCORM cmid={$a->cmid} is now PROTECTED.';
$string['changed_to_public']       = 'SCORM cmid={$a->cmid} is now PUBLIC.';
$string['cleanup_result']          = 'Cleanup: {$a} orphan record(s) removed.';
$string['cleanup_error']           = 'Cleanup error: {$a}';
$string['showing_x_of_y_records']  = 'Showing {$a->from}–{$a->to} of {$a->total} records';

// Privacy metadata.
$string['privacy:metadata:items']             = 'Record of SCORM packages and their protection status.';
$string['privacy:metadata:items:creatorid']   = 'Creator user.';
$string['privacy:metadata:items:cmid']        = 'Course module ID.';
$string['privacy:metadata:items:isprotected'] = 'Whether the package is protected.';
$string['privacy:metadata:items:timecreated'] = 'Record creation time.';

$string['privacy:metadata:logs']             = 'Plugin audit log entries.';
$string['privacy:metadata:logs:userid']      = 'User who performed the action.';
$string['privacy:metadata:logs:eventtype']   = 'Event type.';
$string['privacy:metadata:logs:message']     = 'Event detail.';
$string['privacy:metadata:logs:ipaddress']   = 'IP address.';
$string['privacy:metadata:logs:timecreated'] = 'Event time.';
