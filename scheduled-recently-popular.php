<?php
/*
	Plugin Name: Scheduled Recently Popular
	Plugin URI: http://asahiro.me
	Description: This is a wrapper plugin of <a href="http://wordpress.org/extend/plugins/recently-popular/" target="_blank">"Recently Popular"</a>. Displays tabbed list of the most popular, using scheduled SQL query for tallying up.
	Version: 0.7.2
	Author: asahiro (Hirotaka ASAHI)
	Author URI: http://asahiro.me
	
	Modified: July 26, 2012
	Original Plugin Name: Recently Popular
	Original Plugin URI: http://eric.biven.us/2008/12/03/recently-popular-wordpress-plugin/
	Description: Displays the most popular posts based on history from now to X amount of time in the past.
	Original Version: 0.7.2
	Original Author: Eric Biven
	Original Author URI: http://eric.biven.us/
 */
 
// Copyright (c) asahiro (Hirotaka ASAHI)
// Released under the FreeBSD license:
// http://www.freebsd.org/copyright/freebsd-license.html

//require_once(WP_PLUGIN_DIR . '/plugins/recently-popular/include.php');
//require_once(WP_PLUGIN_DIR . '/plugins/recently-popular/recently-popular-widget.php');
//require_once(WP_PLUGIN_DIR . '/plugins/recently-popular/recently-popular.php');

function srp_is_wpmu() {
	if (function_exists('is_multisite')){
		return is_multisite();
	} else {
		return file_exists(ABSPATH."/wpmu-settings.php");
	}
}
function srp_timezone_setup() {
	if ( !$timezone_string = get_option( 'timezone_string' )) return false;
	@date_default_timezone_set($timezone_string);
}

class ScheduledRecentlyPopularUtil extends RecentlyPopularUtil {

  public static $defaults = array (
	'categories' => '',
	'date_format' => 'Y-m-d',
	'default_thumbnail_url' => '',
	'display' => true,
	'enable_categories' => false,
	'interval_length' => '1',
	'interval_type_h' => '1',
	'interval_type_d' => '1',
	'interval_type_w' => '1',
	'interval_type_m' => '1',
	'limit' => 5,
	'max_length' => 0,
	'max_excerpt_length' => 0,
	'output_format' => '<span></span><a href="%post_url%">%post_title%</a>',
	'post_type' => RecentlyPopularPostType::POSTS,
	'relative_time' => 0,
	'title' => 'Recently Popular',
	'user_type' => RecentlyPopularUserType::ANONYMOUS
  );
}

class ScheduledRecentlyPopular extends RecentlyPopular {

	private $table_suffix = 'recently_popular';
	private $short_path = 'scheduled-recently-popular/scheduled-recently-popular.php';

	private function get_file_path() { return WP_PLUGIN_DIR . '/'.$this->short_path; }
	private function get_table_name() { global $wpdb; return $wpdb->prefix.$this->table_suffix; }

	/*
	 * Standard PHP object property handlers. Allow any property to be created/set/removed for 
	 * flexibility in case someone decides to extend the plugin. Caveat emptor.
	 */
	private $data = array();
	public function __set($name, $value) { $this->data[$name] = $value; }
	public function __get($name) {
		if (array_key_exists($name, $this->data)) {
			return $this->data[$name];
		}
	}

	public function __construct() {
		$this->data = &ScheduledRecentlyPopularUtil::$defaults;
		load_plugin_textdomain('recently-popular', 'recently-popular/languages');
		register_activation_hook($this->get_file_path(), array(&$this, 'activate'));
		register_deactivation_hook($this->get_file_path(), array(&$this, 'deactivate'));
		//add_action('admin_menu', array(&$this, 'admin_menu'));
		$this->widget_title = __('Scheduled Recently Popular');
		$action_tag = 'tally_up_recently_popular_counts';
		if (srp_is_wpmu()) $action_tag .= $current_blog->blog_id;
		add_action($action_tag, array(&$this,'tally_up_counts'), 10, 1);
	}

	public function get_counts($ops = array()) { //集計結果取得表示メソッド
		$o = wp_parse_args($ops, $this->data);
		if ($o['display']) { echo file_get_contents(WP_CONTENT_DIR . "/srp_results.php"); }
		else { return file_get_contents(WP_CONTENT_DIR . "/srp_results.php"); }
	}

