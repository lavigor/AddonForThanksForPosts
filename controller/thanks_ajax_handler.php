<?php
/**
*
* @author Alg
* @version 2.0.0.0.
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
*
*/

namespace alg\AddonForThanksForPosts\controller;

class thanks_ajax_handler
{
protected $thankers = array();
	public function __construct(\phpbb\config\config $config, \phpbb\db\driver\driver_interface $db, \phpbb\auth\auth $auth, \phpbb\template\template $template, \phpbb\user $user, \phpbb\cache\driver\driver_interface $cache, $phpbb_root_path, $php_ext, \phpbb\request\request_interface $request, $table_prefix, $thanks_table, $users_table, $posts_table, \phpbb\controller\helper $controller_helper, \gfksx\ThanksForPosts\core\helper $gfksx_helper = null)
	{
		$this->config = $config;
		$this->db = $db;
		$this->auth = $auth;
		$this->template = $template;
		$this->user = $user;
		$this->cache = $cache;
		$this->phpbb_root_path = $phpbb_root_path;
		$this->php_ext = $php_ext;
		$this->request = $request;
		$this->gfksx_helper = $gfksx_helper;
		$this->return = array(); // save returned data in here
		$this->error = array(); // save errors in here
		$this->thanks_table = $thanks_table;
		$this->users_table = $users_table;
		$this->posts_table = $posts_table;
		$this->controller_helper = $controller_helper;
	}

	public function main($action, $poster, $forum, $topic, $post)
	{
		$this->user->add_lang_ext('gfksx/ThanksForPosts', 'thanks_mod');
		//not allowed like for anonymous
		if ($this->user->data['is_bot'] || $this->user->data['user_id'] == ANONYMOUS  )
		{
			$return_error['ERROR'][] = $this->user->lang['LOGIN_REQUIRED'];
			$json_response = new \phpbb\json_response;
			$json_response->send($return_error);
		}

		// If the main extension is not installed, generate error
		if (!is_null($this->gfksx_helper))
		{
			switch ($action)
			{
				case 'thanks':
				case 'rthanks':
					$this->thanks_for_post($action, $poster, $forum, $topic, $post);
					break;
				case 'clear_thanks':
					$this->clear_list_thanks($poster, $forum, $topic, $post);
					break;
				default:
					$this->error[] = array('error' => $this->user->lang['INCORRECT_THANKS']);
			}
		}
		else
		{
			$this->user->add_lang_ext('alg/AddonForThanksForPosts', 'addon_tfp');
			$this->error[] = array('error' => 'MAIN_EXT_NOT_INSTALLED');
		}

		if (sizeof($this->error))
		{
			$return_error = array();
			foreach($this->error as $cur_error)
			{
					// replace lang vars if possible
					$return_error['ERROR'][] = (isset($this->user->lang[$cur_error['error']])) ? $this->user->lang[$cur_error['error']] : $cur_error['error'];
			}
			$json_response = new \phpbb\json_response;
			$json_response->send($return_error);
		}
		else
		{
			$json_response = new \phpbb\json_response;
			$json_response->send($this->return);
		}
	}

