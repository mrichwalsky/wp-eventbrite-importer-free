<?php
/**
 * Plugin Name: Eventbrite Events Sync
 * Description: Sync Eventbrite organizer events into a custom post type and render with shortcode.
 * Version: 1.0.0
 * Author: Gas Mark 8
 * Author URI: https://gasmark8.com
 * License: GPL-3.0
 */

if (! defined('ABSPATH')) {
	exit;
}

class Eventbrite_Events_Sync_Plugin {
	const OPTION_KEY = 'ebes_settings';
	const CRON_HOOK  = 'ebes_hourly_sync';
	const CPT        = 'eventbriteevent';
	const CACHE_VERSION_OPTION = 'ebes_shortcode_cache_version';
	const SYNC_LOCK_TRANSIENT  = 'ebes_sync_lock';
	const SYNC_LOCK_TTL        = 300;
	const SHORTCODE_CACHE_TTL  = 300;

	public function __construct() {
		add_action('init', array($this, 'register_cpt'));
		add_filter('cron_schedules', array($this, 'register_cron_schedules'));
		add_action('init', array($this, 'ensure_cron_event'));
		add_action('wp_enqueue_scripts', array($this, 'register_assets'));
		add_action('admin_init', array($this, 'register_settings'));
		add_action('admin_menu', array($this, 'add_settings_page'));
		add_action('add_meta_boxes', array($this, 'add_event_meta_box'));
		add_action('save_post_' . self::CPT, array($this, 'save_event_meta_box'));
		add_action('save_post_' . self::CPT, array($this, 'bump_shortcode_cache_version'));
		add_filter('manage_edit-' . self::CPT . '_columns', array($this, 'filter_event_columns'));
		add_action('manage_' . self::CPT . '_posts_custom_column', array($this, 'render_event_columns'), 10, 2);
		add_action('admin_post_ebes_manual_sync', array($this, 'handle_manual_sync'));
		add_action(self::CRON_HOOK, array($this, 'sync_events'));
		add_shortcode('eb_upcoming_events', array($this, 'shortcode_upcoming_events'));
	}