	public function tally_up_counts($ops = array()) { // 閲覧回数集計メソッド
		syslog(LOG_NOTICE, "tally_up_counts.");
	 	global $wpdb;
	 	srp_timezone_setup();
	   	$table_name = $this->get_table_name();
	   	$o = wp_parse_args($ops, $this->data);
	   	if($o['interval_length'] == '') $o['interval_length'] = '1';

		// Establish the needed where clauses.
	   	$wc_user_type = '';
	   	$wc_post_type = '';
	   	$wc_categories = '';
	   	$wc_post_id = '';
	   	$join_categories = '';
	   	$gb_categories = '';

	   	if ($o['user_type'] != RecentlyPopularUserType::ALL) {
	   		$wc_user_type = " AND `rp`.`user_type` = $o[user_type] ";
	   	}

	   	switch ($o['post_type']) {
	   		case RecentlyPopularPostType::ALL :
	   			break;
	   		case RecentlyPopularPostType::PAGES :
	   			$wc_post_type = " AND `p`.`post_type` = 'page' ";
	   			break;
	   		case RecentlyPopularPostType::POSTS :
	   			$wc_post_type = " AND `p`.`post_type` = 'post' ";
	   			break;
	   	}

	   	if ($o['relative_time']) {
	   		$o['interval_length'] = RecentlyPopularUtil::relative_timestamp($o['interval_length'], $o['interval_type']);
	   		$o['interval_type'] = 'SECOND';
	   	}

	   	if (!empty($o['post_id'])) {
	   		$o['post_id'] = strval($o['post_id']);
	   		$wc_post_id = " AND `rp`.`postid` = '$o[post_id] ";
	   	}

	   	if ($o['enable_categories'] == 1 && strlen($o['categories']) > 0) {
	   		// Define the where-clause (wc) and join this way so that we don't
	   		// join the term tables unless we need to.
	   		$o['categories'] = stripslashes($o['categories']);

	   		// Since pages can't have categories we have to use an appropriate
	   		// where clause if the user wants them displayed.
	   		if ($o['post_type'] == RecentlyPopularPostType::POSTS) {
	   			$wc_categories = " AND `tt`.`taxonomy` = 'category'
	   							   AND `t`.`name` IN ($o[categories]) ";
	   		}
	   		else {
	   			$wc_categories = " AND ((`tt`.`taxonomy` = 'category'
	   									 AND `t`.`name` IN ($o[categories]))
	   									OR `p`.`post_type` = 'page') ";
	   		}
	   	}
	   	else {
	   		if ($o['post_type'] == RecentlyPopularPostType::POSTS) {
	   			$wc_categories = " AND `tt`.`taxonomy` = 'category' ";
	   		}
	   		else {
	   			$wc_categories = " AND `tt`.`taxonomy` = 'category' 
	   									OR `p`.`post_type` = 'page' ";
	   		}
	   	}

	   	// Using the sub-select speeds the query up about 15x, even when the term
	   	// tables are joined for every query.  This allows us to expose more info
	   	// for the template tags without harming performance.
	   	$output = '';
	   	foreach( array('HOUR','DAY','WEEK','MONTH') as $interval_type) {
 			if ($o['relative_time']) {
	   			$o['interval_length'] = RecentlyPopularUtil::relative_timestamp($o['interval_length'], $interval_type);
	   			$o['interval_type'] = 'SECOND';
	   		}
	  		$sql = "SELECT
						`rp`.`hits` AS `hits`,
						`rp`.`postid` AS `post_id`,
						`p`.`post_title` AS `post_title`,
						`p`.`post_excerpt` AS `post_excerpt`,
   						`p`.`post_date` AS `post_date`,
						`p`.`post_type` AS `post_type`,
						`u`.`display_name` AS `display_name`,
						`u`.`user_url` AS `user_url`,
						GROUP_CONCAT(DISTINCT `t`.`name` ORDER BY `t`.`name` SEPARATOR ', ') AS `category`
					FROM (
							SELECT 
								COUNT(`post_id`) AS `hits`,
								MIN(`ts`) AS `ts`,
								MIN(`user_type`) AS `user_type`,
								MIN(`post_id`) AS `postid`
							FROM 	`$table_name`
							WHERE   `ts` > (CURRENT_TIMESTAMP() - INTERVAL $o[interval_length] $interval_type)
							GROUP 	BY `post_id`
							ORDER 	BY `hits` DESC
						 ) AS `rp`
					LEFT JOIN `$wpdb->posts` AS `p` ON `rp`.`postid` = `p`.`ID`
					LEFT JOIN `$wpdb->users` AS `u` ON `p`.`post_author` = `u`.`ID`
					LEFT JOIN `$wpdb->term_relationships` AS `tr` ON `p`.`ID` = `tr`.`object_id`
					LEFT JOIN `$wpdb->term_taxonomy` AS `tt` ON `tr`.`term_taxonomy_id` = `tt`.`term_taxonomy_id`
					LEFT JOIN `$wpdb->terms` AS `t` ON `tt`.`term_id` = `t`.`term_id`
					WHERE 1
						$wc_user_type
						$wc_post_type
						$wc_categories
						$wc_post_id
					GROUP BY `rp`.`postid`
					ORDER BY `rp`.`hits` DESC, `rp`.`ts` DESC
					LIMIT $o[limit]
			";

			$most_viewed = $wpdb->get_results($sql);
			$output .= '<li id="recently-popular-' . $interval_type . '" class="widget RecentlyPopularWidget"><h2 class="widgettitle">' . $interval_type . '</h2><ul>';
			if ($most_viewed) {
				foreach ($most_viewed as $post) {
					$images =& get_children('post_type=attachment&post_mime_type=image&post_parent='.$post->post_id);
					$img = wp_get_attachment_image_src(array_shift(array_keys($images)));
					$img_url = $img[0];
					$loutput = str_replace('%post_title%', ($o['max_length'] > 0) ? RecentlyPopularUtil::truncate($post->post_title, $o['max_length']) : $post->post_title, stripslashes($o['output_format']));
					$loutput = str_replace('%post_excerpt%', ($o['max_excerpt_length'] > 0) ? RecentlyPopularUtil::truncate($post->post_excerpt, $o['max_excerpt_length']) : $post->post_excerpt, $loutput);
					$loutput = str_replace('%post_url%', get_permalink($post->post_id), $loutput);
					$loutput = str_replace('%hits%', $post->hits, $loutput);
					$loutput = str_replace('%display_name%', $post->display_name, $loutput);
					$loutput = str_replace('%user_url%', $post->user_url, $loutput);
					$loutput = str_replace('%publish_date%', date($o['date_format'], strtotime($post->post_date)), $loutput);
					// We get get erroneous category names back for pages, so suppress those.
					$loutput = str_replace('%category%', ($post->post_type == 'post') ? $post->category : '', $loutput);
					$loutput = str_replace('%thumbnail_url%', (empty($img_url)) ? $o['default_thumbnail_url'] : $img_url, $loutput);

	   				$output .= empty($post_id) ? "    <li>$loutput</li>\n" : $loutput;
	   			}
	   		}
	   		$output .= '</ul></li>';
		}
		$fp_result = fopen(WP_CONTENT_DIR . "/srp_results.php", "w");
		fwrite($fp_result, $output, 5000);
		fclose($fp_result);
	}
	
	public function activate_recently_popular() {
		$rp_plugin_path = 'recently-popular/recently-popular.php';
		$current_plugins = get_option('active_plugins');
		if(!in_array($rp_plugin_path, $current_plugins)) {
			$current_plugins[] = $rp_plugin_path;
			update_option('active_plugins', $current_plugins);
			do_action('activate_'.$rp_plugin_path);
		}
	}
	
	public function activate() {
		$this->activate_recently_popular();
	}
	public function deactivate() {
		global $current_blog;
		$action_tag = 'tally_up_recently_popular_counts';
		if (srp_is_wpmu()) $action_tag .= '_'.$current_blog->blog_id;
		wp_clear_scheduled_hook($action_tag);
		wp_clear_scheduled_hook($action_tag, $instance);
		wp_clear_scheduled_hook($action_tag, array($instance));
	}

	public function schedule_tallying_up($instance, $old_instance) {
		global $current_blog;
		srp_timezone_setup();
		$action_tag = 'tally_up_recently_popular_counts';
		if (srp_is_wpmu()) $action_tag .= $current_blog->blog_id;
		wp_clear_scheduled_hook($action_tag);
		wp_clear_scheduled_hook($action_tag, $old_instance);
		wp_clear_scheduled_hook($action_tag, array($old_instance));
		add_action($action_tag, array(&$this,'tally_up_counts'), 10, 1);
		//wp_schedule_event(strtotime(mktime(date('H'),0,0,date('m'),date('d'),date('Y')), 'hourly', 'tally_up_recently_popular_counts', $instance);
		wp_schedule_event(mktime(date('H'),0,0,date('m'),date('d'),date('Y'))+3600, 'hourly', $action_tag, array($instance));		
	}
}
	
