<?php
error_reporting(E_ALL);
/*
Plugin Name: Drafts for Friends
Plugin URI: http://automattic.com/
Description: Now you don't need to add friends as users to the blog in order to let them preview your drafts
Author: Neville Longbottom
Version: 2.2
Author URI:
 */

add_action('plugins_loaded', 'wan_load_textdomain');
function wan_load_textdomain() {
	load_plugin_textdomain( 'drafts-for-friends', false, dirname( plugin_basename(__FILE__) ) . '/languages/' );
}

class DraftsForFriends	{

	function __construct() {
		$this->default_purging_delay = 1;
		$this->duplicates_default = true;
		$this->metadata_key = 'draftsforfriends_metadata';
		$this->version = '0.0.0';
		add_action( 'init', array( &$this, 'init' ) );
		add_action( 'admin_init', array( &$this, 'admin_init' ) );
	}

	function admin_init() {
		$this->admin_page_init();
		add_settings_section( 
			'draftsforfriends_settings',
			__( 'Drafts for Friends', 'drafts-for-friends' ),
			array( &$this, 'draftsforfriends_settings_cb' ), 
			'writing'
		);
		register_setting( 'writing', 'draftsforfriends_purge_expired' );
		register_setting( 'writing', 'draftsforfriends_purging_delay' );
		register_setting( 'writing', 'draftsforfriends_allow_duplicates', [ 'default' => true ] );
		add_settings_field(
			'draftsforfriends_purge_expired',
			__( 'Auto-purge expired shares', 'drafts-for-friends' ),
			array( &$this, 'draftsforfriends_purge_expired_cb' ),
			'writing',
			'draftsforfriends_settings'
		);
		add_settings_field(
			'draftsforfriends_purging_delay',
			__( 'Hours to keep expired shares', 'drafts-for-friends' ),
			array( &$this, 'draftsforfriends_purging_delay_cb' ),
			'writing',
			'draftsforfriends_settings'
		);
		add_settings_field(
			'draftsforfriends_allow_duplicates',
			__( 'Allow duplicate shares', 'drafts-for-friends' ),
			array( &$this, 'draftsforfriends_allow_duplicates_cb' ),
			'writing',
			'draftsforfriends_settings'
		);
	}

	function init() {
		global $current_user;
		add_filter( 'cron_schedules', array( &$this, 'add_cron_interval' ) );
		if ( get_option( 'draftsforfriends_purge_expired' ) ) {
			add_action( 'draftsforfriends_cron_hook', array( &$this, 'draftsforfriends_cron' ) );
			$next_purge = wp_next_scheduled( 'draftsforfriends_cron_hook' );
			if ( $next_purge < time() ) {
				wp_unschedule_event( $next_purge, 'draftsforfriends_cron_hook' );
			}
			if ( ! $next_purge ) {
				wp_schedule_event( time(), 'ten_minutes', 'draftsforfriends_cron_hook' );
			}
		} elseif ( wp_next_scheduled( 'draftsforfriends_cron_hook' ) ) {
			$timestamp = wp_next_scheduled( 'draftsforfriends_cron_hook' );
			wp_unschedule_event( $timestamp, 'draftsforfriends_cron_hook' );
		}
		add_action( 'admin_menu', array( $this, 'add_admin_pages' ) );
		add_filter( 'the_posts', array( $this, 'the_posts_intercept' ) );
		add_filter( 'posts_results', array( $this, 'posts_results_intercept' ) );
	}
 
	function add_cron_interval( $schedules ) {
		$schedules['ten_minutes'] = array(
			'interval' => 600,
			'display'  => esc_html__( 'Every ten minutes' ),
		);

		return $schedules;
	}

	function draftsforfriends_cron() {
		$purge_delay_seconds = 3600 * get_option( 'draftsforfriends_purging_delay' );
		$current_seconds = time();
		$shared_posts = $this->get_shared( false );
		foreach( $shared_posts as $post ) {
			$share_meta_collection = get_post_meta( $post->ID, $this->metadata_key );
			foreach ( $share_meta_collection as $share_meta ) {
				if ( $current_seconds >= ( $share_meta['expires'] + $purge_delay_seconds ) ) {
					$this->delete_share_helper( [ 'post_id' => $post->ID, 'key' => $share_meta['key'] ] );
				}
			}
		}
	}