	private function thanks_for_post($action, $poster_id, $forum_id, $topic_id, $post_id)
	{
		$user_id = (int) $this->user->data['user_id'];
		if ($this->user->data['user_type'] != USER_IGNORE && !empty($poster_id))
		{
			switch ($action)
			{
				case 'thanks':
					if ($poster_id != $user_id  && !$this->already_thanked($post_id, $user_id) && ($this->auth->acl_get('f_thanks', $forum_id) || (!$forum_id && (isset($this->config['thanks_global_post']) ? $this->config['thanks_global_post'] : false))) )
					{
						$thanks_time = time();
						$this->user->data['user_id'];

						$thanks_data = array(
							'user_id'	=> $user_id,
							'post_id'	=> $post_id,
							'poster_id'	=> $poster_id,
							'topic_id'	=> $topic_id,
							'forum_id'	=> $forum_id,
							'thanks_time'	=> $thanks_time,
							);
						//add to DB
						$sql = 'INSERT INTO ' . $this->thanks_table . ' ' . $this->db->sql_build_array('INSERT', $thanks_data);
						$this->db->sql_query($sql);

						//notification
						$thanks_data = array_merge($thanks_data, array(
						'username'	=> $this->user->data['username'],
						'lang_act'	=> 'GIVE',
						'post_subject'	=> $this->get_post_subject($post_id),
						));
						$this->gfksx_helper->add_notification($thanks_data);

					}
					else
					{
						$this->error[] = array('error' => $this->user->lang['GLOBAL_INCORRECT_THANKS']);
					}
					break;
				case 'rthanks':
					if (isset($this->config['remove_thanks']) ? !$this->config['remove_thanks'] : true)
					{
						$this->error[] = array('error' => $this->user->lang['DISABLE_REMOVE_THANKS']);
						return;
					}
					$sql = "DELETE FROM " . $this->thanks_table . '
								WHERE post_id ='.  $post_id ." AND user_id = " . (int) $user_id;
					$this->db->sql_query($sql);
					$result = $this->db->sql_affectedrows($sql);
					if ($result == 0)
					{
						$this->error[] = array('error' => $this->user->lang['INCORRECT_THANKS']);
					}
					else
					{
						$thanks_data = array(
							'user_id'	=> $user_id,
							'post_id'	=> $post_id,
							'poster_id'	=> $poster_id,
							'topic_id'	=> $topic_id,
							'forum_id'	=> $forum_id,
							'thanks_time'	=> time(),
							'username'	=> $this->user->data['username'],
							'lang_act'	=> 'REMOVE',
							'post_subject'	=> $this->get_post_subject($post_id),
						);
						$this->gfksx_helper->add_notification($thanks_data, 'gfksx.thanksforposts.notification.type.thanks_remove');
					}
					break;
				default:
			}//end switch
		}
		$i = 0;

		//max post thanks
		if (isset($this->config['thanks_post_reput_view']) ? $this->config['thanks_post_reput_view'] : false)
		{
			$sql = 'SELECT MAX(tally) AS max_post_thanks
				FROM (SELECT post_id, COUNT(*) AS tally FROM ' . $this->thanks_table . ' GROUP BY post_id) t';
			$result = $this->db->sql_query($sql);
			$max_post_thanks = (int) $this->db->sql_fetchfield('max_post_thanks');
			$this->db->sql_freeresult($result);
		}
		else
		{
			$max_post_thanks = 1;
		}
		//give-receive counters
		$ex_fid_ary = array_keys($this->auth->acl_getf('!f_read', true));

		$sql = 'SELECT  (select count(user_id) FROM ' . $this->thanks_table .  ' WHERE user_id=' . $user_id ;
		if (sizeof($ex_fid_ary))
		{
			$sql .= " AND " . $this->db->sql_in_set('forum_id', $ex_fid_ary, true);
		}

		$sql .=  ') give,  (select count(poster_id)	 FROM  ' . $this->thanks_table .  ' WHERE  poster_id = ' . $poster_id;
		if (sizeof($ex_fid_ary))
		{
			$sql .= " AND " . $this->db->sql_in_set('forum_id', $ex_fid_ary, true);
		}
		$sql .= ') rcv ';
		$result = $this->db->sql_query($sql);
		$row_giv_rcv = $this->db->sql_fetchrow($result);
		$poster_give_count = $row_giv_rcv['give'];
		$poster_receive_count = $row_giv_rcv['rcv'];
		$this->db->sql_freeresult($result);
		$l_poster_receive_count = ($poster_receive_count) ? $this->user->lang('THANKS', $poster_receive_count) : '';
		$l_poster_give_count = ($poster_give_count) ? $this->user->lang('THANKS', $poster_give_count) : '';
		$thanks_number = 0;
		$thanks_list =  $this->get_thanks($post_id, $thanks_number);
		$post_reput = ($thanks_number != 0) ? round($thanks_number / ($max_post_thanks / 100), $this->config['thanks_number_digits']) . '%' : '';
		$lang_act = $action == 'thanks' ?  'GIVE' : 'REMOVE';
		$poster_name = '';
		$poster_name_full =  '';
		$this->get_poster_details($poster_id, $poster_name, $poster_name_full);
		$action_togle = $action == 'thanks' ? 'rthanks' : 'thanks' ;
		$path = './app.php/thanks_for_posts/' . $action_togle . '/' . $poster_id . '/' . $forum_id . '/' . $topic_id . '/' . $post_id . '?to_id=' . $poster_id;
		$thank_alt = ($action == 'thanks' ? $this->user->lang['REMOVE_THANKS'] :  $this->user->lang['THANK_POST']) . $poster_name_full;
		$class_icon = $action == 'thanks' ? 'removethanks-icon' : 'thanks-icon';
		$thank_img = "<a  href='" .  $path . "'   data-ajax='togle_thanks' title='" . $thank_alt . "' class='button icon-button " .  $class_icon . "'><span>&nbsp;</span></a>";
		$message = $this->user->lang['THANKS_INFO_' . $lang_act];

		$this->return = array(
			'SUCCESS'			=>  $message,
			'POST_REPUT'		=>  $post_reput,
			'POST_ID'				=>  $post_id,
			'POSTER_ID'				=>  $poster_id,
			'USER_ID'										   =>  $this->user->data['user_id'],
			'CLASS_ICON'									=> $action == 'thanks' ? 'removethanks-icon' : 'thanks-icon',
			'S_THANKS_POST_REPUT_VIEW'		=> isset($this->config['thanks_post_reput_view']) ? (bool) $this->config['thanks_post_reput_view'] : false,
			'THANK_ALT'										=> ($action == 'thanks' ? $this->user->lang['REMOVE_THANKS'] :  $this->user->lang['THANK_POST']) . $poster_name,
			'S_THANKS_REPUT_GRAPHIC' 			=> isset($this->config['thanks_reput_graphic']) ? (bool) $this->config['thanks_reput_graphic'] : false,
			'THANKS_REPUT_GRAPHIC_WIDTH'	=> isset($this->config['thanks_reput_level']) ? (isset($this->config['thanks_reput_height']) ? sprintf('%dpx', $this->config['thanks_reput_level']*$this->config['thanks_reput_height']) : false) : false,
			'THANKS_REPUT_HEIGHT'		=> isset($this->config['thanks_reput_height']) ? sprintf('%dpx', $this->config['thanks_reput_height']) : false,
			'THANKS'					=> $thanks_list,
			'THANKS_POSTLIST_VIEW'		=> isset($this->config['thanks_postlist_view']) ? (bool) $this->config['thanks_postlist_view'] : false,
			'S_MOD_THANKS'				=> $this->auth->acl_get('m_thanks') ? true :false,
			'S_IS_BOT'				=> (!empty($this->user->data['is_bot'])) ? true : false,
			'S_POST_ANONYMOUS'		=> ($poster_id == ANONYMOUS) ? true : false,
			'THANK_TEXT'				=> $this->user->lang['THANK_TEXT_1'],
			'THANK_TEXT_2'				=> ($thanks_number != 1) ? sprintf($this->user->lang['THANK_TEXT_2PL'], $thanks_number) : $this->user->lang['THANK_TEXT_2'],
			'POST_AUTHOR_FULL'			=>$poster_name_full,
			'THANKS_COUNTERS_VIEW'		=> isset($this->config['thanks_counters_view']) ? $this->config['thanks_counters_view'] : false,
			'POSTER_RECEIVE_COUNT'			=> $l_poster_receive_count,
			'POSTER_RECEIVE_COUNT_LINK'	=> './app.php/thankslist/givens/' . $poster_id . '/false',
			'POSTER_GIVE_COUNT'				=> $l_poster_give_count,
			'POSTER_GIVE_COUNT_LINK'	=> './app.php/thankslist/givens/' . $poster_id . '/true',
			'THANK_IMG'					=> $thank_img,
			'THANK_PATH'				=> $path,
			'IS_ALLOW_REMOVE_THANKS'	=> isset($this->config['remove_thanks']) ? (bool) $this->config['remove_thanks'] : true,
		);
	}