	public static function activate() {
		$instance = new self();
		$instance->register_cpt();
		$instance->register_cron_schedules(array());
		flush_rewrite_rules();

		if (! wp_next_scheduled(self::CRON_HOOK)) {
			wp_schedule_event(time() + 300, 'ebes_12_hours', self::CRON_HOOK);
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook(self::CRON_HOOK);
		flush_rewrite_rules();
	}

	public function register_cpt() {
		$labels = array(
			'name'               => 'Eventbrite Events',
			'singular_name'      => 'Eventbrite Event',
			'add_new'            => 'Add New',
			'add_new_item'       => 'Add New Eventbrite Event',
			'edit_item'          => 'Edit Eventbrite Event',
			'new_item'           => 'New Eventbrite Event',
			'view_item'          => 'View Eventbrite Event',
			'search_items'       => 'Search Eventbrite Events',
			'not_found'          => 'No Eventbrite Events found',
			'not_found_in_trash' => 'No Eventbrite Events found in Trash',
			'all_items'          => 'All Eventbrite Events',
			'menu_name'          => 'Eventbrite Events',
		);

		$args = array(
			'labels'             => $labels,
			'public'             => true,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_in_rest'       => true,
			'has_archive'        => true,
			'rewrite'            => array('slug' => 'eventbrite-events'),
			'supports'           => array('title', 'editor', 'excerpt', 'thumbnail'),
			'menu_icon'          => 'dashicons-calendar-alt',
			'capability_type'    => 'post',
		);

		register_post_type(self::CPT, $args);
	}

	public function register_assets() {
		wp_register_style(
			'ebes-frontend',
			plugin_dir_url(__FILE__) . 'assets/css/eventbrite-events-sync.css',
			array(),
			'1.0.0'
		);
	}

	public function register_cron_schedules($schedules) {
		if (! isset($schedules['ebes_12_hours'])) {
			$schedules['ebes_12_hours'] = array(
				'interval' => 12 * HOUR_IN_SECONDS,
				'display'  => 'Every 12 Hours',
			);
		}
		return $schedules;
	}

	public function ensure_cron_event() {
		$event = wp_get_scheduled_event(self::CRON_HOOK);
		if (! $event) {
			wp_schedule_event(time() + 300, 'ebes_12_hours', self::CRON_HOOK);
			return;
		}

		if ($event->schedule !== 'ebes_12_hours') {
			wp_clear_scheduled_hook(self::CRON_HOOK);
			wp_schedule_event(time() + 300, 'ebes_12_hours', self::CRON_HOOK);
		}
	}

	public function register_settings() {
		register_setting(
			'ebes_settings_group',
			self::OPTION_KEY,
			array($this, 'sanitize_settings')
		);

		add_settings_section(
			'ebes_api_section',
			'Eventbrite API Settings',
			function () {
				echo '<p>Provide your Eventbrite token and organizer ID.</p>';
			},
			'ebes-settings'
		);

		add_settings_field(
			'api_token',
			'API Token',
			array($this, 'render_api_token_field'),
			'ebes-settings',
			'ebes_api_section'
		);

		add_settings_field(
			'organizer_id',
			'Organizer ID',
			array($this, 'render_organizer_id_field'),
			'ebes-settings',
			'ebes_api_section'
		);

		add_settings_field(
			'events_limit',
			'Events Limit',
			array($this, 'render_events_limit_field'),
			'ebes-settings',
			'ebes_api_section'
		);

		add_settings_field(
			'skip_existing_titles',
			'Skip Existing Event Titles',
			array($this, 'render_skip_existing_titles_field'),
			'ebes-settings',
			'ebes_api_section'
		);
	}

	public function sanitize_settings($input) {
		$output = array();
		$output['api_token']    = isset($input['api_token']) ? preg_replace('/[^A-Za-z0-9]/', '', (string) $input['api_token']) : '';
		$output['organizer_id'] = isset($input['organizer_id']) ? preg_replace('/[^0-9]/', '', (string) $input['organizer_id']) : '';
		$output['events_limit'] = isset($input['events_limit']) ? max(1, min(1000, intval($input['events_limit']))) : 100;
		$output['skip_existing_titles'] = ! empty($input['skip_existing_titles']) ? 1 : 0;
		return $output;
	}

	public function add_settings_page() {
		add_options_page(
			'Eventbrite Events Sync',
			'Eventbrite Events',
			'manage_options',
			'ebes-settings',
			array($this, 'render_settings_page')
		);
	}

	public function render_settings_page() {
		if (! current_user_can('manage_options')) {
			return;
		}

		$sync_url = wp_nonce_url(
			admin_url('admin-post.php?action=ebes_manual_sync'),
			'ebes_manual_sync'
		);

		?>
		<div class="wrap">
			<h1>Eventbrite Events Sync</h1>
			<form action="options.php" method="post">
				<?php
				settings_fields('ebes_settings_group');
				do_settings_sections('ebes-settings');
				submit_button('Save Settings');
				?>
			</form>

			<hr />
			<h2>Manual Sync</h2>
			<p>Run a sync immediately to import or update events.</p>
			<p><a class="button button-primary" href="<?php echo esc_url($sync_url); ?>">Run Sync Now</a></p>
		</div>
		<?php
	}

	private function get_settings() {
		$defaults = array(
			'api_token'    => '',
			'organizer_id' => '',
			'events_limit' => 20,
			'skip_existing_titles' => 0,
		);
		$settings = get_option(self::OPTION_KEY, array());
		return wp_parse_args($settings, $defaults);
	}

	public function render_api_token_field() {
		$settings = $this->get_settings();
		printf(
			'<input type="password" name="%1$s[api_token]" value="%2$s" class="regular-text" autocomplete="off" />',
			esc_attr(self::OPTION_KEY),
			esc_attr($settings['api_token'])
		);
	}

	public function render_organizer_id_field() {
		$settings = $this->get_settings();
		printf(
			'<input type="text" name="%1$s[organizer_id]" value="%2$s" class="regular-text" />',
			esc_attr(self::OPTION_KEY),
			esc_attr($settings['organizer_id'])
		);
	}

	public function render_events_limit_field() {
		$settings = $this->get_settings();
		printf(
			'<input type="number" min="1" max="1000" name="%1$s[events_limit]" value="%2$s" class="small-text" />',
			esc_attr(self::OPTION_KEY),
			esc_attr($settings['events_limit'])
		);
	}

	public function render_skip_existing_titles_field() {
		$settings = $this->get_settings();
		printf(
			'<label><input type="checkbox" name="%1$s[skip_existing_titles]" value="1" %2$s /> %3$s</label>',
			esc_attr(self::OPTION_KEY),
			checked(1, intval($settings['skip_existing_titles']), false),
			esc_html__('If an Eventbrite Event post already exists with the same title, skip updating/importing that event.', 'eventbrite-events-sync')
		);
	}

	public function handle_manual_sync() {
		if (! current_user_can('manage_options')) {
			wp_die('Unauthorized.');
		}

		check_admin_referer('ebes_manual_sync');
		$result = $this->sync_events();

		$redirect = admin_url('options-general.php?page=ebes-settings');
		if (is_wp_error($result)) {
			$redirect = add_query_arg('ebes_error', rawurlencode($result->get_error_message()), $redirect);
		} else {
			$redirect = add_query_arg('ebes_synced', intval($result), $redirect);
		}

		wp_safe_redirect($redirect);
		exit;
	}

	public function sync_events() {
		if (get_transient(self::SYNC_LOCK_TRANSIENT)) {
			return new WP_Error('ebes_sync_locked', 'A sync is already running. Please wait and retry.');
		}
		set_transient(self::SYNC_LOCK_TRANSIENT, 1, self::SYNC_LOCK_TTL);

		$settings = $this->get_settings();
		$token = $settings['api_token'];
		$organizer_id = $settings['organizer_id'];
		$limit = intval($settings['events_limit']);

		if (empty($token) || empty($organizer_id)) {
			delete_transient(self::SYNC_LOCK_TRANSIENT);
			return new WP_Error('ebes_missing_config', 'Missing Eventbrite API token or organizer ID.');
		}

		$all_events = array();
		$page = 1;
		$max_pages = 50;

		while (count($all_events) < $limit && $page <= $max_pages) {
			$endpoint = sprintf(
				'https://www.eventbriteapi.com/v3/organizers/%s/events/?status=live&order_by=start_asc&page=%d',
				rawurlencode($organizer_id),
				$page
			);

			$response = wp_remote_get(
				$endpoint,
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
					),
					'timeout' => 25,
				)
			);

			if (is_wp_error($response)) {
				delete_transient(self::SYNC_LOCK_TRANSIENT);
				return $response;
			}

			$code = wp_remote_retrieve_response_code($response);
			$body = wp_remote_retrieve_body($response);
			$data = json_decode($body, true);

			if ($code < 200 || $code >= 300) {
				$message = isset($data['error_description']) ? $data['error_description'] : 'Eventbrite API request failed.';
				delete_transient(self::SYNC_LOCK_TRANSIENT);
				return new WP_Error('ebes_api_error', $message);
			}

			if (! isset($data['events']) || ! is_array($data['events'])) {
				delete_transient(self::SYNC_LOCK_TRANSIENT);
				return new WP_Error('ebes_bad_payload', 'Unexpected API response structure.');
			}

			if (empty($data['events'])) {
				break;
			}

			$all_events = array_merge($all_events, $data['events']);

			$has_more = isset($data['pagination']['has_more_items']) ? (bool) $data['pagination']['has_more_items'] : false;
			if (! $has_more) {
				break;
			}

			$page++;
		}