	function draftsforfriends_settings_cb() {
		_e( 'Fine-tune your settings for Drafts for Friends.', 'drafts-for-friends' );
	}

	function draftsforfriends_purge_expired_cb() {
		$setting = get_option( 'draftsforfriends_purge_expired' );
		?>
		<input type="checkbox" name="draftsforfriends_purge_expired" <?php echo ( $setting ) ? 'checked' : ''; ?>/>
		<?php
	}

	function draftsforfriends_purging_delay_cb() {
		$setting = get_option( 'draftsforfriends_purging_delay' );
		?>
		<input type="text" size="1" name="draftsforfriends_purging_delay" value="<?php echo ( isset( $setting) ) ? $setting : '1'; ?>"/>
		<?php
	}

	function draftsforfriends_allow_duplicates_cb() {
		$setting = get_option( 'draftsforfriends_allow_duplicates' );
		?>
		<input type="checkbox" name="draftsforfriends_allow_duplicates" <?php echo ( $setting ) ? 'checked' : ''; ?>/>
		<?php
	}

	function admin_page_init() {
		wp_enqueue_script( 'jquery' );
		$this->enqueue_admin_styles_and_scripts();
	}

	function add_admin_pages(){
		add_submenu_page( "edit.php", __( 'Drafts for Friends', 'draftsforfriends' ), __( 'Drafts for Friends', 'drafts-for-friends' ),
			1, __FILE__, array( $this, 'output_existing_menu_sub_admin_page' ) );
	}

	function calculate_expire_time( $params ) {
		$exp = 0;
		$multiply = 60;
		if ( isset( $params['expires'] ) && $this->input_number_is_valid( $params['expires'] ) ) {
			$exp = intval( $params['expires'] );
		} else {
			throw new Exception( 'Expiration time is not a positive integer!' );
		}
		$mults = array(
			's' => 1,
			'm' => 60,
			'h' => 3600,
			'd' => 24*3600,
		);
		if ( $params['measure'] && $mults[$params['measure']] ) {
			$multiply = $mults[$params['measure']];
		}
		return $exp * $multiply;
	}

	/**
	 * DRY number validation for draft sharing and extension
	 *
	 * Accepts an input, $number, and determines whether it's valid. 
	 * This has to be used in a couple of places, so making it a new function
	 * eases maintenance and reduces duplication.
	 */
	function input_number_is_valid( $number ) {
		return ( is_numeric( $number ) && $number > 0 );
	}

	/**
	 * Simple ownership check to prevent unauthorized sharing of drafts and other hacks.
	 *
	 * Accepts a post ID (presumably for a post that hasn't been published) and returns
	 * a Boolean value indicating whether the post author is the same as the auth'd user.
	 */ 
	function current_user_owns_post( $post_id = null ) {
		if ( null == $post_id ) {
			return false;
		}
		global $current_user;
		$post = get_post( $post_id, 'OBJECT' );
		return ( $post->post_author == $current_user->id );
	}

	function current_user_is_editor() {
		return (
			current_user_can( 'edit_others_posts' )
			&& current_user_can( 'read_private_posts' )
		);
	}

	function create_share( $params ) {
		if ( 
			null == $params['nonce'] 
			|| false == wp_verify_nonce( $params['nonce'], 'draftsforfriends_post_options' ) 
			|| ! ( $this->current_user_owns_post( $params['post_id'] ) || $this->current_user_is_editor() )
		) {
			return null;
		}
		global $current_user;
		if ( $params['post_id'] ) {
			$p = get_post( $params['post_id'] );
			if ( ! $p ) {
				return __( 'There is no such post!', 'drafts-for-friends' );
			}
			if ( 'publish' == get_post_status( $p ) ) {
				return __( 'The post is published!', 'drafts-for-friends' );
			}
			$share_data = array( 
				'expires' => time() + $this->calculate_expire_time( $params ),
				'key'     => 'baba_' . wp_generate_password( 8, false ), 
			);
			add_post_meta( $params['post_id'], $this->metadata_key, $share_data );
		}
	}

	function delete_share( $params ) {
		if ( 
			null == $params['nonce'] 
			|| false == wp_verify_nonce( $params['nonce'], 'draftsforfriends_delete' ) ) {
			return null;
		}
		$this->delete_share_helper( $params );
	}

	function delete_share_helper( $params ) {
		$share_to_delete = $this->get_share_metadata( $params['post_id'], $params['key'] );
		if ( null != $share_to_delete ) {
			delete_post_meta( $params['post_id'], $this->metadata_key, $share_to_delete );
		}
	}