	private function clear_list_thanks($poster_id, $forum_id, $topic_id, $post_id)
	{
		$sql = "DELETE FROM " . $this->thanks_table . '
		WHERE post_id = ' . (int) $post_id;
		$result = $this->db->sql_query($sql);

		if ($result == 0)
		{
			$this->error[] = array('error' => $this->user->lang['INCORRECT_THANKS']);
			return;
		}

		// get some output parameters
		$sql = "SELECT poster_id, u.username" .
					" , (select count(poster_id)  from " . $this->thanks_table . " t where p.poster_id= t.poster_id ) as rcv " .
					" , (select count(user_id) as give  from  " . $this->thanks_table . "  t where user_id= " . $this->user->data['user_id'] . " ) as give " .
					"  FROM  " . $this->posts_table .  " p JOIN " . $this->users_table . " u on p.poster_id = u.user_id " .
					"  WHERE post_id=" . $post_id;
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$poster_id = $row['poster_id'];
		$poster_name = $row['username'];
		$poster_give_count = $row['give'];
		$poster_receive_count = $row['rcv'];
		$this->db->sql_freeresult($result);

		$message = $this->user->lang['CLEAR_LIST_THANKS_POST'];

		$this->return = array(
			'SUCCESS'		=> $message,
				'POST_ID'				=> $post_id,
				'POSTER_ID'				=> $poster_id,
				'USER_ID'				=> $this->user->data['user_id'],
				'THANK_ALT'		=> $this->user->lang['THANK_POST'] . $poster_name,
				'THANK_PATH'	=> './app.php/thanks_for_posts/thanks/' . $poster_id . '/' . $forum_id . '/' . $topic_id . '/' . $post_id . '?to_id=' . $poster_id,
				'S_POST_ANONYMOUS'			=> ($poster_id == ANONYMOUS) ? true : false,
				'POSTER_RECEIVE_COUNT'		=> $poster_receive_count,
				'POSTER_RECEIVE_COUNT_LINK'	=> append_sid("{$this->phpbb_root_path}thankslist.$this->php_ext", "mode=givens&amp;author_id={$poster_id}&amp;give=false"),
				'POSTER_GIVE_COUNT'			=> $poster_give_count,
				'POSTER_GIVE_COUNT_LINK'	=> append_sid("{$this->phpbb_root_path}thankslist.$this->php_ext", "mode=givens&amp;author_id={$poster_id}&amp;give=true"),
				'THANKS_COUNTERS_VIEW'		=> isset($this->config['thanks_counters_view']) ? $this->config['thanks_counters_view'] : false,
			);
	}