		$updated = 0;
		foreach (array_slice($all_events, 0, $limit) as $event) {
			$result = $this->upsert_event_post($event);
			if (! is_wp_error($result)) {
				$updated++;
			}
		}

		$this->bump_shortcode_cache_version();
		delete_transient(self::SYNC_LOCK_TRANSIENT);
		return $updated;
	}

	private function upsert_event_post($event) {
		$event_id = isset($event['id']) ? sanitize_text_field($event['id']) : '';
		if (empty($event_id)) {
			return new WP_Error('ebes_event_id_missing', 'Event ID missing.');
		}

		$title = isset($event['name']['text']) && $event['name']['text'] ? $event['name']['text'] : 'Untitled Event';
		$settings = $this->get_settings();
		$description = isset($event['description']['html']) ? wp_kses_post($event['description']['html']) : '';
		$start_local = isset($event['start']['local']) ? sanitize_text_field($event['start']['local']) : '';
		$end_local = isset($event['end']['local']) ? sanitize_text_field($event['end']['local']) : '';
		$url = isset($event['url']) ? esc_url_raw($event['url']) : '';
		$status = isset($event['status']) ? sanitize_text_field($event['status']) : '';
		$currency = isset($event['currency']) ? sanitize_text_field($event['currency']) : '';
		$image_url = '';
		if (isset($event['logo']['original']['url'])) {
			$image_url = esc_url_raw($event['logo']['original']['url']);
		}

		$existing = get_posts(array(
			'post_type'      => self::CPT,
			'post_status'    => 'any',
			'meta_key'       => '_eb_event_id',
			'meta_value'     => $event_id,
			'posts_per_page' => 1,
			'fields'         => 'ids',
		));

		if (! empty($settings['skip_existing_titles'])) {
			$existing_title_post = get_page_by_title($title, OBJECT, self::CPT);
			if ($existing_title_post instanceof WP_Post) {
				if (empty($existing) || intval($existing_title_post->ID) !== intval($existing[0])) {
					return new WP_Error('ebes_skipped_title_exists', 'Skipped: existing event with same title.');
				}
				return new WP_Error('ebes_skipped_title_exists', 'Skipped: title update protection enabled.');
			}
		}

		$postarr = array(
			'post_type'    => self::CPT,
			'post_title'   => wp_strip_all_tags($title),
			'post_content' => $description,
			'post_status'  => 'publish',
		);

		if (! empty($existing)) {
			$postarr['ID'] = intval($existing[0]);
			$post_id = wp_update_post($postarr, true);
		} else {
			$post_id = wp_insert_post($postarr, true);
		}

		if (is_wp_error($post_id)) {
			return $post_id;
		}

		update_post_meta($post_id, '_eb_event_id', $event_id);
		update_post_meta($post_id, '_eb_start_local', $start_local);
		update_post_meta($post_id, '_eb_end_local', $end_local);
		update_post_meta($post_id, '_eb_url', $url);
		update_post_meta($post_id, '_eb_status', $status);
		update_post_meta($post_id, '_eb_currency', $currency);
		update_post_meta($post_id, '_eb_image_url', $image_url);

		return $post_id;
	}

	public function filter_event_columns($columns) {
		$new_columns = array();
		foreach ($columns as $key => $label) {
			$new_columns[$key] = $label;
			if ($key === 'title') {
				$new_columns['eb_start'] = 'Start';
				$new_columns['eb_end'] = 'End';
				$new_columns['eb_status'] = 'Status';
				$new_columns['eb_url'] = 'Event URL';
			}
		}
		return $new_columns;
	}

	public function render_event_columns($column, $post_id) {
		if ($column === 'eb_start') {
			$value = get_post_meta($post_id, '_eb_start_local', true);
			if (! empty($value)) {
				$ts = strtotime($value);
				echo $ts ? esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $ts)) : esc_html($value);
			}
		}

		if ($column === 'eb_end') {
			$value = get_post_meta($post_id, '_eb_end_local', true);
			if (! empty($value)) {
				$ts = strtotime($value);
				echo $ts ? esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $ts)) : esc_html($value);
			}
		}

		if ($column === 'eb_status') {
			echo esc_html(get_post_meta($post_id, '_eb_status', true));
		}

		if ($column === 'eb_url') {
			$url = get_post_meta($post_id, '_eb_url', true);
			if (! empty($url)) {
				echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">View</a>';
			}
		}
	}

	public function add_event_meta_box() {
		add_meta_box(
			'ebes_event_details',
			'Eventbrite Event Details',
			array($this, 'render_event_meta_box'),
			self::CPT,
			'normal',
			'default'
		);
	}

	public function render_event_meta_box($post) {
		wp_nonce_field('ebes_save_event_meta', 'ebes_event_meta_nonce');

		$fields = array(
			'_eb_event_id'    => 'Eventbrite Event ID',
			'_eb_start_local' => 'Start Local Datetime',
			'_eb_end_local'   => 'End Local Datetime',
			'_eb_url'         => 'Event URL',
			'_eb_status'      => 'Status',
			'_eb_currency'    => 'Currency',
			'_eb_image_url'   => 'Image URL',
		);

		echo '<table class="form-table"><tbody>';
		foreach ($fields as $meta_key => $label) {
			$value = get_post_meta($post->ID, $meta_key, true);
			echo '<tr>';
			echo '<th scope="row"><label for="' . esc_attr($meta_key) . '">' . esc_html($label) . '</label></th>';
			echo '<td><input type="text" id="' . esc_attr($meta_key) . '" name="ebes_meta[' . esc_attr($meta_key) . ']" value="' . esc_attr($value) . '" class="regular-text" /></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	public function save_event_meta_box($post_id) {
		if (! isset($_POST['ebes_event_meta_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ebes_event_meta_nonce'])), 'ebes_save_event_meta')) {
			return;
		}

		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		if (! current_user_can('edit_post', $post_id)) {
			return;
		}

		$allowed = array(
			'_eb_event_id',
			'_eb_start_local',
			'_eb_end_local',
			'_eb_url',
			'_eb_status',
			'_eb_currency',
			'_eb_image_url',
		);

		$posted = isset($_POST['ebes_meta']) ? (array) wp_unslash($_POST['ebes_meta']) : array();
		foreach ($allowed as $meta_key) {
			if (! isset($posted[$meta_key])) {
				continue;
			}

			$value = $posted[$meta_key];
			if ($meta_key === '_eb_url' || $meta_key === '_eb_image_url') {
				$value = esc_url_raw($value);
			} else {
				$value = sanitize_text_field($value);
			}

			update_post_meta($post_id, $meta_key, $value);
		}
	}

	public function bump_shortcode_cache_version() {
		update_option(self::CACHE_VERSION_OPTION, (string) time(), false);
	}

	private function get_shortcode_cache_key($atts, $current_page) {
		$version = (string) get_option(self::CACHE_VERSION_OPTION, '1');
		return 'ebes_sc_' . md5(wp_json_encode($atts) . '|' . (string) $current_page . '|' . $version);
	}

	public function shortcode_upcoming_events($atts = array()) {
		$atts = shortcode_atts(
			array(
				'limit' => 10,
				'pagination' => 'yes',
				'page_param' => 'ebes_page',
				'anchor' => 'eb-upcoming-events',
			),
			$atts,
			'eb_upcoming_events'
		);

		$pagination_enabled = strtolower((string) $atts['pagination']) !== 'no';
		$limit = max(1, min(100, intval($atts['limit'])));
		$page_param = sanitize_key($atts['page_param']);
		$anchor = sanitize_html_class($atts['anchor']);
		if (empty($anchor)) {
			$anchor = 'eb-upcoming-events';
		}
		$current_page = 1;
		if ($pagination_enabled && isset($_GET[$page_param])) {
			$current_page = max(1, intval(wp_unslash($_GET[$page_param])));
		}
		$now = current_time('mysql');
		wp_enqueue_style('ebes-frontend');
		$cache_key = $this->get_shortcode_cache_key($atts, $current_page);
		$cached = get_transient($cache_key);
		if (is_string($cached) && $cached !== '') {
			return $cached;
		}

		$query = new WP_Query(array(
			'post_type'      => self::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => $pagination_enabled ? $limit : -1,
			'paged'          => $pagination_enabled ? $current_page : 1,
			'meta_key'       => '_eb_start_local',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'meta_query'     => array(
				array(
					'key'     => '_eb_start_local',
					'value'   => $now,
					'compare' => '>=',
					'type'    => 'DATETIME',
				),
			),
		));

		if (! $query->have_posts()) {
			return '<p>No upcoming events found.</p>';
		}

		ob_start();
		echo '<div id="' . esc_attr($anchor) . '" class="eb-upcoming-events">';
		while ($query->have_posts()) {
			$query->the_post();
			$post_id = get_the_ID();
			$start_local = get_post_meta($post_id, '_eb_start_local', true);
			$event_url = get_post_meta($post_id, '_eb_url', true);
			$image_url = get_post_meta($post_id, '_eb_image_url', true);
			$start_ts = $start_local ? strtotime($start_local) : false;
			$now_ts = current_time('timestamp');
			if (! $start_ts || $start_ts < $now_ts) {
				continue;
			}

			echo '<article class="eb-event">';
			if (! empty($image_url)) {
				echo '<p class="eb-event-image"><img src="' . esc_url($image_url) . '" alt="' . esc_attr(get_the_title()) . '" loading="lazy" /></p>';
			}
			echo '<h3 class="eb-event-title">' . esc_html(get_the_title()) . '</h3>';
			if (! empty($start_local)) {
				if ($start_ts) {
					echo '<p class="eb-event-date">' . esc_html(wp_date(get_option('date_format'), $start_ts)) . '</p>';
				}
			}
			echo '<div class="eb-event-excerpt">' . wp_kses_post(get_the_excerpt()) . '</div>';
			if (! empty($event_url)) {
				echo '<p><a href="' . esc_url($event_url) . '" target="_blank" rel="noopener noreferrer" class="eb-event-button eventbrite-event-click">View Event</a></p>';
			}
			echo '</article>';
		}
		echo '</div>';

		if ($pagination_enabled && $query->max_num_pages > 1) {
			$base = esc_url_raw(add_query_arg($page_param, '%#%'));
			echo '<nav class="eb-event-pagination">';
			echo wp_kses_post(
				paginate_links(array(
					'base'      => $base,
					'format'    => '',
					'current'   => $current_page,
					'total'     => intval($query->max_num_pages),
					'prev_text' => '&laquo; Prev',
					'next_text' => 'Next &raquo;',
					'type'      => 'list',
					'add_fragment' => '#' . $anchor,
				))
			);
			echo '</nav>';
		}

		wp_reset_postdata();

		$html = ob_get_clean();
		set_transient($cache_key, $html, self::SHORTCODE_CACHE_TTL);
		return $html;
	}
}

$eventbrite_events_sync_plugin = new Eventbrite_Events_Sync_Plugin();
register_activation_hook(__FILE__, array('Eventbrite_Events_Sync_Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('Eventbrite_Events_Sync_Plugin', 'deactivate'));

add_action('admin_notices', function () {
	if (! isset($_GET['page']) || $_GET['page'] !== 'ebes-settings') {
		return;
	}

	if (isset($_GET['ebes_error'])) {
		echo '<div class="notice notice-error"><p>Sync failed: ' . esc_html(wp_unslash($_GET['ebes_error'])) . '</p></div>';
	}

	if (isset($_GET['ebes_synced'])) {
		echo '<div class="notice notice-success"><p>Sync complete. Updated ' . intval($_GET['ebes_synced']) . ' events.</p></div>';
	}
});