	function get_share_metadata( $post_id, $share_key ) {
		$post_share_meta = get_post_meta( $post_id, $this->metadata_key );
		$target_share_meta = null;
		foreach ( $post_share_meta as $key=>$value ) {
			if ( $share_key == $value['key'] ) {
				$target_share_meta = $value;
			}
		}
		return $target_share_meta;
	}

	function extend_share( $params ) {
		if ( null == $params['nonce'] 
			|| false == wp_verify_nonce( $params['nonce'], 'draftsforfriends_extend' ) ) {
			return null;
		}
		$target_share_meta = $this->get_share_metadata( $params['post_id'], $params['key'] );
		$new_share_meta = $target_share_meta;
		$current_time = time();
		$extend_time = $this->calculate_expire_time( $params );
		if ( $current_time > $target_share_meta['expires'] ) {
			$new_share_meta['expires'] = $current_time + $extend_time;
		} else {
			$new_share_meta['expires'] += $extend_time;
		}
		update_post_meta( $params['post_id'], $this->metadata_key, $new_share_meta, $target_share_meta );
	}

	function get_drafts() {
		global $current_user;
		$my_drafts = get_users_drafts( $current_user->id );
		$my_scheduled = $this->get_users_future( $current_user->id );
		$pending = get_posts( array( 'author' => $current_user->id, 'post_status' => 'pending' ) );
		if ( get_option( 'draftsforfriends_allow_duplicates' ) ) {
		} else {
		}
		$ds = array(
			array(
				__( 'Your Drafts:', 'drafts-for-friends' ),
				count( $my_drafts ),
				$my_drafts,
			 ),
			array(
				__( 'Your Scheduled Posts:', 'drafts-for-friends' ),
				count( $my_scheduled ),
				$my_scheduled,
			 ),
			array(
				__( 'Pending Review:', 'drafts-for-friends' ),
				count( $pending ),
				$pending,
			)
		);
		if ( $this->current_user_is_editor() ) {
			$others_unpublished = get_posts( array(
				'author__not_in' => [$current_user->id],
				'post_status'    => ['future', 'draft', 'pending']
			) );
			$ds[] = array(
				__( 'Other Authors:', 'drafts-for-friends' ),
				count ( $others_unpublished ),
				$others_unpublished
			);
		}
		return $ds;
	}

	function get_users_future( $user_id ) {
		global $wpdb;
		return $wpdb->get_results( "SELECT ID, post_title FROM $wpdb->posts WHERE post_type = 'post' AND post_status = 'future' AND post_author = $user_id ORDER BY post_modified DESC" );
	}

	function get_shared( $current_user_only = true ) {
		global $current_user;
		$drafts = array();
		if ( $current_user_only ) {
			$drafts = get_users_drafts( $current_user->id );
		} else {
			$drafts = get_posts( array(
				'post_status' => [ 'draft', 'future', 'pending' ],
				'numberposts' => -1,
				'meta_key'    => $this->metadata_key,
			) );
		}
		foreach ( $drafts as $key => $value ) {
			$meta = get_post_meta( $value->ID, $this->metadata_key );
			if ( is_array( $meta[0] ) && count( $meta[0] ) ) {
				$value->share_meta = $meta;
			}
		}
		return $drafts;
	}