class ScheduledRecentlyPopularWidget extends RecentlyPopularWidget {

	private $data = array();
	public function __set($name, $value) { $this->data[$name] = $value; }
	public function __get($name) {
		if (array_key_exists($name, $this->data)) {
	  		return $this->data[$name];
		}
	} 

	public function ScheduledRecentlyPopularWidget() {
		$this->data = &ScheduledRecentlyPopularUtil::$defaults;
		add_action('widgets_init', array(&$this, 'init'));
		$widget_ops = array('classname' => 'ScheduledRecentlyPopularWidget',
							'description' => 'Shows recently popular posts(tallied up at regular time intervals)');
		$control_ops = array('id_base' => 'scheduled-recently-popular', 'width' => '200px');
		parent::WP_Widget('scheduled-recently-popular', 'Scheduled Recently Popular', $widget_ops, $control_ops);
	}

	public function init() {
  		register_widget('ScheduledRecentlyPopularWidget');
	}

	public function widget($args, $instance) { //ウィジェット表示部．DBから集計結果の取得
		syslog(LOG_NOTICE, "display widget:");
		extract($args, EXTR_SKIP);
		$args['title'] = apply_filters('widget_title', $instance['title']);
		echo($before_widget);
		echo($before_title . $args['title'] . $after_title);
		?>
		<li id="popular-list" class="widget widget-wrapper">
		<div id="popular-list-container-head"><h2>ランキング</h2></div>
		<div id="popular-list-container">
		<ul class="popular-list-tab">
		<li><a href="#recently-popular-HOUR">瞬間</a></li>
		<li><a href="#recently-popular-DAY" class="selected">１日</a></li>
		<li><a href="#recently-popular-WEEK">週間</a></li>
		<li><a href="#recently-popular-MONTH">月間</a></li>
		</ul>
		<ul class="popular-list-panel">
		<?php
  		$srp = new ScheduledRecentlyPopular();
  		$srp->get_counts();
		?></ul></div><div id="popular-list-container-foot"><p>　</p></div></li><?php
		echo($after_widget);
	}

