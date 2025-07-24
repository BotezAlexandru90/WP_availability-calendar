<?php
/*
Plugin Name: Availability Calendar
Plugin URI: https://github.com/BotezAlexandru90/WP_availability-calendar/
Description: A plugin to mark user availability on a calendar, accessed via unique codes. Features timezone-aware scheduling.
Version: 3.7
Author: Surama Badasaz
Author URI: https://zkillboard.com/character/91036298/
License: GPLv2 or later
Text Domain: availability-calendar
*/

if (!defined('ABSPATH')) { exit; }

// --- 1. ACTIVATION HOOK ---
register_activation_hook(__FILE__, 'acal_activate_plugin');
function acal_activate_plugin() {
    global $wpdb; require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    $charset_collate = $wpdb->get_charset_collate();
    $table_codes = $wpdb->prefix . 'availability_codes';
    $sql_codes = "CREATE TABLE $table_codes ( id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, access_code VARCHAR(255) NOT NULL, user_label VARCHAR(255) NOT NULL, created_at DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL, PRIMARY KEY (id), UNIQUE KEY access_code (access_code) ) $charset_collate;";
    dbDelta($sql_codes);
    $table_slots = $wpdb->prefix . 'availability_slots';
    $sql_slots = "CREATE TABLE $table_slots ( id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, code_id BIGINT(20) UNSIGNED NOT NULL, start_time_utc DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL, end_time_utc DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL, selection_timezone VARCHAR(100) NOT NULL, series_id VARCHAR(32) NULL, notes TEXT NULL, PRIMARY KEY (id), KEY code_id (code_id), KEY series_id (series_id) ) $charset_collate;";
    dbDelta($sql_slots);
    if (!get_option('acal_plugin_options')) { update_option('acal_plugin_options', ['acal_my_slot_color' => '#3a87ad', 'acal_other_slot_color' => '#888888', 'acal_recurrence_icon_url' => '', 'acal_note_icon_url' => '']); }
}

// --- Helper Function for Fixed Offsets ---
function acal_get_fixed_offset($timezone_identifier) {
    static $offset_cache = []; // Use a static cache for performance
    if (isset($offset_cache[$timezone_identifier])) {
        return $offset_cache[$timezone_identifier];
    }
    try {
        $tz = new DateTimeZone($timezone_identifier);
        // Use a date in winter (guaranteed standard time for Northern Hemisphere) to find the base offset
        $winter_date = new DateTime('2025-01-15', $tz);
        $offset_seconds = $winter_date->getOffset();
        
        $prefix = ($offset_seconds < 0) ? '-' : '+';
        $offset_seconds = abs($offset_seconds);
        $hours = floor($offset_seconds / 3600);
        $minutes = floor(($offset_seconds % 3600) / 60);
        
        $offset_string = sprintf('%s%02d:%02d', $prefix, $hours, $minutes);
        $offset_cache[$timezone_identifier] = $offset_string;
        return $offset_string;
    } catch (Exception $e) {
        return null; // Return null if the timezone identifier is invalid
    }
}