	// check if the user has already thanked that post
	private function already_thanked($post_id, $user_id)
	{
		$sql = "SELECT count(*) as counter from " . $this->thanks_table .
				" WHERE post_id = " . $post_id .
				" AND user_id = " . $user_id ;
		$result = $this->db->sql_query($sql);
		$thanked = (int) $this->db->sql_fetchfield('counter');
		$this->db->sql_freeresult($result);
		return $thanked;
	}

	// Output thanks list
	private function get_thanks($post_id, &$count)
	{
		$view = '';
		$return = '';
		$user_list = array();
		$count = 0;
		$maxcount = (isset($this->config['thanks_number_post']) ? $this->config['thanks_number_post'] : false);
		$further_thanks = 0;
		$further_thanks_text = '';

		// Go ahead and pull all data for this topic
		$sql = 'SELECT u.user_id, u.username, u.username_clean, u.user_colour, t.thanks_time ' .
				' FROM ' . $this->thanks_table . ' t join ' . $this->users_table . ' u on t.user_id=u.user_id ' .
				' WHERE post_id = ' . $post_id .
				' ORDER BY t.thanks_time DESC';

		$result = $this->db->sql_query($sql);
		$comma = '';
		while ($row = $this->db->sql_fetchrow($result))
		{
			if ($count  >= $maxcount)
			{
				$further_thanks++;
			}
			else
			{
				$return .= $comma;
				$return .= get_username_string('full', $row['user_id'], $row['username'], $row['user_colour']);
				if (isset($this->config['thanks_time_view']) ? $this->config['thanks_time_view'] : false)
				{
					$return .= ($row['thanks_time']) ? ' ('.$this->user->format_date($row['thanks_time'], false, ($view == 'print') ? true : false) . ')' : '';
				}
				$comma = ' &bull; ';
			}
			$count++;
		}

		if ($further_thanks > 0)
		{
			$further_thanks_text = ($further_thanks == 1) ? $this->user->lang['FURTHER_THANKS'] : sprintf($this->user->lang['FURTHER_THANKS_PL'], $further_thanks);
		}
		$return = ($return == '') ? false : ($return . $further_thanks_text);
		$this->db->sql_freeresult($result);
		return $return;
	}

	private function get_poster_details($poster_id, &$poster_name, &$poster_name_full)
	{
		$sql = 'SELECT username, user_colour FROM ' . $this->users_table .
					' WHERE user_id = ' . $poster_id;
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if ($row)
		{
			$poster_name = $row['username'];
			$poster_name_full = get_username_string('full', $poster_id, $row['username'], $row['user_colour']) ;
		}
	}
	private function get_key_by_post($post_id, $user_id)
	{
		$i = 0;
		foreach((array) $this->thankers as $key => $value)
		{
			if ($this->thankers[$key]['post_id'] == $post_id && $this->thankers[$key]['user_id'] == $user_id)
			{
				return $i;
			}
			$i++;
		}
		return $thanked;
	}
	private function get_post_subject($post_id)
	{
		$sql = 'SELECT post_subject FROM ' . $this->posts_table . ' WHERE post_id=' . $post_id;
		$result = $this->db->sql_query($sql, 3600);
		$post_subject =  $this->db->sql_fetchfield('post_subject');
		$this->db->sql_freeresult($result);
		return $post_subject;
	}
}