	public function update($new_instance, $old_instance) {
		syslog(LOG_NOTICE, "update.");
		$action_tag = 'tally_up_recently_popular_counts';
		if (srp_is_wpmu()) $action_tag .= $current_blog->blog_id;
		$instance['categories'] = '';
		if (isset($new_instance['categories'])) {
			foreach ($new_instance['categories'] as $category) {
				$instance['categories'] .= "'".strip_tags($category)."',";
			}
		}
		$instance['categories'] = strip_tags(rtrim((string)$instance['categories'], ','));
		$instance['date_format'] = strip_tags($new_instance['date_format']);
		$instance['default_thumbnail_url'] = strip_tags($new_instance['default_thumbnail_url']);
		$instance['display'] = true;
		$instance['enable_categories'] = ($new_instance['enable_categories'] == '1') ? '1' : '0';
		//$instance['interval_length'] = intval($new_instance['interval_length']);
		//$instance['interval_type'] = strip_tags($new_instance['interval_type']);
		$instance['interval_type_h'] = ($new_instance['interval_type_h'] == '1') ? '1' : '0';
		$instance['interval_type_d'] = ($new_instance['interval_type_d'] == '1') ? '1' : '0';
		$instance['interval_type_w'] = ($new_instance['interval_type_w'] == '1') ? '1' : '0';
		$instance['interval_type_m'] = ($new_instance['interval_type_m'] == '1') ? '1' : '0';
		$instance['limit'] = intval($new_instance['limit']);
		$instance['max_length'] = intval($new_instance['max_length']);
		$instance['max_excerpt_length'] = intval($new_instance['max_excerpt_length']);
		$instance['output_format'] = $new_instance['output_format'];
		$instance['post_type'] = intval($new_instance['post_type']);
		$instance['relative_time'] = ($new_instance['relative_time'] == '1') ? '1' : '0';
		$instance['title'] = strip_tags($new_instance['title']);
		$instance['user_type'] = intval($new_instance['user_type']);
		$srp = new ScheduledRecentlyPopular();
		//$srp->tally_up_counts($instance);
		$srp->schedule_tallying_up($instance, $old_instance);
		do_action($action_tag, $instance);

		return $instance;
	}