// --- 2. ADMIN AREA ---
add_action('admin_menu', 'acal_admin_menu');
function acal_admin_menu() { add_menu_page('Availability Calendar', 'Availability', 'edit_pages', 'acal-main-menu', 'acal_render_codes_page', 'dashicons-calendar-alt', 25); add_submenu_page('acal-main-menu', 'Access Codes', 'Access Codes', 'edit_pages', 'acal-main-menu', 'acal_render_codes_page'); add_submenu_page('acal-main-menu', 'Settings', 'Settings', 'edit_pages', 'acal-settings', 'acal_render_settings_page'); }
add_action('admin_enqueue_scripts', 'acal_admin_scripts');
function acal_admin_scripts($hook) { if ($hook !== 'availability_page_acal-settings') return; wp_enqueue_style('wp-color-picker'); wp_enqueue_script('acal-admin-script', false, ['jquery', 'wp-color-picker'], false, true); wp_add_inline_script('acal-admin-script', 'jQuery(document).ready(function($){$(".acal-color-picker").wpColorPicker();});'); }
function acal_render_codes_page() { global $wpdb; $codes_table = $wpdb->prefix . 'availability_codes'; if (isset($_POST['acal_add_code_nonce']) && wp_verify_nonce($_POST['acal_add_code_nonce'], 'acal_add_code_action')) { $user_label = sanitize_text_field($_POST['user_label']); if (!empty($user_label)) { $access_code = wp_generate_password(16, false); $wpdb->insert($codes_table, ['user_label' => $user_label, 'access_code' => $access_code, 'created_at' => current_time('mysql')], ['%s', '%s', '%s']); echo '<div class="notice notice-success is-dismissible"><p>New access code generated successfully!</p></div>'; } else { echo '<div class="notice notice-error is-dismissible"><p>User Label cannot be empty.</p></div>'; } } if (isset($_GET['action']) && $_GET['action'] === 'delete_code' && isset($_GET['code_id']) && isset($_GET['_wpnonce'])) { if (wp_verify_nonce($_GET['_wpnonce'], 'acal_delete_code_' . $_GET['code_id'])) { $code_id_to_delete = absint($_GET['code_id']); $wpdb->delete($codes_table, ['id' => $code_id_to_delete], ['%d']); $wpdb->delete($wpdb->prefix . 'availability_slots', ['code_id' => $code_id_to_delete], ['%d']); echo '<div class="notice notice-success is-dismissible"><p>Access code and associated slots deleted.</p></div>'; } } ?><div class="wrap"><h1>Manage Access Codes</h1><div id="col-container"><div id="col-right"><div class="col-wrap"><h2>Existing Codes</h2><table class="wp-list-table widefat fixed striped"><thead><tr><th>User Label</th><th>Access Code</th><th>Created</th></tr></thead><tbody><?php $codes = $wpdb->get_results("SELECT * FROM $codes_table ORDER BY created_at DESC"); if ($codes) { foreach ($codes as $code) { $delete_link = add_query_arg(['action' => 'delete_code', 'code_id' => $code->id, '_wpnonce' => wp_create_nonce('acal_delete_code_' . $code->id)]); echo '<tr><td><strong>' . esc_html($code->user_label) . '</strong><div class="row-actions"><span class="trash"><a href="' . esc_url($delete_link) . '" class="submitdelete" onclick="return confirm(\'Are you sure?\');">Delete</a></span></div></td><td><input type="text" onfocus="this.select();" readonly="readonly" value="' . esc_attr($code->access_code) . '" class="large-text code-field"></td><td>' . esc_html($code->created_at) . '</td></tr>'; } } else { echo '<tr><td colspan="3">No codes found.</td></tr>'; } ?></tbody></table></div></div><div id="col-left"><div class="col-wrap"><h2>Add New Code</h2><form method="post"><?php wp_nonce_field('acal_add_code_action', 'acal_add_code_nonce'); ?><div class="form-field"><label for="user_label">User Label</label><input name="user_label" id="user_label" type="text" required><p>A friendly name for this code (e.g., "John Doe").</p></div><?php submit_button('Generate New Access Code'); ?></form></div></div></div></div><?php }
function acal_render_settings_page() { ?><div class="wrap"><h1>Calendar Settings</h1><form method="post" action="options.php"><?php settings_fields('acal_settings_group'); do_settings_sections('acal-settings'); submit_button(); ?></form></div><?php }
add_action('admin_init', 'acal_register_settings'); function acal_register_settings() { 
    register_setting('acal_settings_group', 'acal_plugin_options', 'acal_sanitize_options'); 
    add_settings_section('acal_general_section', 'General Settings', null, 'acal-settings');
    add_settings_field('acal_recurrence_icon_url', 'Recurrence Icon URL', 'acal_render_text_field', 'acal-settings', 'acal_general_section', ['id' => 'acal_recurrence_icon_url', 'desc' => 'Paste a URL to a small icon (e.g., 16x16px). Leave blank for default (🔄).']);
    add_settings_field('acal_note_icon_url', 'Note Icon URL', 'acal_render_text_field', 'acal-settings', 'acal_general_section', ['id' => 'acal_note_icon_url', 'desc' => 'Paste a URL to a small icon (e.g., 16x16px). Leave blank for default (📝).']);
    add_settings_section('acal_colors_section', 'Color Settings', null, 'acal-settings'); 
    add_settings_field('acal_my_slot_color', 'Your Selected Slots Color', 'acal_render_color_picker_field', 'acal-settings', 'acal_colors_section', ['id' => 'acal_my_slot_color', 'default' => '#3a87ad']); 
    add_settings_field('acal_other_slot_color', 'Other Users\' Slots Color', 'acal_render_color_picker_field', 'acal-settings', 'acal_colors_section', ['id' => 'acal_other_slot_color', 'default' => '#888888']); 
}
function acal_render_text_field($args) { $options = get_option('acal_plugin_options'); $value = isset($options[$args['id']]) ? $options[$args['id']] : ''; echo '<input type="text" name="acal_plugin_options[' . esc_attr($args['id']) . ']" value="' . esc_attr($value) . '" class="regular-text" />'; if (!empty($args['desc'])) { echo '<p class="description">' . esc_html($args['desc']) . '</p>'; } }
function acal_render_color_picker_field($args) { $options = get_option('acal_plugin_options'); $value = isset($options[$args['id']]) ? $options[$args['id']] : $args['default']; echo '<input type="text" name="acal_plugin_options[' . esc_attr($args['id']) . ']" value="' . esc_attr($value) . '" class="acal-color-picker" />'; }
function acal_sanitize_options($input) { 
    $sanitized_input = [];
    $sanitized_input['acal_my_slot_color'] = sanitize_hex_color($input['acal_my_slot_color'] ?? '');
    $sanitized_input['acal_other_slot_color'] = sanitize_hex_color($input['acal_other_slot_color'] ?? '');
    $sanitized_input['acal_recurrence_icon_url'] = sanitize_url($input['acal_recurrence_icon_url'] ?? '');
    $sanitized_input['acal_note_icon_url'] = sanitize_url($input['acal_note_icon_url'] ?? '');
    return $sanitized_input; 
}

