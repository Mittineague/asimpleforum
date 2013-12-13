<?php

namespace Model;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PostModel extends BaseModel
{
	public $app;

	public $allowed_html = array(
		'<p>',
		'<br />',
		'<em>',
		'<i>',
		'<strong>',
		'<b>',
		'<abbr>'
	);

	public function __construct (\Silex\Application $app)
	{
		$this->app = $app;
	}

	public function find_by_topic ($topic_id, $page = 1)
	{
		$topic_id = (int) $topic_id;

		if (!$topic_id)
		{
			return false;
		}

		$topics = array();

		$total = $this->app['db']->fetchColumn('SELECT COUNT(*) FROM posts WHERE topic=?', array($topic_id));

		$posts['pagination'] = $this->pagination($total, 10, $page);

		$posts['data'] = $this->app['db']->fetchAll('SELECT p.*, u.username FROM posts p JOIN users u ON p.poster=u.id WHERE p.topic=? ORDER BY added ASC ' . $posts['pagination']['sql_text'], array(
			$topic_id
		));

		return $posts;
	}

	public function add (Request $request)
	{
		$topic_id = (int) $request->get('topicId');

		if (!$topic_id)
		{
			$response = new Response();
	        $response->setStatusCode(400);
	        $response->setContent($this->app['language']->phrase('UNKNOWN_ERROR'));
	        return $response;
		}

		$topic = $this->app['topic']->find_by_id($topic_id);

		if (!$topic)
		{
			$response = new Response();
	        $response->setStatusCode(400);
	        $response->setContent($this->app['language']->phrase('UNKNOWN_ERROR'));
	        return $response;
		}

		$user = $this->app['session']->get('user');

		if (!$user)
		{
			$response = new Response();
	        $response->setStatusCode(400);
	        $response->setContent($this->app['language']->phrase('MUST_BE_LOGGED_IN'));
	        return $response;
		}

		$name = $request->get('name');
		$content = $request->get('content');
		$content = strip_tags($content, implode(',', $this->allowed_html));
		$time = time();

		$last = $this->app['db']->fetchAssoc('SELECT * FROM posts WHERE topic=? ORDER BY added DESC LIMIT 1', array(
			$topic_id
		));


		if ($last['poster'] == $user['id'])
		{
			if ($this->app['config']->board['double_post'] === 'merge')
			{
				preg_match('/\[update\](.*)\[\/update\]/s', $last['content'], $matches);

				if (count($matches) > 0)
				{
					$content = preg_replace(
						'/\[update\](.*)\[\/update\]/s',
						'[update]' . "\n" . '$1' . "\n" . $content . '[/update]',
						$last['content']
					);
				}
				else
				{
					$content = $last['content'] . '[update][size=18][b]Update[/b][/size]' . "\n" . $content . '[/update]';
				}

				$this->app['db']->update('posts', array(
					'content' => $content,
					'updated' => time()
				), array('id' => $last['id']));

				$this->app['db']->update('topics', array(
					'updated' => $time
				), array('id' => $last['topic']));

				$this->app['db']->update('forums', array(
					'lastTopicId' => $topic['id'],
					'lastPosterId' => $user['id'],
					'lastPostTime' => $time,
					'lastPostId' => $last['id']
				), array('id' => $last['forum']));

				$response = new Response();
				$response->setContent(json_encode(array(
					'id' => $last['id'],
					'content' => \Utils::bbcode(nl2br($content)),
					'updated' => true
				)));
				return $response;
			}
			else if ($this->app['config']->board['double_post'] === 'disallow')
			{
				$response = new Response;
				$response->setStatusCode(400);
				$response->setContent($this->app['language']->phrase('NO_DOUBLE_POST'));
				return $response;
			}
		}

		$this->app['db']->insert('posts', array(
			'topic' => $topic['id'],
			'forum' => $topic['forum'],
			'name' => $name,
			'content' => $content,
			'poster' => $user['id'],
			'added' => $time,
			'updated' => $time
		));

		$post_id = $this->app['db']->lastInsertId();

		$this->app['db']->update('topics', array(
			'replies' => $topic['replies'] + 1,
			'updated' => $time,
			'lastPosterId' => $user['id'],
			'lastPostId' => $post_id
		), array('id' => $topic_id));

		$this->app['db']->executeQuery('UPDATE forums SET posts=posts+1, lastTopicId=?,lastPosterId=?, lastPostTime=?, lastPostId=? WHERE id=? LIMIT 1', array(
			$topic['id'],
			$user['id'],
			$time,
			$post_id,
			$topic['forum']
		));

		return json_encode(array(
			'id' => $post_id,
			'username' => $user['username'],
			'userId' => $user['id']
		));
	}

	public function get_first (Request $request)
	{
		$topic_id = (int) $request->get('topicId');

		if (!$topic_id)
		{
			return false;
		}

		$content = $this->app['db']->fetchColumn('SELECT content FROM posts WHERE topic=? ORDER BY added ASC LIMIT 1', array(
			$topic_id
		));

		return \Utils::truncate($content, 200);
	}

	public function report (Request $request)
	{
		$post_id = (int) $request->get('postId');

		if (!$post_id)
		{
			return false;
		}

		$user = $this->app['session']->get('user');

		if (!$user)
		{
			return false;
		}

		$reason = $request->get('reason');

		$this->app['db']->insert('reports', array(
			'type' => 'POST',
			'typeId' => $post_id,
			'reporter' => $user['id'],
			'reason' => $reason,
			'added' => time(),
			'ip' => $request->getClientIp()
		));

		return 'Your report has been logged and a member of the team will look into it shortly.';
	}

	public function find_by_id (Request $request)
	{
		$id = (int) $request->get('id');

		if (!$id)
		{
			return false;
		}

		$post = $this->app['db']->fetchAssoc('SELECT * FROM posts WHERE id=? LIMIT 1', array(
			$id
		));

		$post['content'] = nl2br($post['content']);

		return json_encode($post);
	}

	public function update (Request $request)
	{
		$id = (int) $request->get('id');
		$content = strip_tags($request->get('content'), implode(',', $this->allowed_html));

		if (!$content)
		{
			$response = new Response();
	        $response->setStatusCode(400);
	        $response->setContent($this->app['language']->phrase('FILL_ALL_FIELDS'));
	        return $response;
		}

		$this->app['db']->executeQuery('UPDATE posts SET content=?, edits=edits+1, updated=? WHERE id=? LIMIT 1', array(
			$content,
			time(),
			$id
		));

		return json_encode(array(
			'content' => \Utils::bbcode(nl2br($content))
		));
	}
}