	public function form($instance) {
		$instance = wp_parse_args((array)$instance, $this->data);
	?>
	  <p>
		<label for="<?php echo($this->get_field_id('title')); ?>"><?php _e('Title:'); ?></label>
		<input class="widefat" id="<?php echo($this->get_field_id('title')); ?>" name="<?php echo($this->get_field_name('title')); ?>" type="text" value="<?php echo(esc_attr($instance['title'])); ?>" />
	  </p>
	  <p>
		<br /><input id="<?php echo($this->get_field_id('interval_type_h')); ?>" name="<?php echo($this->get_field_name('interval_type_h')); ?>" type="checkbox" value="1" <?php if ($instance['interval_type_h'] == '1') { echo 'checked="true"'; } ?>/>
		<label for="<?php echo($this->get_field_id('interval_type_h')); ?>"><?php _e('Display Hourly Ranking') ?></label>
		<br /><input id="<?php echo($this->get_field_id('interval_type_d')); ?>" name="<?php echo($this->get_field_name('interval_type_d')); ?>" type="checkbox" value="1" <?php if ($instance['interval_type_d'] == '1') { echo 'checked="true"'; } ?>/>
		<label for="<?php echo($this->get_field_id('interval_type_d')); ?>"><?php _e('Display Daily Ranking') ?></label>
		<br /><input id="<?php echo($this->get_field_id('interval_type_w')); ?>" name="<?php echo($this->get_field_name('interval_type_w')); ?>" type="checkbox" value="1" <?php if ($instance['interval_type_w'] == '1') { echo 'checked="true"'; } ?>/>
		<label for="<?php echo($this->get_field_id('interval_type_w')); ?>"><?php _e('Display Weekly Ranking') ?></label>
		<br /><input id="<?php echo($this->get_field_id('interval_type_m')); ?>" name="<?php echo($this->get_field_name('interval_type_m')); ?>" type="checkbox" value="1" <?php if ($instance['interval_type_m'] == '1') { echo 'checked="true"'; } ?>/>
		<label for="<?php echo($this->get_field_id('interval_type_m')); ?>"><?php _e('Display Monthly Ranking') ?></label>
		<br/><input id="<?php echo($this->get_field_id('relative_time')); ?>" name="<?php echo($this->get_field_name('relative_time')); ?>" type="checkbox" value="1" <?php if ($instance['relative_time'] == '1') { echo 'checked="true"'; } ?>/>
		<label for="<?php echo($this->get_field_id('relative_time')); ?>"><?php _e('Use relative time?', 'recently-popular') ?></label>
		<br/><em><?php _e('Relative time changes the way "Count views no older than" works. For example, if you choose 1 month, normally this would cause all views for the past 30 days to be counted. With the relative time option it will count all view in the current month.', 'recently-popular') ?></em>
	  </p>
	  <p>
		<label for="<?php echo($this->get_field_id('limit')); ?>"><?php _e('Limit to no more than:'); ?></label>
		<input id="<?php echo($this->get_field_id('limit')); ?>" name="<?php echo($this->get_field_name('limit')); ?>" type="text" size="3" value="<?php echo(esc_attr($instance['limit'])); ?>" /> posts
	  </p>
	  <p>
		<label for="<?php echo($this->get_field_id('max_length')); ?>"><?php _e('Limit titles to:', 'recently-popular') ?></label>
		<input id="<?php echo($this->get_field_id('max_length')); ?>" name="<?php echo($this->get_field_name('max_length')); ?>" type="text" size="3" value="<?php echo(esc_attr($instance['max_length'])); ?>" /> <?php _e('characters (enter 0 for no limit)', 'recently-popular') ?>
	  </p>
	  <p>
		<label for="<?php echo($this->get_field_id('max_excerpt_length')); ?>"><?php _e('Limit excerpts to:', 'recently-popular') ?></label>
		<input id="<?php echo($this->get_field_id('max_excerpt_length')); ?>" name="<?php echo($this->get_field_name('max_excerpt_length')); ?>" type="text" size="3" value="<?php echo(esc_attr($instance['max_excerpt_length'])); ?>" /> <?php _e('characters (enter 0 for no limit)', 'recently-popular') ?>
	  </p>
	  <p>
		<label for="<?php echo($this->get_field_id('user_type')); ?>"><?php _e('Count views by:', 'recently-popular') ?></label>
		<select id="<?php echo($this->get_field_id('user_type')); ?>" name="<?php echo($this->get_field_name('user_type')); ?>">
		  <option value="<?php echo(RecentlyPopularUserType::ALL);?>" <?php selected($instance['user_type'], RecentlyPopularUserType::ALL);?>><?php _e('Anonymous &amp; Registered Users', 'recently-popular') ?></option>
		  <option value="<?php echo(RecentlyPopularUserType::ANONYMOUS);?>" <?php selected($instance['user_type'], RecentlyPopularUserType::ANONYMOUS);?>><?php _e('Anonymous Users Only', 'recently-popular') ?></option>
		  <option value="<?php echo(RecentlyPopularUserType::REGISTERED);?>" <?php selected($instance['user_type'], RecentlyPopularUserType::REGISTERED);?>><?php _e('Registered Users Only', 'recently-popular') ?></option>
		</select>
	  </p>
	  <p>
		<label for="<?php echo($this->get_field_id('post_type')); ?>"><?php _e('Count views of:', 'recently-popular') ?></label>
		<select id="<?php echo($this->get_field_id('post_type')); ?>" name="<?php echo($this->get_field_name('post_type')); ?>">
		  <option value="<?php echo(RecentlyPopularPostType::ALL);?>" <?php selected($instance['post_type'], RecentlyPopularPostType::ALL);?>><?php _e('Pages &amp; Posts', 'recently-popular') ?></option>
		  <option value="<?php echo(RecentlyPopularPostType::PAGES);?>" <?php selected($instance['post_type'], RecentlyPopularPostType::PAGES);?>><?php _e('Pages Only', 'recently-popular') ?></option>
		  <option value="<?php echo(RecentlyPopularPostType::POSTS);?>" <?php selected($instance['post_type'], RecentlyPopularPostType::POSTS);?>><?php _e('Posts Only', 'recently-popular') ?></option>
		</select>
	  </p>
	  <p>
		<a href="#" onclick="jQuery('#<?php echo($this->get_field_id('output_format')); ?>-formatting').toggle();return false;"><?php _e('Show/hide formatting options', 'recently-popular') ?></a><br/>
	  </p>
	  <div id="<?php echo($this->get_field_id('output_format')); ?>-formatting" style="display:none;">
		<p>
		  <label for="<?php echo($this->get_field_id('output_format')); ?>"><?php _e('Format each result as:', 'recently-popular') ?></label>
		  <input class="widefat" id="<?php echo($this->get_field_id('output_format')); ?>" name="<?php echo($this->get_field_name('output_format')); ?>" type="text" value="<?php echo(stripslashes(htmlentities($instance['output_format']))); ?>" />
		  <?php _e('or choose a format below by clicking it:', 'recently-popular') ?><br/>
		  <span style="cursor:pointer;" onclick="document.getElementById('<?php echo($this->get_field_id('output_format')); ?>').value='<a href=&quot;%post_url%&quot;>%post_title%</a>';"><u><?php _e('Post Name', 'recently-popular') ?></u></span><br/>
		  <span style="cursor:pointer;" onclick="document.getElementById('<?php echo($this->get_field_id('output_format')); ?>').value='<a href=&quot;%post_url%&quot;>%post_title% (%hits%)</a>';"><u><?php _e('Post Name (Hits)', 'recently-popular') ?></u></span><br/>
		  <span style="cursor:pointer;" onclick="document.getElementById('<?php echo($this->get_field_id('output_format')); ?>').value='<a href=&quot;%post_url%&quot;>%post_title%</a> <?php _e('by', 'recently-popular') ?> %display_name%';"><u><?php _e('Post Name', 'recently-popular') ?></u> <?php _e('by Author Name', 'recently-popular') ?></span><br/>
		</p>
		<p>
		  <label for="<?php echo($this->get_field_id('default_thumbnail_url')); ?>"><?php _e('Default thumbnail URL:', 'recently-popular') ?></label>
		  <input class="widefat" id="<?php echo($this->get_field_id('default_thumbnail_url')); ?>" name="<?php echo($this->get_field_name('default_thumbnail_url')); ?>" type="text" value="<?php echo(stripslashes(htmlentities($instance['default_thumbnail_url']))); ?>" />
		</p>
		<p>
		  <label for="<?php echo($this->get_field_id('date_format')); ?>"><?php _e('Format dates as:', 'recently-popular') ?></label>
		  <input id="<?php echo($this->get_field_id('date_format')); ?>" name="<?php echo($this->get_field_name('date_format')); ?>" type="text" size="10" value="<?php echo(stripslashes(htmlentities($instance['date_format']))); ?>" /> <a href="http://php.net/date" target="_blank"><?php _e('help', 'recently-popular') ?></a>
		</p>
		<div style="background-color:#f9f9f9;border:1px solid #000000;padding:3px;" id="<?php echo($this->get_field_id('output_format')); ?>-tag-help">
		  <?php _e('Available tags:', 'recently-popular') ?><br/>
		  <em>%categories%</em> - <?php _e('the post\'s categories', 'recently-popular') ?><br/>
		  <em>%display_name%</em> - <?php _e('the post\'s author', 'recently-popular') ?><br/>
		  <em>%hits%</em> - <?php _e('the number of qualifying views', 'recently-popular') ?><br/>
		  <em>%post_title%</em> - <?php _e('the post\'s title', 'recently-popular') ?><br/>
		  <em>%post_excerpt%</em> - <?php _e('the post\'s excerpt', 'recently-popular') ?><br/>
		  <em>%post_url%</em> - <?php _e('the post\'s permalink', 'recently-popular') ?><br/>
		  <em>%publish_date%</em> - <?php _e('the post\'s publish date', 'recently-popular') ?><br/>
		  <em>%thumbnail_url%</em> - <?php _e('URL for the thumbnail', 'recently-popular') ?><br/>
		  <em>%user_url%</em> - <?php _e('the post author\'s url', 'recently-popular') ?><br/>
		</div>
		<p>
		</p>
		</div>
		<p>
			<a href="#" onclick="jQuery('#<?php echo($this->get_field_id('categories')); ?>-formatting').toggle();return false;"><?php _e('Show/hide category options', 'recently-popular') ?></a><br/>
		</p>
		<div id="<?php echo($this->get_field_id('categories')); ?>-formatting" style="display:none;">
			<p>
				<em>*<?php _e('Note: Enabling category filtering will eliminate all posts with no category. If pages are selected in \'Count views of\' above then all pages will display since they can\'t have a category.', 'recently-popular') ?></em>
			</p>
			<p>
				<input id="<?php echo($this->get_field_id('enable_categories')); ?>" name="<?php echo($this->get_field_name('enable_categories')); ?>" type="checkbox" value="1" <?php if ($instance['enable_categories'] == '1') { echo 'checked="true"'; } ?>/>
				<label for="<?php echo($this->get_field_id('enable_categories')); ?>"><?php _e('Enable category filtering', 'recently-popular') ?></label>
			</p>
			<p>
				<label for="<?php echo($this->get_field_id('categories')); ?>"><?php _e('Choose only posts in these categories:', 'recently-popular') ?></label>
				<select id="<?php echo($this->get_field_id('categories')); ?>" name="<?php echo($this->get_field_name('categories')); ?>[]" class="widefat" style="height:100px;" multiple="multiple">
					<?php
						global $wpdb;
						$sql = "SELECT t.`name`
								FROM $wpdb->terms AS t
								LEFT JOIN $wpdb->term_taxonomy AS tt ON t.`term_id` = tt.`term_id`
								WHERE tt.`taxonomy` = 'category'
								ORDER BY t.`name` DESC
						";

						$categories = $wpdb->get_results($sql);
						foreach ($categories as $category) {
							$name = $category->name;
							$selected = '';
							if (strpos($instance['categories'], "'$name'") !== false) {
							  $selected = ' selected="selected"';
							}
							echo ("<option value=\"$name\"$selected>$name</option>");
						}
					?>
				</select>
			</p>
		</div>
		<?php
	}

}// class ScheduledRecentlyPopularWidget
new ScheduledRecentlyPopularWidget();

?>