// --- 4. SHORTCODE & FRONTEND ---
add_action('init', 'acal_start_session', 1); function acal_start_session() { if (!session_id()) { session_start(); } }
add_shortcode('availability_calendar', 'acal_shortcode_handler');
function acal_shortcode_handler() { if (isset($_POST['acal_access_code_nonce']) && wp_verify_nonce($_POST['acal_access_code_nonce'], 'acal_access_code_action')) { $submitted_code = sanitize_text_field($_POST['access_code']); if (acal_validate_access_code($submitted_code)) { $_SESSION['acal_access_code'] = $submitted_code; } else { set_transient('acal_login_error', 'Invalid access code. Please try again.', 30); } wp_redirect(remove_query_arg('')); exit; } if (isset($_GET['acal_action']) && $_GET['acal_action'] === 'logout') { unset($_SESSION['acal_access_code']); wp_redirect(remove_query_arg('acal_action')); exit; } wp_enqueue_script('fullcalendar', 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.9/index.global.min.js', [], '6.1.9', true); $options = get_option('acal_plugin_options'); 
    // ** PHP CHANGE: Generate the full timezone list and pass it to the script **
    $vars_to_pass = [
        'ajax_url' => admin_url('admin-ajax.php'), 
        'nonce' => wp_create_nonce('acal_ajax_nonce'), 
        'colors' => ['my_slot' => $options['acal_my_slot_color'] ?? '#3a87ad', 'other_slot' => $options['acal_other_slot_color'] ?? '#888888'], 
        'recurrence_icon_url' => $options['acal_recurrence_icon_url'] ?? '', 
        'note_icon_url' => $options['acal_note_icon_url'] ?? '',
        'timezones' => DateTimeZone::listIdentifiers()
    ]; 
    wp_localize_script('fullcalendar', 'ACAL_VARS', $vars_to_pass); add_action('wp_footer', 'acal_print_inline_css', 99); add_action('wp_footer', 'acal_print_inline_js', 99); ob_start(); if (!empty($_SESSION['acal_access_code']) && acal_validate_access_code($_SESSION['acal_access_code'])) { acal_get_calendar_view(); } else { acal_get_access_form_view(); } return ob_get_clean(); }
function acal_get_access_form_view() { ?><div id="acal-access-container"><h2>Enter Access Code</h2><p>Please enter the access code provided to you.</p><?php $error_message = get_transient('acal_login_error'); if ($error_message) { echo '<p class="acal-error">' . esc_html($error_message) . '</p>'; delete_transient('acal_login_error'); } ?><form method="post"><?php wp_nonce_field('acal_access_code_action', 'acal_access_code_nonce'); ?><input type="text" name="access_code" placeholder="Your Code" required><button type="submit">Submit</button></form></div><?php }
function acal_get_calendar_view() { $logout_url = add_query_arg('acal_action', 'logout'); ?><div id="acal-calendar-wrapper"><div class="acal-header"><div><label for="acal-timezone-select">Your Timezone:</label><select id="acal-timezone-select"></select><small>Standard Time offsets are used (DST is ignored).</small></div><div class="acal-user-info"><p>Editing as: <strong id="acal-user-label-display">...</strong></p><a href="<?php echo esc_url($logout_url); ?>">Log Out</a></div></div><div id="acal-recurrence-controls" style="display: none;"><h3>Repeat Last Selection</h3><div class="acal-recurrence-row"><div class="acal-recurrence-field"><label for="acal-recur-start">Start Date</label><input type="date" id="acal-recur-start"></div><div class="acal-recurrence-field"><label for="acal-recur-end">End Date</label><input type="date" id="acal-recur-end"></div><div class="acal-recurrence-field"><label for="acal-recur-frequency">Frequency</label><select id="acal-recur-frequency"><option value="weekly">Weekly</option><option value="biweekly">Every 2 Weeks</option><option value="monthly-1">Monthly</option><option value="monthly-2">Every 2 Months</option><option value="monthly-3">Every 3 Months</option><option value="monthly-4">Every 4 Months</option><option value="monthly-5">Every 5 Months</option><option value="monthly-6">Every 6 Months</option></select></div><div class="acal-recurrence-field"><button id="acal-apply-recurrence">Apply Recurrence</button></div></div><p id="acal-recurrence-message" style="display:none;"></p></div><div id="acal-calendar"></div><div id="acal-notes-modal" style="display: none;"><div class="acal-modal-content"><span id="acal-modal-close">×</span><h3 id="acal-modal-title"></h3><p><strong>Available:</strong> <span id="acal-modal-users"></span></p><textarea id="acal-modal-notes" placeholder="Add notes for this time slot..."></textarea><div id="acal-modal-footer"><button id="acal-modal-save">Save Notes</button><button id="acal-modal-delete" class="delete">Delete</button></div><p id="acal-modal-message" style="display:none;"></p></div></div></div><?php }

// --- 5. INLINE CSS & JAVASCRIPT ---
function acal_print_inline_css() { $options = get_option('acal_plugin_options'); $my_color = $options['acal_my_slot_color'] ?? '#3a87ad'; $other_color = $options['acal_other_slot_color'] ?? '#888888'; ?><style>#acal-access-container{max-width:400px;margin:40px auto;padding:20px;border:1px solid #ddd;border-radius:5px;text-align:center}#acal-access-container input[type=text]{width:100%;padding:10px;margin-bottom:10px;box-sizing:border-box}#acal-access-container button{width:100%;padding:10px;background-color:#2ea2cc;color:#fff;border:none;cursor:pointer}#acal-access-container .acal-error{color:#d9534f;background-color:#f2dede;padding:10px;border:1px solid #ebccd1;border-radius:4px;margin-bottom:10px}#acal-calendar-wrapper{margin:20px 0}.acal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:15px}.acal-user-info{text-align:right}#acal-calendar{transition:opacity .2s ease-in-out}#acal-recurrence-controls{border:1px solid #ccc;padding:15px;margin-bottom:20px;border-radius:4px}#acal-recurrence-controls h3{margin-top:0}.acal-recurrence-row{display:flex;gap:20px;align-items:flex-end;flex-wrap:wrap}.acal-recurrence-field{display:flex;flex-direction:column}#acal-recurrence-field label{font-weight:bold;margin-bottom:5px}.fc-event.acal-is-mine{background-color:<?php echo esc_attr($my_color);?>!important;border-color:<?php echo esc_attr($my_color);?>!important}.fc-event.acal-is-other{background-color:<?php echo esc_attr($other_color);?>!important;border-color:<?php echo esc_attr($other_color);?>!important}.fc-event-main-frame{display:flex;flex-direction:column;height:100%;}.fc-event-title-container{display:flex;align-items:center;flex-shrink:0;}.fc-event-notes-preview{flex-grow:1;white-space:normal;font-size:0.8em;opacity:0.8;overflow:hidden;margin-top:2px}.acal-recurring-icon,.acal-note-icon{margin-left:5px;font-style:normal;}.acal-icon-img{width:14px;height:14px;margin-left:5px;vertical-align:middle;}#acal-notes-modal{display:none;position:fixed;z-index:1000;left:0;top:0;width:100%;height:100%;overflow:auto;background-color:rgba(0,0,0,0.5)}.acal-modal-content{background-color:#fefefe;margin:15% auto;padding:20px;border:1px solid #888;width:80%;max-width:500px;border-radius:5px;color:#333}.acal-modal-content h3{color:#333}.acal-modal-content p{color:#333}.acal-modal-content textarea{width:100%;height:100px;margin:10px 0;padding:5px;box-sizing:border-box;resize:vertical;color:#333}#acal-modal-close{color:#aaa;float:right;font-size:28px;font-weight:bold;cursor:pointer}#acal-modal-footer{display:flex;justify-content:space-between}#acal-modal-footer button.delete{background-color:#d9534f}</style><?php }
function acal_print_inline_js() {
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof FullCalendar === 'undefined' || !document.getElementById('acal-calendar')) return;
        const calendarEl = document.getElementById('acal-calendar');
        const timezoneSelect = document.getElementById('acal-timezone-select');
        let calendar; let lastSavedSlotId = null; 
        
        // ** JS CHANGE: The list of timezones is now provided by the server **
        const timezones = ACAL_VARS.timezones || ['UTC']; // Fallback to UTC
        const userTz = Intl.DateTimeFormat().resolvedOptions().timeZone;
        
        // Populate dropdown from the server-provided list
        timezones.forEach(tz => { const option = new Option(tz.replace(/_/g, ' '), tz); timezoneSelect.add(option); });
        
        // Try to select the user's auto-detected timezone if it exists in the list
        if (timezones.includes(userTz)) { timezoneSelect.value = userTz; } 
        else { timezoneSelect.value = 'UTC'; } // Default to UTC if not found
        
        function initializeCalendar(displayTimezone) {
            if (calendar) { calendar.destroy(); }
            calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'timeGridWeek', firstDay: 1, headerToolbar: { left: 'prev,next today', center: 'title', right: '' },
                weekends: true, allDaySlot: false, selectable: true, selectMirror: true,
                slotMinTime: '00:00:00', slotMaxTime: '24:00:00', slotDuration: '00:30:00',
                aspectRatio: 1.5, timeZone: displayTimezone, 
                events: function(fetchInfo, successCallback, failureCallback) {
                    const formData = new FormData();
                    formData.append('action', 'acal_get_availability'); formData.append('nonce', ACAL_VARS.nonce); formData.append('viewer_timezone', displayTimezone);
                    fetch(ACAL_VARS.ajax_url, { method: 'POST', body: formData }).then(response => response.json()).then(data => {
                        if (data.success) {
                            const events = data.data.events.map(event => ({ id: event.id, title: event.title, start: event.start, end: event.end, extendedProps: event.extendedProps, className: event.extendedProps.is_mine ? 'acal-is-mine' : 'acal-is-other' }));
                            document.getElementById('acal-user-label-display').textContent = data.data.current_user_label;
                            successCallback(events);
                        } else { failureCallback(new Error(data.data.message)); alert('Error: ' + data.data.message); }
                    });
                },
                eventDidMount: function(info) { if (info.event.extendedProps.notes) { info.el.setAttribute('title', info.event.extendedProps.notes); } },
                eventContent: function(arg) {
                    let recurIcon = ''; let noteIcon = ''; let notePreview = '';
                    if (arg.event.extendedProps.is_recurring) { recurIcon = ACAL_VARS.recurrence_icon_url ? `<img src="${ACAL_VARS.recurrence_icon_url}" class="acal-icon-img" alt="Recurring">` : '<i class="acal-recurring-icon">🔄</i>'; }
                    if (arg.event.extendedProps.has_notes) { noteIcon = ACAL_VARS.note_icon_url ? `<img src="${ACAL_VARS.note_icon_url}" class="acal-icon-img" alt="Note">` : '<i class="acal-note-icon">📝</i>'; }
                    if (arg.event.extendedProps.notes) { let preview = arg.event.extendedProps.notes; if (preview.length > 25) { preview = preview.substring(0, 25) + '...'; } notePreview = `<div class="fc-event-notes-preview">${preview}</div>`; }
                    return { html: `<div class="fc-event-main-frame"><div class="fc-event-title-container"><b>${arg.event.title}</b>${recurIcon}${noteIcon}</div>${notePreview}</div>` };
                },
                select: function(info) { sendSaveRequest(info.start, info.end, timezoneSelect.value); },
                eventClick: function(info) { openNotesModal(info.event); }
            });
            calendar.render();
        }
        
        initializeCalendar(timezoneSelect.value);
        timezoneSelect.addEventListener('change', function() { initializeCalendar(this.value); });

        function sendSaveRequest(startObj, endObj, timezone) {
            calendarEl.style.opacity = '0.5'; const formData = new FormData();
            formData.append('action', 'acal_save_availability'); formData.append('nonce', ACAL_VARS.nonce);
            formData.append('start_time_local', startObj.toISOString().slice(0, 19).replace('T', ' '));
            formData.append('end_time_local', endObj.toISOString().slice(0, 19).replace('T', ' '));
            formData.append('timezone', timezone);
            fetch(ACAL_VARS.ajax_url, { method: 'POST', body: formData }).then(response => response.json()).then(data => { 
                if (data.success) { lastSavedSlotId = data.data.new_slot_id; showRecurrenceControls(startObj); calendar.refetchEvents(); } 
                else { alert('Error: ' + data.data.message); calendar.unselect(); } 
            }).finally(() => { calendarEl.style.opacity = '1'; });
        }

        function sendDeleteRequest(id, type) {
            calendarEl.style.opacity = '0.5'; lastSavedSlotId = null; hideRecurrenceControls();
            const formData = new FormData();
            formData.append('action', 'acal_delete_availability'); formData.append('nonce', ACAL_VARS.nonce);
            formData.append('id', id); formData.append('type', type);
            fetch(ACAL_VARS.ajax_url, { method: 'POST', body: formData }).then(response => response.json()).then(data => { 
                if (data.success) { closeModal(); calendar.refetchEvents(); } else { alert('Error: ' + data.data.message); } 
            }).finally(() => { calendarEl.style.opacity = '1'; });
        }

        const recurControls=document.getElementById('acal-recurrence-controls'), recurStartInput=document.getElementById('acal-recur-start'), recurEndInput=document.getElementById('acal-recur-end'), recurButton=document.getElementById('acal-apply-recurrence'), recurMsg=document.getElementById('acal-recurrence-message');
        function showRecurrenceControls(startDate) { if (!lastSavedSlotId) return; const nextDay = new Date(startDate); nextDay.setDate(startDate.getDate() + 1); const startDateString = nextDay.toISOString().slice(0, 10); recurStartInput.value = startDateString; recurStartInput.min = startDateString; const endDate = new Date(nextDay); endDate.setMonth(endDate.getMonth() + 1); recurEndInput.value = endDate.toISOString().slice(0, 10); recurEndInput.min = startDateString; recurControls.style.display = 'block'; }
        function hideRecurrenceControls() { recurControls.style.display = 'none'; recurMsg.style.display = 'none'; }
        recurButton.addEventListener('click', function() {
            if (!lastSavedSlotId) { alert('An error occurred. Please try selecting the time slot again.'); return; }
            this.disabled = true; this.textContent = 'Applying...'; recurMsg.textContent = ''; recurMsg.style.display = 'none';
            const formData = new FormData();
            formData.append('action', 'acal_apply_recurrence'); formData.append('nonce', ACAL_VARS.nonce);
            formData.append('master_slot_id', lastSavedSlotId);
            formData.append('recur_start_date', recurStartInput.value);
            formData.append('recur_end_date', recurEndInput.value);
            formData.append('recur_frequency', document.getElementById('acal-recur-frequency').value);
            fetch(ACAL_VARS.ajax_url, { method: 'POST', body: formData }).then(response => response.json()).then(data => {
                if (data.success) { recurMsg.textContent = data.data.message; recurMsg.style.color = 'green'; calendar.refetchEvents(); } 
                else { recurMsg.textContent = 'Error: ' + data.data.message; recurMsg.style.color = 'red'; }
                recurMsg.style.display = 'block';
            }).finally(() => { this.disabled = false; this.textContent = 'Apply Recurrence'; lastSavedSlotId = null; setTimeout(hideRecurrenceControls, 4000); });
        });

        const modal=document.getElementById('acal-notes-modal'), modalTitle=document.getElementById('acal-modal-title'), modalUsers=document.getElementById('acal-modal-users'), modalNotes=document.getElementById('acal-modal-notes'), modalSave=document.getElementById('acal-modal-save'), modalDelete=document.getElementById('acal-modal-delete'), modalClose=document.getElementById('acal-modal-close');
        function openNotesModal(event) {
            modalTitle.textContent = event.start.toLocaleString(undefined, { weekday: 'long', month: 'long', day: 'numeric', hour: 'numeric', minute: '2-digit' });
            modalUsers.textContent = event.title;
            modalNotes.value = event.extendedProps.my_notes || '';
            modalNotes.disabled = !event.extendedProps.is_mine;
            modalSave.style.display = event.extendedProps.is_mine ? 'block' : 'none';
            modalDelete.style.display = event.extendedProps.is_mine ? 'block' : 'none';
            modal.dataset.slotId = event.extendedProps.my_slot_id;
            modal.dataset.seriesId = event.extendedProps.my_series_id;
            modal.dataset.isRecurring = event.extendedProps.is_recurring;
            modal.dataset.fullTitle = event.title;
            modal.style.display = 'block';
        }
        function closeModal() { modal.style.display = 'none'; }
        modalClose.onclick = closeModal; window.onclick = function(event) { if (event.target == modal) { closeModal(); } }
        modalSave.onclick = function() {
            const formData = new FormData();
            formData.append('action', 'acal_save_note');
            formData.append('nonce', ACAL_VARS.nonce);
            formData.append('slot_id', modal.dataset.slotId);
            formData.append('notes', modalNotes.value);
            fetch(ACAL_VARS.ajax_url, { method: 'POST', body: formData }).then(r => r.json()).then(data => {
                if (data.success) { closeModal(); calendar.refetchEvents(); }
                else { alert('Error: ' + data.data.message); }
            });
        };
        modalDelete.onclick = function() {
            const currentUserLabel = document.getElementById('acal-user-label-display').textContent || 'your';
            if (modal.dataset.isRecurring === 'true') {
                const userChoice = prompt('This is a recurring event for ' + modal.dataset.fullTitle + '.\n\nType "one" to delete only this instance.\nType "all" to delete the entire series.', 'one');
                if (userChoice === 'one') { sendDeleteRequest(modal.dataset.slotId, 'single'); } 
                else if (userChoice === 'all') { sendDeleteRequest(modal.dataset.seriesId, 'series'); }
            } else {
                if (confirm('Do you want to remove ' + currentUserLabel + ' availability for this time slot?')) { 
                    sendDeleteRequest(modal.dataset.slotId, 'single'); 
                }
            }
        };
    });
    </script>
    <?php
}

