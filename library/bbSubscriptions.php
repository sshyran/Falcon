<?php

class bbSubscriptions extends Sputnik_Library_Plugin {
	protected static $handler = null;

	public static function bootstrap() {
		// Kill the defaults
		remove_action('bbp_new_reply', 'bbp_notify_subscribers', 1, 5);

		try {
			// Check for a handler first
			self::$handler = self::get_handler();

			// Then add our own hooks!
			self::register_hooks();
		}
		catch (Exception $e) {
			add_action('all_admin_notices', function () {
				printf('<div class="error"><p>Problem setting up bbSubscriptions! %s</p></div>', $e->getMessage());
			});

			return false;
		}
	}

	/**
	 * Get a mail handler based on the config
	 *
	 * @return bbSubscriptions_Handler
	 */
	protected static function get_handler() {
		$type = get_option('bbsub_handler_type', 'imap');
		$handler = null;

		switch ($type) {
			case 'lamson':
				$handler = new bbSubscriptions_Handler_Lamson();
				break;
			case 'imap':
				self::$handler = new bbSubscriptions_Handler_IMAP();
				break;
		}

		$handler = apply_filters('bbsub_handler_' . $type, $handler);

		if ($handler === null) {
			throw new Exception('Handler could not be found.');
		}

		return $handler;
	}

	/**
	 * Add a more frequent cron schedule
	 *
	 * We need to check the inbox much more regularly than hourly, so here we
	 * do it every minute instead.
	 *
	 * @wp-filter cron_schedules
	 */
	public static function add_schedule($schedules) {
		$schedules['bbsub_minutely'] = array('interval' => 60, 'display' => 'Once Every Minute');
		return $schedules;
	}

	/**
	 * Send a notification to subscribers
	 *
	 * @wp-filter bbp_new_reply 1
	 */
	public static function notify_on_reply( $reply_id = 0, $topic_id = 0, $forum_id = 0, $anonymous_data = false, $reply_author = 0 ) {
		if (self::$handler === null) {
			return false;
		}

		global $wpdb;

		if ( !bbp_is_subscriptions_active() )
			return false;

		$reply_id = bbp_get_reply_id( $reply_id );
		$topic_id = bbp_get_topic_id( $topic_id );
		$forum_id = bbp_get_forum_id( $forum_id );

		if ( !bbp_is_reply_published( $reply_id ) )
			return false;

		if ( !bbp_is_topic_published( $topic_id ) )
			return false;

		$user_ids = bbp_get_topic_subscribers( $topic_id, true );
		if ( empty( $user_ids ) )
			return false;

		// Poster name
		$reply_author_name = bbp_get_reply_author_display_name( $reply_id );

		do_action( 'bbp_pre_notify_subscribers', $reply_id, $topic_id, $user_ids );

		// Loop through users
		foreach ( (array) $user_ids as $user_id ) {

			// Don't send notifications to the person who made the post
			if ( !empty( $reply_author ) && (int) $user_id == (int) $reply_author )
				continue;

			// For plugins to filter messages per reply/topic/user
			$text = "%1\$s\n\n";
			$text .= "--\nReply to this email or view it online:\n%2\$s\n\nYou are recieving this email because you subscribed to it. Login and visit the topic to unsubscribe from these emails.";
			$text = sprintf($text, strip_tags(bbp_get_reply_content($reply_id)), bbp_get_reply_url($reply_id));

			// For plugins to filter titles per reply/topic/user
			$subject = apply_filters( 'bbp_subscription_mail_title', 'Re: [' . get_option( 'blogname' ) . '] ' . bbp_get_topic_title( $topic_id ), $reply_id, $topic_id, $user_id );
			if ( empty( $subject ) )
				continue;

			// Get user data of this user
			$user = get_userdata( $user_id );

			$headers = array();

			self::$handler->send_mail($user, $subject, $text, $headers, compact($topic_id, $reply_author_name));
		}

		do_action( 'bbp_post_notify_subscribers', $reply_id, $topic_id, $user_ids );

		return true;
	}

	/**
	 * @wp-action bbsub_check_inbox
	 */
	public static function check_inbox() {
		if (self::$handler === null) {
			return false;
		}

		self::$handler->check_inbox();
	}
}