	function output_existing_menu_sub_admin_page(){
		$t = null;
		try {
			if ( array_key_exists( 'draftsforfriends_submit', $_POST ) ) {
				$t = $this->create_share( $_POST );
			} elseif ( array_key_exists( 'action', $_POST )
				&& 'extend' == $_POST['action'] ) {
				$t = $this->extend_share( $_POST );
			} elseif ( array_key_exists( 'action', $_GET )
				&& 'delete' == $_GET['action'] ) {
				$t = $this->delete_share( $_GET );
			}
		} catch ( Exception $e ) {
			error_log( 'Caught exception: ' . $e->getMessage() );
		}
		$ds = $this->get_drafts();
		?>
		<div class="wrap">
			<h2><?php _e( 'Drafts for Friends', 'drafts-for-friends' ); ?></h2>
			<?php 	if ( $t ): ?>
			<div id="message" class="updated fade"><?php echo $t; ?></div>
			<?php 	endif; ?>
			<h3><?php _e( 'Currently shared drafts', 'drafts-for-friends' ); ?></h3>
			<table class="widefat">
				<thead>
					<tr>
						<th><?php _e( 'ID', 'drafts-for-friends' ); ?></th>
						<th><?php _e( 'Title', 'drafts-for-friends' ); ?></th>
						<th><?php _e( 'Link', 'drafts-for-friends' ); ?></th>
						<th><?php _e( 'Expires After', 'drafts-for-friends' ); ?></th>
						<th colspan="3" class="actions"><?php _e( 'Actions', 'drafts-for-friends' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
						$s = $this->get_shared( ! $this->current_user_is_editor() );
						$blog_url = get_bloginfo( 'url' );
						foreach ( $s as $share ) {
							foreach ( $share->share_meta as $metadata ) :
							$share_url = $blog_url . '/?p=' . $share->ID . '&draftsforfriends='. $metadata['key'];
							$edit_url = $blog_url . '/wp-admin/post.php/?post=' . $share->ID . '&action=edit';
					?>
					<tr>
						<td><?php echo $share->ID; ?></td>
						<td><a href="<?php echo $edit_url;?>"><?php echo $share->post_title; ?></a></td>
						<td><a href="<?php echo $share_url; ?>"><?php echo esc_html( $share_url ); ?></a></td>
						<td class="expires_after">
							<?php echo $this->calculate_human_expire_time( $metadata ); ?>
						</td>
						<td class="actions">
							<a class="draftsforfriends-extend edit" id="draftsforfriends-extend-link-<?php echo $metadata['key']; ?>"
								href="javascript:draftsforfriends.toggle_extend( '<?php echo $metadata['key']; ?>' );">
									<?php _e( 'Extend', 'drafts-for-friends' ); ?>
							</a>
							<form class="draftsforfriends-extend" id="draftsforfriends-extend-form-<?php echo $metadata['key']; ?>"
								method="post">
								<input type="hidden" name="nonce" value="<?php echo wp_create_nonce( 'draftsforfriends_extend' ); ?>" />
								<input type="hidden" name="action" value="extend" />
								<input type="hidden" name="key" value="<?php echo $metadata['key']; ?>" />
								<input type="hidden" name="post_id" value="<?php echo $share->ID; ?>" />
								<input type="submit" class="button" name="draftsforfriends_extend_submit"
									value="<?php _e( 'Extend', 'drafts-for-friends' ); ?>"/>
								<?php _e( 'by', 'drafts-for-friends' ); ?>
								<?php echo $this->tmpl_measure_select(); ?>
								<a class="draftsforfriends-extend-cancel"
									href="javascript:draftsforfriends.cancel_extend( '<?php echo $metadata['key']; ?>' );">
									<?php _e( 'Cancel', 'drafts-for-friends' ); ?>
								</a>
							</form>
						</td>
						<td class="actions">
							<a class="delete" href="edit.php?page=<?php echo plugin_basename( __FILE__ ); ?>&amp;action=delete&amp;post_id=<?php echo $share->ID; ?>&amp;key=<?php echo $metadata['key']; ?>&amp;nonce=<?php echo wp_create_nonce( 'draftsforfriends_delete' ); ?>"><?php _e( 'Delete', 'drafts-for-friends' ); ?></a>
						</td>
						<td class="actions">
							<!-- This should resolve the TODO to make the draft link selectable. 
								Putting it in 'Actions' made the most sense. -->
							<a href="javascript:draftsforfriends.copy_draft_link('<?php echo $share_url; ?>');">
								<?php _e('Copy link', 'drafts-for-friends' ); ?>
							</a>
						</td>
					</tr>
					<?php
							endforeach;
						}
						if ( empty( $s ) ):
					?>
					<tr>
						<td colspan="5"><?php _e( 'No shared drafts!', 'drafts-for-friends' ); ?></td>
					</tr>
					<?php
						endif;
					?>
				</tbody>
			</table>
			<h3><?php _e( 'Drafts for Friends', 'drafts-for-friends' ); ?></h3>
			<form id="draftsforfriends-share" method="post">
				<p>
					<select id="draftsforfriends-postid" 	name="post_id">
						<option value=""><?php _e( 'Choose a draft', 'drafts-for-friends' ); ?></option>
						<?php
							foreach ( $ds as $dt ):
								if ( $dt[1] ):
						?>
							<option value="" label=" " disabled="disabled"> </option>
							<option value="" disabled="disabled"><?php echo $dt[0]; ?></option>
							<?php
								foreach ( $dt[2] as $d ):
									if ( empty( $d->post_title ) ) {
										continue;
									}
							?>
								<option value="<?php echo $d->ID?>"><?php echo wp_specialchars( $d->post_title ); ?></option>
						<?php
							endforeach;
							endif;
							endforeach;
						?>
					</select>
				</p>
				<p>
					<input type="hidden" name="nonce" value="<?php echo wp_create_nonce( 'draftsforfriends_post_options' ); ?>" />
					<input type="submit" class="button" name="draftsforfriends_submit"
						value="<?php _e( 'Share it', 'drafts-for-friends' ); ?>" />
					<?php _e( 'for', 'drafts-for-friends' ); ?>
					<?php echo $this->tmpl_measure_select(); ?>.
				</p>
			</form>
		</div>
<?php
	}

	function calculate_human_expire_time( $draft_meta ) {
		$expire_time = $draft_meta["expires"] - time();
		if ( 0 >= $expire_time ) {
			return __( 'Expired', 'drafts-for-friends' );
		}
		$time_divisors = [
			'second' => 60,
			'minute' => 60,
			'hour'   => 24,
			'day'    => 7,
			'week'   => 52,
			'year'   => 1 << 32,
		];
		$expire_string = '.';
		foreach ( $time_divisors as $unit_name => $divisor ) {
			$expire_unit_remainder = ( 'year' == $unit_name ) ? $expire_time : $expire_time % $divisor;
			$expire_time = ( int ) ( $expire_time / $divisor );
			$comma = ( 0 < $expire_time ) ? ',' : '';
			if ( 0 < $expire_unit_remainder ) {
				// Liam Brown: I couldn't get _n() to work by itself, so I had to wrap it inside the regular translation function. Clumsy and ineffective, but I don't know the proper fix.
				$unit_name_i18n = __(
					_n(
						$unit_name,
						$unit_name . 's',
						$expire_unit_remainder
					),
					'drafts-for-friends'
				);
				$expire_string = $comma . ' ' . $expire_unit_remainder . ' ' 
					. $unit_name_i18n . $expire_string;
			}
		}
		return ucfirst( __( 'in', 'drafts-for-friends' ) . ' ' . $expire_string );
	}

	function can_view( $pid ) {
		$share_meta = get_post_meta( $pid, $this->metadata_key )[0];
		$current_time = time();
		if ( $share_meta['key'] == $_GET['draftsforfriends'] && $share_meta['expires'] >= $current_time ) {
			return true;
		}
		return false;
	}

	function posts_results_intercept( $pp ) {
		if ( 1 != count( $pp ) ) return $pp;
		$p = $pp[0];
		$status = get_post_status( $p );
		if ( 'publish' != $status && $this->can_view( $p->ID ) ) {
			$this->shared_post = $p;
		}
		return $pp;
	}

	function the_posts_intercept( $pp ){
		if ( empty( $pp ) && ! is_null( $this->shared_post ) ) {
			return array( $this->shared_post );
		} else {
			$this->shared_post = null;
			return $pp;
		}
	}

	function tmpl_measure_select() {
		$secs = __( 'seconds', 'drafts-for-friends' );
		$mins = __( 'minutes', 'drafts-for-friends' );
		$hours = __( 'hours', 'drafts-for-friends' );
		$days = __( 'days', 'drafts-for-friends' );
		return <<<SELECT
			<input name="expires" type="text" value="2" size="4"/>
			<select name="measure">
				<option value="s">$secs</option>
				<option value="m">$mins</option>
				<option value="h" selected="selected">$hours</option>
				<option value="d">$days</option>
			</select>
SELECT;
	}

	/**
	 * Enqueues essential UI code to the Drafts for Friends admin page.
	 */ 
	function enqueue_admin_styles_and_scripts() {
		wp_enqueue_script(
			'draftsforfriends_admin_js',
			plugin_dir_url( __FILE__ ) . 'admin/js/drafts-for-friends-admin.js',
			array('jquery'),
			$this->version,
			true
		);
		wp_enqueue_style(
			'draftsforfriends_admin_css',
			plugin_dir_url( __FILE__ ) . 'admin/css/drafts-for-friends-admin.css',
			array(),
			$this->version,
			'all'
		);
	}
}

new draftsforfriends();