// --- 6. AJAX HANDLERS ---
function acal_get_current_user_info() { global $wpdb; if (empty($_SESSION['acal_access_code'])) return null; $code = sanitize_text_field($_SESSION['acal_access_code']); return $wpdb->get_row($wpdb->prepare("SELECT id, user_label FROM {$wpdb->prefix}availability_codes WHERE access_code = %s", $code)); }
add_action('wp_ajax_nopriv_acal_get_availability', 'acal_ajax_get_availability'); 
add_action('wp_ajax_acal_get_availability', 'acal_ajax_get_availability');
function acal_ajax_get_availability() {
    check_ajax_referer('acal_ajax_nonce', 'nonce');
    $current_user = acal_get_current_user_info();
    if (!$current_user) { wp_send_json_error(['message' => 'Authentication error.']); }
    $viewer_timezone_str = sanitize_text_field($_POST['viewer_timezone']);
    global $wpdb;
    $results = $wpdb->get_results("SELECT s.id, s.start_time_utc, s.end_time_utc, s.series_id, s.notes, c.user_label, c.id as code_id FROM {$wpdb->prefix}availability_slots s JOIN {$wpdb->prefix}availability_codes c ON s.code_id = c.id ORDER BY s.start_time_utc, c.user_label");
    $viewer_offset_str = acal_get_fixed_offset($viewer_timezone_str);
    if ($viewer_offset_str === null) { wp_send_json_error(['message' => 'Invalid viewer timezone.']); return; }
    try { $viewer_tz = new DateTimeZone($viewer_offset_str); } catch (Exception $e) { wp_send_json_error(['message' => 'Could not process viewer timezone.']); return; }
    $grouped_events = [];
    foreach ($results as $row) { $key = $row->start_time_utc . '|' . $row->end_time_utc; if (!isset($grouped_events[$key])) { $grouped_events[$key] = ['start_utc' => $row->start_time_utc, 'end_utc' => $row->end_time_utc, 'users' => [] ]; } $grouped_events[$key]['users'][] = ['id' => $row->id, 'code_id' => $row->code_id, 'label' => $row->user_label, 'series_id' => $row->series_id, 'notes' => $row->notes]; }
    $events = [];
    foreach ($grouped_events as $key => $group) {
        try {
            $utc_tz = new DateTimeZone('UTC'); $start_dt = new DateTime($group['start_utc'], $utc_tz); $end_dt = new DateTime($group['end_utc'], $utc_tz);
            $start_dt->setTimezone($viewer_tz); $end_dt->setTimezone($viewer_tz);
            $user_labels = []; $my_slot_id = null; $my_series_id = null; $all_notes = ''; $my_notes = ''; $is_mine = false; $is_recurring = false;
            foreach ($group['users'] as $user) {
                $user_labels[] = $user['label'];
                if ($user['code_id'] == $current_user->id) { $is_mine = true; $my_slot_id = $user['id']; $my_series_id = $user['series_id']; $my_notes = $user['notes'] ?? ''; }
                if (!empty($user['notes'])) { $all_notes .= $user['label'] . ': ' . $user['notes'] . "\n"; }
                if ($user['series_id']) { $is_recurring = true; }
            }
            $events[] = [ 
                'id'    => $key, 'title' => implode(', ', $user_labels), 'start' => $start_dt->format('Y-m-d\TH:i:s'), 'end' => $end_dt->format('Y-m-d\TH:i:s'), 
                'extendedProps' => ['is_mine' => $is_mine, 'my_slot_id' => $my_slot_id, 'is_recurring' => $is_recurring, 'my_series_id' => $my_series_id, 'notes' => trim($all_notes), 'my_notes' => $my_notes, 'has_notes' => !empty(trim($all_notes))] 
            ];
        } catch (Exception $e) { continue; }
    }
    wp_send_json_success(['events' => $events, 'current_user_label' => $current_user->user_label]);
}
add_action('wp_ajax_nopriv_acal_save_availability', 'acal_ajax_save_availability'); 
add_action('wp_ajax_acal_save_availability', 'acal_ajax_save_availability');
function acal_ajax_save_availability() {
    check_ajax_referer('acal_ajax_nonce', 'nonce');
    $current_user = acal_get_current_user_info();
    if (!$current_user) { wp_send_json_error(['message' => 'Authentication error.']); }
    $start_local_str = sanitize_text_field($_POST['start_time_local']); $end_local_str = sanitize_text_field($_POST['end_time_local']); $submitter_timezone_str = sanitize_text_field($_POST['timezone']);
    $submitter_offset_str = acal_get_fixed_offset($submitter_timezone_str);
    if ($submitter_offset_str === null) { wp_send_json_error(['message' => 'Invalid submitter timezone.']); return; }
    try { $submitter_tz = new DateTimeZone($submitter_offset_str); $utc_tz = new DateTimeZone('UTC'); $start_dt = new DateTime($start_local_str, $submitter_tz); $end_dt = new DateTime($end_local_str, $submitter_tz); $start_dt->setTimezone($utc_tz); $end_dt->setTimezone($utc_tz); $start_utc_mysql = $start_dt->format('Y-m-d H:i:s'); $end_utc_mysql = $end_dt->format('Y-m-d H:i:s'); } catch (Exception $e) { wp_send_json_error(['message' => 'Could not process submitted time.']); return; }
    global $wpdb;
    $slots_table = $wpdb->prefix . 'availability_slots';
    $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $slots_table WHERE code_id = %d AND start_time_utc = %s AND end_time_utc = %s", $current_user->id, $start_utc_mysql, $end_utc_mysql));
    if ($existing) { wp_send_json_error(['message' => 'You have already selected this time slot.']); return; }
    $wpdb->insert($slots_table, ['code_id' => $current_user->id, 'start_time_utc' => $start_utc_mysql, 'end_time_utc' => $end_utc_mysql, 'selection_timezone' => $submitter_timezone_str], ['%d', '%s', '%s', '%s']);
    wp_send_json_success(['message' => 'Slot saved.', 'new_slot_id' => $wpdb->insert_id]);
}
add_action('wp_ajax_nopriv_acal_delete_availability', 'acal_ajax_delete_availability'); 
add_action('wp_ajax_acal_delete_availability', 'acal_ajax_delete_availability');
function acal_ajax_delete_availability() {
    check_ajax_referer('acal_ajax_nonce', 'nonce');
    $current_user = acal_get_current_user_info();
    if (!$current_user) { wp_send_json_error(['message' => 'Authentication error.']); }
    $id = sanitize_text_field($_POST['id']); $type = sanitize_text_field($_POST['type']);
    if (empty($id) || !in_array($type, ['single', 'series'])) { wp_send_json_error(['message' => 'Invalid deletion request.']); }
    global $wpdb; $slots_table = $wpdb->prefix . 'availability_slots';
    $where = []; $where_format = [];
    if ($type === 'single') { $where['id'] = absint($id); $where_format[] = '%d'; } 
    else { $where['series_id'] = $id; $where_format[] = '%s'; }
    $where['code_id'] = $current_user->id; $where_format[] = '%d';
    $deleted = $wpdb->delete($slots_table, $where, $where_format);
    if ($deleted !== false) { wp_send_json_success(['message' => 'Slot(s) removed.']); } 
    else { wp_send_json_error(['message' => 'Could not remove slot(s).']); }
}
add_action('wp_ajax_nopriv_acal_apply_recurrence', 'acal_apply_recurrence');
add_action('wp_ajax_acal_apply_recurrence', 'acal_apply_recurrence');
function acal_apply_recurrence() {
    check_ajax_referer('acal_ajax_nonce', 'nonce');
    $current_user = acal_get_current_user_info();
    if (!$current_user) { wp_send_json_error(['message' => 'Authentication error.']); }
    $master_slot_id = absint($_POST['master_slot_id']);
    if (!$master_slot_id) { wp_send_json_error(['message' => 'Invalid master event ID.']); }
    global $wpdb; $slots_table = $wpdb->prefix . 'availability_slots';
    $master_event = $wpdb->get_row($wpdb->prepare("SELECT * FROM $slots_table WHERE id = %d AND code_id = %d", $master_slot_id, $current_user->id));
    if (!$master_event) { wp_send_json_error(['message' => 'Master event not found.']); }
    $recur_start_date = sanitize_text_field($_POST['recur_start_date']); $recur_end_date = sanitize_text_field($_POST['recur_end_date']); $frequency = sanitize_text_field($_POST['recur_frequency']);
    $master_offset_str = acal_get_fixed_offset($master_event->selection_timezone);
    if ($master_offset_str === null) { wp_send_json_error(['message' => 'Invalid master timezone.']); }
    $slots_created = 0; $series_id = md5(uniqid(rand(), true));
    try {
        $master_tz = new DateTimeZone($master_offset_str); $utc_tz = new DateTimeZone('UTC');
        $master_start_utc = new DateTime($master_event->start_time_utc, $utc_tz);
        $duration_seconds = (new DateTime($master_event->end_time_utc, $utc_tz))->getTimestamp() - $master_start_utc->getTimestamp();
        $master_start_local = (clone $master_start_utc)->setTimezone($master_tz);
        $day_of_week_name = $master_start_local->format('l');
        $week_of_month = floor(($master_start_local->format('d') - 1) / 7) + 1;
        $ordinal = ($week_of_month == 1) ? 'first' : (($week_of_month == 2) ? 'second' : (($week_of_month == 3) ? 'third' : (($week_of_month == 4) ? 'fourth' : 'fifth')));
        $current_date = new DateTime($recur_start_date, $master_tz); $end_date_limit = new DateTime($recur_end_date, $master_tz); $end_date_limit->setTime(23, 59, 59);
        $month_interval = 1;
        if (strpos($frequency, 'monthly-') === 0) { $month_interval = (int) substr($frequency, 8); $frequency = 'monthly'; }
        $loop_count = 0;
        while ($current_date <= $end_date_limit && $loop_count < 200) {
            $loop_count++; $target_dt = null;
            if ($frequency === 'weekly' && $current_date->format('l') === $day_of_week_name) { $target_dt = clone $current_date; } 
            elseif ($frequency === 'biweekly' && $current_date->format('l') === $day_of_week_name) { $target_dt = clone $current_date; } 
            elseif ($frequency === 'monthly') { if (($current_date->format('n') - 1) % $month_interval === 0) { $target_date_str = "$ordinal $day_of_week_name of " . $current_date->format('F Y'); $temp_dt = new DateTime($target_date_str, $master_tz); if ($temp_dt->format('Y-m') === $current_date->format('Y-m')) { $target_dt = $temp_dt; } } }
            if ($target_dt) {
                $new_start_dt = new DateTime($target_dt->format('Y-m-d') . ' ' . $master_start_local->format('H:i:s'), $master_tz);
                $new_start_dt->setTimezone($utc_tz);
                $start_utc_mysql = $new_start_dt->format('Y-m-d H:i:s');
                $end_utc_mysql = (clone $new_start_dt)->setTimestamp($new_start_dt->getTimestamp() + $duration_seconds)->format('Y-m-d H:i:s');
                $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $slots_table WHERE code_id = %d AND start_time_utc = %s AND end_time_utc = %s", $current_user->id, $start_utc_mysql, $end_utc_mysql));
                if (!$existing) {
                    $wpdb->insert($slots_table, ['code_id' => $current_user->id, 'start_time_utc' => $start_utc_mysql, 'end_time_utc' => $end_utc_mysql, 'selection_timezone' => $master_event->selection_timezone, 'series_id' => $series_id, 'notes' => $master_event->notes], ['%d', '%s', '%s', '%s', '%s', '%s']);
                    $slots_created++;
                }
            }
            if ($frequency === 'weekly') $current_date->modify('+1 day');
            elseif ($frequency === 'biweekly') $current_date->modify($current_date->format('l') === $day_of_week_name ? '+14 days' : '+1 day');
            elseif ($frequency === 'monthly') $current_date->modify('first day of next month');
            else break;
        }
        if ($slots_created > 0) { $wpdb->update($slots_table, ['series_id' => $series_id], ['id' => $master_slot_id], ['%s'], ['%d']); }
    } catch(Exception $e) { wp_send_json_error(['message' => 'An error occurred during date calculation: ' . $e->getMessage()]); }
    wp_send_json_success(['message' => "Recurrence applied. $slots_created new slot(s) were created."]);
}

add_action('wp_ajax_nopriv_acal_save_note', 'acal_ajax_save_note');
add_action('wp_ajax_acal_save_note', 'acal_ajax_save_note');
function acal_ajax_save_note() {
    check_ajax_referer('acal_ajax_nonce', 'nonce');
    $current_user = acal_get_current_user_info();
    if (!$current_user) { wp_send_json_error(['message' => 'Authentication error.']); }
    $slot_id = absint($_POST['slot_id']);
    $notes = sanitize_textarea_field($_POST['notes']);
    global $wpdb;
    $updated = $wpdb->update( $wpdb->prefix . 'availability_slots', ['notes' => $notes], ['id' => $slot_id, 'code_id' => $current_user->id], ['%s'], ['%d', '%d']);
    if ($updated !== false) { wp_send_json_success(['message' => 'Note saved.']); }
    else { wp_send_json_error(['message' => 'Could not save note. Ensure you have permission.']); }
}

// --- 7. HELPER FUNCTIONS ---
function acal_validate_access_code($code) { if (empty($code)) return false; global $wpdb; return $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}availability_codes WHERE access_code = %s", $code)) > 0; }